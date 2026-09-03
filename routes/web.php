<?php

use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\FlysystemDropbox\DropboxAdapter;
use League\Flysystem\Filesystem;
use Google\Client;
use Google\Service\YouTube;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Http\Controllers\YoutubeController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\VoiceSampleController as AdminVoiceSampleController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\VideoApprovalController;
use OpenAI\Client as OpenAIClient;
use App\Services\ProcessService;
use App\Http\Controllers\ProfileController;


Route::middleware(['auth'])->group(function () {

    // Admin Profile Routes (default - uses AuthenticatedLayout)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/', function () {
    $user = auth()->user();
    if ($user->hasRole('user')) {
        return redirect()->route('user.search.page');
    }
    if ($user->hasRole('superAdmin') || $user->hasRole('admin') || $user->hasRole('manager')) {
        return redirect()->route('admin.dashboard');
    }
    return redirect()->route('admin.dashboard');
});

    Route::prefix('admin')->middleware('role:admin|superAdmin|manager')->group(function () {

    // Dashboard — all admin roles
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/stats', [VideoController::class, 'dashboardStats'])->name('admin.dashboard.stats');
    });

    Route::get('/video-management', function () {
        $user = Auth::user();
        // Only superAdmin and admin can manage (interact with) videos
        return Inertia::render('AdminSide/VideoManagement/index', [
            'canManageVideos' => $user->hasAnyRole(['superAdmin', 'admin']),
        ]);
    })->name('admin.video.management');

    // Video Approval Routes
    Route::prefix('video-approval')->group(function () {
        Route::get('/', [VideoApprovalController::class, 'index'])->name('admin.video.approval');
        Route::get('/list', [VideoApprovalController::class, 'list'])->name('admin.video.approval.list');
        Route::get('/{video}/preview-link', [VideoApprovalController::class, 'getPreviewLink'])->name('admin.video.approval.preview');
        Route::post('/{video}/approve', [VideoApprovalController::class, 'approve'])->name('admin.video.approval.approve');
        Route::post('/{video}/reject', [VideoApprovalController::class, 'reject'])->name('admin.video.approval.reject');
        Route::post('/{video}/archive', [VideoApprovalController::class, 'archive'])->name('admin.video.approval.archive');
        Route::post('/{video}/unarchive', [VideoApprovalController::class, 'unarchive'])->name('admin.video.approval.unarchive');
        Route::post('/{video}/reset-status', [VideoApprovalController::class, 'resetStatus'])->name('admin.video.approval.reset');
        Route::post('/bulk-approve', [VideoApprovalController::class, 'bulkApprove'])->name('admin.video.approval.bulk-approve');
        Route::post('/bulk-reject', [VideoApprovalController::class, 'bulkReject'])->name('admin.video.approval.bulk-reject');
        Route::post('/bulk-archive', [VideoApprovalController::class, 'bulkArchive'])->name('admin.video.approval.bulk-archive');
        Route::post('/bulk-unarchive', [VideoApprovalController::class, 'bulkUnarchive'])->name('admin.video.approval.bulk-unarchive');
        Route::post('/bulk-reset-status', [VideoApprovalController::class, 'bulkResetStatus'])->name('admin.video.approval.bulk-reset-status');
        
        // Tags and Audio Status
        Route::post('/{video}/audio-status', [VideoApprovalController::class, 'updateAudioStatus'])->name('admin.video.approval.audio-status');
        Route::get('/{video}/tags', [VideoApprovalController::class, 'getTags'])->name('admin.video.approval.tags.get');
        Route::post('/{video}/tags', [VideoApprovalController::class, 'saveTags'])->name('admin.video.approval.tags.save');
    });

    // User management — view for all, write restricted by role in controller
    Route::prefix('user-management')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('admin.user.management');
        Route::get('/users/list', [AdminUserController::class, 'users_list'])->name('admin.users.list');
        Route::post('/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::put('/users/{id}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy'])->name('admin.users.delete');
    });

    // Voice Samples — view for all, upload/delete for admin roles
    Route::prefix('voice-sample')->group(function () {
        Route::get('/', [AdminVoiceSampleController::class, 'index'])->name('admin.voice.sample');
        Route::get('/get-voice-samples', [AdminVoiceSampleController::class, 'getVoiceSamples'])->name('admin.voice.sample.get');
        Route::middleware('role:admin|superAdmin|manager')->group(function () {
                Route::post('/upload-voice-sample', [AdminVoiceSampleController::class, 'uploadVoiceSample'])->name('admin.voice.sample.upload');
                Route::delete('/delete-voice-sample/{id}', [AdminVoiceSampleController::class, 'deleteVoiceSample'])->name('admin.voice.sample.delete');
        });
    });

    // Settings — superAdmin only
    Route::prefix('settings')->middleware('role:superAdmin')->group(function () {
        Route::get('/', [AdminSettingController::class, 'index'])->name('admin.settings');
        Route::get('/get', [AdminSettingController::class, 'getSettings'])->name('admin.settings.get');
        Route::post('/email-notifications', [AdminSettingController::class, 'updateEmailNotifications'])->name('admin.settings.email.notifications');
    });
});

    Route::prefix('user')->group(function () {
        // User Profile Routes (uses UserAuthenticateLayout)
        Route::get('/profile', [ProfileController::class, 'userEdit'])->name('user.profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('user.profile.update');
        
        Route::get('/search-page', function () {
            return Inertia::render('UserSide/SearchPage');
        })->name('user.search.page');

        Route::match(['get', 'post'], '/searched-items', function (Request $request) {
            // Handle POST request - store in session and redirect
            if ($request->isMethod('post')) {
                $videos = $request->input('videos', []);
                $query = $request->input('query');
                $searchMode = $request->input('searchMode');
                $totalVideos = $request->input('totalVideos');
                $totalSegments = $request->input('totalSegments');
                $searchMetadata = $request->input('searchMetadata', []);
                $pagination = $request->input('pagination', []);
                $searchRequestBody = $request->input('searchRequestBody', []);
                $cursor = $request->input('cursor');
                $searchSessionId = $request->input('search_session_id');
                $isIncremental = $request->input('is_incremental', false);
                
                Log::info('🔄 Searched Page POST received', [
                    'videos_is_array' => is_array($videos),
                    'videos_count' => is_array($videos) ? count($videos) : 'not_array',
                    'query' => $query,
                    'searchMode' => $searchMode,
                    'totalVideos' => $totalVideos,
                    'totalSegments' => $totalSegments,
                    'has_metadata' => !empty($searchMetadata),
                    'has_pagination' => !empty($pagination),
                    'first_video_sample' => is_array($videos) && !empty($videos) ? [
                        'has_video_key' => isset($videos[0]['video']),
                        'video_id' => $videos[0]['video']['id'] ?? 'N/A',
                        'video_title' => $videos[0]['video']['title'] ?? 'N/A',
                        'match_count' => $videos[0]['match_count'] ?? 'N/A',
                        'segments_count' => isset($videos[0]['segments']) ? count($videos[0]['segments']) : 'N/A'
                    ] : 'No videos or empty array'
                ]);
                
                session([
                    'search_videos' => $videos,
                    'search_query' => $query,
                    'search_mode' => $searchMode,
                    'search_total_videos' => $totalVideos,
                    'search_total_segments' => $totalSegments,
                    'search_metadata' => $searchMetadata,
                    'search_pagination' => $pagination,
                    'search_request_body' => $searchRequestBody,
                    'search_cursor' => $cursor,
                    'search_session_id' => $searchSessionId,
                    'search_is_incremental' => $isIncremental,
                ]);
                
                Log::info('✅ Data stored in session', [
                    'session_has_videos' => session()->has('search_videos'),
                    'session_videos_count' => is_array(session('search_videos')) ? count(session('search_videos')) : 'not_array'
                ]);
            }
            
            // Get data from session (for both POST redirect and direct GET)
            $videos = session('search_videos', []);
            $query = session('search_query', '');
            $searchMode = session('search_mode', 'semantic');
            $totalVideos = session('search_total_videos', 0);
            $totalSegments = session('search_total_segments', 0);
            $searchMetadata = session('search_metadata', []);
            $pagination = session('search_pagination', []);
            $searchRequestBody = session('search_request_body', []);
            $cursor = session('search_cursor');
            $searchSessionId = session('search_session_id');
            $isIncremental = session('search_is_incremental', false);
            
            Log::info('📤 Rendering SearchedPage with data', [
                'method' => $request->method(),
                'videos_is_array' => is_array($videos),
                'videos_count' => is_array($videos) ? count($videos) : 'not_array',
                'query' => $query,
                'searchMode' => $searchMode,
                'totalVideos' => $totalVideos,
                'totalSegments' => $totalSegments,
                'has_metadata' => !empty($searchMetadata),
                'has_pagination' => !empty($pagination),
                'first_video_from_session' => is_array($videos) && !empty($videos) ? [
                    'video_id' => $videos[0]['video']['id'] ?? 'N/A',
                    'segments' => isset($videos[0]['segments']) ? count($videos[0]['segments']) : 'N/A'
                ] : 'Empty'
            ]);
            
            return Inertia::render('UserSide/SearchedPage', [
                'videos' => $videos,
                'query' => $query,
                'searchMode' => $searchMode,
                'totalVideos' => $totalVideos,
                'totalSegments' => $totalSegments,
                'searchMetadata' => $searchMetadata,
                'pagination' => $pagination,
                'searchRequestBody' => $searchRequestBody,
                'cursor' => $cursor,
                'search_session_id' => $searchSessionId,
                'is_incremental' => $isIncremental,
            ]);
        })->name('searched.page');
        
        Route::get('/video-page/{id?}', function ($id = null) {
            return Inertia::render('UserSide/MainVideoPage/index', [
                'videoId' => $id
            ]);
        })->name('user.video.page');

        // YouTube API endpoints
        Route::post('/youtube/chapters', [YoutubeController::class, 'getVideoChapters'])->name('youtube.chapters');
        Route::post('/youtube/info', [YoutubeController::class, 'getVideoInfo'])->name('youtube.info');

        // Video Clip Generation endpoints
        Route::post('/clips/generate', [YoutubeController::class, 'generateClip'])->name('clips.generate');
        Route::post('/clips/generate-by-name', [YoutubeController::class, 'generateClipByName'])->name('clips.generate.by.name');
        Route::get('/clips/list', [YoutubeController::class, 'listClips'])->name('clips.list');
        Route::get('/clips/download/{filename}', [YoutubeController::class, 'downloadClip'])->name('clips.download');
        Route::get('/clips/stream/{filename}', [YoutubeController::class, 'streamClip'])->name('clips.stream');
        Route::delete('/clips/delete', [YoutubeController::class, 'deleteClip'])->name('clips.delete');
        Route::post('/clips/cleanup', [YoutubeController::class, 'cleanupOldClips'])->name('clips.cleanup');

        // Video management routes
        Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
        Route::get('/videos/list', [VideoController::class, 'list'])->name('videos.list');
        Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');
        // Route::get('/videos/{video}/viewer', [VideoController::class, 'viewer'])->name('videos.viewer'); 
         Route::post('/videos/{video}/tag-speakers', [VideoController::class, 'tagSpeakers'])->name('videos.tag.speakers');
        Route::get('/videos/{video}/tagged-transcript', [VideoController::class, 'getTaggedTranscript'])->name('videos.tagged.transcript');
        Route::get('/videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
        Route::post('/videos/{video}/retry-processing', [VideoController::class, 'retryProcessing'])->name('videos.retry.processing');
        Route::post('/videos/{video}/track', [VideoController::class, 'trackVideo'])->name('videos.track');
        Route::post('/videos/bulk-track', [VideoController::class, 'bulkTrackVideos'])->name('videos.bulk.track');
        Route::post('/videos/dispatch-job', [VideoController::class, 'dispatchProcessingJob'])->name('videos.dispatch.job');
        
        Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');

        // Search history routes
        Route::get('/search-history', [VideoController::class, 'getSearchHistory'])->name('user.search.history');

        // Video filename suggestions for search autocomplete
        Route::get('/video-suggestions', [VideoController::class, 'videoSuggestions'])->name('user.video.suggestions');
    });

    
});

