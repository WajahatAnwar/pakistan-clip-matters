<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranscriptController extends Controller
{
    public function document(Request $request): \Illuminate\Contracts\View\View
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $title = trim($validated['title'] ?? '');
        $videos = Video::query()
            ->whereNotNull('transcript_id')
            ->where('transcript_id', '!=', '')
            ->when($title !== '', fn ($query) => $query->where('title', 'like', '%'.$title.'%'))
            ->latest('id')
            ->paginate(50)
            ->appends(['title' => $title]);

        $videos->getCollection()->each(function (Video $video) {
            $sections = [];
            foreach (['Urdu' => 'transcript_urdu', 'English' => 'transcript_english'] as $language => $field) {
                $rows = $this->documentRows($video->$field, $video->transcript_timestamp_unit ?? 'milliseconds');
                if ($rows !== []) {
                    $sections[] = ['language' => $language, 'rows' => $rows];
                }
            }
            if ($sections === []) {
                $sections[] = ['language' => 'Transcript', 'rows' => $this->documentRows($video->transcript_text, $video->transcript_timestamp_unit ?? 'milliseconds')];
            }
            $video->setAttribute('document_sections', $sections);
        });

        return view('transcripts.document', compact('videos', 'title'));
    }

    private function documentRows(mixed $transcript, string $unit): array
    {
        if (is_string($transcript)) {
            $decoded = json_decode($transcript, true);
            if (is_array($decoded)) {
                return $this->documentRows($decoded, $unit);
            }
            return trim($transcript) === '' ? [] : [['text' => trim($transcript), 'timestamp' => null]];
        }
        if (! is_array($transcript)) {
            return [];
        }
        foreach (['utterances', 'segments', 'taggedTranscript', 'transcript'] as $key) {
            if (isset($transcript[$key]) && is_array($transcript[$key])) {
                return $this->documentRows($transcript[$key], $unit);
            }
        }
        if (isset($transcript['text']) && is_string($transcript['text'])) {
            $text = trim($transcript['text']);
            if ($text === '') {
                return [];
            }
            $format = static function ($value) use ($unit): ?string {
                if (! is_numeric($value) || $value < 0) {
                    return null;
                }
                $seconds = (int) floor($value / ($unit === 'milliseconds' ? 1000 : 1));
                return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            };
            $start = $format($transcript['start'] ?? null);
            $end = $format($transcript['end'] ?? null);
            return [['text' => $text, 'timestamp' => $start === null ? null : $start.($end === null ? '' : ' – '.$end)]];
        }
        $rows = [];
        foreach ($transcript as $segment) {
            if (is_array($segment)) {
                array_push($rows, ...$this->documentRows($segment, $unit));
            }
        }
        return $rows;
    }

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

        $video = Video::where('transcript_id', $transcriptId)->first();

        if (! $video) {
            return response()->json([
                'message' => 'Transcript not found.',
                'transcript_id' => $transcriptId,
            ], 404);
        }

        return response()->json([
            'transcript_id' => $transcriptId,
            'transcript_urdu' => $this->mainText($video->transcript_urdu),
            'transcript_english' => $this->mainText($video->transcript_english),
        ]);
    }

    private function mainText(mixed $transcript): string
    {
        if (is_string($transcript)) {
            $trimmed = trim($transcript);
            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->mainText($decoded);
            }

            return $trimmed;
        }

        if (! is_array($transcript)) {
            return '';
        }

        if (isset($transcript['text']) && is_string($transcript['text'])) {
            return trim($transcript['text']);
        }

        foreach (['utterances', 'segments', 'taggedTranscript', 'transcript'] as $key) {
            if (isset($transcript[$key]) && is_array($transcript[$key])) {
                return $this->mainText($transcript[$key]);
            }
        }

        $texts = [];
        foreach ($transcript as $segment) {
            if (is_array($segment) && isset($segment['text']) && is_string($segment['text'])) {
                $text = trim($segment['text']);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return implode("\n", $texts);
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
