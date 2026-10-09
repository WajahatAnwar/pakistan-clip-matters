<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VideoEmbedding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QdrantWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $data = validator(array_merge($request->all(), $request->query()), [
            'video_id' => 'required|integer|min:1', 'run_id' => 'nullable|uuid',
            'batch' => 'required|integer|min:1', 'total' => 'required|integer|min:1',
            'status' => 'required|in:completed,failed',
            'segments_embedded' => 'nullable|integer|min:0', 'error' => 'nullable|string',
        ])->validate();
        return DB::transaction(function () use ($data) {
            $embedding = VideoEmbedding::where('video_id', $data['video_id'])->lockForUpdate()->first();
            if (!$embedding) return response()->json(['error' => 'Video embedding not found'], 404);
            // Legacy callbacks are acknowledged but cannot safely mutate a run:
            // they carry no generation identity and may be arbitrarily stale.
            if (empty($data['run_id']) || $data['run_id'] !== $embedding->active_run_id) {
                return response()->json(['message' => 'Unidentified or stale callback ignored']);
            }
            if ((int) $data['total'] !== $embedding->expected_batches || $data['batch'] > $data['total']) {
                return response()->json(['error' => 'Batch contract mismatch'], 422);
            }
            $acks = $embedding->batch_acknowledgments ?? [];
            $key = (string) $data['batch'];
            if (($acks[$key]['status'] ?? null) === 'completed') {
                return response()->json(['message' => 'Duplicate acknowledgment ignored']);
            }
            $acks[$key] = ['status' => $data['status'], 'count' => $data['segments_embedded'] ?? 0];
            $embedding->batch_acknowledgments = $acks;
            $successful = array_filter($acks, fn ($ack) => $ack['status'] === 'completed');
            $embedding->segments_count = array_sum(array_column($successful, 'count'));
            $embedding->save();
            if (count($successful) === $embedding->expected_batches) {
                $embedding->markAsCompleted($embedding->segments_count);
            } elseif ($data['status'] === 'failed') {
                $embedding->markAsFailed($data['error'] ?? 'Indexing batch failed');
            }
            return response()->json(['message' => 'Acknowledgment recorded']);
        });
    }
}