// ========================================
// API WEBHOOK ROUTES (External Services)
// ========================================
Route::post('/api/webhooks/qdrant', [\App\Http\Controllers\Api\QdrantWebhookController::class, 'handleWebhook'])->name('api.webhooks.qdrant');

// ========================================
// SEARCH SUGGESTIONS API
// ========================================
Route::get('/api/search/suggestions', [\App\Http\Controllers\Api\SearchSuggestionController::class, 'index'])->name('api.search.suggestions');

// ========================================
// DROPBOX OAUTH ROUTES
// ========================================
Route::get('connect/dropbox', function () {
    $query = http_build_query([
        'client_id' => config('services.dropbox.client_id'),
        'redirect_uri' => config('services.dropbox.redirect'),
        'response_type' => 'code',
        'token_access_type' => 'offline', // to get refresh token
    ]);
    return redirect("https://www.dropbox.com/oauth2/authorize?$query");
});

Route::get('/mtstomp4', [VideoController::class, 'mtsToMp4'])->name('mtstomp4');

Route::get('/dropbox/callback', function (Request $request) {
    // Log::info('Dropbox OAuth Callback received', ['code' => $request->code]);
    //all  logs  of the response calbavklback
    Log::info('Dropbox OAuth Callback request', $request->all());
    $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
        'code' => $request->code,
        'grant_type' => 'authorization_code',
        'client_id' => config('services.dropbox.client_id'),
        'client_secret' => config('services.dropbox.client_secret'),
        'redirect_uri' => config('services.dropbox.redirect'),
    ]);
    Log::info('Dropbox OAuth Response: ' . $response->body());
    $data = $response->json();

    // Store tokens in user record (if authenticated) or session
    if (auth()->check()) {
        $user = auth()->user();
        $user->dropbox_access_token = $data['access_token'];
        $user->dropbox_refresh_token = $data['refresh_token'] ?? null;
        $user->dropbox_token_expires_at = now()->addSeconds($data['expires_in']);
        $user->save();
        
        // Redirect back to dashboard after successful connection
        return redirect()->route('admin.dashboard')->with('success', 'Dropbox connected successfully!');
    }

    return response()->json([
        'success' => true,
        'message' => 'Dropbox connected successfully!',
        'tokens_saved' => false
    ]);
})->name('dropbox.callback');

        
// ========================================
// YOUTUBE OAUTH ROUTES
// ========================================
Route::get('connect/youtube', function () {
    $googleClient = new Client();
    $googleClient->setClientId(env('GOOGLE_CLIENT_ID'));
    $googleClient->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
    $googleClient->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
    $googleClient->addScope(YouTube::YOUTUBE_UPLOAD);
    $googleClient->setAccessType('offline');
    $googleClient->setPrompt('consent');
    $authUrl = $googleClient->createAuthUrl();
    return redirect($authUrl);
});

