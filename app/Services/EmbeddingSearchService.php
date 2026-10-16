<?php

namespace App\Services;

use App\Models\SearchLog;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingSearchService
{
    public function search(Request $request): JsonResponse
    {
        $started = microtime(true);
        $data = $request->validate([
            'search_type' => 'nullable|in:smart,speaker,title,date,language,summary,transcript,transcript_literal,transcript_alias',
            'query' => 'nullable|string|max:1000', 'word' => 'nullable|string|max:200',
            'words' => 'nullable|array', 'words.*' => 'string|max:200',
            'top_k' => 'nullable|integer|min:1|max:1000', 'max_scanned' => 'nullable|integer|min:100|max:100000',
            'video_id' => 'nullable|integer|min:1', 'speaker' => 'nullable|string|max:200',
            'title' => 'nullable|string|max:500', 'language' => 'nullable|string|max:50',
            'min_score' => 'nullable|numeric|min:0|max:1', 'time_range' => 'nullable|array',
            'time_range.start' => 'nullable|numeric|min:0', 'time_range.end' => 'nullable|numeric|min:0',
            'filter_year' => 'nullable|integer|min:1900|max:2100', 'filter_month' => 'nullable|integer|min:1|max:12',
            'filter_date' => 'nullable|date_format:Y-m-d',
            'search_mode' => 'nullable|in:semantic,simple',
            'filter_type' => 'nullable|in:video,speaker,date,language,title,summary,text',
            'transcript_contract' => 'nullable|in:literal,normalized,alias',
            'query_decomposition_mode' => 'nullable|in:off,shadow,on',
            'summary_candidates' => 'nullable|array|max:60',
            'summary_candidates.*.video_id' => 'required|integer|min:1',
            'summary_candidates.*.matched_summary_fields' => 'required|array',
            'summary_candidates.*.matched_summary_fields.*' => 'string|max:100',
            'cursor' => 'nullable|string|max:2000', 'batch_size' => 'nullable|integer|min:1|max:50',
            'search_session_id' => 'nullable|string|max:100', 'use_incremental' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $data = $this->normalizeSearchPayload($data);
        if (str_starts_with($data['cursor'] ?? '', 'php:')) {
            return response()->json(['success' => false, 'code' => 'search_session_expired',
                'message' => 'This search session expired. Run the search again.', 'retryable' => true], 409);
        }
        $mode = $data['search_mode'] ?? 'semantic';
        if ($mode === 'semantic' && empty($data['query']) && empty($data['words']) && empty($data['word']) && !empty($data['speaker'])) {
            $mode = 'simple';
            $data['filter_type'] = 'speaker';
        }
        $data['search_mode'] = $mode;
        // Exact metadata filters use paginated SQL; transcript/summary contracts
        // keep the current application's evidence-aware presenter.
        if ($mode === 'simple' && in_array($data['filter_type'] ?? null, ['title', 'text', 'summary'], true)) {
            return app(StageThreeSearch::class)->search($data);
        }
        if (($mode === 'simple' && in_array($data['filter_type'] ?? null, ['video', 'date', 'language'], true))
            || ($mode === 'semantic' && empty($data['query']) && empty($data['word']) && empty($data['words'])
                && (!empty($data['filter_year']) || !empty($data['filter_date'])))) {
            return $this->metadataSearch($data);
        }
        $incremental = $mode === 'semantic' && (($data['use_incremental'] ?? false)
            || !empty($data['cursor']) || !empty($data['search_session_id']));
        $perPage = (int) ($data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $payload = array_intersect_key($data, array_flip([
            'query','word','words','top_k','max_scanned','video_id','speaker','title','language','min_score',
            'time_range','filter_year','filter_month','filter_date','search_mode','filter_type',
            'transcript_contract','query_decomposition_mode','summary_candidates',
        ]));
        $payload['search_mode'] = $mode;
        $payload['query'] = $data['query'] ?? '';
        $payload['top_k'] = (int) ($data['top_k'] ?? 200);
        $payload['transcript_contract'] = $data['transcript_contract'] ?? 'normalized';
        if ($incremental) {
            $payload['cursor'] = $data['cursor'] ?? null;
            $payload['search_session_id'] = $data['search_session_id'] ?? null;
            $payload['batch_size'] = (int) ($data['batch_size'] ?? $perPage);
        }
        try {
            // Python caches verified evidence and cursor snapshots. Never retry a
            // costly first page or turn an error/no-match into a literal fallback.
            $response = Http::acceptJson()->withHeaders(['X-API-Key' => config('services.embedding.api_key', '')])
                ->connectTimeout(10)->timeout((int) config('services.embedding.search_timeout', 120))
                ->post(rtrim(config('services.embedding.url'), '/') . ($incremental ? '/search/incremental' : '/search'), $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            return response()->json(['success' => false, 'message' => 'Search service is temporarily unavailable. Please retry.',
                'code' => 'search_service_unavailable', 'retryable' => true], 503);
        }
        if ($response->failed()) {
            $detail = $response->json('detail');
            $detail = is_array($detail) && !array_is_list($detail) ? $detail : [];
            return response()->json([
                'success' => false, 'message' => $detail['message'] ?? 'Search could not complete. Please retry.',
                'code' => $detail['code'] ?? 'search_service_error',
                'retryable' => $detail['retryable'] ?? in_array($response->status(), [409, 429, 502, 503, 504]),
                'diagnostic_id' => $detail['diagnostic_id'] ?? null, 'reason' => $detail['reason'] ?? null,
                'status_code' => $response->status(),
            ], $response->status());
        }
        $upstream = $response->json();
        if (!is_array($upstream) || !is_array($upstream['results'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'Search returned an invalid response. Please retry.',
                'code' => 'search_service_invalid_response', 'retryable' => true], 502);
        }
        $rows = $upstream['results'];
        $videos = Video::readyForSearch()->whereIn('id', array_column($rows, 'video_id'))->get(Video::SEARCH_CARD_COLUMNS)->keyBy('id');
        $groups = [];
        $omitted = 0;
        foreach ($rows as $row) {
            $video = $videos->get($row['video_id'] ?? null);
            if (!$video) { $omitted++; continue; }
            $id = $video->id;
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'video' => ['id' => $id, 'title' => $video->title, 'filename' => $video->filename,
                        'youtube_url' => $video->youtube_url, 'youtube_video_id' => $video->youtube_video_id,
                        'language' => $video->language_detected, 'created_at' => $video->created_at?->toISOString(),
                        'video_created_at' => $video->video_created_at?->toISOString(), 'summary' => $video->summary,
                        'has_audio' => $video->has_audio, 'audio_duration_seconds' => $video->audio_duration_seconds,
                        'summary_english' => $video->summary_english, 'summary_urdu' => $video->summary_urdu],
                    'segments' => [], 'match_count' => 0, 'best_score' => $row['score'] ?? 0,
                    'match_types' => [], 'matched_fields' => [], 'is_video_only' => false,
                ];
            }
            $groups[$id]['match_types'] = array_values(array_unique(array_merge($groups[$id]['match_types'], $row['match_types'] ?? [])));
            $groups[$id]['matched_fields'] = array_values(array_unique(array_filter(array_merge($groups[$id]['matched_fields'], [$row['matched_field'] ?? null]))));
            if (!empty($row['is_video_only'])) {
                $groups[$id]['is_video_only'] = true;
                $groups[$id]['contract_match'] = $row;
                continue;
            }
            $text = $row['text'] ?? '';
            // Copy the complete evidence contract, not a lossy list of UI fields.
            $groups[$id]['segments'][] = array_merge($row, [
                'segment_id' => $row['id'] ?? null, 'text_preview' => mb_substr($text, 0, 150),
                'youtube_timestamp_url' => $row['youtube_url_timestamped'] ?? $this->timestampUrl($video->youtube_url, $row['start_time'] ?? 0),
            ]);
            $groups[$id]['match_count']++;
        }
        $allGroups = array_values($groups); // Preserve Python order and distinct source occurrences.
        foreach ($allGroups as &$group) {
            $group['passage_count'] = $group['match_count'];
            $group['occurrence_count'] = ($data['filter_type'] ?? null) === 'text'
                ? array_sum(array_column($group['segments'], 'occurrence_count')) : null;
            $group['avg_score'] = count($group['segments'])
                ? array_sum(array_column($group['segments'], 'score')) / count($group['segments']) : $group['best_score'];
        }
        unset($group);
        $contract = $upstream['search_contract'] ?? $upstream['metadata']['search_contract'] ?? null;
        $validation = $upstream['metadata']['passage_validation'] ?? $contract['passage_validation'] ?? null;
        $ranking = $upstream['ranking'] ?? $upstream['metadata']['ranking'] ?? null;
        $partial = ($contract['status'] ?? null) === 'partial' || ($validation['status'] ?? null) === 'partial' || $omitted > 0;
        $total = $incremental ? ($upstream['cursor']['total_available'] ?? count($allGroups)) : count($allGroups);
        $display = $incremental ? $allGroups : array_slice($allGroups, ($page - 1) * $perPage, $perPage);
        $result = [
            'success' => true, 'query' => $upstream['query'] ?? $payload['query'], 'words' => $data['words'] ?? [],
            'speaker_filter' => $data['speaker'] ?? null, 'filters' => $payload, 'search_mode' => $mode, 'filter_type' => $data['filter_type'] ?? null,
            'grouped_by_video' => $display, 'total_videos' => $total,
            'total_segments' => array_sum(array_column($allGroups, 'match_count')),
            'current_page' => $page, 'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'has_next_page' => $incremental ? ($upstream['cursor']['has_more'] ?? false) : $page * $perPage < $total,
            'has_prev_page' => !$incremental && $page > 1,
            'is_incremental' => $incremental, 'search_contract' => $contract,
            'ranking' => $ranking, 'passage_validation' => $validation,
            'search_status' => $partial ? 'partial' : ($validation['status'] ?? $contract['status'] ?? 'completed'),
            'retryable' => $partial || ($validation['retryable'] ?? false),
            'counts' => ['matching_videos' => $total, 'displayed_passages' => array_sum(array_column($allGroups, 'match_count')),
                'matched_transcript_occurrences' => ($data['filter_type'] ?? null) === 'text' ? array_sum(array_column($allGroups, 'occurrence_count')) : null],
            'search_metadata' => [
                'status' => $partial ? 'partial' : ($validation['status'] ?? $contract['status'] ?? 'completed'),
                'retryable' => $partial || ($validation['retryable'] ?? false),
                'search_contract' => $contract, 'passage_validation' => $validation, 'ranking' => $ranking,
                'upstream_metadata' => $upstream['metadata'] ?? [], 'omitted_unavailable_passages' => $omitted,
                'counts_scope' => $incremental ? 'python_eligible_snapshot' : 'returned_python_results',
                'response_time_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        ];
        if ($incremental) {
            $result['cursor'] = $upstream['cursor'];
            $result['search_session_id'] = $upstream['search_session_id'] ?? null;
            $result['batch_metadata'] = $upstream['metadata'] ?? [];
        }
        try {
            SearchLog::create(['user_id' => $request->user()?->id, 'query' => $payload['query'],
                'speaker' => $data['speaker'] ?? null, 'video_id' => $data['video_id'] ?? null,
                'results_count' => $result['total_segments'], 'videos_count' => count($display),
                'min_score' => $data['min_score'] ?? .35, 'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(), 'response_time_ms' => $result['search_metadata']['response_time_ms'],
                'search_type' => $mode, 'filters' => json_encode($payload)]);
        } catch (\Throwable $error) {
            Log::warning('Search history could not be recorded', ['exception' => get_class($error)]);
        }
        return response()->json($result);
    }

    private function metadataSearch(array $data): JsonResponse
    {
        $query = Video::readyForSearch();
        if (!empty($data['video_id'])) $query->where('id', $data['video_id']);
        if (!empty($data['language'])) $query->where('language_detected', $data['language']);
        if (!empty($data['filter_date'])) $query->whereDate('video_created_at', $data['filter_date']);
        elseif (!empty($data['filter_year'])) {
            $query->whereYear('video_created_at', $data['filter_year']);
            if (!empty($data['filter_month'])) $query->whereMonth('video_created_at', $data['filter_month']);
        }
        $page = $data['page'] ?? 1; $perPage = $data['per_page'] ?? 10;
        $total = (clone $query)->count();
        $groups = $query->orderBy('id')->forPage($page, $perPage)->get(Video::SEARCH_CARD_COLUMNS)->map(fn ($video) => [
            'video' => array_intersect_key($video->toArray(), array_flip(Video::SEARCH_CARD_COLUMNS)),
            'segments' => [], 'match_count' => 0, 'passage_count' => 0, 'occurrence_count' => null,
            'best_score' => 0, 'avg_score' => 0, 'is_video_only' => true,
            'match_types' => ['simple_'.($data['filter_type'] ?? 'date').'_filter'], 'matched_fields' => [],
        ]);
        return response()->json(['success' => true, 'query' => $data['query'] ?? '',
            'search_mode' => 'simple', 'filter_type' => $data['filter_type'] ?? 'date', 'is_incremental' => false,
            'grouped_by_video' => $groups, 'total_videos' => $total, 'total_segments' => 0,
            'counts' => ['matching_videos' => $total, 'displayed_passages' => 0, 'matched_transcript_occurrences' => null],
            'current_page' => $page, 'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage),
            'has_next_page' => $page * $perPage < $total, 'has_prev_page' => $page > 1,
            'search_status' => $total ? 'completed' : 'no_matches', 'retryable' => false,
            'search_metadata' => ['status' => $total ? 'completed' : 'no_matches', 'authority' => 'eligible_database_metadata']]);
    }

    private function normalizeSearchPayload(array $validated): array
    {
        // Backward compatibility
        $searchMode = $validated['search_mode'] ?? null;
        $filterType = $validated['filter_type'] ?? null;
        $query = $validated['query'] ?? null;
        $dateFilter = !empty($validated['filter_year']) || !empty($validated['filter_date']);
        $language = $validated['language'] ?? null;

        if (!empty($validated['search_type'])) {
            $type = $validated['search_type'];
            switch ($type) {
                case 'smart':
                    $searchMode = 'semantic';
                    $filterType = null;
                    if (empty($query) && !$dateFilter) {
                        abort(response()->json(['success' => false, 'message' => 'Smart search requires a text query or date filter', 'errors' => ['query' => ['Query is required']]], 422));
                    }
                    break;
                case 'speaker':
                    $searchMode = 'simple';
                    $filterType = 'speaker';
                    $query = trim((string) ($validated['speaker'] ?? '')) !== ''
                        ? trim($validated['speaker']) : trim((string) $query);
                    if ($query === '') abort(response()->json(['success' => false, 'message' => 'Speaker search requires a speaker name', 'errors' => ['query' => ['Speaker name is required']]], 422));
                    $validated['query'] = $query;
                    $validated['speaker'] = $query;
                    break;
                case 'title':
                    $searchMode = 'simple';
                    $filterType = 'title';
                    $query = trim((string) ($validated['title'] ?? '')) !== '' ? $validated['title'] : $query;
                    $validated['query'] = $query;
                    $validated['title'] = $query;
                    if (trim((string) $query) === '') abort(response()->json(['success' => false, 'message' => 'Title search requires a title query', 'errors' => ['query' => ['Title is required']]], 422));
                    break;
                case 'date':
                    $searchMode = 'simple';
                    $filterType = 'date';
                    if (!$dateFilter) abort(response()->json(['success' => false, 'message' => 'Date search requires a valid year, month, or date filter', 'errors' => ['date' => ['Date filter is required']]], 422));
                    break;
                case 'language':
                    $searchMode = 'simple';
                    $filterType = 'language';
                    if (empty($language)) abort(response()->json(['success' => false, 'message' => 'Language search requires selecting a language', 'errors' => ['language' => ['Language is required']]], 422));
                    break;
                case 'transcript_literal':
                case 'transcript_alias':
                case 'transcript':
                    $validated['transcript_contract'] = $type === 'transcript_literal' ? 'literal' : ($type === 'transcript_alias' ? 'alias' : ($validated['transcript_contract'] ?? 'normalized'));
                    $searchMode = 'simple';
                    $filterType = 'text';
                    if (trim((string) $query) === '') abort(response()->json(['success' => false, 'message' => 'Transcript search requires a text query', 'errors' => ['query' => ['Query is required']]], 422));
                    break;
                case 'summary':
                    $searchMode = 'simple';
                    $filterType = 'summary';
                    if (empty($query)) abort(response()->json(['success' => false, 'message' => 'Summary search requires a text query', 'errors' => ['query' => ['Query is required']]], 422));
                    break;
            }
        } else {
            // fallback for legacy
            $searchMode = $searchMode ?? 'semantic';
        }

        $validated['search_mode'] = $searchMode;
        $validated['filter_type'] = $filterType;
        
        return $validated;
    }


    private function timestampUrl(?string $url, float $seconds): ?string
    {
        if (!$url) return null;
        return $url . (str_contains($url, '?') ? '&' : '?') . 't=' . (int) $seconds . 's';
    }
}
