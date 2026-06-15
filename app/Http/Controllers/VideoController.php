<?php
namespace App\Http\Controllers;

use App\Jobs\GenerateVideoEmbedding;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use OpenAI;
use App\Services\DropboxService;
use App\Services\SpeakerTaggingService;
use App\Models\User;
use App\Models\SearchLog;

class VideoController extends Controller
{
    /**
     * Display a listing of the user's videos
     */
    public function index(Request $request)
{
    $perPage = $request->get('per_page', 5);
    $status = $request->get('status'); // Filter by status: all, processing, failed, tracked
    $search = $request->get('search'); // Search query
    $sort = $request->get('sort', 'newest'); // Sort order: newest or oldest

        // Get all videos (shared across all admins)
        // Only show approved and non-archived videos
        $query = Video::with('embedding')
        ->where('approval_status', 'approved')
        ->where('is_archived', false)
        ->select([
            'id',
            'dropbox_path',
            'filename',
            'title',
            'description',
            'youtube_video_id',
            'speakers_data',
            'processing_status',
            'processing_error',
            'youtube_url',
            'transcript_id',
            'language_detected',
            'pyannote_job_id',
            'diarization_data',
            'identification_data',
            'created_at',
            'video_created_at',
        ]);

        // Apply search filter
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('filename', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Apply status filters
        if ($status && $status !== 'all') {
            switch ($status) {
                case 'processing':
                    $query->whereIn('processing_status', ['processing', 'pending']);
                    break;
                case 'failed':
                    $query->where('processing_status', 'failed');
                    break;
                case 'tracked':
                    // Tracked means processing is completed
                    $query->where('processing_status', 'completed')
                          ->whereNotNull('dropbox_path')
                          ->whereNotNull('transcript_id')
                          ->whereNotNull('speakers_data')
                          ->whereNotNull('pyannote_job_id')
                          ->whereNotNull('diarization_data')
                          ->whereNotNull('identification_data');
                    break;
            }
        }

        // Apply sorting based on sort parameter
        // Videos under processing always appear on top
        $sortOrder = $sort === 'oldest' ? 'asc' : 'desc';
        $videos = $query
            ->orderByRaw("CASE WHEN processing_status IN ('processing', 'pending') THEN 0 ELSE 1 END")
            ->orderBy('created_at', $sortOrder)
            ->paginate($perPage);

        $videos->getCollection()->transform(function ($video) {
            $video->onDropbox = $video->dropbox_path ? true : false;
            $video->onYoutube = $video->youtube_video_id ? true : false;
            $video->isTranscript = $video->transcript_id && $video->speakers_data !== null ? true : false;
            $video->isTagged = $video->pyannote_job_id && $video->diarization_data !== null && $video->identification_data !== null ? true : false;
            // YouTube is optional — a video is tracked if Dropbox + Transcript + Speaker Tagging are done
            $video->isTracked = $video->isTranscript && $video->isTagged && $video->onDropbox ? true : false;

            // Add embedding status
            $video->hasEmbedding = $video->embedding && $video->embedding->isCompleted();
            $video->embeddingStatus = $video->embedding ? $video->embedding->status : null;
            $video->embeddingSegmentsCount = $video->embedding ? $video->embedding->segments_count : 0;

            return $video->makeHidden([
                'dropbox_path',
                'speakers_data',
                'diarization_data',
                'identification_data',
                'embedding'
            ]);
        });

    return response()->json($videos);
    }

    /**
     * Get dashboard statistics for user's videos
     */
    public function dashboardStats(Request $request)
    {
        // Total videos count (all videos shared across admins)
        $totalVideos = Video::count();

        // Completed videos (fully uploaded and processed)
        $completedVideos = Video::where('processing_status', 'completed')
            ->count();

        // Total size of all videos in MB
        $totalSizeMB = Video::sum('size_mb');

        // Total size of uploaded videos
        $uploadedSizeMB = Video::whereNotNull('dropbox_path')
            ->sum('size_mb');

        // Videos with transcripts
        $transcribedVideos = Video::whereNotNull('transcript_id')
            ->whereNotNull('speakers_data')
            ->count();

        // Total hours of video processed (audio duration)
        $totalSeconds = Video::whereNotNull('audio_duration_seconds')
            ->sum('audio_duration_seconds');
        $totalHours = $totalSeconds ? round($totalSeconds / 3600, 2) : 0;

        // Videos uploaded to YouTube
        $youtubeVideos = Video::whereNotNull('youtube_video_id')
            ->count();

        // Videos on Dropbox
        $dropboxVideos = Video::whereNotNull('dropbox_path')
            ->count();

        // Videos with embeddings
        $embeddedVideos = Video::whereHas('embedding', function ($query) {
                $query->where('status', 'completed');
            })
            ->count();

        // Failed videos with error details
        $failedVideos = Video::where('processing_status', 'failed')
            ->select('id', 'filename', 'title', 'processing_error', 'processing_completed_at')
            ->get();

        // Processing videos
        $processingVideos = Video::where('processing_status', 'processing')
            ->count();

        // Videos by status breakdown
        $statusBreakdown = Video::selectRaw('processing_status, COUNT(*) as count, SUM(size_mb) as total_size_mb')
            ->groupBy('processing_status')
            ->get();

        // Average video size
        $avgVideoSize = $totalVideos > 0 ? round($totalSizeMB / $totalVideos, 2) : 0;

        // Videos uploaded by time period
        // Last 7 days (for weekly chart)
        $weeklyUploads = Video::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')

            
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->pluck('count', 'date');

        // Fill in missing days with 0
        $weeklyData = [];
        $weeklyCategories = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayName = now()->subDays($i)->format('D'); // Mon, Tue, etc.
            $weeklyCategories[] = $dayName;
            $weeklyData[] = $weeklyUploads->get($date, 0);
        }

        // Last 4 weeks (for monthly chart)
        $monthlyUploads = [];
        $monthlyCategories = [];
        for ($i = 3; $i >= 0; $i--) {
            $startOfWeek = now()->subWeeks($i)->startOfWeek();
            $endOfWeek = now()->subWeeks($i)->endOfWeek();

            $count = Video::whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->count();
            
            $monthlyCategories[] = 'Week ' . (4 - $i);
            $monthlyUploads[] = $count;
        }

        // Search Analytics
        $searchStats = SearchLog::getStatistics();
        
        // Searches per day (last 7 days)
        $searchesPerDay = SearchLog::getSearchesPerDay(7);
        $searchDailyData = [];
        $searchDailyCategories = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayName = now()->subDays($i)->format('D');
            $searchDailyCategories[] = $dayName;
            $searchDailyData[] = $searchesPerDay->get($date, 0);
        }

        // Most searched keywords with pagination
        $keywordsPage = $request->get('keywords_page', 1);
        $keywordsPerPage = $request->get('keywords_per_page', 10);
        $keywordsPaginated = SearchLog::getMostSearchedKeywordsPaginated($keywordsPage, $keywordsPerPage);