Route::get('auth/google/callback', function (Request $request) {
    try {
        // Increase execution time for large video uploads
        set_time_limit(600); // 10 minutes

        $googleClient = new Client();
        $googleClient->setClientId(env('GOOGLE_CLIENT_ID'));
        $googleClient->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $googleClient->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
        $googleClient->addScope(YouTube::YOUTUBE_UPLOAD);
        $googleClient->setAccessType('offline');
        $googleClient->setPrompt('consent');

        $token = $googleClient->fetchAccessTokenWithAuthCode($request->code);
        if (isset($token['error'])) {
            return response()->json(['error' => $token['error'], 'details' => $token], 400);
        }

        // Save tokens to database if user is authenticated
        if (auth()->check()) {
            $user = auth()->user();
            $user->google_access_token = $token['access_token'];
            if (isset($token['refresh_token'])) {
                $user->google_refresh_token = $token['refresh_token'];
            }
            if (isset($token['expires_in'])) {
                $user->google_token_expires_at = now()->addSeconds($token['expires_in']);
            }
            $user->save();
            
            // Redirect back to dashboard after successful connection
            // Dashboard will check for Dropbox connection
            return redirect()->route('admin.dashboard')->with('success', 'YouTube connected successfully!');
        }

        $googleClient->setAccessToken($token);
        if ($googleClient->isAccessTokenExpired()) {
            $googleClient->fetchAccessTokenWithRefreshToken($googleClient->getRefreshToken());
        }

        // Return JSON for non-authenticated users
        return response()->json([
            'success' => true,
            'message' => 'YouTube connected successfully!',
            'note' => 'Use /test-list-videos and /test-pipeline-sync to process videos',
            'tokens_saved' => false
        ]);
    } catch (\Exception $e) {
        Log::error('YouTube callback error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'error' => 'Failed to connect YouTube',
            'message' => $e->getMessage()
        ], 500);
    }
});

// ========================================
// VIDEO PROCESSING PIPELINE ROUTES (MAIN)
// ========================================
/**
 * Test the complete pipeline with Queue Job (Async)
 * Usage: GET /test-pipeline-async?video=/path/to/video.mp4
 */
