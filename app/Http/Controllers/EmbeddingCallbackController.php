<?php

namespace App\Http\Controllers;

use App\Models\VideoEmbedding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmbeddingCallbackController extends Controller
{
    public function store(Request $request)
    {
        $key = (string) config('services.embedding.api_key', '');
        if ($key === '') return response()->json(['message' => 'Embedding authentication is not configured'], 503);
        if (!hash_equals($key, (string) $request->header('X-API-Key', ''))) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $data = $request->validate([
            'video_id' => 'required|integer|min:1', 'run_id' => 'required|string|max:100',
            'status' => 'required|in:processing,completed,failed',
            'segments_embedded' => 'required_if:status,completed|integer|min:0',
            'collection' => 'nullable|string|max:200', 'error' => 'nullable|string|max:2000',
        ]);
        return DB::transaction(function () use ($data) {
            $embedding = VideoEmbedding::where('video_id', $data['video_id'])->lockForUpdate()->first();
            if (!$embedding || $embedding->active_run_id !== $data['run_id']) {
                return response()->json(['status' => 'ignored', 'reason' => 'stale_generation']);
            }
            if ($embedding->status === 'completed') {
                return response()->json(['status' => 'acknowledged']); // idempotent callbacks
            }
            if ($data['status'] === 'completed') {
                $embedding->markAsCompleted($data['segments_embedded'], $data['collection'] ?? $embedding->qdrant_collection);
            } elseif ($data['status'] === 'failed') {
                $embedding->markAsFailed($data['error'] ?? 'Python indexing failed');
            }
            // Processing notifications cannot downgrade a failed/completed generation.
            return response()->json(['status' => 'acknowledged']);
        });
    }
}
