<?php

namespace App\Services;

use App\Models\User;
use App\Models\Video;
use App\Jobs\ProcessVideoJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class VideoProcessingService
{
    protected $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Start processing a video from Dropbox
     * 
     * @param string $dropboxVideoPath Path to video in Dropbox
     * @param string|null $title Video title
     * @param string|null $description Video description
     * @return array Job information
     */
    public function processVideo(string $dropboxVideoPath, ?string $title = null, ?string $description = null): array
    {
        // Validate user has required connections
        $this->validateUserConnections();

        // Verify file exists in Dropbox and is accessible
        $dropboxService = new DropboxService($this->user);
        try {
            $fileExists = $dropboxService->fileExists($dropboxVideoPath);
            if (!$fileExists) {
                throw new \Exception("Video file not found in Dropbox: {$dropboxVideoPath}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to verify Dropbox file', [
                'user_id' => $this->user->id,
                'path' => $dropboxVideoPath,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Cannot access video file in Dropbox: " . $e->getMessage());
        }

        // Extract proper extension (handle filenames with brackets like [video_id])
        $filename = basename($dropboxVideoPath);
        $extension = 'mp4'; // default
        if (preg_match('/\.([a-zA-Z0-9]{2,4})(?:\s*\[.*\])?$/', $filename, $matches)) {
            $extension = strtolower($matches[1]);
        } elseif (preg_match('/\.([a-zA-Z0-9]{2,4})$/', $filename, $matches)) {
            $extension = strtolower($matches[1]);
        }

        // Check if video already exists in database
        // For admin roles, check globally (not per-user) since all admins share the same video pool
        if ($this->user->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            $video = Video::where('dropbox_path', $dropboxVideoPath)->first();
        } else {
            // Regular users check only their own videos
            $video = Video::where('dropbox_path', $dropboxVideoPath)
                ->where('user_id', $this->user->id)
                ->first();
        }

        if ($video) {
            // Video already exists - check status
            if ($video->processing_status === 'completed') {
                Log::info('Video already processed successfully, skipping', [
                    'video_id' => $video->id,
                    'dropbox_path' => $dropboxVideoPath
                ]);
                
                return [
                    'status' => 'skipped',
                    'message' => 'Video already processed successfully',
                    'video_id' => $video->id,
                    'user_id' => $this->user->id,
                    'video_path' => $dropboxVideoPath,
                ];
            } else {
                // Video exists but failed or pending - reset it for retry
                Log::info('Video exists with status: ' . $video->processing_status . ', retrying processing', [
                    'video_id' => $video->id,
                    'dropbox_path' => $dropboxVideoPath,
                    'previous_status' => $video->processing_status
                ]);
                
                // Reset video status and error
                $video->update([
                    'processing_status' => 'pending',
                    'processing_error' => null,
                    'title' => $title ?? $video->title,
                    'description' => $description ?? $video->description,
                    'filename' => $filename,
                    'extension' => $extension,
                ]);
            }
        } else {
            // Create new video record in database
            $video = Video::create([
                'user_id' => $this->user->id,
                'dropbox_path' => $dropboxVideoPath,
                'filename' => $filename,
                'extension' => $extension,
                'title' => $title ?? 'Video - ' . now()->format('Y-m-d H:i:s'),
                'description' => $description ?? 'Processed by Clip Matters',
                'processing_status' => 'pending',
            ]);
            
            Log::info('Created new video record', [
                'video_id' => $video->id,
                'dropbox_path' => $dropboxVideoPath
            ]);
        }

        // Dispatch the job with video ID
        ProcessVideoJob::dispatch(
            $this->user->id,
            $video->id,
            $dropboxVideoPath,
            $title,
            $description
        );

        Log::info('Video processing job dispatched', [
            'user_id' => $this->user->id,
            'video_id' => $video->id,
            'video_path' => $dropboxVideoPath,
        ]);

        return [
            'status' => 'queued',
            'message' => 'Video processing job dispatched to queue',
            'video_id' => $video->id,
            'user_id' => $this->user->id,
            'video_path' => $dropboxVideoPath,
            'job_dispatched_at' => now()->toDateTimeString(),
            'note' => 'Check Laravel queue worker logs for processing status'
        ];
    }

    /**
     * Process video synchronously (for testing)
     * 
     * @param string $dropboxVideoPath
     * @param string|null $title
     * @param string|null $description
     * @return array Results
     */
    public function processVideoSync(string $dropboxVideoPath, ?string $title = null, ?string $description = null): array
    {
        // Validate user has required connections
        $this->validateUserConnections();

        // Verify file exists in Dropbox and is accessible
        $dropboxService = new DropboxService($this->user);
        try {
            $fileExists = $dropboxService->fileExists($dropboxVideoPath);
            if (!$fileExists) {
                throw new \Exception("Video file not found in Dropbox: {$dropboxVideoPath}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to verify Dropbox file', [
                'user_id' => $this->user->id,
                'path' => $dropboxVideoPath,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Cannot access video file in Dropbox: " . $e->getMessage());
        }

        // Extract proper extension (handle filenames with brackets like [video_id])
        $filename = basename($dropboxVideoPath);
        $extension = 'mp4'; // default
        if (preg_match('/\.([a-zA-Z0-9]{2,4})(?:\s*\[.*\])?$/', $filename, $matches)) {
            $extension = strtolower($matches[1]);
        } elseif (preg_match('/\.([a-zA-Z0-9]{2,4})$/', $filename, $matches)) {
            $extension = strtolower($matches[1]);
        }

        // Check if video already exists in database
        $video = Video::where('dropbox_path', $dropboxVideoPath)
            ->where('user_id', $this->user->id)
            ->first();

        if ($video) {
            // Video already exists - check status
            if ($video->processing_status === 'completed') {
                Log::info('Video already processed successfully, skipping sync processing', [
                    'video_id' => $video->id,
                    'dropbox_path' => $dropboxVideoPath
                ]);
                
                return [
                    'status' => 'skipped',
                    'message' => 'Video already processed successfully',
                    'video_id' => $video->id,
                    'user_id' => $this->user->id,
                ];
            } else {
                // Video exists but failed or pending - reset it for retry
                Log::info('Video exists with status: ' . $video->processing_status . ', retrying sync processing', [
                    'video_id' => $video->id,
                    'dropbox_path' => $dropboxVideoPath,
                    'previous_status' => $video->processing_status
                ]);
                
                // Reset video status and error
                $video->update([
                    'processing_status' => 'pending',
                    'processing_error' => null,
                    'title' => $title ?? $video->title,
                    'description' => $description ?? $video->description,
                    'filename' => $filename,
                    'extension' => $extension,
                ]);
            }
        } else {
            // Create new video record in database
            $video = Video::create([
                'user_id' => $this->user->id,
                'dropbox_path' => $dropboxVideoPath,
                'filename' => $filename,
                'extension' => $extension,
                'title' => $title ?? 'Video - ' . now()->format('Y-m-d H:i:s'),
                'description' => $description ?? 'Processed by Clip Matters',
                'processing_status' => 'pending',
            ]);
            
            Log::info('Created new video record for sync processing', [
                'video_id' => $video->id,
                'dropbox_path' => $dropboxVideoPath
            ]);
        }

        Log::info('Starting synchronous video processing', [
            'user_id' => $this->user->id,
            'video_id' => $video->id,
            'video_path' => $dropboxVideoPath
        ]);

        // Create and execute job immediately
        $job = new ProcessVideoJob(
            $this->user->id,
            $video->id,
            $dropboxVideoPath,
            $title,
            $description
        );

        $job->handle();

        return [
            'status' => 'completed',
            'message' => 'Video processed successfully (sync mode)',
            'video_id' => $video->id,
            'user_id' => $this->user->id,
            'video_path' => $dropboxVideoPath
        ];
    }

    /**
     * Get list of available videos from user's Dropbox
     * 
     * @return array
     */
    public function getAvailableVideos(): array
    {
        if (!$this->user->getDropboxAccessToken()) {
            throw new \Exception('Dropbox not connected');
        }

        $dropboxService = new DropboxService($this->user);
        
        Log::info('Starting Dropbox video scan', ['user_id' => $this->user->id]);
        
        try {
            $files = $dropboxService->listFiles('', true);
        } catch (\Exception $e) {
            Log::error('Failed to list Dropbox files', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Failed to access Dropbox: ' . $e->getMessage());
        }

        if ($files->isEmpty()) {
            Log::warning('No files found in Dropbox', ['user_id' => $this->user->id]);
        }

        $videos = [];
        // Supported video formats:
        // - Common: mp4, webm, avi, mov, mkv, flv, wmv, m4v
        // - Professional/Media House: mts, m2ts, mxf (AVCHD/Broadcast formats)
        // - Mobile: 3gp, 3g2
        $videoExtensions = ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'm4v', 'mts', 'm2ts', 'mxf', '3gp', '3g2'];
        
        $fileCount = 0;
        foreach ($files as $file) {
            $fileCount++;
            if ($file->isFile()) {
                $extension = strtolower(pathinfo($file->path(), PATHINFO_EXTENSION));
                if (in_array($extension, $videoExtensions)) {
                    $sizeBytes = $file->size();
                    $sizeMB = $sizeBytes ? round($sizeBytes / 1024 / 1024, 2) : null;
                    
                    Log::info('Found video file in Dropbox', [
                        'path' => $file->path(),
                        'extension' => $extension,
                        'size_mb' => $sizeMB
                    ]);
                    
                    $videos[] = [
                        'path' => $file->path(),
                        'extension' => $extension,
                        'filename' => basename($file->path()),
                        'size_bytes' => $sizeBytes,
                        'size_mb' => $sizeMB,
                        'size_formatted' => $this->formatFileSize($sizeBytes)
                    ];
                }
            }
        }
        
        Log::info("Dropbox scan complete", [
            'total_files_scanned' => $fileCount,
            'videos_found' => count($videos),
            'total_video_size_mb' => array_sum(array_column($videos, 'size_mb'))
        ]);

        return $videos;
    }

    /**
     * Validate that user has all required API connections
     * 
     * @throws \Exception 
     */
    protected function validateUserConnections(): void
    {
        $errors = [];

        if (!$this->user->getDropboxAccessToken()) {
            $errors[] = 'Dropbox not connected';
        }

        // Google/YouTube is optional - videos will stream from Dropbox if not connected
        // if (!$this->user->getGoogleAccessToken()) {
        //     $errors[] = 'YouTube/Google not connected';
        // }

        if (!$this->user->assemblyai_api_key && !env('ASSEMBLYAI_API_KEY')) {
            $errors[] = 'AssemblyAI API key not configured';
        }

        if (!empty($errors)) {
            throw new \Exception('Missing required connections: ' . implode(', ', $errors));
        }
    }

    /**
     * Get processing status for recent jobs
     *
     * @return array
     */
    public function getRecentProcessingResults(): array
    {
        $cacheKeys = cache()->get('video_processing_keys_' . $this->user->id, []);
        $results = [];

        foreach ($cacheKeys as $key) {
            if ($result = cache()->get($key)) {
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * Get user's videos from database
     * For admin roles (superAdmin, admin, manager), returns ALL videos (shared pool)
     * For regular users, returns only their own videos
     *
     * @param string|null $status Filter by processing status
     * @param int $limit Maximum number of videos to return
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserVideos(?string $status = null, int $limit = 50)
    {
        // Admin roles see ALL videos (shared pool)
        if ($this->user->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            $query = Video::query()->latest();
        } else {
            // Regular users only see their own videos
            $query = $this->user->videos()->latest();
        }

        if ($status) {
            $query->where('processing_status', $status);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get a specific video by ID
     * For admin roles, can access any video. For regular users, only their own.
     *
     * @param int $videoId
     * @return Video|null
     */
    public function getVideo(int $videoId): ?Video
    {
        if ($this->user->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            return Video::find($videoId);
        }
        return $this->user->videos()->find($videoId);
    }

    /**
     * Format file size in human-readable format
     *
     * @param int|null $bytes File size in bytes
     * @return string Formatted size (e.g., "1.5 GB", "250 MB")
     */
    protected function formatFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes === 0) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}