Route::get('process-video', function (Request $request) {
    try {
        if (!auth()->check()) {
            return response()->json(['error' => 'Please login first'], 401);
        }
        $videoPath = $request->get('video');
        Log::info("Received video path: " . $videoPath);
        if (!$videoPath) {
            return response()->json([
                'error' => 'Please provide video path',
                'usage' => 'GET /test-pipeline-async?video=/path/to/video.mp4'
            ], 400);
        }
        $videoService = new \App\Services\VideoProcessingService(auth()->user());
        $result = $videoService->processVideo(
            $videoPath,
            $videoPath,
            'Automated test processing'
        );

        return response()->json([
            'success' => true,
            'message' => 'Video processing job queued successfully!',
            'data' => $result,
            'note' => 'Check logs for progress: storage/logs/laravel.log'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null
        ], 500);
    }
});

/**
 * Test the complete pipeline Synchronously (Blocking - for testing)
 * Usage: GET /test-pipeline-sync?video=/path/to/video.mp4
 */
Route::get('process-video1', function (Request $request) {
    try {
        if (!auth()->check()) {
            return response()->json(['error' => 'Please login first'], 401);
        }

        $videoPath = $request->get('video');

        if (!$videoPath) {
            // Get first available video
            $videoService = new \App\Services\VideoProcessingService(auth()->user());
            $videos = $videoService->getAvailableVideos();

            if (empty($videos)) {
                return response()->json([
                    'error' => 'No videos found in Dropbox',
                    'suggestion' => 'Upload a video to your Dropbox root folder'
                ], 404);
            }

            $videoPath = $videos[0]['path'];
        }

        $videoService = new \App\Services\VideoProcessingService(auth()->user());

        // This will run synchronously and block until complete
        $videoService->processVideoSync(
            $videoPath,
            $videoPath,
            'Automated test processing (sync)'
        );

        return response()->json([
            'success' => true,
            'message' => 'Video processed successfully!',
            'video_path' => $videoPath,
            'note' => 'Check logs for detailed results: storage/logs/laravel.log'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null
        ], 500);
    }
});

/**
 * Debug Dropbox connection - shows raw API responses
 */