        return response()->json([
            'success' => true,
            'stats' => [
                'total_videos' => $totalVideos,
                'completed_videos' => $completedVideos,
                'processing_videos' => $processingVideos,
                'failed_videos' => $failedVideos->count(),
                'transcribed_videos' => $transcribedVideos,
                'youtube_videos' => $youtubeVideos,
                'dropbox_videos' => $dropboxVideos,
                'embedded_videos' => $embeddedVideos,
                'total_size_mb' => round($totalSizeMB, 2),
                'uploaded_size_mb' => round($uploadedSizeMB, 2),
                'average_video_size_mb' => $avgVideoSize,
                'total_hours_processed' => $totalHours,
                'total_minutes_processed' => round($totalSeconds / 60, 2),
                // Search stats
                'searches_today' => $searchStats['today'],
                'searches_this_week' => $searchStats['this_week'],
                'searches_this_month' => $searchStats['this_month'],
                'total_searches' => $searchStats['total'],
                'avg_search_response_time_ms' => $searchStats['avg_response_time_ms'],
            ],
            'charts' => [
                'weekly' => [
                    'categories' => $weeklyCategories,
                    'data' => $weeklyData
                ],
                'monthly' => [
                    'categories' => $monthlyCategories,
                    'data' => $monthlyUploads
                ],
                'searches_daily' => [
                    'categories' => $searchDailyCategories,
                    'data' => $searchDailyData
                ]
            ],
            'search_analytics' => [
                'top_keywords' => collect($keywordsPaginated['data'])->map(function($item) {
                    return [
                        'keyword' => $item->keyword,
                        'count' => $item->count
                    ];
                }),
                'keywords_pagination' => [
                    'total' => $keywordsPaginated['total'],
                    'current_page' => $keywordsPaginated['current_page'],
                    'per_page' => $keywordsPaginated['per_page'],
                    'last_page' => $keywordsPaginated['last_page']
                ],
                'statistics' => $searchStats
            ],
            'status_breakdown' => $statusBreakdown->map(function($item) {
                return [
                    'status' => $item->processing_status ?? 'pending',
                    'count' => $item->count,
                    'total_size_mb' => round($item->total_size_mb ?? 0, 2)
                ];
            }),
            'failed_videos_details' => $failedVideos->map(function($video) {
                return [
                    'id' => $video->id,
                    'filename' => $video->filename,
                    'title' => $video->title,
                    'error' => $video->processing_error,
                    'failed_at' => $video->processing_completed_at
                ];
            })
        ]);
    }

    /**
     * Display the specified video
     */
    public function show(Video $video)
    {
        return Inertia::render('UserSide/MainVideoPage/index', [
                'videoId' => $video->id
            ]);
    }

    /**
     * Get videos list as JSON
     */
    public function list(Request $request)
    {
        // Get all videos (shared across all admins)
        $query = Video::latest();

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('processing_status', $request->status);
        }

        // Search by title or filename
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('filename', 'like', "%{$search}%");
            });
        }

        $videos = $query->paginate($request->get('per_page', 20));

        return response()->json($videos);
    }

    /**
     * Get video processing status
     */
    public function status(Video $video)
    {
        return response()->json([
            'id' => $video->id,
            'status' => $video->processing_status,
            'youtube_video_id' => $video->youtube_video_id,
            'youtube_url' => $video->youtube_url,
            'transcript_id' => $video->transcript_id,
            'processing_started_at' => $video->processing_started_at,
            'processing_completed_at' => $video->processing_completed_at,
            'error' => $video->processing_error,
        ]);
    }

    /**
     * Delete a video
     */
    public function destroy(Video $video)
    {
        $video->delete();

        return response()->json([
            'message' => 'Video deleted successfully'
        ]);
    }

    /**
     * Display video in HTML page (opens in new tab)
     */
    // public function viewer(Video $video)
    // {
    //     // Ensure user owns the video
    //     if ($video->user_id !== auth()->id()) {
    //         abort(403);
    //     }
    //     return view('videos.viewer', compact('video'));
    // }
    






    /*  * Open ai  integration  with  model text-embedding-3-small
     */
    public function embeddingsGenerate($videoId = null)
    {
        // If video ID provided, process single video
        if ($videoId) {
            $video = Video::with('embedding')->find($videoId);
            
            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => "Video not found with ID: {$videoId}"
                ], 404);
            }

            // Check if video has required data
            if (!$video->identification_data) {
                return response()->json([
                    'success' => false,
                    'message' => "Video does not have identification data yet. Please process the video first.",
                    'video' => [
                        'id' => $video->id,
                        'filename' => $video->filename,
                        'title' => $video->title,
                        'has_transcript' => $video->speakers_data !== null,
                        'has_diarization' => $video->diarization_data !== null,
                        'has_identification' => $video->identification_data !== null
                    ]
                ], 422);
            }

            // Check if already has embedding
            if ($video->embedding && $video->embedding->isCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => "Video already has a completed embedding.",
                    'video' => [
                        'id' => $video->id,
                        'filename' => $video->filename,
                        'embedding_status' => $video->embedding->status
                    ]
                ], 422);
            }

            // Dispatch the job to embeddings queue
            GenerateVideoEmbedding::dispatch($video)->onQueue('high');

            return response()->json([
                'success' => true,
                'message' => "Embedding job dispatched to embeddings queue.",
                'video' => [
                    'id' => $video->id,
                    'filename' => $video->filename,
                    'title' => $video->title
                ]
            ]);
        }

        // If no ID provided, process all videos (batch mode)
        $videos = Video::whereNotNull('identification_data')
            ->with('embedding')
            ->get();

        $dispatchedCount = 0;

        foreach ($videos as $video) {
            // Skip if already has completed embedding
            if ($video->embedding && $video->embedding->isCompleted()) {
                continue;
            }

            GenerateVideoEmbedding::dispatch($video)->onQueue('embeddings');
            $dispatchedCount++;
        }

        return response()->json([
            'success' => true,
            'message' => "Embedding jobs dispatched for {$dispatchedCount} videos.",
            'total_videos' => $videos->count(),
            'dispatched' => $dispatchedCount,
            'already_embedded' => $videos->count() - $dispatchedCount
        ]);
    }

    /**
     * Generate embedding for a single video by name
     * Usage: GET /test/embed-video?name=video_filename.mp4
     */
    public function embedSingleVideo(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string'
        ]);

        $videoName = $validated['name'];

        // Find video by filename
        $video = Video::where(function ($query) use ($videoName) {
            $query->where('filename', $videoName)
                ->orWhere('title', $videoName);
        })
            ->with('embedding')
            ->first();

        if (!$video) {
            return response()->json([
                'success' => false,
                'message' => "Video not found with name: {$videoName}"
            ], 404);
        }

        // Check if video has required data
        if (!$video->identification_data) {
            return response()->json([
                'success' => false,
                'message' => "Video does not have identification data yet. Please process the video first.",
                'video' => [
                    'id' => $video->id,
                    'filename' => $video->filename,
                    'title' => $video->title,
                    'has_transcript' => $video->speakers_data !== null,
                    'has_diarization' => $video->diarization_data !== null,
                    'has_identification' => $video->identification_data !== null
                ]
            ], 422);
        }

        // Check if already has embedding
        if ($video->embedding && $video->embedding->isCompleted()) {
            return response()->json([
                'success' => true,
                'message' => "Video already has embeddings",
                'video' => [
                    'id' => $video->id,
                    'filename' => $video->filename,
                    'title' => $video->title,
                    'youtube_url' => $video->youtube_url
                ],
                'embedding' => [
                    'status' => $video->embedding->status,
                    'segments_count' => $video->embedding->segments_count,
                    'completed_at' => $video->embedding->completed_at,
                ]
            ]);
        }

        // Dispatch embedding job to embeddings queue
        GenerateVideoEmbedding::dispatch($video)->onQueue('high');

        return response()->json([
            'success' => true,
            'message' => "Embedding job dispatched to high priority queue for video: {$video->filename}",
            'video' => [
                'id' => $video->id,
                'filename' => $video->filename,
                'title' => $video->title,
                'youtube_url' => $video->youtube_url
            ],
            'embedding_status' => $video->embedding ? $video->embedding->status : 'pending'
        ]);
    }

    /**
     * Get embedding status for a video
     */
    public function embeddingStatus(Video $video)
    {
        $embedding = $video->embedding;

        if (!$embedding) {
            return response()->json([
                'has_embedding' => false,
                'status' => null,
                'message' => 'No embedding record found for this video'
            ]);
        }

        return response()->json([
            'has_embedding' => true,
            'status' => $embedding->status,
            'segments_count' => $embedding->segments_count,
            'collection' => $embedding->qdrant_collection,
            'vector_dimensions' => $embedding->vector_dimensions,
            'started_at' => $embedding->started_at,
            'completed_at' => $embedding->completed_at,
            'failed_at' => $embedding->failed_at,
            'error_message' => $embedding->error_message,
        ]);
    }

    /**
     * Get embedding service API key from config
     */
    private function getEmbeddingApiKey(): string
    {
        return config('services.embedding.api_key', '');
    }

    /**
     * Get embedding service URL from config
     */
    private function getEmbeddingServiceUrl(): string
    {
        return config('services.embedding.url', 'https://web-production-935af.up.railway.app');
    }

    /**
     * Search for similar video segments using embeddings with elastic search
     * Supports: semantic search, fuzzy matching, speaker search, keyword search
     */
   public function embeddingsSearch(Request $request)
{
    $startTime = microtime(true);

    Log::info('Embedding search request received', [
        'request' => $request->all()
    ]);

    $maxQueryLength = 1000;
    $longQueryMessage = 'Search is too long. Please reduce the length and try again.';
    $longQueryHint = 'Looks like your search is too long. Try using fewer words or a shorter sentence.';
    $rawQueryInput = (string) ($request->input('query') ?? '');
    if ($rawQueryInput !== '' && mb_strlen(trim($rawQueryInput)) > $maxQueryLength) {
        return response()->json([
            'success' => false,
            'message' => $longQueryMessage,
            'errors' => [
                'query' => [$longQueryHint],
            ],
        ], 422);
    }

    $validated = $request->validate([
        'query' => 'nullable|string|max:1000',
        'word' => 'nullable|string|max:200',
        'words' => 'nullable|array',
        'words.*' => 'string|max:200',
        'top_k' => 'nullable|integer|min:1|max:1000',
        'max_scanned' => 'nullable|integer|min:100|max:100000',
        'video_id' => 'nullable|integer',
        'speaker' => 'nullable|string|max:200',
        'title' => 'nullable|string|max:500',
        'language' => 'nullable|string|max:50',
        'min_score' => 'nullable|numeric|min:0|max:1',
        'time_range' => 'nullable|array',
        'time_range.start' => 'nullable|numeric|min:0',
        'time_range.end' => 'nullable|numeric|min:0',
        'page' => 'nullable|integer|min:1',
        'per_page' => 'nullable|integer|min:1|max:50',
        'filter_year' => 'nullable|integer|min:1900|max:2100',
        'filter_month' => 'nullable|integer|min:1|max:12',
        'filter_date' => 'nullable|date',
        // Dual-mode search parameters
        'search_mode' => 'nullable|string|in:semantic,simple',
        'filter_type' => 'nullable|string|in:video,speaker,date,language,title,summary,text',
        // Incremental search parameters
        'cursor' => 'nullable|string|max:1000',
        'batch_size' => 'nullable|integer|min:1|max:50',
        'search_session_id' => 'nullable|string|max:100',
        'use_incremental' => 'nullable|boolean',
    ], [
        'query.max' => $longQueryMessage,
    ]);

    $query = $validated['query'] ?? null;
    $page = $validated['page'] ?? 1;
    $perPage = $validated['per_page'] ?? 10;
    
    // Incremental search parameters
    $cursor = $validated['cursor'] ?? null;
    $batchSize = $validated['batch_size'] ?? 10;
    $searchSessionId = $validated['search_session_id'] ?? null;
    $useIncremental = $validated['use_incremental'] ?? false;
    $isIncrementalRequest = $useIncremental || !empty($cursor) || !empty($searchSessionId);
    $isIncrementalCursorRequest = $isIncrementalRequest && !empty($cursor);

    // Handle PHP-side page cursor: "php:{page}:{session_id}"
    // These are generated by Laravel when it has sliced results over multiple pages.
    $phpCursorPage = null;
    if (!empty($cursor) && str_starts_with((string) $cursor, 'php:')) {
        $parts = explode(':', $cursor, 3);
        $phpCursorPage = (int) ($parts[1] ?? 1);
        $phpCursorSessionId = $parts[2] ?? '';
        if (!empty($phpCursorSessionId) && empty($searchSessionId)) {
            $searchSessionId = $phpCursorSessionId;
        }
        // Clear cursor so it's not forwarded to Python — this is a Laravel-only page
        $cursor = null;
        $isIncrementalCursorRequest = false; // treat as first-page for Python
    }
    // Must be set AFTER PHP cursor decoder so php:N:uuid cursors still allow title fallback
    $allowShortTitleFallback = !$isIncrementalCursorRequest;

    // Recover/normalize session ID from cursor payload.
    // If frontend sends a mismatched session ID, cursor session takes precedence.
    $cursorSessionId = null;
    if (!empty($cursor)) {
        try {
            $normalizedCursor = strtr($cursor, '-_', '+/');
            $cursorPadding = strlen($normalizedCursor) % 4;
            if ($cursorPadding > 0) {
                $normalizedCursor .= str_repeat('=', 4 - $cursorPadding);
            }

            $decodedCursorJson = base64_decode($normalizedCursor, true);
            if ($decodedCursorJson !== false) {
                $decodedCursor = json_decode($decodedCursorJson, true);
                if (is_array($decodedCursor) && !empty($decodedCursor['search_session_id'])) {
                    $cursorSessionId = (string) $decodedCursor['search_session_id'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to decode incremental cursor for session recovery', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    if (!empty($cursorSessionId)) {
        if (empty($searchSessionId)) {
            $searchSessionId = $cursorSessionId;
            Log::info('Recovered search_session_id from cursor', [
                'session_id' => substr($searchSessionId, 0, 8),
            ]);
        } elseif ($searchSessionId !== $cursorSessionId) {
            Log::warning('search_session_id mismatch between request and cursor; using cursor session', [
                'request_session_id' => substr($searchSessionId, 0, 8),
                'cursor_session_id' => substr($cursorSessionId, 0, 8),
            ]);
            $searchSessionId = $cursorSessionId;
        }
    }
    
    // Date filter parameters
    $filterYear = $validated['filter_year'] ?? null;
    $filterMonth = $validated['filter_month'] ?? null;
    $filterDate = $validated['filter_date'] ?? null;
    
    // Calculate top_k
    // For incremental cursor follow-up requests, reuse the cached session so top_k only
    // controls the initial Qdrant fetch. For the first incremental request use up to 1000
    // so Python can cache a large pool and slice it into batches on subsequent calls.
    $topK = $validated['top_k'] ?? 1000;
    if ($isIncrementalRequest) {
        // Cursor requests read from Python's session cache — top_k doesn't matter for them.
        // First-page requests: fetch up to 1000 segments so Python can serve many batches.
        $topK = $isIncrementalCursorRequest ? 20 : min($validated['top_k'] ?? 1000, 1000);
    }

    Log::info('Embedding search normalized params', [
        'requested_top_k' => $validated['top_k'] ?? null,
        'effective_top_k' => $topK,
        'is_incremental_request' => $isIncrementalRequest,
        'search_session_id' => $searchSessionId ? substr($searchSessionId, 0, 8) : null,
    ]);

    $videoId = $validated['video_id'] ?? null;
    $minScore = $validated['min_score'] ?? 0.35;
    $word = $validated['word'] ?? null;
    $words = $validated['words'] ?? [];
    $speaker = $validated['speaker'] ?? null;
    $title = $validated['title'] ?? null;
    $language = $validated['language'] ?? null;
    $timeRange = $validated['time_range'] ?? null;
    $maxScanned = $validated['max_scanned'] ?? 10000;
    
    // Dual-mode search parameters
    $searchMode = $validated['search_mode'] ?? 'semantic'; // 'semantic' or 'simple'
    $filterType = $validated['filter_type'] ?? null;       // 'video', 'speaker', 'date', 'language', 'title', 'summary', 'text'
    
    // Handle backward compatibility - convert single word to words array
    if ($word && !empty($word)) {
        $words[] = $word;
    }
    
    // Safety net: if query is empty but words are provided, reconstruct query
    // This ensures semantic search always runs when the user types something
    if (empty($query) && !empty($words)) {
        $query = implode(' ', $words);
    }
    
    // Require at least one search parameter OR a date filter
    $hasDateFilter = !empty($filterYear) || !empty($filterDate);
    
    // ═══════════════════════════════════════════════════════════════════════════
    // EXACT TITLE MATCH — highest priority search
    // If query exactly matches a video title, return that video immediately
    // ═══════════════════════════════════════════════════════════════════════════
    $isExactTitleMatch = !empty($query) && strlen(trim($query)) >= 3;
    
    if ($isExactTitleMatch) {
        Log::info('Checking for exact title match', [
            'query' => $query,
        ]);

        $videoQuery = Video::searchable(); // completed + approved + not archived
        
        // Search for exact title match (case-insensitive)
        $videoQuery->whereRaw('LOWER(title) = LOWER(?)', [trim($query)]);

        // Apply date filters if present
        if ($filterDate) {
            $videoQuery->whereDate('video_created_at', $filterDate);
        } elseif ($filterYear && $filterMonth) {
            $videoQuery->whereYear('video_created_at', $filterYear)
                      ->whereMonth('video_created_at', $filterMonth);
        } elseif ($filterYear) {
            $videoQuery->whereYear('video_created_at', $filterYear);
        }

        if ($videoId) $videoQuery->where('id', $videoId);
        if ($language) $videoQuery->where('language_detected', $language);

        $exactMatchVideos = $videoQuery->get();

        if ($exactMatchVideos->count() > 0) {
            Log::info('Found exact title match', [
                'query' => $query,
                'matched_videos_count' => $exactMatchVideos->count(),
            ]);

            $groupedByVideo = $exactMatchVideos->map(function ($video) {
                return [
                    'video' => [
                        'id' => $video->id,
                        'title' => $video->title,
                        'filename' => $video->filename,
                        'youtube_url' => $video->youtube_url,
                        'youtube_video_id' => $video->youtube_video_id,
                        'language' => $video->language_detected,
                        'created_at' => $video->created_at->toISOString(),
                        'video_created_at' => $video->video_created_at?->toISOString(),
                        'audio_duration_seconds' => $video->audio_duration_seconds,
                        'summary' => $video->summary,
                    ],
                    'match_count' => 0,
                    'best_score' => 100.0,
                    'avg_score' => 100.0,
                    'match_types' => ['exact_title_match'],
                    'matched_fields' => ['title'],
                    'segments' => [],
                ];
            });

            $totalVideos = $groupedByVideo->count();
            $totalPages = ceil($totalVideos / $perPage);
            $offset = ($page - 1) * $perPage;
            $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();

            $responseTimeMs = round((microtime(true) - $startTime) * 1000);
            SearchLog::create([
                'user_id' => auth()->id(),
                'query' => $query,
                'word' => $word,
                'speaker' => $speaker,
                'video_id' => $videoId,
                'results_count' => 0,
                'videos_count' => $totalVideos,
                'min_score' => $minScore,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_time_ms' => $responseTimeMs,
                'search_type' => 'exact_title_match',
                'filters' => json_encode(['search_mode' => $searchMode, 'filter_type' => $filterType]),
            ]);

            return response()->json([
                'success' => true,
                'query' => $query,
                'words' => $words,
                'search_mode' => 'exact_match',
                'filter_type' => 'title',
                'filters' => [
                    'video_id' => $videoId,
                    'language' => $language,
                    'filter_year' => $filterYear,
                    'filter_month' => $filterMonth,
                    'filter_date' => $filterDate,
                ],
                'segments' => [],
                'grouped_by_video' => $paginatedVideos,
                'total_segments' => 0,
                'total_videos' => $totalVideos,
                'current_page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1,
                'search_metadata' => [
                    'search_type' => 'exact_title_match',
                    'search_mode' => 'exact_match',
                    'response_time_ms' => $responseTimeMs,
                ],
                'message' => "Found {$totalVideos} video(s) with exact title match: '{$query}'",
            ]);
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // SHORT NUMERIC QUERY (2-3 digits) — search in video titles locally.
    // Only queries that are purely numeric with 2 or 3 digits are handled here.
    // 4+ digit numeric queries are passed to the Python service as normal.
    // ═══════════════════════════════════════════════════════════════════════════
    $isShortNumericQuery = !empty($query) && preg_match('/^\d{2,3}$/', trim($query));
    
    if ($isShortNumericQuery) {
        Log::info('Short numeric query — searching video titles in database', [
            'query' => $query,
            'search_mode' => $searchMode,
        ]);

        $videoQuery = Video::searchable();

        // Search the numeric value in the video title
        $videoQuery->where('title', 'LIKE', '%' . trim($query) . '%');

        // Apply date filters if present
        if ($filterDate) {
            $videoQuery->whereDate('video_created_at', $filterDate);
        } elseif ($filterYear && $filterMonth) {
            $videoQuery->whereYear('video_created_at', $filterYear)
                      ->whereMonth('video_created_at', $filterMonth);
        } elseif ($filterYear) {
            $videoQuery->whereYear('video_created_at', $filterYear);
        }

        if ($videoId) $videoQuery->where('id', $videoId);
        if ($language) $videoQuery->where('language_detected', $language);

        $allVideos = $videoQuery->get();
        $totalVideos = $allVideos->count();

        $groupedByVideo = $allVideos->map(function ($video) {
            return [
                'video' => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'filename' => $video->filename,
                    'youtube_url' => $video->youtube_url,
                    'youtube_video_id' => $video->youtube_video_id,
                    'language' => $video->language_detected,
                    'created_at' => $video->created_at->toISOString(),
                    'video_created_at' => $video->video_created_at?->toISOString(),
                    'audio_duration_seconds' => $video->audio_duration_seconds,
                    'summary' => $video->summary,
                ],
                'match_count' => 0,
                'best_score' => 1.0,
                'avg_score' => 1.0,
                'match_types' => ['title_numeric_match'],
                'matched_fields' => ['title'],
                'segments' => [],
            ];
        });

        $totalPages = ceil($totalVideos / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();

        $responseTimeMs = round((microtime(true) - $startTime) * 1000);
        SearchLog::create([
            'user_id' => auth()->id(),
            'query' => $query,
            'word' => $word,
            'speaker' => $speaker,
            'video_id' => $videoId,
            'results_count' => 0,
            'videos_count' => $totalVideos,
            'min_score' => $minScore,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'response_time_ms' => $responseTimeMs,
            'search_type' => 'numeric_title_search',
            'filters' => json_encode(['search_mode' => $searchMode, 'filter_type' => $filterType]),
        ]);

        return response()->json([
            'success' => true,
            'query' => $query,
            'words' => $words,
            'search_mode' => $searchMode,
            'filter_type' => $filterType,
            'filters' => [
                'video_id' => $videoId,
                'language' => $language,
                'filter_year' => $filterYear,
                'filter_month' => $filterMonth,
                'filter_date' => $filterDate,
            ],
            'segments' => [],
            'grouped_by_video' => $paginatedVideos,
            'total_segments' => 0,
            'total_videos' => $totalVideos,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_next_page' => $page < $totalPages,
            'has_prev_page' => $page > 1,
            'search_metadata' => [
                'search_type' => 'numeric_title_search',
                'search_mode' => $searchMode,
                'response_time_ms' => $responseTimeMs,
            ],
            'message' => "Found {$totalVideos} videos matching '{$query}' in title",
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SIMPLE SEARCH MODE — structured filters only, no vector/LLM operations.
    // Handles date/video/language entirely in Laravel (Eloquent).
    // Routes speaker/title/summary to Python service with search_mode=simple.
    // ═══════════════════════════════════════════════════════════════════════════
    if ($searchMode === 'simple') {
        Log::info('Simple search mode', [
            'filter_type' => $filterType,
            'video_id' => $videoId,
            'speaker' => $speaker,
            'title' => $title,
            'language' => $language,
            'filter_year' => $filterYear,
            'filter_month' => $filterMonth,
            'filter_date' => $filterDate,
        ]);

        // Filters handled entirely in Laravel via Eloquent
        if (in_array($filterType, ['date', 'video', 'language'])) {
            $videoQuery = Video::searchable(); // completed + approved + not archived

            if ($filterType === 'date') {
                if ($filterDate) {
                    $videoQuery->whereDate('video_created_at', $filterDate);
                } elseif ($filterYear && $filterMonth) {
                    $videoQuery->whereYear('video_created_at', $filterYear)
                              ->whereMonth('video_created_at', $filterMonth);
                } elseif ($filterYear) {
                    $videoQuery->whereYear('video_created_at', $filterYear);
                }
            } elseif ($filterType === 'video') {
                if ($videoId) $videoQuery->where('id', $videoId);
            } elseif ($filterType === 'language') {
                if ($language) $videoQuery->where('language_detected', $language);
            }

            $allVideos = $videoQuery->get();
            $totalVideos = $allVideos->count();

            $groupedByVideo = $allVideos->map(function ($video) use ($filterType) {
                return [
                    'video' => [
                        'id' => $video->id,
                        'title' => $video->title,
                        'filename' => $video->filename,
                        'youtube_url' => $video->youtube_url,
                        'youtube_video_id' => $video->youtube_video_id,
                        'language' => $video->language_detected,
                        'created_at' => $video->created_at->toISOString(),
                        'video_created_at' => $video->video_created_at?->toISOString(),
                        'audio_duration_seconds' => $video->audio_duration_seconds,
                        'summary' => $video->summary,
                    ],
                    'match_count' => 0,
                    'best_score' => 1.0,
                    'avg_score' => 1.0,
                    'match_types' => ["simple_{$filterType}_filter"],
                    'matched_fields' => [$filterType],
                    'segments' => []
                ];
            });

            $totalPages = ceil($totalVideos / $perPage);
            $offset = ($page - 1) * $perPage;
            $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();

            $responseTimeMs = round((microtime(true) - $startTime) * 1000);
            SearchLog::create([
                'user_id' => auth()->id(),
                'query' => $query,
                'word' => $word,
                'speaker' => $speaker,
                'video_id' => $videoId,
                'results_count' => 0,
                'videos_count' => $totalVideos,
                'min_score' => $minScore,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_time_ms' => $responseTimeMs,
                'search_type' => "simple_{$filterType}",
                'filters' => json_encode(['filter_type' => $filterType, 'language' => $language]),
            ]);

            return response()->json([
                'success' => true,
                'query' => $query,
                'words' => $words,
                'search_mode' => 'simple',
                'filter_type' => $filterType,
                'filters' => [
                    'video_id' => $videoId,
                    'language' => $language,
                    'filter_year' => $filterYear,
                    'filter_month' => $filterMonth,
                    'filter_date' => $filterDate,
                ],
                'segments' => [],
                'grouped_by_video' => $paginatedVideos,
                'total_segments' => 0,
                'total_videos' => $totalVideos,
                'current_page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1,
                'search_metadata' => [
                    'search_type' => "simple_{$filterType}",
                    'search_mode' => 'simple',
                    'filter_type' => $filterType,
                    'response_time_ms' => $responseTimeMs,
                ],
                'message' => "Found {$totalVideos} videos"
            ]);
        }

        // Filters that need Python service (speaker, title, summary) — forward with search_mode=simple
        if (in_array($filterType, ['speaker', 'title', 'summary', 'text'])) {
            try {
                $embeddingServiceUrl = $this->getEmbeddingServiceUrl();
                $searchPayload = [
                    'search_mode' => 'simple',
                    'filter_type' => $filterType,
                    'top_k' => $topK,
                    'min_score' => $minScore,
                    'max_scanned' => $maxScanned,
                ];
                if ($speaker) $searchPayload['speaker'] = $speaker;
                if ($title) $searchPayload['title'] = $title;
                if ($query) $searchPayload['query'] = $query;
                if (!empty($words)) $searchPayload['words'] = $words;
                if ($videoId) $searchPayload['video_id'] = $videoId;
                if ($language) $searchPayload['language'] = $language;
                
                $cacheKey = 'search:simple:' . md5(json_encode($searchPayload));
                $results = Cache::remember($cacheKey, 300, function () use ($embeddingServiceUrl, $searchPayload) {
                    $response = Http::retry(3, 200)
                        ->timeout(120)
                        ->withHeaders([
                            'X-API-Key' => $this->getEmbeddingApiKey(),
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ])
                        ->post("{$embeddingServiceUrl}/search", $searchPayload);

                    if ($response->failed()) {
                        $errorBody = $response->body();
                        $statusCode = $response->status();
                        throw new \RuntimeException("Embedding service error|{$statusCode}|{$errorBody}");
                    }
                    return $response->json();
                });

                // Enrich with video data
                $videoIds = collect($results['results'] ?? [])->pluck('video_id')->filter()->unique()->values()->toArray();
                $videos = !empty($videoIds) ? Video::searchable()->whereIn('id', $videoIds)->get()->keyBy('id') : collect();

                $enrichedResults = collect($results['results'] ?? [])->map(function ($result) use ($videos) {
                    $vid = $result['video_id'] ?? null;
                    $video = $vid ? $videos->get($vid) : null;
                    if (!$video) return null;

                    $youtubeTimestampUrl = null;
                    if ($video->youtube_url) {
                        $startTime = floor($result['start_time'] ?? 0);
                        $separator = strpos($video->youtube_url, '?') !== false ? '&' : '?';
                        $youtubeTimestampUrl = $video->youtube_url . $separator . 't=' . $startTime . 's';
                    }

                    return [
                        'segment_id' => $result['id'] ?? null,
                        'score' => round(($result['score'] ?? 0) * 100, 2),
                        'match_types' => $result['match_types'] ?? [],
                        'matched_field' => $result['matched_field'] ?? null,
                        'fuzzy_score' => $result['fuzzy_score'] ?? null,
                        'speaker' => mb_convert_encoding($result['speaker'] ?? 'UNKNOWN', 'UTF-8', 'UTF-8'),
                        'diarization_speaker' => $result['diarization_speaker'] ?? '',
                        'start_time' => $result['start_time'] ?? 0,
                        'end_time' => $result['end_time'] ?? 0,
                        'duration' => $result['duration'] ?? 0,
                        'text' => mb_convert_encoding($result['text'] ?? '', 'UTF-8', 'UTF-8'),
                        'text_preview' => mb_substr($result['text'] ?? '', 0, 150) . (mb_strlen($result['text'] ?? '') > 150 ? '...' : ''),
                        'video' => [
                            'id' => $video->id,
                            'title' => $video->title,
                            'filename' => $video->filename,
                            'youtube_url' => $video->youtube_url,
                            'youtube_video_id' => $video->youtube_video_id,
                            'language' => $video->language_detected,
                            'created_at' => $video->created_at->toISOString(),
                            'video_created_at' => $video->video_created_at?->toISOString(),
                        ],
                        'youtube_timestamp_url' => $youtubeTimestampUrl,
                    ];
                })->filter()->values();

                // Group by video
                $groupedByVideo = $enrichedResults->groupBy('video.id')->map(function ($segments) {
                    $first = $segments->first();
                    // Deduplicate segments by normalized text, keeping highest score
                    $uniqueSegments = $segments->groupBy(function ($seg) {
                        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $seg['text'] ?? '')));
                    })->map(function ($dupes) {
                        return $dupes->sortByDesc('score')->first();
                    })->values();
                    return [
                        'video' => $first['video'],
                        'match_count' => $uniqueSegments->count(),
                        'best_score' => $uniqueSegments->max('score'),
                        'avg_score' => round($uniqueSegments->avg('score'), 2),
                        'match_types' => $uniqueSegments->pluck('match_types')->flatten()->unique()->values(),
                        'matched_fields' => $uniqueSegments->pluck('matched_field')->filter()->unique()->values(),
                        'segments' => $uniqueSegments->map(fn($seg) => [
                            'segment_id' => $seg['segment_id'],
                            'score' => $seg['score'],
                            'match_types' => $seg['match_types'],
                            'matched_field' => $seg['matched_field'],
                            'fuzzy_score' => $seg['fuzzy_score'],
                            'speaker' => $seg['speaker'],
                            'diarization_speaker' => $seg['diarization_speaker'] ?? '',
                            'start_time' => $seg['start_time'],
                            'end_time' => $seg['end_time'],
                            'duration' => $seg['duration'],
                            'text' => $seg['text'],
                            'text_preview' => $seg['text_preview'],
                            'youtube_timestamp_url' => $seg['youtube_timestamp_url'],
                        ])->values()
                    ];
                })->values();

                $totalVideos = $groupedByVideo->count();
                // Count actual unique segments after deduplication
                $totalUniqueSegments = $groupedByVideo->sum(fn($v) => count($v['segments'] ?? []));
                $totalPages = ceil($totalVideos / $perPage);
                $offset = ($page - 1) * $perPage;
                $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();

                $responseTimeMs = round((microtime(true) - $startTime) * 1000);
                SearchLog::create([
                    'user_id' => auth()->id(),
                    'query' => $query,
                    'word' => $word,
                    'speaker' => $speaker,
                    'video_id' => $videoId,
                    'results_count' => $enrichedResults->count(),
                    'videos_count' => $totalVideos,
                    'min_score' => $minScore,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'response_time_ms' => $responseTimeMs,
                    'search_type' => "simple_{$filterType}",
                    'filters' => json_encode(['filter_type' => $filterType]),
                ]);

                return response()->json([
                    'success' => true,
                    'query' => $query,
                    'words' => $words,
                    'search_mode' => 'simple',
                    'filter_type' => $filterType,
                    'speaker_filter' => $results['speaker_filter'] ?? null,
                    'filters' => [
                        'video_id' => $videoId,
                        'speaker' => $speaker,
                        'title' => $title,
                        'language' => $language,
                        'min_score' => $minScore,
                    ],
                    'grouped_by_video' => $paginatedVideos,
                    'total_segments' => $totalUniqueSegments,
                    'total_videos' => $totalVideos,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
                    'has_next_page' => $page < $totalPages,
                    'has_prev_page' => $page > 1,
                    'search_metadata' => [
                        'search_type' => "simple_{$filterType}",
                        'search_mode' => 'simple',
                        'filter_type' => $filterType,
                        'response_time_ms' => $responseTimeMs,
                    ],
                ], 200, [], JSON_UNESCAPED_UNICODE);

            } catch (RequestException $e) {
                $statusCode = (int) ($e->response?->status() ?? 500);
                $errorBody = (string) ($e->response?->body() ?? $e->getMessage());
                $userMessage = 'Simple search failed';

                try {
                    $parsedError = json_decode($errorBody, true);
                    if (is_array($parsedError)) {
                        if (!empty($parsedError['detail']) && is_string($parsedError['detail'])) {
                            $userMessage = $parsedError['detail'];
                        } elseif (!empty($parsedError['message']) && is_string($parsedError['message'])) {
                            $userMessage = $parsedError['message'];
                        }
                    }
                } catch (\Throwable $parseErr) {
                    // Fall through to regex extraction below.
                }

                if ($userMessage === 'Simple search failed') {
                    if (preg_match('/"detail"\s*:\s*"([^\"]+)"/u', $errorBody, $m) === 1) {
                        $userMessage = stripcslashes($m[1]);
                    }
                }

                Log::warning('Simple search upstream HTTP error', [
                    'status' => $statusCode,
                    'message' => $e->getMessage(),
                    'body' => $errorBody,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $userMessage,
                    'error' => config('app.debug') ? $errorBody : null,
                ], $statusCode >= 400 && $statusCode < 600 ? $statusCode : 500);
            } catch (\RuntimeException $e) {
                $parts = explode('|', $e->getMessage(), 3);
                if (count($parts) === 3 && $parts[0] === 'Embedding service error') {
                    $statusCode = (int) $parts[1];
                    // Extract user-friendly error message from Python service response
                    $errorDetail = $parts[2];
                    $userMessage = 'Simple search failed';
                    try {
                        $parsedError = json_decode($errorDetail, true);
                        if (isset($parsedError['detail'])) {
                            $userMessage = $parsedError['detail'];
                        }
                    } catch (\Throwable $parseErr) {
                        // Keep default message
                    }
                    return response()->json([
                        'success' => false,
                        'message' => $userMessage,
                        'error' => config('app.debug') ? $errorDetail : null,
                    ], $statusCode >= 400 && $statusCode < 500 ? $statusCode : 500);
                }
                throw $e;
            } catch (\Exception $e) {
                Log::error('Simple search failed', ['error' => $e->getMessage()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Simple search failed. Please try a different query.',
                    'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
                ], 500);
            }
        }

        // Invalid filter_type for simple mode
        return response()->json([
            'success' => false,
            'message' => 'filter_type is required for simple search mode. Must be one of: video, speaker, date, language, title, summary, text'
        ], 400);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SEMANTIC SEARCH MODE — existing behavior (vector + LLM pipeline)
    // ═══════════════════════════════════════════════════════════════════════════
    
    if (empty($query) && empty($words) && empty($speaker) && empty($title) && !$hasDateFilter) {
        return response()->json([
            'success' => false,
            'message' => 'At least one search parameter is required (query, words, speaker, title, or date filter)'
        ], 400);
    }
    
    // If only date filter is provided without search terms, we need to get all videos with that date
    // and return empty segments but with video information
    $dateOnlySearch = $hasDateFilter && empty($query) && empty($words) && empty($speaker) && empty($title);

    // Handle date-only search without calling embedding service
    if ($dateOnlySearch) {
        Log::info('Date-only search', [
            'filter_year' => $filterYear,
            'filter_month' => $filterMonth,
            'filter_date' => $filterDate
        ]);

        $videoQuery = Video::query();
        
        // Exclude archived videos from search results
        $videoQuery->where('is_archived', false);
        
        // Apply date filters
        if ($filterDate) {
            $videoQuery->whereDate('video_created_at', $filterDate);
        } elseif ($filterYear && $filterMonth) {
            $videoQuery->whereYear('video_created_at', $filterYear)
                      ->whereMonth('video_created_at', $filterMonth);
        } elseif ($filterYear) {
            $videoQuery->whereYear('video_created_at', $filterYear);
        }
        
        // Apply other filters if present
        if ($videoId) $videoQuery->where('id', $videoId);
        if ($language) $videoQuery->where('language_detected', $language);
        
        $allVideos = $videoQuery->get();
        $totalVideos = $allVideos->count();
        
        // Group by video (each video is its own group with empty segments)
        $groupedByVideo = $allVideos->map(function($video) {
            return [
                'video' => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'filename' => $video->filename,
                    'youtube_url' => $video->youtube_url,
                    'youtube_video_id' => $video->youtube_video_id,
                    'language' => $video->language_detected,
                    'created_at' => $video->created_at->toISOString(),
                ],
                'match_count' => 0,
                'best_score' => 0,
                'avg_score' => 0,
                'match_types' => [],
                'matched_fields' => ['date_filter'],
                'segments' => []
            ];
        });
        
        // Calculate pagination
        $totalPages = ceil($totalVideos / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();
        
        return response()->json([
            'success' => true,
            'query' => null,
            'words' => [],
            'filters' => [
                'video_id' => $videoId,
                'language' => $language,
                'min_score' => $minScore,
                'filter_year' => $filterYear,
                'filter_month' => $filterMonth,
                'filter_date' => $filterDate,
            ],
            'segments' => [],
            'grouped_by_video' => $paginatedVideos,
            'total_segments' => 0,
            'total_videos' => $totalVideos,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_next_page' => $page < $totalPages,
            'has_prev_page' => $page > 1,
            'search_metadata' => [
                'search_type' => 'date_only',
                'date_filter_applied' => true,
            ],
            'message' => "Found {$totalVideos} videos matching the date filter"
        ]);
    }

    // Short semantic acronym/keyword fallback for title matches (e.g. "hnr").
    // This ensures brief queries that often appear in titles return all relevant videos,
    // not only transcript-segment semantic hits.
    $normalizedQuery = trim((string) ($query ?? ''));
    $queryTokenCount = count(array_filter(preg_split('/\s+/', $normalizedQuery)));

    // ── PHP PERSON ALIAS DETECTION ────────────────────────────────────────────
    // Config for known public figures: maps every possible query alias to a set of
    // title-search terms and a canonical name.  When a query matches any alias:
    //   1) Title fallback length/token limits are bypassed.
    //   2) Title search uses ALL name variants (OR) to find every related video.
    //   3) top_k is boosted for the first incremental page.
    $phpPersonAliases = [
        'hafiz_naeem' => [
            'canonical'   => 'Hafiz Naeem Ur Rehman',
            // Short strings that reliably appear in video titles for this person
            'title_terms' => [
                'hnr', 'h n r',
                'hafiz naeem', 'hafiz naim', 'hafiz naam', 'hafiz neem',
                'naeem ur rehman', 'naeem ur rahman', 'naeem rehman', 'naeem rehmann',
                'naeemur rehman', 'naeem',
                'hafiz', 'hafeez naeem',
            ],
            // Every query string (lowercase) that should trigger this person's profile
            'aliases' => [
                'hnr', 'h.n.r', 'h.n.r.', 'h n r',
                'hafiz naeem', 'hafiz naeem ur rehman', 'hafiz naeem ur rahman',
                'hafiz naeem rehman', 'hafiz naem', 'hafiz naim', 'hafiz naam',
                'hafiz naeemur rehman', 'hafiz naeemurrehman',
                'hafiz naeemurehman', 'hafiznaeem', 'hafiznaeem ur rehman',
                'naeem ur rehman', 'naeem ur rahman', 'naeem rehman', 'naeem rehmann',
                'naeemur rehman', 'naeemurrehman', 'naeemurrahman',
                'naeem', 'hafiz',
                'ameer jamaat', 'ameer e jamaat', 'ameer jamat',
                'ameer jamaat e islami', 'ameer jamaat islami', 'ameer-e-jamat',
                'hafiz sahab', 'hafiz sahib', 'naeem sahab', 'naeem sahib',
                'naeem bhai', 'hafiz bhai',
                // Urdu script
                'حافظ نعیم', 'حافظ نعیم الرحمن', 'نعیم الرحمن', 'نعیم',
                'حافظ نعیم رحمان', 'نعیم رحمان', 'حافظ صاحب', 'نعیم صاحب',
            ],
        ],
    ];

    // Normalize query for alias matching (strip punctuation, lowercase)
    $normalizedQueryLower   = mb_strtolower($normalizedQuery);
    $normalizedQueryCleaned = preg_replace('/[._,\-]+/', ' ', $normalizedQueryLower);
    $normalizedQueryCleaned = trim(preg_replace('/\s+/', ' ', $normalizedQueryCleaned));

    $detectedPersonAlias = null;
    foreach ($phpPersonAliases as $personKey => $personData) {
        foreach ($personData['aliases'] as $alias) {
            $aliasLower = mb_strtolower($alias);
            if ($normalizedQueryLower === $aliasLower || $normalizedQueryCleaned === $aliasLower) {
                $detectedPersonAlias = $personKey;
                break 2;
            }
        }
    }

    if ($detectedPersonAlias) {
        Log::info('Person alias detected', [
            'query'   => $normalizedQuery,
            'matched' => $detectedPersonAlias,
            'canonical' => $phpPersonAliases[$detectedPersonAlias]['canonical'],
        ]);
    }
    // ── END PERSON ALIAS DETECTION ────────────────────────────────────────────

    // Boost top_k for known person alias queries on the first page so Python
    // fetches a large enough candidate pool to surface all relevant segments.
    if ($detectedPersonAlias !== null && !$isIncrementalCursorRequest) {
        $topK = 1000;
    }

    $isShortTitleFallbackEligible =
        $searchMode === 'semantic'
        && !empty($normalizedQuery)
        && mb_strlen($normalizedQuery) >= 2
        && (
            // Known person alias: bypass length/token restrictions entirely
            $detectedPersonAlias !== null
            // Generic short query: original restrictions apply
            || (mb_strlen($normalizedQuery) <= 12 && $queryTokenCount <= 2)
        )
        && empty($speaker)
        && empty($title)
        && preg_match('/^[\p{L}\p{N}\s._-]+$/u', $normalizedQuery);

    // Mixed alphanumeric single-token semantic queries (e.g. "120hz") can underperform
    // in pure vector mode. If semantic returns empty, auto-fallback once to simple text mode.
    $alnumFallbackAttempted = (bool) $request->input('alnum_fallback_attempted', false);
    $isMixedAlnumSingleTokenQuery =
        $searchMode === 'semantic'
        && !$alnumFallbackAttempted
        && !empty($normalizedQuery)
        && $queryTokenCount === 1
        && preg_match('/^(?=.*\p{L})(?=.*\d)[\p{L}\d._-]+$/u', $normalizedQuery)
        && empty($speaker)
        && empty($title);

    // Short semantic phrase queries (1-3 tokens) can occasionally collapse to zero
    // enrichable results on the fast incremental path even though literal transcript
    // matches exist. If that happens, fallback once to simple text mode.
    $shortTextFallbackAttempted = (bool) $request->input('short_text_fallback_attempted', false);
    $isShortSemanticTextFallbackQuery =
        $searchMode === 'semantic'
        && !$shortTextFallbackAttempted
        && !empty($normalizedQuery)
        && $queryTokenCount >= 1
        && $queryTokenCount <= 3
        && empty($speaker)
        && empty($title)
        && !$isMixedAlnumSingleTokenQuery
        && preg_match('/[\p{L}\d]/u', $normalizedQuery);

    $sanitizeSemanticFallbackResponse = function ($fallbackResponse, string $fallbackType, array $extraContext = []) use ($query) {
        if (!($fallbackResponse instanceof \Illuminate\Http\JsonResponse)) {
            return $fallbackResponse;
        }

        $payload = $fallbackResponse->getData(true);
        if (!is_array($payload)) {
            return $fallbackResponse;
        }

        $normalizeMatchTypes = function (array $matchTypes) {
            $normalized = collect($matchTypes)
                ->map(function ($type) {
                    if (!is_string($type) || trim($type) === '') {
                        return null;
                    }

                    if (str_starts_with($type, 'simple_')) {
                        return 'keyword';
                    }

                    if ($type === 'title_keyword_fallback') {
                        return 'title_match';
                    }

                    return $type;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            return empty($normalized) ? ['keyword'] : $normalized;
        };

        if (!empty($payload['grouped_by_video']) && is_array($payload['grouped_by_video'])) {
            $payload['grouped_by_video'] = array_values(array_map(function ($videoGroup) use ($normalizeMatchTypes) {
                if (!is_array($videoGroup)) {
                    return $videoGroup;
                }

                if (isset($videoGroup['match_types']) && is_array($videoGroup['match_types'])) {
                    $videoGroup['match_types'] = $normalizeMatchTypes($videoGroup['match_types']);
                }

                if (!empty($videoGroup['segments']) && is_array($videoGroup['segments'])) {
                    $videoGroup['segments'] = array_values(array_map(function ($segment) use ($normalizeMatchTypes) {
                        if (!is_array($segment)) {
                            return $segment;
                        }

                        if (isset($segment['match_types']) && is_array($segment['match_types'])) {
                            $segment['match_types'] = $normalizeMatchTypes($segment['match_types']);
                        }

                        return $segment;
                    }, $videoGroup['segments']));
                }

                return $videoGroup;
            }, $payload['grouped_by_video']));
        }

        $payload['search_mode'] = 'semantic';
        if (($payload['filter_type'] ?? null) === 'text') {
            unset($payload['filter_type']);
        }

        if (!isset($payload['search_metadata']) || !is_array($payload['search_metadata'])) {
            $payload['search_metadata'] = [];
        }

        $payload['search_metadata']['search_type'] = 'elastic_semantic';
        $payload['search_metadata']['search_mode'] = 'semantic';
        unset($payload['search_metadata']['filter_type']);
        unset($payload['search_metadata']['fallback_reason']);
        unset($payload['search_metadata']['upstream_metadata']);

        $fallbackResponse->setData($payload);

        Log::info('Semantic fallback response sanitized for frontend', array_merge([
            'fallback_type' => $fallbackType,
            'query' => $query,
            'status' => $fallbackResponse->getStatusCode(),
        ], $extraContext));

        return $fallbackResponse;
    };

    $buildShortTitleFallback = function (array $excludeVideoIds = []) use (
        $isShortTitleFallbackEligible,
        $normalizedQuery,
        $detectedPersonAlias,
        $phpPersonAliases,
        $videoId,
        $language,
        $filterDate,
        $filterYear,
        $filterMonth
    ) {
        if (!$isShortTitleFallbackEligible) {
            return collect();
        }

        $titleQuery = Video::searchable();

        // For person aliases search ALL known name variants (OR) so that every
        // related video title is matched regardless of how it was labelled.
        if ($detectedPersonAlias !== null && !empty($phpPersonAliases[$detectedPersonAlias]['title_terms'])) {
            $allTitleTerms = array_unique(array_merge(
                [$normalizedQuery],
                $phpPersonAliases[$detectedPersonAlias]['title_terms']
            ));
            $titleQuery->where(function ($q) use ($allTitleTerms) {
                foreach ($allTitleTerms as $term) {
                    if (!empty(trim($term))) {
                        $q->orWhere('title', 'LIKE', '%' . $term . '%');
                    }
                }
            });
        } else {
            $titleQuery->where('title', 'LIKE', '%' . $normalizedQuery . '%');
        }

        if ($filterDate) {
            $titleQuery->whereDate('video_created_at', $filterDate);
        } elseif ($filterYear && $filterMonth) {
            $titleQuery->whereYear('video_created_at', $filterYear)
                ->whereMonth('video_created_at', $filterMonth);
        } elseif ($filterYear) {
            $titleQuery->whereYear('video_created_at', $filterYear);
        }

        if ($videoId) {
            $titleQuery->where('id', $videoId);
        }

        if ($language) {
            $titleQuery->where('language_detected', $language);
        }

        if (!empty($excludeVideoIds)) {
            $titleQuery->whereNotIn('id', $excludeVideoIds);
        }

        return $titleQuery
            ->orderByDesc('video_created_at')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(function ($video) {
                return [
                    'video' => [
                        'id' => $video->id,
                        'title' => $video->title,
                        'filename' => $video->filename,
                        'youtube_url' => $video->youtube_url,
                        'youtube_video_id' => $video->youtube_video_id,
                        'language' => $video->language_detected,
                        'created_at' => $video->created_at->toISOString(),
                        'video_created_at' => $video->video_created_at?->toISOString(),
                        'audio_duration_seconds' => $video->audio_duration_seconds,
                        'summary' => $video->summary,
                    ],
                    'match_count' => 0,
                    'best_score' => 0,
                    'avg_score' => 0,
                    'match_types' => ['title_match'],
                    'matched_fields' => ['video_title'],
                    'segments' => [],
                    'is_video_only' => true,
                ];
            })->values();
    };

    $buildShortTitleFallbackResponse = function (string $reason, array $extraContext = []) use (
        $buildShortTitleFallback,
        $startTime,
        $query,
        $words,
        $searchMode,
        $filterType,
        $videoId,
        $speaker,
        $title,
        $language,
        $minScore,
        $timeRange,
        $maxScanned,
        $filterYear,
        $filterMonth,
        $filterDate,
        $page,
        $perPage,
        $isShortTitleFallbackEligible
    ) {
        $titleFallbackGroups = $buildShortTitleFallback([]);
        if ($titleFallbackGroups->isEmpty()) {
            return null;
        }

        $fallbackTotalVideos = $titleFallbackGroups->count();
        $fallbackTotalPages = max(1, (int) ceil($fallbackTotalVideos / $perPage));
        $fallbackOffset = ($page - 1) * $perPage;
        $fallbackPaginated = $titleFallbackGroups->slice($fallbackOffset, $perPage)->values();
        $responseTimeMs = round((microtime(true) - $startTime) * 1000);

        $fallbackLogContext = array_merge([
            'reason' => $reason,
            'query' => $query,
            'fallback_videos' => $fallbackTotalVideos,
        ], $extraContext);

        Log::info('Short title fallback response generated', $fallbackLogContext);

        if ($searchMode === 'semantic' && $isShortTitleFallbackEligible) {
            Log::warning('Semantic short-query collapsed to title fallback', $fallbackLogContext);
        }

        return response()->json([
            'success' => true,
            'query' => $query,
            'words' => $words,
            'speaker_filter' => null,
            'filters' => [
                'video_id' => $videoId,
                'speaker' => $speaker,
                'title' => $title,
                'language' => $language,
                'min_score' => $minScore,
                'time_range' => $timeRange,
                'max_scanned' => $maxScanned,
                'filter_year' => $filterYear,
                'filter_month' => $filterMonth,
                'filter_date' => $filterDate,
            ],
            'segments' => [],
            'grouped_by_video' => $fallbackPaginated,
            'total_segments' => 0,
            'total_videos' => $fallbackTotalVideos,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $fallbackTotalPages,
            'has_next_page' => $page < $fallbackTotalPages,
            'has_prev_page' => $page > 1,
            'search_metadata' => [
                'search_type' => 'elastic_semantic',
                'search_mode' => 'semantic',
                'filter_type' => $filterType,
                'response_time_ms' => $responseTimeMs,
            ],
            'message' => "Found {$fallbackTotalVideos} videos",
        ]);
    };

    $runMixedAlnumSimpleFallback = function (string $reason, array $extraContext = []) use (
        $isMixedAlnumSingleTokenQuery,
        $sanitizeSemanticFallbackResponse,
        $request,
        $query,
        $words,
        $searchMode,
        $filterType,
        $topK,
        $videoId,
        $speaker,
        $title,
        $language,
        $minScore,
        $timeRange,
        $maxScanned,
        $filterYear,
        $filterMonth,
        $filterDate,
        $batchSize,
        $normalizedQuery
    ) {
        if (!$isMixedAlnumSingleTokenQuery) {
            return null;
        }

        $queryLower = mb_strtolower((string) $normalizedQuery);
        $queryCollapsed = preg_replace('/[^\p{L}\d]+/u', '', $queryLower) ?? $queryLower;
        preg_match_all('/\p{L}+|\d+/u', $queryCollapsed, $splitMatches);
        $splitAlphaNumParts = $splitMatches[0] ?? [];

        $fallbackWords = collect($words)
            ->filter(fn($value) => is_string($value) && trim($value) !== '')
            ->map(fn($value) => mb_strtolower(trim($value)))
            ->push($queryLower)
            ->push($queryCollapsed)
            ->merge($splitAlphaNumParts)
            ->filter(function ($value) {
                if (!is_string($value)) {
                    return false;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return false;
                }
                return mb_strlen($trimmed) >= 2 || preg_match('/^\d+$/', $trimmed);
            })
            ->unique()
            ->values()
            ->all();

        // Prefer split-token query for simple text fallback (e.g. "120hz" -> "120 hz").
        // This improves compatibility with text filters that tokenize on whitespace.
        $fallbackQuery = !empty($queryCollapsed) ? $queryCollapsed : $query;
        if (!empty($splitAlphaNumParts) && count($splitAlphaNumParts) >= 2) {
            $fallbackQuery = implode(' ', $splitAlphaNumParts);
        }

        $fallbackPayload = $request->request->all();
        $fallbackPayload['query'] = $fallbackQuery;
        $fallbackPayload['words'] = $fallbackWords;
        $fallbackPayload['search_mode'] = 'simple';
        $fallbackPayload['filter_type'] = 'text';
        $fallbackPayload['use_incremental'] = false;
        $fallbackPayload['cursor'] = null;
        $fallbackPayload['search_session_id'] = null;
        $fallbackPayload['batch_size'] = $batchSize;
        $fallbackPayload['top_k'] = max((int) $topK, 200);
        $fallbackPayload['alnum_fallback_attempted'] = true;

        Log::warning('Mixed alnum semantic fallback to simple text search', array_merge([
            'reason' => $reason,
            'query' => $query,
            'search_mode' => $searchMode,
            'filter_type' => $filterType,
            'fallback_query' => $fallbackQuery,
            'fallback_words' => $fallbackWords,
            'video_id' => $videoId,
            'speaker' => $speaker,
            'title' => $title,
            'language' => $language,
            'min_score' => $minScore,
            'time_range' => $timeRange,
            'max_scanned' => $maxScanned,
            'filter_year' => $filterYear,
            'filter_month' => $filterMonth,
            'filter_date' => $filterDate,
        ], $extraContext));

        $fallbackRequest = Request::create(
            $request->getPathInfo() ?: '/search',
            'POST',
            $fallbackPayload
        );
        $fallbackRequest->setUserResolver($request->getUserResolver());
        $fallbackResponse = $this->embeddingsSearch($fallbackRequest);
        return $sanitizeSemanticFallbackResponse($fallbackResponse, 'mixed_alnum_simple_text', [
            'reason' => $reason,
        ]);
    };

    $runShortSemanticTextFallback = function (string $reason, array $extraContext = []) use (
        $isShortSemanticTextFallbackQuery,
        $sanitizeSemanticFallbackResponse,
        $request,
        $query,
        $words,
        $searchMode,
        $filterType,
        $topK,
        $batchSize,
        $maxScanned,
        $videoId,
        $speaker,
        $title,
        $language,
        $minScore,
        $timeRange,
        $filterYear,
        $filterMonth,
        $filterDate,
        $queryTokenCount
    ) {
        if (!$isShortSemanticTextFallbackQuery) {
            return null;
        }

        $queryTokens = collect(preg_split('/\s+/u', mb_strtolower((string) $query) ?? ''))
            ->filter(fn($value) => is_string($value) && trim($value) !== '')
            ->map(fn($value) => trim($value));

        $fallbackWords = collect($words)
            ->filter(fn($value) => is_string($value) && trim($value) !== '')
            ->map(fn($value) => mb_strtolower(trim($value)))
            ->merge($queryTokens)
            ->filter(function ($value) {
                if (!is_string($value)) {
                    return false;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return false;
                }
                return mb_strlen($trimmed) >= 2 || preg_match('/^\d+$/', $trimmed);
            })
            ->unique()
            ->values()
            ->all();

        $fallbackPayload = $request->request->all();
        $fallbackPayload['query'] = $query;
        $fallbackPayload['words'] = $fallbackWords;
        $fallbackPayload['search_mode'] = 'simple';
        $fallbackPayload['filter_type'] = 'text';
        $fallbackPayload['use_incremental'] = false;
        $fallbackPayload['cursor'] = null;
        $fallbackPayload['search_session_id'] = null;
        $fallbackPayload['batch_size'] = $batchSize;
        $fallbackPayload['top_k'] = max((int) $topK, 200);
        $fallbackPayload['max_scanned'] = max((int) $maxScanned, 20000);
        $fallbackPayload['short_text_fallback_attempted'] = true;

        Log::warning('Short semantic fallback to simple text search', array_merge([
            'reason' => $reason,
            'query' => $query,
            'query_token_count' => $queryTokenCount,
            'search_mode' => $searchMode,
            'filter_type' => $filterType,
            'fallback_query' => $query,
            'fallback_words' => $fallbackWords,
            'video_id' => $videoId,
            'speaker' => $speaker,
            'title' => $title,
            'language' => $language,
            'min_score' => $minScore,
            'time_range' => $timeRange,
            'max_scanned' => $maxScanned,
            'filter_year' => $filterYear,
            'filter_month' => $filterMonth,
            'filter_date' => $filterDate,
        ], $extraContext));

        $fallbackRequest = Request::create(
            $request->getPathInfo() ?: '/search',
            'POST',
            $fallbackPayload
        );
        $fallbackRequest->setUserResolver($request->getUserResolver());
        $fallbackResponse = $this->embeddingsSearch($fallbackRequest);
        return $sanitizeSemanticFallbackResponse($fallbackResponse, 'short_semantic_text', [
            'reason' => $reason,
        ]);
    };

    try {
        $embeddingServiceUrl = $this->getEmbeddingServiceUrl();

        // Determine if we should use incremental search
        $isIncrementalSearch = $isIncrementalRequest;
        $endpoint = $isIncrementalSearch ? '/search/incremental' : '/search';

        // FIXED: Include max_scanned in payload
        $searchPayload = [
            'query' => $query ?? '',
            'words' => array_values(array_filter($words)),
            'top_k' => $topK,
            'min_score' => $minScore,
            'max_scanned' => $maxScanned, // NEW
            'search_mode' => $searchMode ?? 'semantic',
        ];
        if ($filterType) $searchPayload['filter_type'] = $filterType;

        // Add incremental search parameters if using cursor-based pagination
        if ($isIncrementalSearch) {
            $searchPayload['cursor'] = $cursor;
            $searchPayload['batch_size'] = $batchSize;
            $searchPayload['search_session_id'] = $searchSessionId;
        }

        // Add optional filters
        if ($videoId) $searchPayload['video_id'] = $videoId;
        if ($speaker) $searchPayload['speaker'] = $speaker;
        if ($title) $searchPayload['title'] = $title;
        if ($language) $searchPayload['language'] = $language;
        if ($timeRange) $searchPayload['time_range'] = $timeRange;

        Log::info('Elastic search request', [
            'payload' => $searchPayload,
            'service_url' => $embeddingServiceUrl . $endpoint,
            'is_incremental' => $isIncrementalSearch,
        ]);

        $pythonStartTime = microtime(true);

        // For incremental search, don't cache - always call the service to get fresh cursor state
        // For regular search, cache for 5 minutes
        if ($isIncrementalSearch) {
            // Cursor requests paginate cached Python results — fast (30s timeout)
            // First-page requests run the full search pipeline — slow (150s timeout)
            $hasCursor = !empty($cursor);
            $httpTimeout = 120;
            // No retries: retrying a slow first-page request triggers ANOTHER full pipeline run
            // on the Python service, multiplying load with no benefit
            $response = Http::retry(1, 0)
                ->timeout(120)
                ->withHeaders([
                    'X-API-Key' => $this->getEmbeddingApiKey(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post("{$embeddingServiceUrl}{$endpoint}", $searchPayload);

            if ($response->failed()) {
                $errorBody = $response->body();
                $statusCode = $response->status();
                throw new \RuntimeException("Embedding service error|{$statusCode}|{$errorBody}");
            }

            $results = $response->json();
        } else {
            // Cache key based on search payload (5-minute TTL for identical queries)
            $cacheKey = 'search:' . md5(json_encode($searchPayload));
            $cacheTtl = 300; // 5 minutes

            $results = Cache::remember($cacheKey, $cacheTtl, function () use ($embeddingServiceUrl, $searchPayload, $endpoint) {
                // Call the embedding service with API key authentication and retry logic
                // Speaker-only searches scan many segments so need longer timeout
                $response = Http::retry(2, 1000)
                    ->timeout(120)
                    ->withHeaders([
                        'X-API-Key' => $this->getEmbeddingApiKey(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])
                    ->post("{$embeddingServiceUrl}{$endpoint}", $searchPayload);

                if ($response->failed()) {
                    // Don't cache failures — throw so Cache::remember doesn't store it
                    $errorBody = $response->body();
                    $statusCode = $response->status();
                    throw new \RuntimeException("Embedding service error|{$statusCode}|{$errorBody}");
                }

                return $response->json();
            });
        }

        $pythonElapsed = round(microtime(true) - $pythonStartTime, 2);
        Log::info('⏱️ Python API response time', [
            'elapsed_seconds' => $pythonElapsed,
            'is_incremental' => $isIncrementalSearch,
            'cache_hit' => isset($results['metadata']['cache_hit']) ? $results['metadata']['cache_hit'] : 'N/A',
        ]);

        $upstreamMetadata = (isset($results['metadata']) && is_array($results['metadata']))
            ? $results['metadata']
            : [];

        // Handle empty results from service
        if (empty($results) || !isset($results['results'])) {
            $mixedAlnumFallbackResponse = $runMixedAlnumSimpleFallback('empty_or_missing_service_results', [
                'upstream_metadata' => $upstreamMetadata,
            ]);
            if ($mixedAlnumFallbackResponse) {
                return $mixedAlnumFallbackResponse;
            }

            $shortSemanticFallbackResponse = $runShortSemanticTextFallback('empty_or_missing_service_results', [
                'upstream_metadata' => $upstreamMetadata,
            ]);
            if ($shortSemanticFallbackResponse) {
                return $shortSemanticFallbackResponse;
            }

            if ($allowShortTitleFallback) {
                $fallbackResponse = $buildShortTitleFallbackResponse('empty_or_missing_service_results', [
                    'upstream_metadata' => $upstreamMetadata,
                ]);
                if ($fallbackResponse) {
                    return $fallbackResponse;
                }
            }

            return response()->json([
                'success' => true,
                'query' => $query,
                'words' => $words,
                'speaker_filter' => $results['speaker_filter'] ?? null,
                'filters' => [
                    'video_id' => $videoId,
                    'speaker' => $speaker,
                    'title' => $title,
                    'language' => $language,
                    'min_score' => $minScore,
                    'time_range' => $timeRange,
                    'max_scanned' => $maxScanned, // NEW
                    'filter_year' => $filterYear,
                    'filter_month' => $filterMonth,
                    'filter_date' => $filterDate,
                ],
                'segments' => [],
                'grouped_by_video' => [],
                'total_segments' => 0,
                'total_videos' => 0,
                'message' => 'No matching segments found. Try adjusting search parameters.',
                'elastic_features' => [
                    'fuzzy_matching' => true,
                    'semantic_search' => !empty($query),
                    'keyword_search' => !empty($words)
                ]
            ]);
        }

        $buildVideoLookup = function (array $candidateVideoIds) use (
            $filterDate,
            $filterYear,
            $filterMonth
        ) {
            if (empty($candidateVideoIds)) {
                return collect();
            }

            $videoQuery = Video::whereIn('id', $candidateVideoIds)
                ->where('is_archived', false);

            // Apply date filters based on video_created_at field
            if ($filterDate) {
                $videoQuery->whereDate('video_created_at', $filterDate);
            } elseif ($filterYear && $filterMonth) {
                $videoQuery->whereYear('video_created_at', $filterYear)
                    ->whereMonth('video_created_at', $filterMonth);
            } elseif ($filterYear) {
                $videoQuery->whereYear('video_created_at', $filterYear);
            }

            return $videoQuery->get()->keyBy('id');
        };

        $enrichResultsWithVideoData = function (array $serviceResults, $videos) {
            return collect($serviceResults)->map(function($result) use ($videos) {
            $videoId = $result['video_id'] ?? null;
            $video = $videoId ? $videos->get($videoId) : null;

            if (!$video) {
                return null;
            }

            $text = mb_convert_encoding($result['text'] ?? '', 'UTF-8', 'UTF-8');
            $speaker = mb_convert_encoding($result['speaker'] ?? 'UNKNOWN', 'UTF-8', 'UTF-8');

            // FIXED: Proper YouTube URL timestamp construction
            $youtubeTimestampUrl = null;
            if ($video->youtube_url) {
                $startTime = floor($result['start_time'] ?? 0);
                $separator = strpos($video->youtube_url, '?') !== false ? '&' : '?';
                $youtubeTimestampUrl = $video->youtube_url . $separator . 't=' . $startTime . 's';
            }

            return [
                'segment_id' => $result['id'] ?? null,
                'score' => round(($result['score'] ?? 0) * 100, 2),
                'match_types' => $result['match_types'] ?? [],
                'matched_field' => $result['matched_field'] ?? null, // NEW: show which field matched
                'matched_words_count' => $result['matched_words_count'] ?? null, // NEW
                'fuzzy_score' => $result['fuzzy_score'] ?? null, // NEW: show fuzzy match quality
                'speaker' => $speaker,
                'diarization_speaker' => $result['diarization_speaker'] ?? '',
                'start_time' => $result['start_time'] ?? 0,
                'end_time' => $result['end_time'] ?? 0,
                'duration' => $result['duration'] ?? 0,
                'text' => $text,
                'text_preview' => mb_substr($text, 0, 150) . (mb_strlen($text) > 150 ? '...' : ''),
                'text_length' => $result['text_length'] ?? mb_strlen($text),
                'video' => [
                    'id' => $video->id,
                    'title' => $video->title,
                    'filename' => $video->filename,
                    'youtube_url' => $video->youtube_url,
                    'youtube_video_id' => $video->youtube_video_id,
                    'language' => $video->language_detected,
                    'created_at' => $video->created_at->toISOString(),
                ],
                'youtube_timestamp_url' => $youtubeTimestampUrl, // FIXED
                'youtube_url_timestamped' => $result['youtube_url_timestamped'] ?? null
            ];
            })->filter()->values();
        };

        $rawServiceResults = collect($results['results'] ?? [])->values();
        $videoIds = $rawServiceResults
            ->pluck('video_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $videos = $buildVideoLookup($videoIds);
        $enrichedResults = $enrichResultsWithVideoData($rawServiceResults->all(), $videos);

        // Incremental safety net:
        // If the first batch yields zero enrichable local videos, advance one/few
        // cached cursor batches to find overlapping local IDs before returning empty.
        $incrementalRecoveryBatches = 0;
        $incrementalRecoveryResultsAdded = 0;
        $incrementalRecoveryMaxBatches = 2;

        $shouldAttemptIncrementalRecovery =
            $isIncrementalSearch
            && !$isIncrementalCursorRequest
            && $enrichedResults->isEmpty()
            && !empty($results['cursor']['next'] ?? null);

        if ($shouldAttemptIncrementalRecovery) {
            $nextCursor = $results['cursor']['next'] ?? null;
            $resolvedSessionId = $results['search_session_id'] ?? $searchSessionId;

            while (
                $enrichedResults->isEmpty()
                && !empty($nextCursor)
                && $incrementalRecoveryBatches < $incrementalRecoveryMaxBatches
            ) {
                $recoveryPayload = $searchPayload;
                $recoveryPayload['cursor'] = $nextCursor;
                $recoveryPayload['search_session_id'] = $resolvedSessionId;

                $recoveryResponse = Http::retry(1, 0)
                    ->timeout(120)
                    ->withHeaders([
                        'X-API-Key' => $this->getEmbeddingApiKey(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])
                    ->post("{$embeddingServiceUrl}{$endpoint}", $recoveryPayload);

                if ($recoveryResponse->failed()) {
                    Log::warning('Incremental recovery batch request failed', [
                        'query' => $query,
                        'status' => $recoveryResponse->status(),
                    ]);
                    break;
                }

                $recoveryData = $recoveryResponse->json();
                $recoveryBatch = collect($recoveryData['results'] ?? [])->values();

                if ($recoveryBatch->isEmpty()) {
                    break;
                }

                $rawServiceResults = $rawServiceResults
                    ->concat($recoveryBatch)
                    ->values();

                $incrementalRecoveryBatches++;
                $incrementalRecoveryResultsAdded += $recoveryBatch->count();

                $nextCursor = $recoveryData['cursor']['next'] ?? null;
                $resolvedSessionId = $recoveryData['search_session_id'] ?? $resolvedSessionId;

                if (isset($recoveryData['metadata']) && is_array($recoveryData['metadata'])) {
                    $upstreamMetadata = array_merge($upstreamMetadata, $recoveryData['metadata']);
                }

                $videoIds = $rawServiceResults
                    ->pluck('video_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $videos = $buildVideoLookup($videoIds);
                $enrichedResults = $enrichResultsWithVideoData($rawServiceResults->all(), $videos);
            }

            if ($incrementalRecoveryBatches > 0) {
                Log::info('Incremental recovery scan completed', [
                    'query' => $query,
                    'batches_fetched' => $incrementalRecoveryBatches,
                    'results_appended' => $incrementalRecoveryResultsAdded,
                    'recovered_videos' => $enrichedResults->isNotEmpty(),
                ]);

                $results['results'] = $rawServiceResults->all();
                $results['search_session_id'] = $resolvedSessionId;

                if (!isset($results['cursor']) || !is_array($results['cursor'])) {
                    $results['cursor'] = [];
                }
                $results['cursor']['next'] = $nextCursor;

                $upstreamMetadata['laravel_incremental_recovery_applied'] = true;
                $upstreamMetadata['laravel_incremental_recovery_batches'] = $incrementalRecoveryBatches;
                $upstreamMetadata['laravel_incremental_recovery_results_added'] = $incrementalRecoveryResultsAdded;
                $results['metadata'] = $upstreamMetadata;
            }
        }

        $upstreamMetadata['laravel_incremental_recovery_applied'] = $upstreamMetadata['laravel_incremental_recovery_applied'] ?? false;
        $upstreamMetadata['laravel_incremental_recovery_batches'] = $upstreamMetadata['laravel_incremental_recovery_batches'] ?? 0;
        $upstreamMetadata['laravel_incremental_recovery_results_added'] = $upstreamMetadata['laravel_incremental_recovery_results_added'] ?? 0;
        $results['metadata'] = $upstreamMetadata;

        if ($enrichedResults->isEmpty()) {
            $mixedAlnumFallbackResponse = $runMixedAlnumSimpleFallback('enriched_results_empty_after_filters', [
                'upstream_metadata' => $upstreamMetadata,
            ]);
            if ($mixedAlnumFallbackResponse) {
                return $mixedAlnumFallbackResponse;
            }

            $shortSemanticFallbackResponse = $runShortSemanticTextFallback('enriched_results_empty_after_filters', [
                'upstream_metadata' => $upstreamMetadata,
            ]);
            if ($shortSemanticFallbackResponse) {
                return $shortSemanticFallbackResponse;
            }

            if ($allowShortTitleFallback) {
                $fallbackResponse = $buildShortTitleFallbackResponse('enriched_results_empty_after_filters', [
                    'upstream_metadata' => $upstreamMetadata,
                ]);
                if ($fallbackResponse) {
                    return $fallbackResponse;
                }
            }

            return response()->json([
                'success' => true,
                'query' => $query,
                'words' => $words,
                'speaker_filter' => $results['speaker_filter'] ?? null,
                'filters' => [
                    'video_id' => $videoId,
                    'speaker' => $speaker,
                    'title' => $title,
                    'language' => $language,
                    'min_score' => $minScore,
                    'time_range' => $timeRange,
                    'max_scanned' => $maxScanned,
                    'filter_year' => $filterYear,
                    'filter_month' => $filterMonth,
                    'filter_date' => $filterDate,
                ],
                'segments' => [],
                'grouped_by_video' => [],
                'total_segments' => 0,
                'total_videos' => 0,
                'message' => 'No matching segments found after filtering',
                'elastic_features' => [
                    'fuzzy_matching' => true,
                    'typo_tolerance' => true,
                    'semantic_search' => !empty($query),
                    'keyword_search' => !empty($words),
                    'speaker_search' => !empty($speaker),
                    'title_search' => !empty($title)
                ]
            ]);
        }

        // Group results by video
        $groupedByVideo = $enrichedResults->groupBy('video.id')->map(function ($segments, $videoId) {
            $firstSegment = $segments->first();
            // Deduplicate segments by normalized text, keeping highest score
            $uniqueSegments = $segments->groupBy(function ($seg) {
                return mb_strtolower(trim(preg_replace('/\s+/', ' ', $seg['text'] ?? '')));
            })->map(function ($dupes) {
                return $dupes->sortByDesc('score')->first();
            })->values();
            return [
                'video' => $firstSegment['video'],
                'match_count' => $uniqueSegments->count(),
                'best_score' => $uniqueSegments->max('score'),
                'avg_score' => round($uniqueSegments->avg('score'), 2),
                'match_types' => $uniqueSegments->pluck('match_types')->flatten()->unique()->values(),
                'matched_fields' => $uniqueSegments->pluck('matched_field')->filter()->unique()->values(),
                'segments' => $uniqueSegments->map(function ($seg) {
                    return [
                        'segment_id' => $seg['segment_id'],
                        'score' => $seg['score'],
                        'match_types' => $seg['match_types'],
                        'matched_field' => $seg['matched_field'],
                        'matched_words_count' => $seg['matched_words_count'] ?? null,
                        'fuzzy_score' => $seg['fuzzy_score'],
                        'speaker' => $seg['speaker'],
                        'diarization_speaker' => $seg['diarization_speaker'] ?? '',
                        'start_time' => $seg['start_time'],
                        'end_time' => $seg['end_time'],
                        'duration' => $seg['duration'],
                        'text' => $seg['text'],
                        'text_preview' => $seg['text_preview'],
                        'youtube_timestamp_url' => $seg['youtube_timestamp_url'],
                    ];
                })->values()
            ];
        })->values();

        $existingVideoIds = $groupedByVideo->pluck('video.id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        if ($allowShortTitleFallback) {
            $titleFallbackGroups = $buildShortTitleFallback($existingVideoIds);
            if ($titleFallbackGroups->isNotEmpty()) {
                // For incremental requests, cap title fallback to keep batch size predictable.
                // Non-incremental requests use per_page slicing below, so no cap needed there.
                if ($isIncrementalSearch) {
                    $titleFallbackGroups = $titleFallbackGroups->take($batchSize * 2);
                }

                $semanticVideoCountBeforeFallback = $groupedByVideo->count();

                Log::info('Short title fallback appended to semantic results', [
                    'query' => $query,
                    'semantic_video_count' => $semanticVideoCountBeforeFallback,
                    'title_fallback_count' => $titleFallbackGroups->count(),
                ]);

                if ($semanticVideoCountBeforeFallback === 0 && $isShortTitleFallbackEligible) {
                    Log::warning('Semantic short-query collapsed; title fallback now primary result source', [
                        'query' => $query,
                        'title_fallback_count' => $titleFallbackGroups->count(),
                        'upstream_metadata' => $upstreamMetadata,
                    ]);
                }

                $groupedByVideo = $groupedByVideo
                    ->concat($titleFallbackGroups)
                    ->values();
            }
        }

        // Calculate pagination
        $totalVideos = $groupedByVideo->count();
        // Count actual unique segments after deduplication
        $totalUniqueSegments = $groupedByVideo->sum(fn($v) => count($v['segments'] ?? []));
        
        // For incremental search apply per_page slicing so first response is exactly 10 videos.
        // Python already cached all results server-side; subsequent cursor requests get the next batch.
        if ($isIncrementalSearch) {
            $totalPages = max(1, (int) ceil($totalVideos / $perPage));
            $currentIncrementalPage = $phpCursorPage ?? 1;
            $offset = ($currentIncrementalPage - 1) * $perPage;
            $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();
        } else {
            $totalPages = ceil($totalVideos / $perPage);
            $offset = ($page - 1) * $perPage;
            // Slice results for current page
            $paginatedVideos = $groupedByVideo->slice($offset, $perPage)->values();
        }

        Log::info('🎯 Pagination applied', [
            'total_videos' => $totalVideos,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'paginated_count' => $paginatedVideos->count(),
            'first_paginated_video' => $paginatedVideos->isNotEmpty() ? [
                'video_id' => $paginatedVideos[0]['video']['id'] ?? 'N/A',
                'video_title' => $paginatedVideos[0]['video']['title'] ?? 'N/A',
                'segments_count' => count($paginatedVideos[0]['segments'] ?? [])
            ] : 'Empty'
        ]);

        $responseData = [
            'success' => true,
            'query' => $query,
            'words' => $words,
            'speaker_filter' => $results['speaker_filter'] ?? null,
            'filters' => [
                'video_id' => $videoId,
                'speaker' => $speaker,
                'title' => $title,
                'language' => $language,
                'min_score' => $minScore,
                'time_range' => $timeRange,
                'max_scanned' => $maxScanned,
                'filter_year' => $filterYear,
                'filter_month' => $filterMonth,
                'filter_date' => $filterDate,
            ],
            'grouped_by_video' => $paginatedVideos,
            'total_segments' => $totalUniqueSegments,
            'total_videos' => $totalVideos,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_next_page' => $page < $totalPages,
            'has_prev_page' => $page > 1,
            'search_metadata' => [
                'service_url' => $embeddingServiceUrl,
                'search_type' => 'elastic_semantic',
                'response_time_ms' => round((microtime(true) - $startTime) * 1000),
                'elastic_features' => [
                    'fuzzy_matching' => true,
                    'typo_tolerance' => true,
                    'semantic_search' => !empty($query),
                    'keyword_search' => !empty($words),
                    'speaker_search' => !empty($speaker),
                    'title_search' => !empty($title)
                ],
                'service_stats' => [
                    'total_semantic_hits' => $results['total_semantic_hits'] ?? 0,
                    'total_keyword_hits' => $results['total_keyword_hits'] ?? 0,
                    'total_title_hits' => $results['total_title_hits'] ?? 0,
                    'returned' => $results['returned'] ?? 0,
                    'unique_videos' => $results['unique_videos'] ?? 0,
                    'collection' => $results['collection'] ?? 'unknown',
                    'short_query_mode' => $upstreamMetadata['short_query_mode'] ?? null,
                    'short_query_terms' => $upstreamMetadata['short_query_terms'] ?? [],
                    'query_shape' => $upstreamMetadata['query_shape'] ?? null,
                    'effective_retrieval_threshold' => $upstreamMetadata['effective_retrieval_threshold'] ?? null,
                    'lexical_backstop_requested' => $upstreamMetadata['lexical_backstop_requested'] ?? null,
                    'lexical_backstop_applied' => $upstreamMetadata['lexical_backstop_applied'] ?? null,
                    'lexical_backstop_reason' => $upstreamMetadata['lexical_backstop_reason'] ?? null,
                    'lexical_backstop_added' => $upstreamMetadata['lexical_backstop_added'] ?? null,
                    'lexical_backstop_scanned' => $upstreamMetadata['lexical_backstop_scanned'] ?? null,
                    'diagnostics_warnings' => $upstreamMetadata['diagnostics_warnings'] ?? [],
                    'laravel_incremental_recovery_applied' => $upstreamMetadata['laravel_incremental_recovery_applied'] ?? false,
                    'laravel_incremental_recovery_batches' => $upstreamMetadata['laravel_incremental_recovery_batches'] ?? 0,
                    'laravel_incremental_recovery_results_added' => $upstreamMetadata['laravel_incremental_recovery_results_added'] ?? 0,
                ],
                'upstream_metadata' => $upstreamMetadata,
            ]
        ];

        // Add cursor metadata if using incremental search
        if ($isIncrementalSearch) {
            // PHP-side slicing means there may be more videos even when Python says has_more=false.
            // PHP is authoritative: it always re-fetches Python from cursor=null (cache hit) so
            // Python's own cursor/has_more reflects its internal batch pagination, not PHP pages.
            // Using Python's has_more here causes an infinite "View More" loop (page 2 gets offset=10
            // but $totalVideos is still 3, returning 0 results while has_more stays true).
            $phpHasMorePages = $currentIncrementalPage < $totalPages; // true only if there are pages after the current one
            $effectiveHasMore = $phpHasMorePages;

            // Encode a simple PHP-side page cursor so "View More" can get the next slice.
            // Format: "php:{page}:{session_id}" — frontend sends this back as cursor.
            $nextPhpPage = ($currentIncrementalPage ?? 1) + 1;
            $phpCursorNext = $effectiveHasMore
                ? 'php:' . $nextPhpPage . ':' . ($results['search_session_id'] ?? '')
                : ($results['cursor']['next'] ?? null);

            $responseData['cursor'] = [
                'next' => $phpCursorNext,
                'has_more' => $effectiveHasMore,
                // PHP's $totalVideos is authoritative — it includes title-fallback videos and
                // excludes segments that were filtered out.  Python's total_available reflects
                // its raw Qdrant cache and must NOT be used as the display total.
                'total_available' => $totalVideos,
            ];
            $responseData['search_session_id'] = $results['search_session_id'] ?? null;
            $responseData['is_incremental'] = true;
            $responseData['batch_metadata'] = $results['metadata'] ?? [];

            // Keep pagination fields in the response so frontend page-based fallback also works
            $responseData['current_page'] = $currentIncrementalPage ?? 1;
            $responseData['per_page'] = $perPage;
            $responseData['total_pages'] = $totalPages;
            $responseData['has_next_page'] = $phpHasMorePages;
            $responseData['has_prev_page'] = ($currentIncrementalPage ?? 1) > 1;
            
            Log::info('📦 Incremental search response', [
                'session_id' => substr($responseData['search_session_id'] ?? '', 0, 8),
                'has_more' => $responseData['cursor']['has_more'],
                'total_available' => $responseData['cursor']['total_available'],
                'batch_size' => count($paginatedVideos)
            ]);
        }

        // Log search for analytics
        $responseTimeMs = round((microtime(true) - $startTime) * 1000);
        SearchLog::create([
            'user_id' => auth()->id(),
            'query' => $query,
            'word' => $word,
            'speaker' => $speaker,
            'video_id' => $videoId,
            'results_count' => $enrichedResults->count(),
            'videos_count' => $totalVideos,
            'min_score' => $minScore,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'response_time_ms' => $responseTimeMs,
            'search_type' => 'elastic_semantic',
            'filters' => json_encode([
                'title' => $title,
                'language' => $language,
                'words' => $words,
                'time_range' => $timeRange,
                'max_scanned' => $maxScanned // NEW
            ])
        ]);

        $totalElapsed = round(microtime(true) - $startTime, 2);

        Log::info('📦 Returning search response', [
            'success' => true,
            'query' => $query,
            'grouped_by_video_count' => $paginatedVideos->count(),
            'total_segments' => $totalUniqueSegments,
            'total_videos' => $totalVideos,
            'current_page' => $page,
            'total_request_time_seconds' => $totalElapsed,
            'response_data_keys' => array_keys($responseData),
            'sample_video' => $paginatedVideos->isNotEmpty() ? [
                'video_id' => $paginatedVideos[0]['video']['id'] ?? 'N/A',
                'segments_count' => count($paginatedVideos[0]['segments'] ?? [])
            ] : 'None'
        ]);

        return response()->json($responseData, 200, [], JSON_UNESCAPED_UNICODE);

    } catch (\RuntimeException $e) {
        // Handle embedding service HTTP failures (thrown from Cache::remember)
        $parts = explode('|', $e->getMessage(), 3);
        if (count($parts) === 3 && $parts[0] === 'Embedding service error') {
            $statusCode = (int) $parts[1];
            $errorBody = $parts[2];

            Log::error('Embedding service error', [
                'status' => $statusCode,
                'body' => $errorBody,
            ]);

            $errorMessage = 'Search service unavailable';
            if ($statusCode === 401) $errorMessage = 'Authentication failed. Invalid API key.';
            elseif ($statusCode === 429) $errorMessage = 'Rate limit exceeded. Please try again later.';
            elseif ($statusCode === 422) $errorMessage = 'Invalid search parameters.';

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'error' => config('app.debug') ? $errorBody : null,
                'status_code' => $statusCode
            ], $statusCode);
        }

        // Re-throw if it's not our embedding service error
        throw $e;
    } catch (\Exception $e) {
        Log::error('Embedding search failed', [
            'query' => $query,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $responseTimeMs = round((microtime(true) - $startTime) * 1000);
        SearchLog::create([
            'user_id' => auth()->id(),
            'query' => $query,
            'word' => $word,
            'speaker' => $speaker,
            'video_id' => $videoId,
            'results_count' => 0,
            'videos_count' => 0,
            'min_score' => $minScore,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'response_time_ms' => $responseTimeMs,
            'search_type' => 'elastic_semantic_failed', // NEW: mark as failed
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Search failed',
            'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during search'
        ], 500);
    }
}

    /**
     * Generate summary for a video from stored transcription using OpenAI
     * 
     * @param Video $video
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateSummary(Video $video)
    {
        // Check if video has transcript data
        if (!$video->speakers_data && !$video->transcript_text) {
            return response()->json([
                'success' => false,
                'message' => 'Video does not have transcription data. Please process the video first.'
            ], 422);
        }

        try {
            // Build transcript text from speakers_data if available
            $transcriptText = '';
            
            if ($video->speakers_data) {
                $speakersData = is_string($video->speakers_data)
                    ? json_decode($video->speakers_data, true)
                    : $video->speakers_data;
                
                foreach ($speakersData as $utterance) {
                    $speaker = $utterance['speaker'] ?? 'Speaker';
                    $text = $utterance['text'] ?? '';
                    $transcriptText .= "{$speaker}: {$text}\n";
                }
            } elseif ($video->transcript_text) {
                $transcriptText = $video->transcript_text;
            }

            if (empty(trim($transcriptText))) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transcript text available to summarize.'
                ], 422);
            }

            // Truncate if too long (OpenAI has token limits)
            $maxChars = 30000; // ~7500 tokens approximately
            if (strlen($transcriptText) > $maxChars) {
                $transcriptText = substr($transcriptText, 0, $maxChars) . '... [transcript truncated]';
            }

            // Get OpenAI API key
            $openaiApiKey = env('OPENAI_API_KEY');
            if (!$openaiApiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'OpenAI API key not configured.'
                ], 500);
            }

            // Detect language for better prompting
            $language = $video->language_detected ?? 'unknown';
            $languageInstruction = '';
            
            if ($language === 'ur') {
                $languageInstruction = 'The transcript is in Urdu. Please provide the summary in Urdu language.';
            } elseif ($language !== 'en' && $language !== 'unknown') {
                $languageInstruction = "The transcript is in {$language}. Please provide the summary in the same language as the transcript.";
            }

            // Create OpenAI client
            $client = OpenAI::client($openaiApiKey);

            // Generate summary using GPT
            $response = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are an expert at summarizing video transcripts. Create a clear, concise summary that captures the main points, key topics discussed, and any important conclusions. {$languageInstruction}"
                    ],
                    [
                        'role' => 'user',
                        'content' => "Please summarize the following video transcript:\n\n{$transcriptText}"
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.3,
            ]);

            $summary = $response->choices[0]->message->content ?? null;

            if (!$summary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate summary from OpenAI.'
                ], 500);
            }

            // Update video with the generated summary
            $video->update(['summary' => $summary]);

            Log::info('Summary generated for video', [
                'video_id' => $video->id,
                'language' => $language,
                'summary_length' => strlen($summary)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Summary generated successfully.',
                'video_id' => $video->id,
                'language' => $language,
                'summary' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate summary', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate summary.',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Generate summaries for all videos without summaries (batch)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateAllSummaries()
    {
        // Get all videos without summaries (shared across all admins)
        $videos = Video::whereNull('summary')
            ->where(function($query) {
                $query->whereNotNull('speakers_data')
                      ->orWhereNotNull('transcript_text');
            })
            ->get();

        if ($videos->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No videos need summaries.',
                'processed' => 0
            ]);
        }

        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($videos as $video) {
            try {
                // Call the single video summary method logic inline
                $transcriptText = '';
                
                if ($video->speakers_data) {
                    $speakersData = is_string($video->speakers_data) 
                        ? json_decode($video->speakers_data, true) 
                        : $video->speakers_data;
                    
                    foreach ($speakersData as $utterance) {
                        $speaker = $utterance['speaker'] ?? 'Speaker';
                        $text = $utterance['text'] ?? '';
                        $transcriptText .= "{$speaker}: {$text}\n";
                    }
                } elseif ($video->transcript_text) {
                    $transcriptText = $video->transcript_text;
                }

                if (empty(trim($transcriptText))) {
                    continue;
                }

                $maxChars = 30000;
                if (strlen($transcriptText) > $maxChars) {
                    $transcriptText = substr($transcriptText, 0, $maxChars) . '... [transcript truncated]';
                }

                $openaiApiKey = env('OPENAI_API_KEY');
                $language = $video->language_detected ?? 'unknown';
                $languageInstruction = '';
                
                if ($language === 'ur') {
                    $languageInstruction = 'The transcript is in Urdu. Please provide the summary in Urdu language.';
                } elseif ($language !== 'en' && $language !== 'unknown') {
                    $languageInstruction = "The transcript is in {$language}. Please provide the summary in the same language.";
                }

                $client = OpenAI::client($openaiApiKey);

                $response = $client->chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are an expert at summarizing video transcripts. Create a clear, concise summary that captures the main points. {$languageInstruction}"
                        ],
                        [
                            'role' => 'user',
                            'content' => "Please summarize the following video transcript:\n\n{$transcriptText}"
                        ]
                    ],
                    'max_tokens' => 1000,
                    'temperature' => 0.3,
                ]);

                $summary = $response->choices[0]->message->content ?? null;

                if ($summary) {
                    $video->update(['summary' => $summary]);
                    $processed++;
                }

                // Add small delay to avoid rate limiting
                usleep(500000); // 0.5 second

            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ];
                Log::error('Failed to generate summary for video', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Summaries generated for {$processed} videos.",
            'total_videos' => $videos->count(),
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors
        ]);
    }

    public function dropboxVideoTemporaryLink(Request $request){
        Log::info('Dropbox temporary link request received', [
            'request' => $request->all()
        ]);
        
        // Validate that either dropbox_path or video_id is provided
        $request->validate([
            'dropbox_path' => 'required_without:video_id|string|nullable',
            'video_id' => 'required_without:dropbox_path|integer|nullable'
        ]);
        
        $dropboxPath = null;
        $video = null;

        // Get dropbox_path either directly or from video_id
        if ($request->has('video_id') && $request->video_id) {
            // Fetch video by ID
            $video = Video::find($request->video_id);
            
            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found'
                ], 404);
            }
            
            if (!$video->dropbox_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video does not have a Dropbox path'
                ], 404);
            }
            
            $dropboxPath = $video->dropbox_path;
            Log::info('Using dropbox_path from video_id', [
                'video_id' => $video->id,
                'dropbox_path' => $dropboxPath
            ]);
        } else {
            // Use dropbox_path directly
            $dropboxPath = $request->dropbox_path;
            
            // Find the video with this dropbox_path to get the owner
            $video = Video::where('dropbox_path', $dropboxPath)
                ->first();
            Log::info('Using dropbox_path directly', ['dropbox_path' => $dropboxPath]);
        }

        // Use video owner if found, otherwise use Super Admin for Dropbox access
        $user = null;
        if ($video && $video->user) {
            $user = $video->user;
            Log::info('Using video owner for Dropbox access', ['user_id' => $user->id]);
        } else {
            // Fallback: use Super Admin who holds the Dropbox credentials
            $user = User::getSuperAdmin();
            Log::info('Using Super Admin for Dropbox access', ['user_id' => $user ? $user->id : null]);
        }
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No user with Dropbox access found'
            ], 404);
        }
        
        try {
            $dropboxService = new DropboxService($user);
            
            // Get temporary link directly - will throw exception if file not found
            $link = $dropboxService->getTemporaryLink($dropboxPath);
            return response()->json([
                'success' => true,
                'temporary_link' => $link
            ]);
        } catch (\Exception $e) {
            // Check if it's a file not found error
            if (str_contains($e->getMessage(), 'not_found') || str_contains($e->getMessage(), 'path/not_found')) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found in Dropbox'
                ], 404);
            }
            Log::error('Error fetching Dropbox temporary link', [
                'dropbox_path' => $dropboxPath,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error fetching temporary link: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry/Resume processing for a video
     * This will handle both failed and partially processed videos
     * 
     * @param Request $request
     * @param Video $video
     * @return \Illuminate\Http\JsonResponse
     */
    public function retryProcessing(Request $request, Video $video)
    {
        $user = auth()->user();

        // Check if user has permission to manage videos
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage videos.'], 403);
        }

        try {
            // Check if video is already being processed
            if ($video->processing_status === 'processing') {
                return response()->json([
                    'success' => false,
                    'message' => 'Video is already being processed. Please wait for the current process to complete.'
                ], 409);
            }

            // Determine if we should restart from beginning or continue
            $forceRestart = $request->input('force_restart', false);

            if ($forceRestart) {
                // Reset video to initial state
                $video->update([
                    'processing_status' => 'pending',
                    'processing_error' => null,
                    'youtube_video_id' => null,
                    'youtube_url' => null,
                    'transcript_id' => null,
                    'pyannote_job_id' => null,
                    'language_detected' => null,
                    'speakers_data' => null,
                    'diarization_data' => null,
                    'identification_data' => null,
                ]);

                Log::info('Video reset for full reprocessing', [
                    'video_id' => $video->id,
                    'dropbox_path' => $video->dropbox_path
                ]);
            } else {
                // Just reset the status to allow continuation
                $video->update([
                    'processing_status' => 'pending',
                    'processing_error' => null,
                ]);

                Log::info('Video marked for retry processing', [
                    'video_id' => $video->id,
                    'has_youtube' => !empty($video->youtube_video_id),
                    'has_transcript' => !empty($video->transcript_id),
                    'has_speakers' => !empty($video->pyannote_job_id)
                ]);
            }

            // Dispatch the processing job
            \App\Jobs\ProcessVideoJob::dispatch(
                auth()->id(),
                $video->id,
                $video->dropbox_path,
                $video->title,
                $video->description
            );

            return response()->json([
                'success' => true,
                'message' => $forceRestart
                    ? 'Video processing restarted from beginning'
                    : 'Video processing resumed',
                'video_id' => $video->id,
                'processing_status' => 'queued',
                'action' => $forceRestart ? 'restart' : 'resume'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retry video processing', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry processing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Track a single video - starts processing pipeline
     * 
     * @param Request $request
     * @param Video $video
     * @return \Illuminate\Http\JsonResponse
     */
    public function trackVideo(Request $request, Video $video)
    {
        $user = auth()->user();

        // Check if user has permission to manage videos
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage videos.'], 403);
        }

        try {
            // Check if video is already being processed
            if ($video->processing_status === 'processing') {
                return response()->json([
                    'success' => false,
                    'message' => 'Video is already being processed'
                ], 409);
            }

            // Check if video is already completed
            $isCompleted = $video->youtube_video_id && 
                          $video->transcript_id && 
                          $video->pyannote_job_id && 
                          $video->identification_data;
            
            if ($isCompleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video is already fully processed'
                ], 409);
            }

            // Reset status and dispatch processing job
            $video->update([
                'processing_status' => 'pending',
                'processing_error' => null,
            ]);

            // Dispatch the processing job
            \App\Jobs\ProcessVideoJob::dispatch(
                auth()->id(),
                $video->id,
                $video->dropbox_path,
                $video->title,
                $video->description
            );

            Log::info('Video tracking started', [
                'video_id' => $video->id,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Video processing started',
                'video_id' => $video->id,
                'processing_status' => 'queued'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to track video', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start video processing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk track multiple videos
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkTrackVideos(Request $request)
    {
        $user = auth()->user();

        // Check if user has permission to manage videos
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage videos.'], 403);
        }

        try {
            $videoIds = $request->input('video_ids', []);
            
            // Flatten the array in case it's nested
            if (is_array($videoIds) && isset($videoIds[0]) && is_array($videoIds[0])) {
                $videoIds = array_merge(...$videoIds);
            }
            
            // Ensure we have a flat array of integers
            $videoIds = array_map('intval', array_filter((array) $videoIds, function($id) {
                return is_numeric($id) || (is_array($id) && isset($id[0]));
            }));
            
            if (empty($videoIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid video IDs provided'
                ], 400);
            }

            Log::info('Bulk track request', [
                'user_id' => auth()->id(),
                'video_ids' => $videoIds,
                'count' => count($videoIds)
            ]);

            // Fetch all videos
            $videos = Video::whereIn('id', $videoIds)
                ->get();

            if ($videos->count() !== count($videoIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some videos not found or unauthorized'
                ], 403);
            }

            $dispatched = 0;
            $skipped = 0;
            $errors = [];

            foreach ($videos as $video) {
                // Skip if already processing
                if ($video->processing_status === 'processing') {
                    $skipped++;
                    continue;
                }

                // Skip if already completed
                $isCompleted = $video->youtube_video_id && 
                              $video->transcript_id && 
                              $video->pyannote_job_id && 
                              $video->identification_data;
                
                if ($isCompleted) {
                    $skipped++;
                    continue;
                }

                try {
                    // Reset status
                    $video->update([
                        'processing_status' => 'pending',
                        'processing_error' => null,
                    ]);

                    // Dispatch job
                    \App\Jobs\ProcessVideoJob::dispatch(
                        auth()->id(),
                        $video->id,
                        $video->dropbox_path,
                        $video->title,
                        $video->description
                    );

                    $dispatched++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'video_id' => $video->id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            Log::info('Bulk tracking completed', [
                'user_id' => auth()->id(),
                'total_requested' => count($videoIds),
                'dispatched' => $dispatched,
                'skipped' => $skipped,
                'errors' => count($errors)
            ]);

            return response()->json([
                'success' => true,
                'message' => "Processing started for {$dispatched} video(s)",
                'dispatched' => $dispatched,
                'skipped' => $skipped,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk tracking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Bulk tracking failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dispatch processing job for all pending videos
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function dispatchProcessingJob(Request $request)
    {
        $user = auth()->user();

        // Check if user has permission to manage videos
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage videos.'], 403);
        }

        try {
            // Get all pending videos
            $videos = Video::where('processing_status', 'pending')
                ->whereNotNull('dropbox_path')
                ->get();

            if ($videos->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No pending videos to process',
                    'dispatched' => 0
                ]);
            }

            $dispatched = 0;

            foreach ($videos as $video) {
                try {
                    \App\Jobs\ProcessVideoJob::dispatch(
                        auth()->id(),
                        $video->id,
                        $video->dropbox_path,
                        $video->title,
                        $video->description
                    );

                    $video->update(['processing_status' => 'queued']);
                    $dispatched++;
                } catch (\Exception $e) {
                    Log::error('Failed to dispatch job for video', [
                        'video_id' => $video->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Processing jobs dispatched', [
                'user_id' => auth()->id(),
                'dispatched' => $dispatched
            ]);

            return response()->json([
                'success' => true,
                'message' => "Processing jobs dispatched for {$dispatched} video(s)",
                'dispatched' => $dispatched
            ]);

        } catch (\Exception $e) {
            Log::error('Dispatch job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to dispatch processing jobs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tag speakers in video transcript
     * Merges transcript data (with A/B speakers) with diarization data (with actual names)
     */
    public function tagSpeakers(Request $request, Video $video, SpeakerTaggingService $taggingService)
    {
        
        try {
            Log::info('Tagging speakers for video', [
                'video_id' => $video->id
            ]);

            // Check if video has the required data
            if (empty($video->speakers_data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transcript data available for this video'
                ], 400);
            }

            if (empty($video->identification_data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No speaker identification data available for this video'
                ], 400);
            }

            // Parse the data
            $transcriptData = is_string($video->speakers_data) 
                ? json_decode($video->speakers_data, true) 
                : $video->speakers_data;

            $diarizationData = is_string($video->identification_data) 
                ? json_decode($video->identification_data, true) 
                : $video->identification_data;

            // Validate data format
            if (!is_array($transcriptData) || !is_array($diarizationData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data format'
                ], 400);
            }

            // Tag the speakers
            $result = $taggingService->tagSpeakers($transcriptData, $diarizationData);
            Log::info('Speaker tagging result', [
                'video_id' => $video->id,
                'tagged_segments' => $result['statistics']['taggedSegments'],
                'untagged_segments' => $result['statistics']['untaggedSegments']
            ]);
            // Save the tagged transcript back to the video
            $video->update([
                'speakers_data' => json_encode($result['taggedTranscript']),
                'speaker_mapping' => json_encode($result['speakerMapping']),
            ]);

            // Generate formatted transcript
            $formattedTranscript = $taggingService->generateFormattedTranscript($result['taggedTranscript']);
            Log::info('Speakers tagged for video', [
                'video_id' => $video->id,
                'tagged_segments' => $result['statistics']['taggedSegments'],
                'untagged_segments' => $result['statistics']['untaggedSegments']
            ]);

        

            return response()->json([
                'success' => true,
                'message' => 'Speakers tagged successfully',
                'data' => [
                    'video_id' => $video->id,
                    'statistics' => $result['statistics'],
                    'speaker_mapping' => $result['speakerMapping'],
                    'formatted_transcript' => $formattedTranscript,
                    'tagged_segments_count' => $result['statistics']['taggedSegments'],
                    'untagged_segments_count' => $result['statistics']['untaggedSegments'],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to tag speakers', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to tag speakers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tagged transcript with speaker names
     */
    public function getTaggedTranscript(Video $video, SpeakerTaggingService $taggingService)
    {
        
        try {
            // Check if video has transcript data
            if (empty($video->speakers_data)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transcript data available'
                ], 404);
            }

            $transcriptData = is_string($video->speakers_data) 
                ? json_decode($video->speakers_data, true) 
                : $video->speakers_data;

            $speakerMapping = !empty($video->speaker_mapping)
                ? (is_string($video->speaker_mapping) ? json_decode($video->speaker_mapping, true) : $video->speaker_mapping)
                : null;

            // Apply speaker mapping to transcript if mapping exists but actualSpeaker not set
            if ($speakerMapping && !isset($transcriptData[0]['actualSpeaker'])) {
                Log::info('Applying speaker mapping to transcript', [
                    'video_id' => $video->id,
                    'mapping' => $speakerMapping
                ]);
                
                foreach ($transcriptData as &$segment) {
                    if (isset($segment['speaker'])) {
                        $speaker = $segment['speaker'];
                        
                        // Check if this speaker is in the mapping
                        if (isset($speakerMapping[$speaker])) {
                            $segment['actualSpeaker'] = $speakerMapping[$speaker];
                            Log::info('Applied mapping', [
                                'original' => $speaker,
                                'mapped' => $speakerMapping[$speaker]
                            ]);
                        }
                    }
                }
                unset($segment); // Break reference
            }

            // Generate formatted transcript
            $formattedTranscript = $taggingService->generateFormattedTranscript($transcriptData);

            return response()->json([
                'success' => true,
                'data' => [
                    'video_id' => $video->id,
                    'transcript' => $transcriptData,
                    'formatted_transcript' => $formattedTranscript,
                    'speaker_mapping' => $speakerMapping,
                    'has_speaker_tags' => isset($transcriptData[0]['actualSpeaker']),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get tagged transcript', [
                'video_id' => $video->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get transcript: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's search history
     */
    public function getSearchHistory(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        // Get all search history (shared across all admins)
        $searchHistory = SearchLog::latest()
        ->paginate($perPage);
        return response()->json($searchHistory);
    }

    /**
     * Get video filename suggestions for search autocomplete.
     * Returns filenames of approved, non-archived videos that have embeddings ready.
     */
    public function videoSuggestions(Request $request)
    {
        $search = $request->get('q', '');

        $query = Video::where('approval_status', 'approved')
            ->where('is_archived', false)
            ->whereHas('embedding', function ($q) {
                $q->where('status', 'completed');
            })
            ->select('id', 'filename', 'title');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('filename', 'like', '%' . $search . '%')
                  ->orWhere('title', 'like', '%' . $search . '%');
            });
        }

        $videos = $query->orderBy('filename')
            ->limit(20)
            ->get()
            ->map(fn($v) => [
                'id' => $v->id,
                'filename' => $v->filename,
                'title' => $v->title,
            ]);

        return response()->json($videos);
    }

    public function mtsToMp4()
    {
      $video=Video::where('id', '120')->first();
      $videoPath=$video->dropbox_path;
      $dropboxService=new DropboxService($video->user);
      $result=$dropboxService->convertAndUploadMtsToMp4($videoPath);
      Log::info('MTS to MP4 conversion result: ' . json_encode($result));
      return response()->json($result);
    }
}