<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VideoEmbedding;
use Illuminate\Support\Facades\Log;

class QdrantWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from the Python Qdrant indexing service.
     */
    public function handleWebhook(Request $request)
    {
        $videoId = $request->query('video_id');
        $batch = $request->query('batch');
        $total = $request->query('total');
        
        $status = $request->input('status');
        $segmentsEmbedded = $request->input('segments_embedded', 0);
        $error = $request->input('error');

        Log::info("Received Qdrant Webhook", [
            'video_id' => $videoId,
            'batch' => $batch,
            'total' => $total,
            'status' => $status,
            'segments' => $segmentsEmbedded
        ]);

        if (!$videoId) {
            return response()->json(['error' => 'Missing video_id'], 400);
        }

        $embedding = VideoEmbedding::where('video_id', $videoId)->first();

        if (!$embedding) {
            Log::warning("Received webhook for unknown video_id: {$videoId}");
            return response()->json(['error' => 'Video embedding not found'], 404);
        }

        if ($status === 'failed') {
            Log::error("Qdrant webhook reported failure for video_id {$videoId}: {$error}");
            $embedding->markAsFailed($error);
            return response()->json(['message' => 'Failure recorded']);
        }

        if ($status === 'completed') {
            // Update segments count
            if ($segmentsEmbedded > 0) {
                // Increment current segments count
                $embedding->increment('segments_count', $segmentsEmbedded);
            }

            // If this is the final batch, mark the whole embedding as completed
            if ($batch == $total) {
                $embedding->refresh(); // get updated segments_count
                $embedding->markAsCompleted($embedding->segments_count);
                Log::info("Video {$videoId} embedding fully completed.");
            }

            return response()->json(['message' => 'Webhook processed successfully']);
        }

        return response()->json(['message' => 'Status ignored'], 200);
    }
}