Route::get('/debug-dropbox', function (Request $request) {
    if (!auth()->check()) {
        return response()->json(['error' => 'Please login first'], 401);
    }
    
    $user = auth()->user();
    
    $results = [
        'token_info' => [
            'has_access_token' => !empty($user->dropbox_access_token),
            'has_refresh_token' => !empty($user->dropbox_refresh_token),
            'token_expires_at' => $user->dropbox_token_expires_at,
            'is_expired' => $user->dropbox_token_expires_at ? now()->gte($user->dropbox_token_expires_at) : 'no_expiry_set',
            'token_preview' => $user->dropbox_access_token ? substr($user->dropbox_access_token, 0, 20) . '...' : null,
            'dropbox_team_member_id' => $user->dropbox_team_member_id ?? 'not_set'
        ]
    ];
    
    // Refresh token if expired
    if ($user->dropbox_token_expires_at && now()->gte($user->dropbox_token_expires_at)) {
        try {
            $dropboxService = new \App\Services\DropboxService($user);
            $dropboxService->refreshAccessToken();
            $user->refresh();
            $results['token_refreshed'] = true;
        } catch (\Exception $e) {
            $results['refresh_error'] = $e->getMessage();
        }
    }
    
    $token = $user->dropbox_access_token;
    
    // For Team accounts - Get team info first
    try {
        $response = Http::withToken($token)
            ->withOptions(['verify' => false])
            ->withHeaders(['Content-Type' => 'application/json'])
            ->send('POST', 'https://api.dropboxapi.com/2/team/get_info', [
                'body' => 'null'
            ]);
        
        $results['team_info'] = [
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    } catch (\Exception $e) {
        $results['team_info'] = ['error' => $e->getMessage()];
    }

    // Get team members list
    try {
        $response = Http::withToken($token)
            ->withOptions(['verify' => false])
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://api.dropboxapi.com/2/team/members/list_v2', [
                'limit' => 10,
                'include_removed' => false
            ]);
        
        $members = $response->json()['members'] ?? [];
        $results['team_members'] = [
            'status' => $response->status(),
            'count' => count($members),
            'members' => collect($members)->map(function($m) {
                return [
                    'team_member_id' => $m['profile']['team_member_id'] ?? null,
                    'email' => $m['profile']['email'] ?? null,
                    'name' => $m['profile']['name']['display_name'] ?? null,
                    'status' => $m['profile']['status']['.tag'] ?? null,
                    'role' => $m['role']['.tag'] ?? null,
                ];
            })->toArray()
        ];
        
        // If we found members and no member_id is set, try with the first admin
        if (!empty($members)) {
            $adminMember = collect($members)->first(function($m) {
                return ($m['role']['.tag'] ?? '') === 'team_admin';
            }) ?? $members[0];
            
            $memberId = $adminMember['profile']['team_member_id'] ?? null;
            
            if ($memberId) {
                $results['suggested_member'] = [
                    'team_member_id' => $memberId,
                    'email' => $adminMember['profile']['email'] ?? null,
                    'name' => $adminMember['profile']['name']['display_name'] ?? null,
                ];
                
                // Test listing files with this member
                try {
                    $response = Http::withToken($token)
                        ->withOptions(['verify' => false])
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'Dropbox-API-Select-User' => $memberId
                        ])
                        ->post('https://api.dropboxapi.com/2/files/list_folder', [
                            'path' => '',
                            'recursive' => false,
                            'include_mounted_folders' => true
                        ]);
                    
                    $entries = $response->json()['entries'] ?? [];
                    $results['list_with_member'] = [
                        'status' => $response->status(),
                        'member_id' => $memberId,
                        'entries_count' => count($entries),
                        'entries' => collect($entries)->take(10)->map(function($e) {
                            return [
                                'name' => $e['name'] ?? null,
                                'type' => $e['.tag'] ?? null,
                                'path' => $e['path_display'] ?? null,
                            ];
                        })->toArray()
                    ];
                } catch (\Exception $e) {
                    $results['list_with_member'] = ['error' => $e->getMessage()];
                }
                
                // Check namespaces for this member
                try {
                    $response = Http::withToken($token)
                        ->withOptions(['verify' => false])
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'Dropbox-API-Select-User' => $memberId
                        ])
                        ->send('POST', 'https://api.dropboxapi.com/2/users/get_current_account', [
                            'body' => 'null'
                        ]);
                    
                    $accountData = $response->json();
                    $results['member_account'] = [
                        'status' => $response->status(),
                        'root_info' => $accountData['root_info'] ?? null,
                        'account_type' => $accountData['account_type'] ?? null
                    ];
                    
                    // If there's a root namespace, try listing with it
                    $rootNamespaceId = $accountData['root_info']['root_namespace_id'] ?? null;
                    $homeNamespaceId = $accountData['root_info']['home_namespace_id'] ?? null;
                    
                    if ($rootNamespaceId) {
                        // Try with team space (root namespace)
                        try {
                            $response = Http::withToken($token)
                                ->withOptions(['verify' => false])
                                ->withHeaders([
                                    'Content-Type' => 'application/json',
                                    'Dropbox-API-Select-User' => $memberId,
                                    'Dropbox-API-Path-Root' => json_encode(['.tag' => 'root', 'root' => $rootNamespaceId])
                                ])
                                ->post('https://api.dropboxapi.com/2/files/list_folder', [
                                    'path' => '',
                                    'recursive' => false,
                                    'include_mounted_folders' => true
                                ]);
                            
                            $entries = $response->json()['entries'] ?? [];
                            $results['list_team_space'] = [
                                'status' => $response->status(),
                                'root_namespace_id' => $rootNamespaceId,
                                'entries_count' => count($entries),
                                'entries' => collect($entries)->take(15)->map(function($e) {
                                    return [
                                        'name' => $e['name'] ?? null,
                                        'type' => $e['.tag'] ?? null,
                                        'path' => $e['path_display'] ?? null,
                                    ];
                                })->toArray()
                            ];
                            
                            // List inside user's home folder recursively to find videos
                            $homePath = $accountData['root_info']['home_path'] ?? '';
                            if ($homePath) {
                                try {
                                    $response = Http::withToken($token)
                                        ->withOptions(['verify' => false])
                                        ->withHeaders([
                                            'Content-Type' => 'application/json',
                                            'Dropbox-API-Select-User' => $memberId,
                                            'Dropbox-API-Path-Root' => json_encode(['.tag' => 'root', 'root' => $rootNamespaceId])
                                        ])
                                        ->post('https://api.dropboxapi.com/2/files/list_folder', [
                                            'path' => $homePath,
                                            'recursive' => true,
                                            'include_mounted_folders' => true
                                        ]);
                                    
                                    $allEntries = $response->json()['entries'] ?? [];
                                    
                                    // Filter for video files
                                    // Common: mp4, webm, avi, mov, mkv, flv, wmv, m4v
                                    // Professional/Media House: mts, m2ts, mxf (AVCHD/Broadcast)
                                    // Mobile: 3gp, 3g2
                                    $videoExtensions = ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'm4v', 'mts', 'm2ts', 'mxf', '3gp', '3g2'];
                                    $videos = collect($allEntries)->filter(function($e) use ($videoExtensions) {
                                        if (($e['.tag'] ?? '') !== 'file') return false;
                                        $ext = strtolower(pathinfo($e['name'] ?? '', PATHINFO_EXTENSION));
                                        return in_array($ext, $videoExtensions);
                                    });
                                    
                                    $results['list_home_folder'] = [
                                        'status' => $response->status(),
                                        'home_path' => $homePath,
                                        'total_entries' => count($allEntries),
                                        'video_files_found' => $videos->count(),
                                        'videos' => $videos->take(20)->map(function($e) {
                                            return [
                                                'name' => $e['name'] ?? null,
                                                'path' => $e['path_display'] ?? null,
                                                'size' => $e['size'] ?? null,
                                            ];
                                        })->values()->toArray(),
                                        'folders' => collect($allEntries)->filter(function($e) {
                                            return ($e['.tag'] ?? '') === 'folder';
                                        })->take(10)->map(function($e) {
                                            return $e['path_display'] ?? $e['name'];
                                        })->values()->toArray()
                                    ];
                                } catch (\Exception $e) {
                                    $results['list_home_folder'] = ['error' => $e->getMessage()];
                                }
                            }
                            
                            // List team namespaces (shared team folders)
                            try {
                                $response = Http::withToken($token)
                                    ->withOptions(['verify' => false])
                                    ->withHeaders(['Content-Type' => 'application/json'])
                                    ->post('https://api.dropboxapi.com/2/team/namespaces/list', [
                                        'limit' => 100
                                    ]);
                                
                                $namespaces = $response->json()['namespaces'] ?? [];
                                $results['team_namespaces'] = [
                                    'status' => $response->status(),
                                    'count' => count($namespaces),
                                    'namespaces' => collect($namespaces)->map(function($ns) {
                                        return [
                                            'name' => $ns['name'] ?? null,
                                            'namespace_id' => $ns['namespace_id'] ?? null,
                                            'namespace_type' => $ns['namespace_type']['.tag'] ?? null,
                                        ];
                                    })->toArray()
                                ];
                                
                                // Try to list each team_folder namespace
                                foreach ($namespaces as $ns) {
                                    if (($ns['namespace_type']['.tag'] ?? '') === 'team_folder') {
                                        $nsId = $ns['namespace_id'];
                                        $nsName = $ns['name'];
                                        try {
                                            $response = Http::withToken($token)
                                                ->withOptions(['verify' => false])
                                                ->withHeaders([
                                                    'Content-Type' => 'application/json',
                                                    'Dropbox-API-Select-User' => $memberId,
                                                    'Dropbox-API-Path-Root' => json_encode(['.tag' => 'namespace_id', 'namespace_id' => $nsId])
                                                ])
                                                ->post('https://api.dropboxapi.com/2/files/list_folder', [
                                                    'path' => '',
                                                    'recursive' => true,
                                                    'include_mounted_folders' => true
                                                ]);
                                            
                                            $allEntries = $response->json()['entries'] ?? [];
                                            // Common: mp4, webm, avi, mov, mkv, flv, wmv, m4v
                                            // Professional/Media House: mts, m2ts, mxf (AVCHD/Broadcast)
                                            // Mobile: 3gp, 3g2
                                            $videoExtensions = ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'm4v', 'mts', 'm2ts', 'mxf', '3gp', '3g2'];
                                            $videos = collect($allEntries)->filter(function($e) use ($videoExtensions) {
                                                if (($e['.tag'] ?? '') !== 'file') return false;
                                                $ext = strtolower(pathinfo($e['name'] ?? '', PATHINFO_EXTENSION));
                                                return in_array($ext, $videoExtensions);
                                            });
                                            
                                            $results['team_folder_' . $nsName] = [
                                                'namespace_id' => $nsId,
                                                'total_entries' => count($allEntries),
                                                'video_files_found' => $videos->count(),
                                                'videos' => $videos->take(10)->map(function($e) {
                                                    return [
                                                        'name' => $e['name'] ?? null,
                                                        'path' => $e['path_display'] ?? null,
                                                    ];
                                                })->values()->toArray()
                                            ];
                                        } catch (\Exception $e) {
                                            $results['team_folder_' . $nsName] = ['error' => $e->getMessage()];
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                $results['team_namespaces'] = ['error' => $e->getMessage()];
                            }
                        } catch (\Exception $e) {
                            $results['list_team_space'] = ['error' => $e->getMessage()];
                        }
                    }
                } catch (\Exception $e) {
                    $results['member_account'] = ['error' => $e->getMessage()];
                }
            }
        }
    } catch (\Exception $e) {
        $results['team_members'] = ['error' => $e->getMessage()];
    }
    
    return response()->json($results, 200, [], JSON_PRETTY_PRINT);
});

