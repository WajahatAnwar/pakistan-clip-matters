<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\DropboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class YoutubeController extends Controller
{
    
    /**
     * Extract chapters from a YouTube video
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVideoChapters(Request $request)
    {
        $request->validate([
            'video_id' => 'required|string',
        ]);

        $videoId = $request->video_id;
        
        try {
            // Fetch YouTube video page HTML
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])->get("https://www.youtube.com/watch?v={$videoId}");

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch video data',
                    'chapters' => []
                ], 400);
            }

            $html = $response->body();
            
            // Extract chapters from the page
            $chapters = $this->extractChaptersFromHtml($html, $videoId);
            
            if (empty($chapters)) {
                // If no chapters found, create time-based segments
                $chapters = $this->createDefaultChapters($videoId);
            }

            return response()->json([
                'success' => true,
                'chapters' => $chapters,
                'video_id' => $videoId
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching video chapters: ' . $e->getMessage(),
                'chapters' => $this->createDefaultChapters($videoId)
            ], 500);
        }
    }

    /**
     * Extract chapters from YouTube page HTML
     * 
     * @param string $html
     * @param string $videoId
     * @return array
     */
    private function extractChaptersFromHtml($html, $videoId)
    {
        $chapters = [];
        
        // Try to find chapters in the ytInitialData JSON
        if (preg_match('/var ytInitialData = ({.*?});/', $html, $matches)) {
            $jsonData = $matches[1];
            $data = json_decode($jsonData, true);
            
            // Navigate through the complex YouTube data structure
            $engagement = $data['engagementPanels'] ?? [];
            
            foreach ($engagement as $panel) {
                if (isset($panel['engagementPanelSectionListRenderer']['content']['macroMarkersListRenderer']['contents'])) {
                    $markers = $panel['engagementPanelSectionListRenderer']['content']['macroMarkersListRenderer']['contents'];
                    
                    foreach ($markers as $index => $marker) {
                        if (isset($marker['macroMarkersListItemRenderer'])) {
                            $item = $marker['macroMarkersListItemRenderer'];
                            $timeDescription = $item['timeDescription']['simpleText'] ?? '';
                            $title = $item['title']['simpleText'] ?? "Chapter " . ($index + 1);
                            
                            // Convert time to seconds
                            $seconds = $this->timeToSeconds($timeDescription);
                            
                            $chapters[] = [
                                'start' => $seconds,
                                'title' => $title,
                                'thumbnail' => "https://img.youtube.com/vi/{$videoId}/mqdefault.jpg"
                            ];
                        }
                    }
                }
            }
            
            // Add end times to chapters
            for ($i = 0; $i < count($chapters); $i++) {
                if (isset($chapters[$i + 1])) {
                    $chapters[$i]['end'] = $chapters[$i + 1]['start'];
                } else {
                    // Last chapter - we'll set a large number, frontend will adjust
                    $chapters[$i]['end'] = $chapters[$i]['start'] + 300; // +5 minutes as placeholder
                }
            }
        }
        
        return $chapters;
    }

    /**
     * Create default time-based chapters
     * 
     * @param string $videoId
     * @return array
     */
    private function createDefaultChapters($videoId)
    {
        // Try to get video duration from oEmbed
        try {
            $response = Http::get("https://www.youtube.com/oembed", [
                'url' => "https://www.youtube.com/watch?v={$videoId}",
                'format' => 'json'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                // oEmbed doesn't provide duration, so we'll create smart defaults
            }
        } catch (\Exception $e) {
            // Ignore and continue with defaults
        }

        // Create intelligent time-based chapters (every 5 minutes)
        $chapters = [];
        $intervals = [
            ['start' => 0, 'title' => 'Introduction'],
            ['start' => 300, 'title' => 'Chapter 2'],
            ['start' => 600, 'title' => 'Chapter 3'],
            ['start' => 900, 'title' => 'Chapter 4'],
            ['start' => 1200, 'title' => 'Chapter 5'],
            ['start' => 1500, 'title' => 'Chapter 6'],
        ];

        foreach ($intervals as $index => $interval) {
            $nextStart = isset($intervals[$index + 1]) ? $intervals[$index + 1]['start'] : $interval['start'] + 300;
            
            $chapters[] = [
                'start' => $interval['start'],
                'end' => $nextStart,
                'title' => $interval['title'],
                'thumbnail' => "https://img.youtube.com/vi/{$videoId}/mqdefault.jpg"
            ];
        }
        
        return $chapters;
    }

    /**
     * Convert timestamp string to seconds
     * 
     * @param string $time
     * @return int
     */
    private function timeToSeconds($time)
    {
        $parts = array_reverse(explode(':', $time));
        $seconds = 0;
        
        foreach ($parts as $index => $part) {
            $seconds += intval($part) * pow(60, $index);
        }
        
        return $seconds;
    }

    /**
     * Get video information including duration
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVideoInfo(Request $request)
    {
        $request->validate([
            'video_id' => 'required|string',
        ]);

        $videoId = $request->video_id;
        
        try {
            // Use oEmbed to get basic video info
            $response = Http::get("https://www.youtube.com/oembed", [
                'url' => "https://www.youtube.com/watch?v={$videoId}",
                'format' => 'json'
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch video info'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $response->json()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching video info: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a trimmed clip from a video stored in Dropbox
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateClip(Request $request)
    {
        $request->validate([
            'video_id' => 'required|integer',
            'start_time' => 'required|numeric|min:0',
            'end_time' => 'required|numeric|gt:start_time',
        ]);

        $videoId = $request->video_id;
        $startTime = $request->start_time;
        $endTime = $request->end_time;
        $duration = $endTime - $startTime;

        // Limit clip duration to 5 minutes max
        if ($duration > 300) {
            return response()->json([
                'success' => false,
                'message' => 'Clip duration cannot exceed 5 minutes (300 seconds)'
            ], 400);
        }

        try {
            // Get video from database
            $video = Video::find($videoId);
            
            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found'
                ], 404);
            }

            // Check if video has a Dropbox path
            if (!$video->dropbox_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video is not available on Dropbox'
                ], 400);
            }

            // Get the user who owns the video for Dropbox access
            $user = $video->user;
            if (!$user || !$user->getDropboxAccessToken()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dropbox not connected for this video owner'
                ], 400);
            }

            // Initialize Dropbox service
            $dropboxService = new DropboxService($user);

            // Create temp and clips directories if they don't exist
            $tempDir = storage_path('app/temp');
            $clipsDir = storage_path('app/public/clips');
            
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            if (!file_exists($clipsDir)) {
                mkdir($clipsDir, 0755, true);
            }

            // Generate unique filenames
            $uniqueId = Str::uuid()->toString();
            $extension = pathinfo($video->filename, PATHINFO_EXTENSION) ?: 'mp4';
            $tempVideoPath = "{$tempDir}/{$uniqueId}_source.{$extension}";
            $clipFilename = "{$uniqueId}_clip.mp4";
            $clipPath = "{$clipsDir}/{$clipFilename}";

            Log::info('Generating clip', [
                'video_id' => $videoId,
                'dropbox_path' => $video->dropbox_path,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration
            ]);

            // Download video from Dropbox
            $stream = $dropboxService->readStream($video->dropbox_path);
            
            if (!$stream) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to download video from Dropbox'
                ], 500);
            }

            // Save the stream to a temp file
            $tempFile = fopen($tempVideoPath, 'w');
            stream_copy_to_stream($stream, $tempFile);
            fclose($tempFile);
            fclose($stream);

            Log::info('Video downloaded from Dropbox', [
                'temp_path' => $tempVideoPath,
                'file_size' => filesize($tempVideoPath)
            ]);

            // Format time for FFmpeg (HH:MM:SS.mmm)
            $startTimeFormatted = $this->secondsToTimestamp($startTime);
            $durationFormatted = $this->secondsToTimestamp($duration);

            // Build FFmpeg command
            // Using -ss before -i for fast seeking, and -t for duration
            $ffmpegCommand = [
                'ffmpeg',
                '-y',                           // Overwrite output file
                '-ss', $startTimeFormatted,     // Start time (before input for fast seeking)
                '-i', $tempVideoPath,           // Input file
                '-t', $durationFormatted,       // Duration
                '-c:v', 'libx264',              // Video codec
                '-preset', 'fast',              // Encoding preset
                '-crf', '23',                   // Quality (lower = better, 18-28 recommended)
                '-c:a', 'aac',                  // Audio codec
                '-b:a', '128k',                 // Audio bitrate
                '-movflags', '+faststart',      // Enable fast start for web playback
                '-avoid_negative_ts', 'make_zero',
                $clipPath
            ];

            Log::info('Running FFmpeg command', ['command' => implode(' ', $ffmpegCommand)]);

            // Execute FFmpeg
            $process = new Process($ffmpegCommand);
            $process->setTimeout(300); // 5 minutes timeout
            $process->run();

            // Clean up temp source file
            if (file_exists($tempVideoPath)) {
                unlink($tempVideoPath);
            }

            if (!$process->isSuccessful()) {
                Log::error('FFmpeg failed', [
                    'error' => $process->getErrorOutput(),
                    'output' => $process->getOutput()
                ]);

                // Clean up failed clip if it exists
                if (file_exists($clipPath)) {
                    unlink($clipPath);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate clip',
                    'error' => $process->getErrorOutput()
                ], 500);
            }

            Log::info('Clip generated successfully', [
                'clip_path' => $clipPath,
                'clip_size' => filesize($clipPath)
            ]);

            // Generate download URL
            $downloadUrl = url("storage/clips/{$clipFilename}");

            return response()->json([
                'success' => true,
                'message' => 'Clip generated successfully',
                'data' => [
                    'video_id' => $video->id,
                    'video_title' => $video->title ?? $video->filename,
                    'clip_filename' => $clipFilename,
                    'download_url' => $downloadUrl,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration' => $duration,
                    'file_size_mb' => round(filesize($clipPath) / (1024 * 1024), 2)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Clip generation failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Clean up any temp files
            if (isset($tempVideoPath) && file_exists($tempVideoPath)) {
                unlink($tempVideoPath);
            }
            if (isset($clipPath) && file_exists($clipPath)) {
                unlink($clipPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate clip: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate clip by video name/filename
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateClipByName(Request $request)
    {
        $request->validate([
            'video_name' => 'required|string',
            'start_time' => 'required|numeric|min:0',
            'end_time' => 'required|numeric|gt:start_time',
        ]);

        // Find video by filename or title
        $video = Video::where('filename', $request->video_name)
            ->orWhere('title', $request->video_name)
            ->first();

        if (!$video) {
            return response()->json([
                'success' => false,
                'message' => "Video not found with name: {$request->video_name}"
            ], 404);
        }

        // Forward to main clip generation method
        $request->merge(['video_id' => $video->id]);
        return $this->generateClip($request);
    }

    /**
     * List available clips for download
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function listClips()
    {
        $clipsDir = storage_path('app/public/clips');
        
        if (!file_exists($clipsDir)) {
            return response()->json([
                'success' => true,
                'clips' => []
            ]);
        }

        $files = glob("{$clipsDir}/*.mp4");
        $clips = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $clips[] = [
                'filename' => $filename,
                'download_url' => url("clips/download/{$filename}"),
                'size_mb' => round(filesize($file) / (1024 * 1024), 2),
                'created_at' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }

        // Sort by creation time (newest first)
        usort($clips, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return response()->json([
            'success' => true,
            'total' => count($clips),
            'clips' => $clips
        ]);
    }

    /**
     * Delete a clip
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteClip(Request $request)
    {
        $request->validate([
            'filename' => 'required|string'
        ]);

        $filename = basename($request->filename); // Sanitize filename
        $clipPath = storage_path("app/public/clips/{$filename}");

        if (!file_exists($clipPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Clip not found'
            ], 404);
        }

        unlink($clipPath);

        return response()->json([
            'success' => true,
            'message' => 'Clip deleted successfully'
        ]);
    }

    /**
     * Clean up old clips (older than 24 hours)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function cleanupOldClips()
    {
        $clipsDir = storage_path('app/public/clips');
        
        if (!file_exists($clipsDir)) {
            return response()->json([
                'success' => true,
                'deleted_count' => 0
            ]);
        }

        $files = glob("{$clipsDir}/*.mp4");
        $deletedCount = 0;
        $cutoffTime = time() - (24 * 60 * 60); // 24 hours ago

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'deleted_count' => $deletedCount,
            'message' => "Deleted {$deletedCount} old clips"
        ]);
    }

    /**
     * Download a clip file directly
     * 
     * @param string $filename
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function downloadClip($filename)
    {
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $clipPath = storage_path("app/public/clips/{$filename}");

        if (!file_exists($clipPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Clip not found'
            ], 404);
        }

        // Return file as download with proper headers
        return response()->download($clipPath, $filename, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    /**
     * Stream a clip file (for preview/playback)
     * 
     * @param string $filename
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function streamClip($filename)
    {
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $clipPath = storage_path("app/public/clips/{$filename}");

        if (!file_exists($clipPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Clip not found'
            ], 404);
        }

        $fileSize = filesize($clipPath);
        $mimeType = 'video/mp4';

        return response()->file($clipPath, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes'
        ]);
    }

    /**
     * Convert seconds to HH:MM:SS.mmm timestamp format for FFmpeg
     * 
     * @param float $seconds
     * @return string
     */
    private function secondsToTimestamp($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%06.3f', $hours, $minutes, $secs);
    }
    public function validateYouTubeUrl(Request $request)
    {
        $request->validate([
            'youtube_url' => 'required|url',
        ]);

        $youtubeUrl = $request->youtube_url;

        // Regular expression to validate YouTube URL
        $pattern = '/^(https?:\/\/)?(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})([&?].*)?$/';

        if (preg_match($pattern, $youtubeUrl, $matches)) {
            $videoId = $matches[4];
            Log::info('Valid YouTube URL format', ['url' => $youtubeUrl, 'video_id' => $videoId]);
            
            // Check if this YouTube URL exists in the videos table
            $video = Video::where('youtube_url', $youtubeUrl)->first();
            
            if ($video) {
                Log::info('Video found in database', ['video_id' => $video->id, 'video_name' => $video->filename]);
                
                // Parse speakers_data if it's a JSON string
                $speakersData = $video->speakers_data;
                if (is_string($speakersData)) {
                    // Use JSON_UNESCAPED_UNICODE to properly decode Unicode characters
                    $speakersData = json_decode($speakersData, true);
                }
                
                // Get speaker mapping for actual speaker names
                $speakerMapping = $video->speaker_mapping ?? [];
                if (is_string($speakerMapping)) {
                    $speakerMapping = json_decode($speakerMapping, true) ?? [];
                }
                
                // Extract and format speaker segments
                $formattedSpeakers = [];
                if (is_array($speakersData)) {
                    foreach ($speakersData as $segment) {
                        // Decode the text to display actual Unicode characters (Urdu/Arabic text)
                        $text = $segment['text'] ?? '';
                        $speakerLabel = $segment['speaker'] ?? 'Unknown';
                        
                        // Map speaker label to actual speaker name if available
                        $actualSpeaker = $speakerMapping[$speakerLabel] ?? $speakerLabel;
                        
                        $formattedSpeakers[] = [
                            'speaker' => $speakerLabel,
                            'actualSpeaker' => $actualSpeaker,  // Include mapped speaker name
                            'text' => $text, // This will show actual Urdu text
                            'start' => isset($segment['start']) ? round($segment['start'] / 1000, 2) : 0, // Convert ms to seconds
                            'end' => isset($segment['end']) ? round($segment['end'] / 1000, 2) : 0,
                            'confidence' => $segment['confidence'] ?? 0,
                            'word_count' => isset($segment['words']) ? count($segment['words']) : 0
                        ];
                    }
                }
                
                // Use JSON_UNESCAPED_UNICODE flag to ensure proper UTF-8 output
                return response()->json([
                    'success' => true,
                    'message' => 'Video found successfully',
                    'video' => [
                        'id' => $video->id,
                        'name' => $video->filename,
                        'title' => $video->title,
                        'path' => $video->dropbox_path,
                        'youtube_url' => $video->youtube_url,
                        'speakers_data' => $formattedSpeakers,
                        'total_segments' => count($formattedSpeakers)
                    ]
                ], 200, [], JSON_UNESCAPED_UNICODE);
            } else {
                // URL format is valid but not found in database
                Log::warning('YouTube URL not found in database', ['url' => $youtubeUrl, 'video_id' => $videoId]);
                return response()->json([
                    'success' => false,
                    'message' => 'No video found with this YouTube URL'
                ], 404); 
            }
        } else {
            Log::warning('Invalid YouTube URL format', ['url' => $youtubeUrl]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid YouTube URL format'
            ], 400);
        }
    }

    public function getVideoDetails(Request $request)
    {
        $videoId = $request->video_id;

        Log::info('Fetching video details', ['video_id' => $videoId]);

        $video = null;
        $youtubeVideoId = null;

        // Check if videoId is numeric (database ID) or alphanumeric (YouTube ID)
        if (is_numeric($videoId)) {
            // It's a database ID, fetch from database
            Log::info('Treating as database video ID', ['db_video_id' => $videoId]);
            $video = Video::find($videoId);

            if (!$video) {
                Log::error('Video not found in database', ['video_id' => $videoId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Video not found in database'
                ], 404);
            }

            // Extract YouTube video ID from URL
            $youtubeUrl = $video->youtube_url;
            if ($youtubeUrl) {
                $pattern = '/^(https?:\/\/)?(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})([&?].*)?$/';
                if (preg_match($pattern, $youtubeUrl, $matches)) {
                    $youtubeVideoId = $matches[4];
                }
            }

            if (!$youtubeVideoId) {
                // No YouTube URL — video may only exist on Dropbox. Continue without YouTube.
                Log::info('No YouTube video ID available, will use Dropbox fallback', ['video_id' => $videoId, 'dropbox_path' => $video->dropbox_path]);
            }
        } else {
            // It's a YouTube video ID (11 characters)
            Log::info('Treating as YouTube video ID', ['youtube_video_id' => $videoId]);
            $youtubeVideoId = $videoId;
        }

        Log::info('Using YouTube video ID', ['youtube_video_id' => $youtubeVideoId]);

        // Call YouTube API to get video duration
        $apiKey = env('YOUTUBE_API_KEY');
        
        // Initialize response data
        $durationSeconds = null;
        $duration = null;
        
        // Try to get duration from YouTube API if API key is available and we have a YouTube ID
        if ($apiKey && $youtubeVideoId) {
            try {
                $response = Http::get('https://www.googleapis.com/youtube/v3/videos', [
                    'id' => $youtubeVideoId,
                    'part' => 'contentDetails',
                    'key' => $apiKey
                ]);
                
                if ($response->successful()) {
                    $data = $response->json();
                    $duration = $data['items'][0]['contentDetails']['duration'] ?? null;
                    
                    if ($duration) {
                        // Convert ISO 8601 duration (PT5M2S) to seconds
                        $durationSeconds = $this->parseDuration($duration);
                        Log::info('YouTube video duration from API', [
                            'youtube_video_id' => $youtubeVideoId, 
                            'duration' => $duration, 
                            'seconds' => $durationSeconds
                        ]);
                    }
                } else {
                    Log::warning('YouTube API failed', ['status' => $response->status()]);
                }
            } catch (\Exception $e) {
                Log::error('YouTube API error', ['error' => $e->getMessage()]);
            }
        } else {
            Log::warning('YouTube API key not configured');
        }
        
        // Fallback to database duration if YouTube API failed
        if (!$durationSeconds && $video && $video->audio_duration_seconds) {
            $durationSeconds = $video->audio_duration_seconds;
            Log::info('Using duration from database', ['duration_seconds' => $durationSeconds]);
        }
        
        // If still no duration, set to 0 (frontend can handle this)
        if (!$durationSeconds) {
            $durationSeconds = 0;
            Log::info('Duration not available, setting to 0');
        }
        
        $formattedDuration = $this->formatDuration($durationSeconds);

        // If we have video data from database, include it
        $response = [
            'success' => true,
            'duration_iso' => $duration,
            'duration_seconds' => $durationSeconds,
            'duration_formatted' => $formattedDuration,
            'duration_parts' => [
                'hours' => floor($durationSeconds / 3600),
                'minutes' => floor(($durationSeconds % 3600) / 60),
                'seconds' => $durationSeconds % 60
            ]
        ];

        // Add video data if fetched from database
        if ($video) {
            // Parse speakers_data if it's a JSON string
            $summary = $video->summary;
            $detectedLanguage = $video->language_detected ?? 'unknown';
            $speakersData = $video->speakers_data;
            if (is_string($speakersData)) {
                $speakersData = json_decode($speakersData, true);
            }
            
            // Parse translations
            $engTranslation = $video->transcript_english;
            if (is_string($engTranslation)) {
                $engTranslation = json_decode($engTranslation, true);
            }
            $urduTranslation = $video->transcript_urdu;
            if (is_string($urduTranslation)) {
                $urduTranslation = json_decode($urduTranslation, true);
            }
            
            // Get speaker mapping
            $speakerMapping = $video->speaker_mapping ?? [];
            if (is_string($speakerMapping)) {
                $speakerMapping = json_decode($speakerMapping, true) ?? [];
            }
            
            // Determine primary transcript and alternative based on detected language
            // If Urdu detected: primary = speakers_data (original Urdu), alternative = English translation
            // If English detected: primary = speakers_data (original English), alternative = Urdu translation
            $primaryTranscript = $speakersData; // Original transcript is always the primary
            $alternativeTranscript = null;
            $alternativeLanguage = null;
            
            // Determine primary summary and alternative based on detected language
            $primarySummary = $summary; // Original summary
            $alternativeSummary = null;
            
            if ($detectedLanguage === 'ur') {
                // Detected Urdu - alternative is English
                $alternativeTranscript = $engTranslation;
                $alternativeLanguage = 'en';
                $alternativeSummary = $video->summary_english;
            } elseif ($detectedLanguage === 'en') {
                // Detected English - alternative is Urdu
                $alternativeTranscript = $urduTranslation;
                $alternativeLanguage = 'ur';
                $alternativeSummary = $video->summary_urdu;
            } else {
                // Other language - provide both translations as alternatives
                $alternativeTranscript = $engTranslation; // Default to English as alternative
                $alternativeLanguage = 'en';
                $alternativeSummary = $video->summary_english;
            }
            
            $response['video'] = [
                'id' => $video->id,
                'title' => $video->title,
                'filename' => $video->filename,
                'youtube_url' => $video->youtube_url,
                'youtube_video_id' => $youtubeVideoId,
                'dropbox_path' => $video->dropbox_path,
                'speakers_data' => $primaryTranscript, // Primary transcript (original language)
                'alternative_transcript' => $alternativeTranscript, // Alternative translation
                'alternative_language' => $alternativeLanguage, // 'en' or 'ur'
                'summary' => $primarySummary, // Primary summary (original language)
                'alternative_summary' => $alternativeSummary, // Alternative summary translation
                'summary_urdu' => $video->summary_urdu, // Keep for backward compatibility
                'summary_english' => $video->summary_english, // Keep for backward compatibility
                'eng_translation' => $engTranslation, // Keep for backward compatibility
                'urdu_translation' => $urduTranslation, // Keep for backward compatibility
                'language_detected' => $detectedLanguage,
                'speaker_mapping' => $speakerMapping,
                'audio_duration_seconds' => $video->audio_duration_seconds,
                'created_at' => $video->created_at
            ];
        }
        return response()->json($response, 200, [], JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Convert ISO 8601 duration to seconds
     * Example: PT5M2S = 302 seconds (5 minutes, 2 seconds)
     * 
     * @param string $duration
     * @return int
     */
    private function parseDuration($duration)
    {
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $matches);
        
        $hours = isset($matches[1]) ? (int)$matches[1] : 0;
        $minutes = isset($matches[2]) ? (int)$matches[2] : 0;
        $seconds = isset($matches[3]) ? (int)$matches[3] : 0;
        
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
    
    /**
     * Format seconds to HH:MM:SS
     * 
     * @param int $seconds
     * @return string
     */
    private function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

}