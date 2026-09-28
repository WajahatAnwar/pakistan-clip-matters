<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\AssemblyAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TranscriptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($authError = $this->apiKeyError($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = Video::query()
            ->whereNotNull('transcript_id')
            ->where('transcript_id', '!=', '')
            ->latest('id')
            ->paginate($perPage);

        $transcripts = $paginator->getCollection()->map(function (Video $video) {
            $text = (string) ($video->transcript_text ?? '');

            return [
                'transcript_id' => $video->transcript_id,
                'title' => $video->title,
                'length' => mb_strlen($text),
            ];
        })->values();

        return response()->json([
            'data' => $transcripts,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        if ($authError = $this->apiKeyError($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'transcript_id' => ['required', 'string', 'max:255'],
        ]);

        $transcriptId = trim($validated['transcript_id']);

        if ($transcriptId === '') {
            return response()->json([
                'message' => 'The transcript_id field is required.',
                'errors' => [
                    'transcript_id' => ['The transcript_id field is required.'],
                ],
            ], 422);
        }

        $video = Video::with('user')
            ->where('transcript_id', $transcriptId)
            ->first();

        if (! $video) {
            return response()->json([
                'message' => 'Transcript not found.',
                'transcript_id' => $transcriptId,
            ], 404);
        }

        try {
            $transcript = (new AssemblyAiService($video->user))->getTranscript($transcriptId);
        } catch (\Throwable $exception) {
            Log::warning('Transcript API failed to retrieve AssemblyAI transcript', [
                'video_id' => $video->id,
                'transcript_id' => $transcriptId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to retrieve transcript from AssemblyAI.',
                'transcript_id' => $transcriptId,
                'error' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'transcript_id' => $transcriptId,
            'text' => (string) ($transcript['text'] ?? ''),
        ]);
    }

    private function apiKeyError(Request $request): ?JsonResponse
    {
        $configuredApiKey = (string) config('services.transcript_api.key', '');

        if ($configuredApiKey === '') {
            return response()->json([
                'message' => 'Transcript API is not configured.',
            ], 503);
        }

        $providedApiKey = (string) $request->header('X-API-Key', '');

        if ($providedApiKey === '' || ! hash_equals($configuredApiKey, $providedApiKey)) {
            return response()->json([
                'message' => 'Invalid or missing API key.',
            ], 401);
        }

        return null;
    }
}