/**
 * Cleanup duplicate video records in database
 * - Keeps the oldest record for each unique dropbox_path
 * - Deletes all newer duplicates
 * Usage: GET /cleanup-duplicate-videos?execute=true
 * Without execute=true, it will only show what would be deleted (dry-run)
 */
Route::get('/cleanup-duplicate-videos', function (Request $request) {
    try {
        if (!auth()->check()) {
            return response()->json(['error' => 'Please login first'], 401);
        }

        $execute = $request->get('execute', false) === 'true';

        // Find all duplicate dropbox_paths
        $duplicates = \Illuminate\Support\Facades\DB::table('videos')
            ->select('dropbox_path', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'), \Illuminate\Support\Facades\DB::raw('MIN(id) as keep_id'))
            ->groupBy('dropbox_path')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No duplicate videos found!',
                'duplicates_found' => 0
            ]);
        }

        $details = [];
        $totalDuplicates = 0;
        $deletedCount = 0;

        foreach ($duplicates as $duplicate) {
            $duplicateCount = $duplicate->count - 1;
            $totalDuplicates += $duplicateCount;

            $videos = \App\Models\Video::where('dropbox_path', $duplicate->dropbox_path)
                ->orderBy('id')
                ->get();

            $videoDetails = [
                'path' => $duplicate->dropbox_path,
                'total_records' => $duplicate->count,
                'duplicate_count' => $duplicateCount,
                'records' => []
            ];

            foreach ($videos as $video) {
                $willKeep = $video->id == $duplicate->keep_id;
                $videoDetails['records'][] = [
                    'id' => $video->id,
                    'status' => $video->processing_status,
                    'created_at' => $video->created_at,
                    'action' => $willKeep ? 'KEEP (oldest)' : 'DELETE'
                ];
            }

            if ($execute) {
                $deleted = \App\Models\Video::where('dropbox_path', $duplicate->dropbox_path)
                    ->where('id', '>', $duplicate->keep_id)
                    ->delete();
                
                $deletedCount += $deleted;
                $videoDetails['deleted'] = $deleted;

                Log::info('Deleted duplicate videos via web', [
                    'dropbox_path' => $duplicate->dropbox_path,
                    'kept_id' => $duplicate->keep_id,
                    'deleted_count' => $deleted,
                    'user_id' => auth()->id()
                ]);
            }

            $details[] = $videoDetails;
        }

        return response()->json([
            'success' => true,
            'mode' => $execute ? 'EXECUTED' : 'DRY-RUN',
            'message' => $execute 
                ? "Deleted {$deletedCount} duplicate records" 
                : "Found {$totalDuplicates} duplicates. Add ?execute=true to delete them.",
            'summary' => [
                'duplicate_paths' => $duplicates->count(),
                'total_duplicates' => $totalDuplicates,
                'deleted' => $execute ? $deletedCount : 0
            ],
            'details' => $details,
            'usage' => $execute ? null : 'Add ?execute=true to the URL to actually delete duplicates'
        ], 200, [], JSON_PRETTY_PRINT);

    } catch (\Exception $e) {
        Log::error('Error cleaning up duplicate videos', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});

/**
 * List available videos in Dropbox, compare with DB, auto-process new videos, and delete orphaned DB records
 * - Checks for videos in Dropbox that aren't in DB (needs processing)
 * - Checks for videos in DB that aren't in Dropbox anymore (deletes them)
 * Usage: GET /list-videos?auto_process=true
 */
