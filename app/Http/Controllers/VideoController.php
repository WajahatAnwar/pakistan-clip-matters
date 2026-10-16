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

        // Exclude failed videos with no audio from all tabs except the 'failed' tab
        if ($status !== 'failed') {
            $query->where(function ($q) {
                $q->where('processing_status', '!=', 'failed')
                  ->orWhere('has_audio', '!=', false)
                  ->orWhereNull('has_audio');
            });
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
                'operational_metrics' => \App\Services\OperationalMetrics::current((float)$totalSizeMB),
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
     * Normalize friendly search_type to internal search_mode and filter_type
     */
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

    /**
     * Search for similar video segments using embeddings with elastic search
     * Supports: semantic search, fuzzy matching, speaker search, keyword search
     */
    public function embeddingsSearch(Request $request)
    {
        return app(\App\Services\EmbeddingSearchService::class)->search($request);
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

    public function dropboxVideoTemporaryLink(Request $request)
    {
        abort_unless($request->user(), 401);
        $input=$request->validate([
            'video_id'=>'nullable|integer|min:1|required_without:dropbox_path',
            'dropbox_path'=>'nullable|string|max:2000|required_without:video_id',
            'refresh'=>'sometimes|boolean', 'link_id'=>'nullable|string|max:64',
        ]);
        $video=isset($input['video_id']) ? Video::find($input['video_id'])
            : Video::where('dropbox_path',$input['dropbox_path'])->first();
        if (!$video || !$video->dropbox_path) return response()->json(['success'=>false,'code'=>'media_missing','message'=>'Video media was not found.'],404);
        $viewer=$request->user();
        $privileged=$viewer->id===$video->user_id || $viewer->hasAnyRole(['superAdmin','admin']);
        if (!$privileged && ($video->approval_status!=='approved' || $video->processing_status!=='completed' || $video->is_archived)) abort(403);
        // Never resolve caller-supplied paths with privileged credentials.
        $owner=$video->user ?: User::getSuperAdmin();
        if (!$owner) return response()->json(['success'=>false,'code'=>'media_access','message'=>'Media access is unavailable.'],503);
        $key='playback:v1:'.$viewer->id.':'.$owner->id.':'.$video->id.':'.hash('sha256',$video->dropbox_path);
        try {
            $result=Cache::lock($key.':lock',40)->block(3,function()use($key,$input,$owner,$video){
                $cached=Cache::get($key);
                // Coalesce simultaneous renewal of the same failed generation.
                $refresh=($input['refresh']??false) && $cached && ($input['link_id']??null)===$cached['link_id'];
                if ($cached && !$refresh) return $cached;
                $link=(new DropboxService($owner))->getTemporaryLink($video->dropbox_path,true);
                if (!is_string($link) || !filter_var($link,FILTER_VALIDATE_URL) || parse_url($link,PHP_URL_SCHEME)!=='https') throw new \RuntimeException('Media provider unavailable');
                $result=['success'=>true,'video_id'=>$video->id,'temporary_link'=>$link,'link_id'=>bin2hex(random_bytes(16)),
                    'expires_at'=>now()->addHours(3)->toIso8601String()];
                Cache::put($key,$result,now()->addHours(3));
                return $result;
            });
            return response()->json($result)->header('Cache-Control','private, no-store');
        } catch (\DomainException $e) {
            $missing=$e->getCode()===404;
            return response()->json(['success'=>false,'code'=>$missing?'media_missing':'provider_access',
                'message'=>$missing?'Video media was not found in Dropbox.':'Dropbox access is unavailable. Please contact an administrator.'],$missing?404:503);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json(['success'=>false,'code'=>'media_busy','message'=>'Media is being refreshed. Please retry.'],503);
        } catch (\Throwable $e) {
            Log::warning('Playback link unavailable',['video_id'=>$video->id,'exception_type'=>get_class($e)]);
            return response()->json(['success'=>false,'code'=>'media_unavailable','message'=>'Media could not be loaded. Please retry.'],503);
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
