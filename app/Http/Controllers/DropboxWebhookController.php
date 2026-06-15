<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\DropboxService;
use App\Models\User;
use App\Models\Video;
use App\Models\DropboxDeletion;
use Illuminate\Support\Facades\Http;

class DropboxWebhookController extends Controller
{
    /**
     * Handle Dropbox webhook - both verification (GET) and notifications (POST)
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        if ($request->isMethod('get')) {
            return $this->verify($request);
        }
        
        return $this->webhook($request);
    }

    /**
     * Handle Dropbox webhook verification (GET request)
     * 
     * When registering a webhook URI, Dropbox sends a GET request with a 'challenge' parameter.
     * Your app must echo back the challenge parameter.
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function verify(Request $request)
    {
        $challenge = $request->query('challenge');

        if (!$challenge) {
            Log::warning('Dropbox webhook verification failed: no challenge parameter');
            return response('Missing challenge parameter', 400);
        }

        Log::info('Dropbox webhook verification successful', [
            'challenge' => $challenge
        ]);

        // Echo back the challenge with required headers
        return response($challenge, 200)
            ->header('Content-Type', 'text/plain')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Handle Dropbox webhook notification (POST request)
     * 
     * Dropbox sends POST requests when user files change.
     * The request includes an X-Dropbox-Signature header for HMAC-SHA256 validation.
     * 
     * Payload format:
     * {
     *   "list_folder": {
     *     "accounts": ["dbid:AAH4f99T0taONIb-OurWxbNQ6ywGRopQngc", ...]
     *   },
     *   "delta": {
     *     "users": [12345678, 23456789, ...]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function webhook(Request $request)
    {
        // Get the signature from headers
        $signature = $request->header('X-Dropbox-Signature');
        
        // Get the raw request body
        $body = $request->getContent();

        // Validate HMAC signature
        $appSecret = env('DROPBOX_APP_SECRET');
        
        if (!$appSecret) {
            Log::error('Dropbox webhook: DROPBOX_APP_SECRET not configured');
            return response('Server configuration error', 500);
        }

        $expectedSignature = hash_hmac('sha256', $body, $appSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Dropbox webhook: Invalid signature', [
                'expected' => $expectedSignature,
                'received' => $signature
            ]);
            return response('Invalid signature', 403);
        }

        // Parse the JSON payload
        $payload = json_decode($body, true);

        if (!$payload) {
            Log::error('Dropbox webhook: Invalid JSON payload');
            return response('Invalid payload', 400);
        }

        // Log full payload in pretty JSON format
        Log::info('Dropbox webhook FULL PAYLOAD (pretty print):', [
            'payload' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ]);

        // Handle both personal and team account formats
        $accounts = $payload['list_folder']['accounts'] ?? [];
        $teams = $payload['list_folder']['teams'] ?? [];
        $users = $payload['delta']['users'] ?? [];
        $deltaTeams = $payload['delta']['teams'] ?? [];

        Log::info('Dropbox webhook notification received', [
            'personal_accounts_count' => count($accounts),
            'team_accounts_count' => count($teams),
            'legacy_users_count' => count($users),
            'delta_teams_count' => count($deltaTeams),
            'is_team_account' => !empty($teams) || !empty($deltaTeams),
            'full_structure' => [
                'list_folder' => [
                    'accounts' => $accounts,
                    'teams' => $teams
                ],
                'delta' => [
                    'users' => $users,
                    'teams' => $deltaTeams
                ]
            ]
        ]);

        // Process personal accounts (list_folder format)
        foreach ($accounts as $accountId) {
            $this->processAccountAsync($accountId);
        }

        // Merge team members from both list_folder.teams and delta.teams to avoid duplicates
        $allTeamMembers = [];
        foreach ($teams as $teamId => $memberIds) {
            foreach ($memberIds as $memberId) {
                $allTeamMembers[$teamId . '|' . $memberId] = [$teamId, $memberId];
            }
        }
        foreach ($deltaTeams as $teamId => $memberIds) {
            foreach ($memberIds as $memberId) {
                $allTeamMembers[$teamId . '|' . $memberId] = [$teamId, $memberId];
            }
        }

        // Process deduplicated team members
        foreach ($allTeamMembers as [$teamId, $memberId]) {
            $this->processTeamMemberAsync($teamId, $memberId);
        }

        // Process legacy personal users (delta format)
        foreach ($users as $userId) {
            $this->processUserAsync($userId);
        }

        // Respond quickly (within 10 seconds as required by Dropbox)
        return response('', 200);
    }

    /**
     * Process account changes asynchronously
     * 
     * @param string $accountId Dropbox account ID (dbid:xxx format)
     * @return void
     */
    protected function processAccountAsync($accountId)
    {
        Log::info('Processing Dropbox account changes', [
            'account_id' => $accountId
        ]);

        // Dispatch a job to process the account changes
        // This allows us to respond quickly to Dropbox
        dispatch(function() use ($accountId) {
            try {
                $this->processAccount($accountId);
            } catch (\Exception $e) {
                Log::error('Error processing Dropbox account', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        })->afterResponse();
    }

    /**
     * Process team member changes asynchronously
     * 
     * @param string $teamId Dropbox team ID (dbtid:xxx format)
     * @param string $memberId Dropbox team member ID (dbmid:xxx format)
     * @return void
     */
    protected function processTeamMemberAsync($teamId, $memberId)
    {
        Log::info('Processing Dropbox team member changes', [
            'team_id' => $teamId,
            'member_id' => $memberId
        ]);

        // Dispatch a job to process the team member changes
        dispatch(function() use ($teamId, $memberId) {
            try {
                $this->processTeamMember($teamId, $memberId);
            } catch (\Exception $e) {
                Log::error('Error processing Dropbox team member', [
                    'team_id' => $teamId,
                    'member_id' => $memberId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        })->afterResponse();
    }

    /**
     * Process user changes asynchronously (legacy delta format)
     * 
     * @param int $userId Legacy Dropbox user ID
     * @return void
     */
    protected function processUserAsync($userId)
    {
        Log::info('Processing Dropbox user changes (legacy)', [
            'user_id' => $userId
        ]);

        // Dispatch a job to process the user changes
        dispatch(function() use ($userId) {
            try {
                $this->processUser($userId);
            } catch (\Exception $e) {
                Log::error('Error processing Dropbox user', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        })->afterResponse();
    }

    /**
     * Process account changes using list_folder/continue
     * 
     * @param string $accountId Dropbox account ID
     * @return void
     */
    protected function processAccount($accountId)
    {
        Log::info('Processing account file changes', [
            'account_id' => $accountId
        ]);

        // TODO: Implement your business logic here
        // 1. Find the user by Dropbox account ID
        // 2. Get their cursor (stored from previous sync)
        // 3. Call /files/list_folder/continue with the cursor
        // 4. Process the changed files
        // 5. Update the cursor for next time
        
        // Example:
        // $user = User::where('dropbox_account_id', $accountId)->first();
        // if (!$user) {
        //     Log::warning('User not found for account', ['account_id' => $accountId]);
        //     return;
        // }
        
        // $dropboxService = new DropboxService($user);
        // $cursor = $user->dropbox_cursor;
        // 
        // if ($cursor) {
        //     $result = $dropboxService->listFolderContinue($cursor);
        // } else {
        //     $result = $dropboxService->listFolder('');
        // }
        // 
        // foreach ($result['entries'] as $entry) {
        //     // Process each changed file
        //     Log::info('File changed', [
        //         'path' => $entry['path_display'],
        //         'type' => $entry['.tag']
        //     ]);
        // }
        // 
        // // Update cursor
        // $user->update(['dropbox_cursor' => $result['cursor']]);
    }

    /**
     * Process team member changes using list_folder/continue
     * 
     * @param string $teamId Dropbox team ID
     * @param string $memberId Dropbox team member ID
     * @return void
     */
    protected function processTeamMember($teamId, $memberId)
    {
        Log::info('Processing team member file changes', [
            'team_id' => $teamId,
            'member_id' => $memberId
        ]);

        try {
            // Find user by team member ID
            $user = User::where('dropbox_team_member_id', $memberId)->first();
            
            if (!$user) {
                Log::warning('No user found for team member. Skipping to avoid 422 no_permission error.', [
                    'team_id' => $teamId,
                    'member_id' => $memberId
                ]);
                return;
            }
            
            // Get Dropbox access token
            $token = $user->getDropboxAccessToken();
            if (!$token) {
                Log::error('No valid Dropbox token available', [
                    'user_id' => $user->id
                ]);
                return;
            }
            
            // Get stored cursor for this user
            $cursor = $user->dropbox_cursor;
            
            Log::info('Fetching Dropbox changes', [
                'user_id' => $user->id,
                'has_cursor' => !empty($cursor),
                'cursor_preview' => $cursor ? substr($cursor, 0, 50) . '...' : 'null'
            ]);
            
            // Fetch file changes from Dropbox
            $changes = $this->fetchDropboxChanges($token, $cursor, $memberId, $user->dropbox_root_namespace_id);
            
            // If cursor was invalid (from a different app), clear it and retry with a fresh list_folder
            if (is_array($changes) && !empty($changes['reset_cursor'])) {
                Log::info('Resetting stale Dropbox cursor and retrying', ['user_id' => $user->id]);
                $user->update(['dropbox_cursor' => null]);
                $changes = $this->fetchDropboxChanges($token, null, $memberId, $user->dropbox_root_namespace_id);
            }
            
            if (!$changes || !empty($changes['reset_cursor'])) {
                Log::warning('Failed to fetch Dropbox changes');
                return;
            }
            
            Log::info('Dropbox changes fetched', [
                'entries_count' => count($changes['entries'] ?? []),
                'has_more' => $changes['has_more'] ?? false,
                'new_cursor' => substr($changes['cursor'] ?? '', 0, 50) . '...'
            ]);
            
            // Process each change
            $deletedCount = 0;
            $addedCount = 0;
            $modifiedCount = 0;
            
            foreach ($changes['entries'] as $entry) {
                $tag = $entry['.tag'] ?? '';
                $path = $entry['path_display'] ?? $entry['path_lower'] ?? '';
                
                Log::info('Processing Dropbox entry', [
                    'tag' => $tag,
                    'path' => $path,
                    'name' => $entry['name'] ?? 'N/A'
                ]);
                
                if ($tag === 'deleted') {
                    // File was deleted
                    $this->handleDeletion($user, $entry);
                    $deletedCount++;
                } elseif ($tag === 'file') {
                    // File was added or modified
                    if (isset($entry['server_modified'])) {
                        $modifiedCount++;
                    } else {
                        $addedCount++;
                    }
                    Log::info('File added/modified', [
                        'path' => $path,
                        'size' => $entry['size'] ?? 0
                    ]);
                }
            }
            
            // Update cursor
            if (isset($changes['cursor'])) {
                $user->update(['dropbox_cursor' => $changes['cursor']]);
                Log::info('Updated cursor for user', [
                    'user_id' => $user->id,
                    'cursor_preview' => substr($changes['cursor'], 0, 50) . '...'
                ]);
            }
            
            Log::info('Dropbox sync completed', [
                'user_id' => $user->id,
                'deleted' => $deletedCount,
                'added' => $addedCount,
                'modified' => $modifiedCount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in processTeamMember', [
                'team_id' => $teamId,
                'member_id' => $memberId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Fetch file changes from Dropbox
     */
    protected function fetchDropboxChanges($token, $cursor, $memberId, $rootNamespaceId)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];
        
        // Add team headers
        if ($memberId) {
            $headers['Dropbox-API-Select-User'] = $memberId;
        }
        if ($rootNamespaceId) {
            $headers['Dropbox-API-Path-Root'] = json_encode([
                '.tag' => 'namespace_id',
                'namespace_id' => $rootNamespaceId
            ]);
        }
        
        try {
            if ($cursor) {
                // Continue from last cursor
                $response = Http::withHeaders($headers)
                    ->timeout(60)
                    ->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                        'cursor' => $cursor
                    ]);
            } else {
                // First time - list root folder
                $response = Http::withHeaders($headers)
                    ->timeout(60)
                    ->post('https://api.dropboxapi.com/2/files/list_folder', [
                        'path' => '',
                        'recursive' => true,
                        'include_deleted' => true, // Important: to detect deletions
                        'include_mounted_folders' => true
                    ]);
            }
            
            if (!$response->successful()) {
                $body = $response->body();
                Log::error('Dropbox API error', [
                    'status' => $response->status(),
                    'body' => $body
                ]);
                
                // If cursor is invalid (e.g. from a different app), signal to reset it
                if ($response->status() === 400 && str_contains($body, 'cursor')) {
                    Log::warning('Dropbox cursor is invalid/stale - will be reset');
                    return ['reset_cursor' => true];
                }
                
                return null;
            }
            
            return $response->json();
            
        } catch (\Exception $e) {
            Log::error('Exception fetching Dropbox changes', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Handle file deletion
     */
    protected function handleDeletion($user, $entry)
    {
        $path = $entry['path_display'] ?? $entry['path_lower'] ?? '';
        $filename = $entry['name'] ?? basename($path);
        
        Log::info('🗑️ FILE DELETED IN DROPBOX', [
            'path' => $path,
            'filename' => $filename,
            'user_id' => $user->id,
            'timestamp' => now()->toDateTimeString()
        ]);
        
        // Find the video in database
        $video = Video::where('user_id', $user->id)
            ->where('dropbox_path', $path)
            ->first();
        
        // Store video details before deletion
        $videoDetails = null;
        if ($video) {
            $videoDetails = [
                'video_id' => $video->id,
                'title' => $video->title,
                'youtube_url' => $video->youtube_url,
                'youtube_video_id' => $video->youtube_video_id,
                'processing_status' => $video->processing_status,
                'transcript_text' => $video->transcript_text ? substr($video->transcript_text, 0, 200) : null,
                'speakers_count' => $video->speakers_count,
                'audio_duration_seconds' => $video->audio_duration_seconds,
                'approval_status' => $video->approval_status,
                'created_at' => $video->created_at?->toDateTimeString(),
            ];
            
            Log::info('📹 VIDEO FOUND - WILL BE DELETED', [
                'video_id' => $video->id,
                'video_title' => $video->title,
                'youtube_url' => $video->youtube_url,
                'processing_status' => $video->processing_status,
                'approval_status' => $video->approval_status
            ]);
        }
        
        // Create deletion log (before deleting the video)
        $deletion = DropboxDeletion::create([
            'user_id' => $user->id,
            'video_id' => $video?->id,
            'dropbox_path' => $path,
            'filename' => $filename,
            'file_type' => $this->getFileType($filename),
            'metadata' => [
                'entry' => $entry,
                'video_details' => $videoDetails,
            ],
            'processed' => false,
            'deleted_at' => now()
        ]);
        
        Log::info('✅ Deletion logged in database', [
            'deletion_id' => $deletion->id,
            'dropbox_path' => $path,
            'video_found' => !is_null($video),
            'video_id' => $video?->id ?? 'N/A'
        ]);
        
        // Delete the video from database
        if ($video) {
            try {
                $videoId = $video->id;
                $videoTitle = $video->title;
                
                // Delete the video
                $video->delete();
                
                // Mark deletion as processed
                $deletion->update(['processed' => true]);
                
                Log::info('🗑️✅ VIDEO DELETED FROM SYSTEM', [
                    'video_id' => $videoId,
                    'video_title' => $videoTitle,
                    'deletion_id' => $deletion->id,
                    'dropbox_path' => $path,
                    'timestamp' => now()->toDateTimeString()
                ]);
                
            } catch (\Exception $e) {
                Log::error('❌ ERROR DELETING VIDEO', [
                    'video_id' => $video->id,
                    'video_title' => $video->title,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            Log::warning('⚠️ NO VIDEO FOUND IN DATABASE', [
                'dropbox_path' => $path,
                'filename' => $filename,
                'message' => 'File deleted from Dropbox but no matching video in database'
            ]);
            
            // Still mark as processed since there's nothing to delete
            $deletion->update(['processed' => true]);
        }
    }
    
    /**
     * Get file type from filename
     */
    protected function getFileType($filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $videoExtensions = ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm', 'mts', 'm4v'];
        $audioExtensions = ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a'];
        
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        } elseif (in_array($extension, $audioExtensions)) {
            return 'audio';
        }
        
        return 'other';
    }

    /**
     * Process user changes (legacy delta format)
     * 
     * @param int $userId Legacy Dropbox user ID
     * @return void
     */
    protected function processUser($userId)
    {
        Log::info('Processing user file changes (legacy)', [
            'user_id' => $userId
        ]);

        // TODO: Implement legacy user processing if needed
        // This is for older Dropbox API integrations
    }
}