Route::get('/list-videos', function (Request $request) {
    try {
        if (!auth()->check()) {
            return response()->json(['error' => 'Please login first'], 401);
        }

        $videoService = new \App\Services\VideoProcessingService(auth()->user());
        $dropboxVideos = $videoService->getAvailableVideos();
        Log::info("Found " . count($dropboxVideos) . " videos in Dropbox");
        // Get ALL videos from DB (no limit) for accurate comparison, fetching only needed columns to prevent OOM
        $userVideos = $videoService->getUserVideos(null, 10000, ['id', 'title', 'dropbox_path', 'processing_status', 'youtube_url']);

        // Extract Dropbox video paths for comparison
        $dropboxPaths = [];
        foreach ($dropboxVideos as $dropboxVideo) {
            $dropboxPaths[] = $dropboxVideo['path'];
        }

        // Extract DB video paths for comparison
        $dbVideoPaths = [];
        foreach ($userVideos as $video) {
            $dbVideoPaths[] = $video->dropbox_path;
        }

        // Check for videos in DB that are no longer in Dropbox and delete them
        $deletedVideos = [];
        foreach ($userVideos as $video) {
            if (!in_array($video->dropbox_path, $dropboxPaths)) {
                try {
                    Log::info("Deleting video from DB (no longer in Dropbox): {$video->dropbox_path}", [
                        'video_id' => $video->id,
                        'title' => $video->title
                    ]);
                    
                    $deletedVideos[] = [
                        'id' => $video->id,
                        'title' => $video->title,
                        'dropbox_path' => $video->dropbox_path,
                        'status' => 'deleted'
                    ];
                    
                    // Delete the video record from database
                    $video->delete();
                    
                } catch (\Exception $e) {
                    Log::error("Failed to delete video from DB: {$video->dropbox_path}", [
                        'video_id' => $video->id,
                        'error' => $e->getMessage()
                    ]);
                    
                    $deletedVideos[] = [
                        'id' => $video->id,
                        'title' => $video->title,
                        'dropbox_path' => $video->dropbox_path,
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        if (!empty($deletedVideos)) {
            Log::info("Deleted " . count(array_filter($deletedVideos, fn($v) => $v['status'] === 'deleted')) . " videos from DB that no longer exist in Dropbox");
        }

        // Compare Dropbox videos with DB
        $needsProcessing = [];
        $alreadyProcessed = [];

        foreach ($dropboxVideos as $dropboxVideo) {
            $path = $dropboxVideo['path'];
            
            // Find video in DB by path
            $existingVideo = $userVideos->firstWhere('dropbox_path', $path);
            
            if ($existingVideo) {
                // Video exists - check if it's completed
                if ($existingVideo->processing_status === 'completed') {
                    $alreadyProcessed[] = array_merge($dropboxVideo, [
                        'db_id' => $existingVideo->id,
                        'status' => 'completed',
                        'youtube_url' => $existingVideo->youtube_url
                    ]);
                } else {
                    // Video exists but not completed (failed, pending, or processing)
                    // Add to needs processing for retry
                    $needsProcessing[] = array_merge($dropboxVideo, [
                        'db_id' => $existingVideo->id,
                        'status' => $existingVideo->processing_status,
                        'action' => 'retry'
                    ]);
                }
            } else {
                // Video doesn't exist in DB - needs processing
                $needsProcessing[] = array_merge($dropboxVideo, [
                    'action' => 'new'
                ]);
            }
        }

        $processedVideos = [];
        $syncedVideos = [];
        $autoProcess = $request->get('auto_process', false);
        
        // Convert string 'false' to boolean false
        if ($autoProcess === 'false' || $autoProcess === '0') {
            $autoProcess = false;
        }

        // Sync only mode (auto_process=false): Create video records without processing
        if (!$autoProcess && !empty($needsProcessing)) {
            Log::info("Starting sync-only mode for " . count($needsProcessing) . " videos (no processing)");
            
            foreach ($needsProcessing as $index => $video) {
                // Skip if video already exists in DB (has db_id)
                if (isset($video['db_id'])) {
                    $syncedVideos[] = [
                        'path' => $video['path'],
                        'name' => $video['filename'] ?? basename($video['path']),
                        'status' => 'pending_exists', // Mark existing pending videos differently
                        'video_id' => $video['db_id']
                    ];
                    continue;
                }
                
                try {
                    // Extract clean title from filename (without extension)
                    $filename = $video['filename'] ?? basename($video['path']);
                    $cleanTitle = pathinfo($filename, PATHINFO_FILENAME);
                    
                    // Extract proper extension
                    $extension = 'mp4'; // default
                    if (preg_match('/\.([a-zA-Z0-9]{2,4})(?:\s*\[.*\])?$/', $filename, $matches)) {
                        $extension = strtolower($matches[1]);
                    } elseif (preg_match('/\.([a-zA-Z0-9]{2,4})$/', $filename, $matches)) {
                        $extension = strtolower($matches[1]);
                    }
                    
                    // Create video record WITHOUT dispatching processing job
                    $newVideo = \App\Models\Video::create([
                        'user_id' => auth()->id(),
                        'dropbox_path' => $video['path'],
                        'filename' => $filename,
                        'extension' => $extension,
                        'title' => $cleanTitle,
                        'description' => 'Synced from Dropbox - pending approval',
                        'processing_status' => 'pending',
                        'approval_status' => 'pending', // New videos need approval
                    ]);
                    
                    $syncedVideos[] = [
                        'path' => $video['path'],
                        'name' => $filename,
                        'status' => 'synced',
                        'video_id' => $newVideo->id
                    ];
                    
                    Log::info("Synced video (pending approval): {$video['path']}", ['video_id' => $newVideo->id]);
                } catch (\Exception $e) {
                    $syncedVideos[] = [
                        'path' => $video['path'],
                        'name' => $video['filename'] ?? basename($video['path']),
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error("Failed to sync video: {$video['path']}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            Log::info("Sync completed. Total synced: " . count(array_filter($syncedVideos, fn($v) => $v['status'] === 'synced')));
        }

        // Auto-process videos if requested (auto_process=true)
        if ($autoProcess && !empty($needsProcessing)) {
            Log::info("Starting auto-process for " . count($needsProcessing) . " videos");
            
            foreach ($needsProcessing as $index => $video) {
                try {
                    Log::info("Processing video " . ($index + 1) . "/" . count($needsProcessing) . ": {$video['path']}");
                    
                    // Extract clean title from filename (without extension)
                    $filename = $video['filename'] ?? basename($video['path']);
                    $cleanTitle = pathinfo($filename, PATHINFO_FILENAME);
                    
                    $result = $videoService->processVideo(
                        $video['path'],
                        $cleanTitle,
                        'Auto-processed from Dropbox sync'
                    );
                    
                    $processedVideos[] = [
                        'path' => $video['path'],
                        'name' => $video['filename'] ?? basename($video['path']),
                        'status' => 'queued',
                        'video_id' => $result['video_id'] ?? null
                    ];
                    
                    Log::info("Queued video for processing: {$video['path']}", ['video_id' => $result['video_id'] ?? null]);
                } catch (\Exception $e) {
                    $processedVideos[] = [
                        'path' => $video['path'],
                        'name' => $video['filename'] ?? basename($video['path']),
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error("Failed to queue video: {$video['path']}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
            
            Log::info("Auto-process completed. Total queued: " . count(array_filter($processedVideos, fn($v) => $v['status'] === 'queued')));
        }

        $message = $autoProcess 
            ? count($processedVideos) . ' video(s) queued for processing'
            : count(array_filter($syncedVideos, fn($v) => $v['status'] === 'synced')) . ' video(s) synced and pending approval';

        return response()->json([
            'success' => true,
            'message' => $message,
            'summary' => [
                'total_in_dropbox' => count($dropboxVideos),
                'already_processed' => count($alreadyProcessed),
                'needs_processing' => count($needsProcessing),
                'synced_pending_approval' => count(array_filter($syncedVideos, fn($v) => $v['status'] === 'synced')),
                'existing_pending_approval' => count(array_filter($syncedVideos, fn($v) => $v['status'] === 'pending_exists')),
                'queued_for_processing' => count($processedVideos),
                'deleted_from_db' => count(array_filter($deletedVideos, fn($v) => $v['status'] === 'deleted')),
                'failed_to_delete' => count(array_filter($deletedVideos, fn($v) => $v['status'] === 'failed')),
            ],
            'needs_processing' => $needsProcessing,
            'already_processed' => $alreadyProcessed,
            'deleted_videos' => !empty($deletedVideos) ? $deletedVideos : null,
            'synced_videos' => !$autoProcess ? $syncedVideos : null,
            'processing_results' => $autoProcess ? $processedVideos : null,
            'usage' => [
                'sync_only' => 'GET /list-videos?auto_process=false (creates records, pending approval)',
                'auto_process' => 'GET /list-videos?auto_process=true (creates and processes immediately)',
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage()
        ], 500);
    }
})->name('test.list.videos');

// Primary search endpoint (POST with JSON body)
Route::post('/api/videos/embeddings/search', [VideoController::class, 'embeddingsSearch'])->name('videos.embeddings.search');

// GET endpoint for simple testing
Route::get('/api/search', [VideoController::class, 'embeddingsSearch'])->name('api.search.embeddings');


Route::post('youtube/validate', [YoutubeController::class, 'validateYouTubeUrl'])->name('user.youtube.validate');
Route::post('/videoLink',[VideoController::class,'dropboxVideoTemporaryLink'])->name('video.link');
Route::post('/getVideoDetails',[YoutubeController::class,'getVideoDetails'])->name('user.youtube.details');

// ========================================
// VOICE SAMPLE PUBLIC ACCESS ROUTE
// ========================================
Route::get('/voice-samples/{filename}', function ($filename) {
    // Try voice_samples directory first
    $path = storage_path('app/public/voice_samples/' . $filename);

    // If not found, try temp directory
    if (!file_exists($path)) {
        $path = storage_path('app/temp/' . $filename);
    }

    if (!file_exists($path)) {
        abort(404, 'Voice sample not found');
    }

    return response()->file($path);
})->name('voice.sample.view');

Route::get('/ffmpeg-check', function () {
    return shell_exec('ffmpeg -version');
});

// Test route for clip generation (no auth required)
Route::post('/test/clips/generate', [YoutubeController::class, 'generateClip'])->name('test.clips.generate');

// Public clip download/stream routes (no auth required - shareable links)
Route::get('/clips/download/{filename}', [YoutubeController::class, 'downloadClip'])->name('public.clips.download');
Route::get('/clips/stream/{filename}', [YoutubeController::class, 'streamClip'])->name('public.clips.stream');

// Webhook route for Pyannote voiceprint callback (outside auth middleware)
Route::post('/webhook/pyannote/voiceprint', [AdminVoiceSampleController::class, 'handleVoiceprintWebhook'])
    ->name('webhook.pyannote.voiceprint');

// Webhook route for Pyannote speaker identification callback (outside auth middleware)
Route::post('/webhook/pyannote/identify', [AdminVoiceSampleController::class, 'handleIdentifyWebhook'])
    ->name('webhook.pyannote.identify');

// Dropbox webhook routes (outside auth middleware)
Route::match(['get', 'post'], '/webhook/dropbox', [\App\Http\Controllers\DropboxWebhookController::class, 'handleWebhook'])
    ->name('webhook.dropbox');

// Credentials page password verification
Route::post('/verify-creds-password', function (Request $request) {
    $password = $request->input('password');
    $correctPassword = 'ClipMatters!9X#Q4@C';
    
    if ($password === $correctPassword) {
        Log::info('Credentials page password verified successfully for IP: ' . $request->ip());
        return response()->json([
            'success' => true,
            'message' => 'Password verified successfully'
        ]);
    }
    
    return response()->json([
        'success' => false,
        'message' => 'Incorrect password'
    ], 401);
})->name('verify.creds.password');

require __DIR__ . '/auth.php';