<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Video;
use App\Services\DropboxService;
use App\Services\GoogleApiService;
use App\Services\ProcessService;
use App\Services\AssemblyAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\VideoProcessedNotification;
use OpenAI;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public $timeout = 3600; // 1 hour timeout
    public $tries = 2; // Retry once on failure (e.g. token expiration)
    public $backoff = 30; // Wait 30 seconds before retry

    protected $userId;
    protected $videoId;
    protected $dropboxVideoPath;
    protected $videoTitle;
    protected $videoDescription;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, int $videoId, string $dropboxVideoPath, string $videoTitle = null, string $videoDescription = null)
    {
        $this->userId = $userId;
        $this->videoId = $videoId;
        $this->dropboxVideoPath = $dropboxVideoPath;
        $this->timeout = (int) config('video-processing.timeout', 21600);
        $this->onQueue(config('video-processing.queue', 'default'));
        
        // Clean and set video title - YouTube has max 100 chars and doesn't allow < > characters
        $cleanTitle = $videoTitle;
        if (empty($cleanTitle)) {
            // Extract filename without extension as fallback title
            $cleanTitle = pathinfo(basename($dropboxVideoPath), PATHINFO_FILENAME);
        }
        // Remove invalid characters and limit length for YouTube
        $cleanTitle = str_replace(['<', '>', '|'], '', $cleanTitle);
        $cleanTitle = trim($cleanTitle);
        if (strlen($cleanTitle) > 100) {
            $cleanTitle = substr($cleanTitle, 0, 97) . '...';
        }
        $this->videoTitle = !empty($cleanTitle) ? $cleanTitle : 'Video - ' . now()->format('Y-m-d H:i:s');
        
        $this->videoDescription = $videoDescription ?? 'Processed by Clip Matters';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $results = [
            'user_id' => $this->userId,
            'video_id' => $this->videoId,
            'dropbox_path' => $this->dropboxVideoPath,
            'steps' => [],
            'status' => 'processing'
        ];

        try {
            Log::info('ProcessVideoJob started', ['user_id' => $this->userId, 'video_id' => $this->videoId, 'video' => $this->dropboxVideoPath]);

            // Get user and video record
            $user = User::find($this->userId);
            $video = Video::find($this->videoId);
            if (!$user) {
                throw new \Exception('User not found');
            }
            if (!$video) {
                throw new \Exception('Video record not found');
            }

            $dropboxService = new DropboxService($user);

            // Dropbox size is not guaranteed to be stored before processing.
            $remoteFileSize = $dropboxService->getFileSize($this->dropboxVideoPath);
            if ($remoteFileSize !== null) {
                $video->update(['size_mb' => round($remoteFileSize / 1024 / 1024, 2)]);
            }

            // Mark the video as processing after the metadata preflight.
            $video->markAsProcessing();

            // ============================================
            // STEP 1: OPEN DROPBOX SOURCE
            // ============================================
            Log::info('Step 1: Opening Dropbox video source');
            $results['steps'][] = ['step' => 1, 'action' => 'Opening Dropbox video source', 'status' => 'started'];

            $localVideoPath = null;
            $streamFromDropbox = (bool) config('video-processing.stream_from_dropbox', true);

            if ($streamFromDropbox) {
                $videoInput = $dropboxService->getTemporaryLink($this->dropboxVideoPath);
                if (!$videoInput) {
                    throw new \Exception('Failed to get Dropbox streaming link');
                }

                $results['video_size_mb'] = $remoteFileSize !== null
                    ? round($remoteFileSize / 1024 / 1024, 2)
                    : null;
                $results['steps'][] = ['step' => 1, 'action' => 'Dropbox stream opened', 'status' => 'completed', 'size_mb' => $results['video_size_mb']];
                Log::info('Video will be processed directly from Dropbox', [
                    'video_id' => $this->videoId,
                    'size_mb' => $results['video_size_mb'],
                ]);
            } else {
                $localVideoPath = storage_path('app/temp/video_' . time() . '_' . uniqid() . '.' . pathinfo($this->dropboxVideoPath, PATHINFO_EXTENSION));

                if (!file_exists(dirname($localVideoPath))) {
                    mkdir(dirname($localVideoPath), 0755, true);
                }

                $downloadSuccess = $dropboxService->downloadToFile($this->dropboxVideoPath, $localVideoPath, $remoteFileSize);
                if (!$downloadSuccess || !file_exists($localVideoPath)) {
                    throw new \Exception('Failed to download file from Dropbox');
                }

                $videoInput = $localVideoPath;
                $videoSize = filesize($localVideoPath);
                $results['local_video_path'] = $localVideoPath;
                $results['video_size_mb'] = round($videoSize / 1024 / 1024, 2);
                $results['steps'][] = ['step' => 1, 'action' => 'Downloaded from Dropbox', 'status' => 'completed', 'size_mb' => $results['video_size_mb']];
                $video->update(['size_mb' => $results['video_size_mb']]);
                Log::info('Video downloaded', ['size_mb' => $results['video_size_mb']]);
            }

            // =================================================
            // STEP 1.5: EXTRACT VIDEO CREATION DATE VIA FFPROBE
            // =================================================
            try {
                $creationTime = null;

                // Probe both format AND stream tags (MTS/AVCHD files store metadata in streams)
                $ffprobeOutput = [];
                $ffprobeReturnCode = null;
                exec(
                    'ffprobe -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($videoInput) . ' 2>/dev/null',
                    $ffprobeOutput,
                    $ffprobeReturnCode
                );

                if ($ffprobeReturnCode === 0 && !empty($ffprobeOutput)) {
                    $metadata = json_decode(implode("\n", $ffprobeOutput), true);
                    Log::info('FFprobe metadata keys', [
                        'format_tags' => array_keys($metadata['format']['tags'] ?? []),
                        'stream_count' => count($metadata['streams'] ?? []),
                    ]);
                    

                    // 1) Check format-level tags
                    $formatTags = $metadata['format']['tags'] ?? [];
                    $creationTime = $formatTags['creation_time']
                        ?? $formatTags['Creation Time']
                        ?? $formatTags['date']
                        ?? $formatTags['DATE']
                        ?? $formatTags['com.apple.quicktime.creationdate']
                        ?? null;

                    // 2) If not found, check each stream's tags
                    if (!$creationTime && !empty($metadata['streams'])) {
                        foreach ($metadata['streams'] as $stream) {
                            $streamTags = $stream['tags'] ?? [];
                            $creationTime = $streamTags['creation_time']
                                ?? $streamTags['Creation Time']
                                ?? $streamTags['date']
                                ?? null;
                            if ($creationTime) {
                                Log::info('Found creation_time in stream tags', ['codec_type' => $stream['codec_type'] ?? 'unknown']);
                                break;
                            }
                        }
                    }
                }

                // 3) Fallback: try to parse date from the Dropbox path (e.g. "Jan 23" or "15-01-23")
                if (!$creationTime) {
                    $dropboxPath = $this->dropboxVideoPath;
                    // Match patterns like (15-01-23) or (01-15-23) in path
                    if (preg_match('/\((\d{1,2}-\d{1,2}-\d{2,4})\)/', $dropboxPath, $m)) {
                        try {
                            $creationTime = \Carbon\Carbon::createFromFormat('d-m-y', $m[1])->startOfDay()->toDateTimeString();
                            Log::info('Parsed creation date from Dropbox path pattern', ['matched' => $m[1], 'parsed' => $creationTime]);
                        } catch (\Exception $e) {
                            // Try alternative format
                            try {
                                $creationTime = \Carbon\Carbon::createFromFormat('m-d-y', $m[1])->startOfDay()->toDateTimeString();
                                Log::info('Parsed creation date from Dropbox path (m-d-y)', ['matched' => $m[1], 'parsed' => $creationTime]);
                            } catch (\Exception $e2) {
                                Log::info('Could not parse date from path pattern', ['matched' => $m[1]]);
                            }
                        }
                    }
                }

                if ($creationTime) {
                    $parsedDate = \Carbon\Carbon::parse($creationTime);
                    $video->update(['video_created_at' => $parsedDate]);
                    Log::info('Video creation date saved', ['video_created_at' => $parsedDate->toDateTimeString()]);
                } else {
                    Log::info('No creation_time metadata found in video file or path');
                }
            } catch (\Exception $e) {
                Log::warning('Failed to extract video creation date', ['error' => $e->getMessage()]);
                // Non-fatal — continue processing
            }
            // ============================================
            // STEP 2: UPLOAD TO YOUTUBE (OR SKIP IF NOT CONNECTED)
            // ============================================
            $youtubeVideoId = null;
            $youtubeUrl = null;
            $uploadFailed = false;

            // Check if Google/YouTube is connected before attempting upload
            $googleConnected = $user->getGoogleAccessToken() || $user->google_refresh_token;
            
            if ($streamFromDropbox) {
                Log::info('Step 2: Skipping YouTube upload — source is streamed from Dropbox');
                $results['steps'][] = ['step' => 2, 'action' => 'YouTube upload skipped — Dropbox streaming enabled', 'status' => 'skipped'];
                $results['youtube_video_id'] = null;
                $results['youtube_url'] = null;
                $uploadFailed = true;

                $video->update([
                    'youtube_video_id' => null,
                    'youtube_url' => null,
                    'notes' => 'Source processed directly from Dropbox; local video storage and YouTube upload skipped',
                ]);
            } elseif (!$googleConnected) {
                // Google not connected — skip YouTube upload, will stream from Dropbox
                Log::info('Step 2: Skipping YouTube upload — Google not connected, will stream from Dropbox');
                $results['steps'][] = ['step' => 2, 'action' => 'YouTube upload skipped — Google not connected', 'status' => 'skipped'];
                $results['youtube_video_id'] = null;
                $results['youtube_url'] = null;
                $results['google_not_connected'] = true;
                $uploadFailed = true;
                
                $video->update([
                    'youtube_video_id' => null,
                    'youtube_url' => null,
                    'notes' => 'YouTube upload skipped — Google not connected, streaming from Dropbox'
                ]);
            } else {
                // Google is connected — attempt YouTube upload
                Log::info('Step 2: Uploading to YouTube');
                $results['steps'][] = ['step' => 2, 'action' => 'Uploading to YouTube', 'status' => 'started'];

                try {
                    $googleService = new GoogleApiService($user);
                    // Force token refresh before upload to avoid expiration during long uploads
                    $googleService->getAccessToken();
                    $uploadResponse = $googleService->uploadVideo(
                        $localVideoPath,
                        $this->videoTitle,
                        $this->videoDescription,
                        ['clip-matters', 'automated'],
                        'unlisted'
                    );

                    $youtubeVideoId = $uploadResponse->id;
                    $youtubeUrl = 'https://www.youtube.com/watch?v=' . $youtubeVideoId;
                    
                    $results['youtube_video_id'] = $youtubeVideoId;
                    $results['youtube_url'] = $youtubeUrl;
                    $results['steps'][] = ['step' => 2, 'action' => 'Uploaded to YouTube', 'status' => 'completed', 'video_id' => $youtubeVideoId];
                    
                    // Update video record with YouTube info
                    $video->update([
                        'youtube_video_id' => $youtubeVideoId,
                        'youtube_url' => $youtubeUrl,
                        'youtube_privacy' => 'unlisted',
                    ]);
                    
                    Log::info('Video uploaded to YouTube', ['video_id' => $youtubeVideoId, 'url' => $youtubeUrl]);

                    // Wait for YouTube to process the video
                    Log::info('Waiting 30 seconds for YouTube to process video...');
                    sleep(30);

                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    
                    // Check if it's a token/auth expiration error — log and continue (don't retry, skip YouTube)
                    if (str_contains($errorMessage, 'invalid_grant') || 
                        str_contains($errorMessage, 'Token has been expired') ||
                        str_contains($errorMessage, 'Invalid Credentials') ||
                        str_contains($errorMessage, 'unauthorized') ||
                        str_contains($errorMessage, 'Login Required') ||
                        str_contains($errorMessage, 'No Google refresh token')) {
                        Log::warning('YouTube upload failed due to auth issue, will stream from Dropbox', [
                            'video_id' => $this->videoId,
                            'error' => $errorMessage
                        ]);
                        
                        // Skip YouTube — stream from Dropbox instead
                        $youtubeUrl = null;
                        $youtubeVideoId = null;
                        $uploadFailed = true;
                        
                        $results['youtube_video_id'] = null;
                        $results['youtube_url'] = null;
                        $results['youtube_auth_failed'] = true;
                        $results['steps'][] = ['step' => 2, 'action' => 'YouTube upload skipped — auth expired', 'status' => 'completed'];
                        
                        $video->update([
                            'youtube_video_id' => null,
                            'youtube_url' => null,
                            'notes' => 'YouTube auth expired — streaming from Dropbox'
                        ]);
                    }
                    // Check if it's an upload limit error
                    elseif (str_contains($errorMessage, 'uploadLimitExceeded') || str_contains($errorMessage, 'exceeded the number of videos')) {
                        Log::warning('YouTube upload limit exceeded, video will stream from Dropbox');
                        
                        // No YouTube URL — frontend will auto-fallback to Dropbox streaming
                        $youtubeUrl = null;
                        $youtubeVideoId = null;
                        $uploadFailed = true;
                        
                        $results['youtube_video_id'] = null;
                        $results['youtube_url'] = null;
                        $results['upload_limit_exceeded'] = true;
                        $results['steps'][] = ['step' => 2, 'action' => 'YouTube upload limit exceeded — will use Dropbox streaming', 'status' => 'completed'];
                        
                        // Update video record — no YouTube, will stream from Dropbox
                        $video->update([
                            'youtube_video_id' => null,
                            'youtube_url' => null,
                            'notes' => 'YouTube upload limit exceeded — streaming from Dropbox'
                        ]);
                        
                        Log::info('Video will stream from Dropbox', ['video_id' => $this->videoId, 'dropbox_path' => $this->dropboxVideoPath]);
                    } else {
                        // For any other error, log and continue with Dropbox streaming
                        Log::warning('YouTube upload failed, will stream from Dropbox', [
                            'video_id' => $this->videoId,
                            'error' => $errorMessage
                        ]);
                        
                        $youtubeUrl = null;
                        $youtubeVideoId = null;
                        $uploadFailed = true;
                        
                        $results['youtube_video_id'] = null;
                        $results['youtube_url'] = null;
                        $results['youtube_upload_error'] = $errorMessage;
                        $results['steps'][] = ['step' => 2, 'action' => 'YouTube upload failed — will use Dropbox streaming', 'status' => 'completed'];
                        
                        $video->update([
                            'youtube_video_id' => null,
                            'youtube_url' => null,
                            'notes' => 'YouTube upload failed — streaming from Dropbox: ' . substr($errorMessage, 0, 200)
                        ]);
                    }
                }
            } // End of Google connected else block

            // ============================================
            // STEP 3: EXTRACT AUDIO FROM VIDEO SOURCE
            // ============================================
            Log::info('Step 3: Extracting audio from video source');
            $results['steps'][] = ['step' => 3, 'action' => 'Extracting audio', 'status' => 'started'];

            $processService = new ProcessService();
            $audioPath = storage_path('app/temp/audio_' . time() . '_' . uniqid() . '.mp3');
            
            // Check if video actually has an audio stream
            $hasAudio = false;
            $ffprobeOutput = [];
            $ffprobeReturnCode = null;
            exec(
                'ffprobe -v quiet -select_streams a -show_entries stream=codec_type -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($videoInput) . ' 2>/dev/null',
                $ffprobeOutput,
                $ffprobeReturnCode
            );
            
            if ($ffprobeReturnCode === 0 && !empty($ffprobeOutput)) {
                $hasAudio = true;
            }

            if (!$hasAudio) {
                Log::info('No audio stream detected in video. Skipping audio extraction and transcription steps.');
                $results['steps'][] = ['step' => 3, 'action' => 'Audio extraction skipped - no audio stream', 'status' => 'skipped'];
                
                $video->update([
                    'notes' => ($video->notes ? $video->notes . "\n" : '') . 'Video has no audio stream. Transcription skipped.'
                ]);
                
                // Skip to cleanup
                goto cleanup_step;
            }

            // FFmpeg reads the Dropbox URL directly when streaming is enabled.
            $ffmpegArgs = [
                '-i', $videoInput,
                '-vn',  // No video
                '-acodec', 'libmp3lame',  // MP3 codec
                '-ab', '192k',  // Bitrate
                '-ar', '44100',  // Sample rate
                '-y',  // Overwrite output file
                $audioPath
            ];
            
            try {
                $processService->runFfmpeg(
                    $ffmpegArgs,
                    (int) config('video-processing.ffmpeg_timeout', 10800)
                );
                Log::info('Audio extracted from video source', [
                    'audio_path' => $audioPath,
                    'source' => $streamFromDropbox ? 'dropbox_stream' : 'local_file',
                ]);
            } catch (\Exception $e) {
                // If FFmpeg extraction fails, try downloading from YouTube as fallback (only if YouTube URL exists)
                if ($youtubeUrl) {
                    Log::warning('FFmpeg extraction failed, trying YouTube download', ['error' => $e->getMessage()]);
                    $audioPath = storage_path('app/temp/audio_' . time() . '_' . uniqid() . '.mp3');
                    $audioPath = $processService->downloadYoutubeAudio($youtubeUrl, $audioPath);
                    Log::info('Audio downloaded from YouTube', ['audio_path' => $audioPath]);
                } else {
                    Log::error('FFmpeg extraction failed and no YouTube URL available', ['error' => $e->getMessage()]);
                    throw new \Exception('Failed to extract audio: FFmpeg failed and no YouTube fallback available');
                }
            }
            
            $audioSize = filesize($audioPath);
            $results['audio_path'] = $audioPath;
            $results['audio_size_mb'] = round($audioSize / 1024 / 1024, 2);
            $results['steps'][] = ['step' => 3, 'action' => 'Audio extracted', 'status' => 'completed', 'size_mb' => $results['audio_size_mb']];
            
            Log::info('Audio extracted', ['size_mb' => $results['audio_size_mb']]);

            // Clean up local video file after extracting audio
            if ($localVideoPath && file_exists($localVideoPath)) {
                unlink($localVideoPath);
                Log::info('Local video file deleted', ['path' => $localVideoPath]);
            }
            // ============================================
            // STEP 4: UPLOAD TO ASSEMBLYAI
            // ============================================
            Log::info('Step 4: Uploading audio to AssemblyAI');
            $results['steps'][] = ['step' => 4, 'action' => 'Uploading to AssemblyAI', 'status' => 'started'];

            $assemblyService = new AssemblyAiService($user);
            $uploadUrl = $assemblyService->uploadAudio($audioPath);
            
            $results['assemblyai_upload_url'] = $uploadUrl;
            $results['steps'][] = ['step' => 4, 'action' => 'Uploaded to AssemblyAI', 'status' => 'completed'];



            // ============================================
            // STEP 5: REQUEST TRANSCRIPTION
            // ============================================
            Log::info('Step 5: Requesting transcription');
            $results['steps'][] = ['step' => 5, 'action' => 'Requesting transcription', 'status' => 'started'];

            $transcriptData = $assemblyService->requestTranscript($uploadUrl, [
                'speaker_labels' => true,
                'language_detection' => true,
                'language_detection_options' => [
                    'expected_languages' => ['ur', 'en'], // Force detection to pick ONLY between Urdu and English
                    'fallback_language' => 'ur' // Default to Urdu if unsure (e.g., Arabic intro is confusing)
                ],
                'summarization' => true,
                'summary_model' => 'conversational', 
                'summary_type' => 'bullets',
            ]);

            $transcriptId = $transcriptData['id'];
            $results['transcript_id'] = $transcriptId;
            $results['steps'][] = ['step' => 5, 'action' => 'Transcription requested', 'status' => 'completed', 'transcript_id' => $transcriptId];
            
            // Update video record with transcript ID
            $video->update(['transcript_id' => $transcriptId]);
            
            Log::info('Transcription requested', ['transcript_id' => $transcriptId]);

            // ============================================
            // STEP 6: POLL FOR COMPLETION
            // ============================================
            Log::info('Step 6: Waiting for transcription to complete');
            $results['steps'][] = ['step' => 6, 'action' => 'Polling for completion', 'status' => 'started'];

            $finalTranscript = $assemblyService->pollTranscript($transcriptId, 5, 120); // Poll for up to 10 minutes

            // Extract transcript data
            $results['transcript_status'] = $finalTranscript['status'];
            $results['transcript_text'] = $finalTranscript['text'];
            $results['text_length'] = strlen($finalTranscript['text']);
            $results['language_detected'] = $finalTranscript['language_code'] ?? 'unknown';
            $results['audio_duration_seconds'] = $finalTranscript['audio_duration'] ?? 0;
            $results['audio_duration_minutes'] = round($results['audio_duration_seconds'] / 60, 2);
            $results['confidence'] = $finalTranscript['confidence'] ?? 0;

            // Speaker information
            if (isset($finalTranscript['utterances']) && count($finalTranscript['utterances']) > 0) {
                // Chunk long utterances into smaller pieces (e.g. max 15 seconds) to preserve accurate timestamps
                $finalTranscript['utterances'] = $this->chunkUtterances($finalTranscript['utterances'], 15000);

                $speakers = array_unique(array_column($finalTranscript['utterances'], 'speaker'));
                $results['speakers_count'] = count($speakers);
                $results['utterances_count'] = count($finalTranscript['utterances']);
                $results['utterances'] = $finalTranscript['utterances'];
            }

            // Summary
            if (isset($finalTranscript['summary'])) {
                $results['summary'] = $finalTranscript['summary'];
            }

            $results['steps'][] = ['step' => 6, 'action' => 'Transcription completed', 'status' => 'completed'];
            
            // ============================================
            // STEP 6.1: REFINE TRANSCRIPT WITH OPENAI (Remove glitches, duplications, errors)
            // ============================================
            Log::info('Step 6.1: Refining transcript with OpenAI to remove glitches and errors');
            $results['steps'][] = ['step' => '6.1', 'action' => 'Refining transcript with OpenAI', 'status' => 'started'];
            
            try {
                $openaiApiKey = env('OPENAI_API_KEY');
                
                if ($openaiApiKey) {
                    $client = OpenAI::client($openaiApiKey);
                    
                    // Refine the main transcript text
                    if (!empty($finalTranscript['text'])) {
                        Log::info('Refining main transcript text');
                        $refinedText = $this->refineTranscriptText($client, $finalTranscript['text'], $results['language_detected'] ?? 'unknown');
                        
                        if ($refinedText) {
                            $finalTranscript['text'] = $refinedText;
                            Log::info('Main transcript text refined', [
                                'original_length' => strlen($results['transcript_text']),
                                'refined_length' => strlen($refinedText)
                            ]);
                        }
                    }
                    
                    // Refine utterances (individual speaker segments)
                    if (isset($finalTranscript['utterances']) && !empty($finalTranscript['utterances'])) {
                        Log::info('Refining utterances', ['count' => count($finalTranscript['utterances'])]);
                        $finalTranscript['utterances'] = $this->refineUtterances($client, $finalTranscript['utterances'], $results['language_detected'] ?? 'unknown');
                        Log::info('Utterances refined successfully');
                    }
                    
                    $results['steps'][] = ['step' => '6.1', 'action' => 'Transcript refined', 'status' => 'completed'];
                } else {
                    Log::warning('Transcript refinement skipped - OpenAI API key not configured');
                    $results['steps'][] = ['step' => '6.1', 'action' => 'Refinement skipped', 'status' => 'skipped', 'reason' => 'OpenAI API key not configured'];
                }
            } catch (\Exception $e) {
                Log::error('Transcript refinement failed', [
                    'error' => $e->getMessage()
                ]);
                $results['steps'][] = ['step' => '6.1', 'action' => 'Refinement failed', 'status' => 'error', 'error' => $e->getMessage()];
                // Don't throw - continue with original transcript
            }
            
            // Update video record with all transcription data (now refined)
            $video->update([
                'transcript_text' => $finalTranscript['text'] ?? null,
                'language_detected' => $finalTranscript['language_code'] ?? null,
                'audio_duration_seconds' => $finalTranscript['audio_duration'] ?? null,
                'confidence' => $finalTranscript['confidence'] ?? null,
                'speakers_count' => $results['speakers_count'] ?? null,
                'speakers_data' => isset($finalTranscript['utterances']) ? $finalTranscript['utterances'] : null,
                'summary' => $finalTranscript['summary'] ?? null,
            ]);
            
            Log::info('Transcription completed', [
                'language' => $results['language_detected'],
                'duration_minutes' => $results['audio_duration_minutes'],
                'speakers' => $results['speakers_count'] ?? 0
            ]);

            // Log::info('all transcript data', $finalTranscript);
            // Log::info('results so far', $results);

            // ============================================
            // STEP 6.2: TRANSLATE TRANSCRIPT (based on detected language)
            // ============================================
            // If detected language is Urdu, translate to English only
            // If detected language is English, translate to Urdu only
            $detectedLanguage = $results['language_detected'] ?? 'unknown';
            Log::info('Step 6.2: Translating transcript based on detected language', ['detected' => $detectedLanguage]);
            $results['steps'][] = ['step' => '6.2', 'action' => 'Translating transcript', 'status' => 'started'];

            try {
                $openaiApiKey = env('OPENAI_API_KEY');
                
                if ($openaiApiKey && isset($finalTranscript['utterances']) && !empty($finalTranscript['utterances'])) {
                    $client = OpenAI::client($openaiApiKey);
                    
                    // Prepare utterances for translation
                    $utterances = $finalTranscript['utterances'];
                    
                    $transcriptUrdu = null;
                    $transcriptEnglish = null;
                    
                    // Translate based on detected language
                    if ($detectedLanguage === 'ur') {
                        // Original is Urdu, only translate to English
                        $transcriptEnglish = $this->translateUtterances($client, $utterances, 'English');
                        // Store original as Urdu (no refinement on translated text — refinement causes the model to revert translations)
                        $transcriptUrdu = $utterances;
                        Log::info('Detected Urdu - translated to English, keeping original as Urdu');
                    } elseif ($detectedLanguage === 'en') {
                        // Original is English, only translate to Urdu
                        $transcriptUrdu = $this->translateUtterances($client, $utterances, 'Urdu');
                        // Store original as English (no refinement on translated text — refinement causes the model to revert translations)
                        $transcriptEnglish = $utterances;
                        Log::info('Detected English - translated to Urdu, keeping original as English');
                    } else {
                        // Unknown or other language - translate to both (no refinement on translated text)
                        $transcriptUrdu = $this->translateUtterances($client, $utterances, 'Urdu');
                        $transcriptEnglish = $this->translateUtterances($client, $utterances, 'English');
                        Log::info('Detected other language - translated to both Urdu and English');
                    }
                    
                    // Update video with translations
                    $video->update([
                        'transcript_urdu' => $transcriptUrdu,
                        'transcript_english' => $transcriptEnglish,
                    ]);
                    
                    $results['transcript_urdu_count'] = $transcriptUrdu ? count($transcriptUrdu) : 0;
                    $results['transcript_english_count'] = $transcriptEnglish ? count($transcriptEnglish) : 0;
                    $results['steps'][] = ['step' => '6.2', 'action' => 'Transcript translated', 'status' => 'completed'];
                    
                    Log::info('Transcript translations completed', [
                        'video_id' => $this->videoId,
                        'detected_language' => $detectedLanguage,
                        'urdu_segments' => $results['transcript_urdu_count'],
                        'english_segments' => $results['transcript_english_count']
                    ]);
                } else {
                    $skipReason = !$openaiApiKey ? 'OpenAI API key not configured' : 'No utterances available';
                    Log::warning('Transcript translation skipped', ['reason' => $skipReason]);
                    $results['steps'][] = ['step' => '6.2', 'action' => 'Translation skipped', 'status' => 'skipped', 'reason' => $skipReason];
                }
            } catch (\Exception $e) {
                Log::error('Transcript translation failed', [
                    'video_id' => $this->videoId,
                    'error' => $e->getMessage()
                ]);
                $results['steps'][] = ['step' => '6.2', 'action' => 'Translation failed', 'status' => 'error', 'error' => $e->getMessage()];
                // Don't throw - continue with the rest of processing
            }

            // ============================================
            // STEP 6.5: GENERATE SUMMARY WITH OPENAI (if AssemblyAI summary is null)
            // ============================================
            // AssemblyAI summarization only works for English audio
            // For non-English (like Urdu), we use OpenAI to generate summary
            if (empty($finalTranscript['summary'])) {
                Log::info('Step 6.5: AssemblyAI summary is null, generating with OpenAI');
                $results['steps'][] = ['step' => '6.5', 'action' => 'Generating summary with OpenAI', 'status' => 'started'];

                try {
                    $openaiApiKey = env('OPENAI_API_KEY');
                    
                    if ($openaiApiKey) {
                        // Build transcript text from utterances
                        $transcriptText = '';
                        if (isset($finalTranscript['utterances']) && !empty($finalTranscript['utterances'])) {
                            foreach ($finalTranscript['utterances'] as $utterance) {
                                $speaker = $utterance['speaker'] ?? 'Speaker';
                                // Sanitize each text segment as we build
                                $text = $this->sanitizeUtf8($utterance['text'] ?? '');
                                $transcriptText .= "{$speaker}: {$text}\n";
                            }
                        } elseif (!empty($finalTranscript['text'])) {
                            $transcriptText = $this->sanitizeUtf8($finalTranscript['text']);
                        }

                        if (!empty(trim($transcriptText))) {
                            // Additional sanitization pass on the complete text
                            $transcriptText = $this->sanitizeUtf8($transcriptText);
                            
                            // Verify it's JSON-encodable before proceeding
                            $testEncode = json_encode(['test' => $transcriptText], JSON_UNESCAPED_UNICODE);
                            if ($testEncode === false) {
                                Log::warning('Transcript text failed JSON encoding test, applying aggressive sanitization', [
                                    'video_id' => $this->videoId,
                                    'json_error' => json_last_error_msg()
                                ]);
                                // Fall back to ASCII-safe characters + Urdu range
                                $transcriptText = preg_replace('/[^\x20-\x7E\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\n\r\t ]/u', '', $transcriptText);
                            }
                            
                            // Truncate if too long (OpenAI token limits)
                            $maxChars = 30000;
                            if (strlen($transcriptText) > $maxChars) {
                                $transcriptText = substr($transcriptText, 0, $maxChars) . '... [transcript truncated]';
                            }

                            // Detect language for better prompting
                            $language = $results['language_detected'] ?? 'unknown';
                            $languageInstruction = '';
                            
                            if ($language === 'ur') {
                                $languageInstruction = 'The transcript is in Urdu. Please provide the summary in Urdu language.';
                            } elseif ($language !== 'en' && $language !== 'unknown') {
                                $languageInstruction = "The transcript is in {$language}. Please provide the summary in the same language as the transcript.";
                            }

                            // Create OpenAI client and generate summary
                            $client = OpenAI::client($openaiApiKey);

                            $response = $client->chat()->create([
                                'model' => 'gpt-4o-mini',
                                'messages' => [
                                    [
                                        'role' => 'system',
                                        'content' => "You are an expert at summarizing video transcripts. Create a clear, concise summary in bullet points that captures the main topics discussed, key points, arguments, and any important conclusions. Do NOT mention or reference any speaker names, speaker labels, or who said what — focus purely on the subject matter and content of the discussion. {$languageInstruction}"
                                    ],
                                    [
                                        'role' => 'user',
                                        'content' => "Please summarize the topics and content discussed in the following video transcript:\n\n{$transcriptText}"
                                    ]
                                ],
                                'max_tokens' => 1000,
                                'temperature' => 0.3,
                            ]);

                            $openaiSummary = $response->choices[0]->message->content ?? null;

                            if ($openaiSummary) {
                                $results['summary'] = $openaiSummary;
                                $results['summary_source'] = 'openai';
                                
                                // Update video with OpenAI-generated summary
                                $video->update(['summary' => $openaiSummary]);
                                
                                Log::info('Summary generated with OpenAI', [
                                    'video_id' => $this->videoId,
                                    'language' => $language,
                                    'summary_length' => strlen($openaiSummary)
                                ]);
                                
                                $results['steps'][] = ['step' => '6.5', 'action' => 'Summary generated with OpenAI', 'status' => 'completed'];
                            } else {
                                Log::warning('OpenAI returned empty summary');
                                $results['steps'][] = ['step' => '6.5', 'action' => 'OpenAI returned empty summary', 'status' => 'warning'];
                            }
                        } else {
                            Log::warning('No transcript text available for summary generation');
                            $results['steps'][] = ['step' => '6.5', 'action' => 'No transcript text available', 'status' => 'skipped'];
                        }
                    } else {
                        Log::warning('OPENAI_API_KEY not configured, skipping summary generation');
                        $results['steps'][] = ['step' => '6.5', 'action' => 'OpenAI API key not configured', 'status' => 'skipped'];
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to generate summary with OpenAI', [
                        'video_id' => $this->videoId,
                        'error' => $e->getMessage()
                    ]);
                    $results['steps'][] = ['step' => '6.5', 'action' => 'OpenAI summary generation failed', 'status' => 'error', 'error' => $e->getMessage()];
                    // Don't throw - continue with the rest of processing
                }
            } else {
                $results['summary'] = $finalTranscript['summary'];
                $results['summary_source'] = 'assemblyai';
                Log::info('Using AssemblyAI summary (English detected)');
            }

            // ============================================
            // STEP 6.6: TRANSLATE SUMMARY TO URDU AND ENGLISH
            // ============================================
            Log::info('Step 6.6: Translating summary based on detected language');
            $results['steps'][] = ['step' => '6.6', 'action' => 'Translating summary', 'status' => 'started'];

            try {
                $openaiApiKey = env('OPENAI_API_KEY');
                $summaryToTranslate = $results['summary'] ?? $video->summary ?? null;
                
                if ($openaiApiKey && !empty($summaryToTranslate)) {
                    $client = OpenAI::client($openaiApiKey);
                    $detectedLanguage = $results['language_detected'] ?? 'unknown';
                    
                    $summaryUrdu = null;
                    $summaryEnglish = null;
                    
                    // Translate based on detected language
                    if ($detectedLanguage === 'ur') {
                        // Original is Urdu, only translate to English
                        $summaryEnglish = $this->translateText($client, $summaryToTranslate, 'English');
                        // Store original as Urdu
                        $summaryUrdu = $summaryToTranslate;
                        Log::info('Summary: Detected Urdu - translated to English, keeping original as Urdu');
                    } elseif ($detectedLanguage === 'en') {
                        // Original is English, only translate to Urdu
                        $summaryUrdu = $this->translateText($client, $summaryToTranslate, 'Urdu');
                        // Store original as English
                        $summaryEnglish = $summaryToTranslate;
                        Log::info('Summary: Detected English - translated to Urdu, keeping original as English');
                    } else {
                        // Unknown or other language - translate to both
                        $summaryUrdu = $this->translateText($client, $summaryToTranslate, 'Urdu');
                        $summaryEnglish = $this->translateText($client, $summaryToTranslate, 'English');
                        Log::info('Summary: Detected other language - translated to both Urdu and English');
                    }
                    
                    // Update video with summary translations
                    $video->update([
                        'summary_urdu' => $summaryUrdu,
                        'summary_english' => $summaryEnglish,
                    ]);
                    
                    $results['summary_urdu_length'] = $summaryUrdu ? strlen($summaryUrdu) : 0;
                    $results['summary_english_length'] = $summaryEnglish ? strlen($summaryEnglish) : 0;
                    $results['steps'][] = ['step' => '6.6', 'action' => 'Summary translated', 'status' => 'completed'];
                    
                    Log::info('Summary translations completed', [
                        'video_id' => $this->videoId,
                        'detected_language' => $detectedLanguage,
                        'urdu_length' => $results['summary_urdu_length'],
                        'english_length' => $results['summary_english_length']
                    ]);
                } else {
                    $skipReason = !$openaiApiKey ? 'OpenAI API key not configured' : 'No summary available';
                    Log::warning('Summary translation skipped', ['reason' => $skipReason]);
                    $results['steps'][] = ['step' => '6.6', 'action' => 'Summary translation skipped', 'status' => 'skipped', 'reason' => $skipReason];
                }
            } catch (\Exception $e) {
                Log::error('Summary translation failed', [
                    'video_id' => $this->videoId,
                    'error' => $e->getMessage()
                ]);
                $results['steps'][] = ['step' => '6.6', 'action' => 'Summary translation failed', 'status' => 'error', 'error' => $e->getMessage()];
                // Don't throw - continue with the rest of processing
            }

            // ============================================
            // STEP 7: SPEAKER IDENTIFICATION
            // ============================================
            Log::info('Step 7: Identifying speakers using Pyannote');
            $results['steps'][] = ['step' => 7, 'action' => 'Identifying speakers', 'status' => 'started'];

            try {
                // Call speaker identification with the uploaded audio URL
                // Log::info("Uploadurl  fro  idetify spearkers: " . $uploadUrl);
                $speakerData = $assemblyService->identifySpeakers($audioPath);
                
                if (isset($speakerData['error'])) {
                    Log::warning('Speaker identification skipped', ['reason' => $speakerData['message'] ?? 'No voiceprints available']);
                    $results['speaker_identification'] = 'skipped';
                    $results['speaker_identification_reason'] = $speakerData['message'] ?? 'No voiceprints available';
                } else {
                    // Extract diarization and identification data
                    $diarizationData = $speakerData['output']['diarization'] ?? [];
                    $identificationData = $speakerData['output']['identification'] ?? [];
                    $voiceprintsData = $speakerData['output']['voiceprints'] ?? [];
                    
                    $results['diarization_segments'] = count($diarizationData);
                    $results['identification_segments'] = count($identificationData);
                    $results['identified_speakers'] = count($voiceprintsData);
                    $results['pyannote_job_id'] = $speakerData['jobId'] ?? null;
                    
                    // Update video record with speaker identification data
                    $video->update([
                        'diarization_data' => $diarizationData,
                        'identification_data' => $identificationData,
                        'pyannote_job_id' => $speakerData['jobId'] ?? null,
                    ]);
                    
                    Log::info('Speaker identification completed', [
                        'diarization_segments' => count($diarizationData),
                        'identification_segments' => count($identificationData),
                        'identified_speakers' => count($voiceprintsData),
                        'speakers' => array_column($voiceprintsData, 'match')
                    ]);

                    // ============================================
                    // STEP 7.1: AUTO-TAG SPEAKERS IN TRANSCRIPT
                    // ============================================
                    // Automatically apply speaker names to transcript data
                    Log::info('Step 7.1: Auto-tagging speakers in transcript');
                    $results['steps'][] = ['step' => '7.1', 'action' => 'Auto-tagging speakers', 'status' => 'started'];

                    try {
                        // Get the current speakers_data
                        $speakersData = $video->speakers_data;
                        if (is_string($speakersData)) {
                            $speakersData = json_decode($speakersData, true);
                        }

                        if (!empty($speakersData) && !empty($identificationData)) {
                            // Tag speakers using the tagging service
                            $taggingService = new \App\Services\SpeakerTaggingService();
                            $tagResult = $taggingService->tagSpeakers($speakersData, $identificationData);

                            // Update video with tagged transcript and speaker mapping
                            $video->update([
                                'speakers_data' => json_encode($tagResult['taggedTranscript']),
                                'speaker_mapping' => json_encode($tagResult['speakerMapping']),
                            ]);

                            // Also tag the translated transcripts if they exist
                            $this->tagTranslatedTranscripts($video, $identificationData, $taggingService);

                            $results['speaker_tagging'] = [
                                'tagged_segments' => $tagResult['statistics']['taggedSegments'],
                                'untagged_segments' => $tagResult['statistics']['untaggedSegments'],
                                'speaker_mapping' => $tagResult['speakerMapping'],
                                'speaker_counts' => $tagResult['statistics']['speakerCounts'],
                            ];

                            Log::info('Speakers auto-tagged successfully', [
                                'video_id' => $this->videoId,
                                'tagged_segments' => $tagResult['statistics']['taggedSegments'],
                                'untagged_segments' => $tagResult['statistics']['untaggedSegments'],
                                'speaker_mapping' => $tagResult['speakerMapping']
                            ]);

                            $results['steps'][] = ['step' => '7.1', 'action' => 'Speakers auto-tagged', 'status' => 'completed', 'stats' => $tagResult['statistics']];

                            // ============================================
                            // STEP 7.2: REGENERATE SUMMARY WITH ACTUAL SPEAKER NAMES
                            // ============================================
                            // The summary was generated in Step 6.5/6.6 BEFORE speaker identification,
                            // so it contains generic labels like "Speaker A", "Speaker B".
                            // Now that we know actual names, regenerate the summary.
                            Log::info('Step 7.2: Regenerating summary with actual speaker names');
                            $results['steps'][] = ['step' => '7.2', 'action' => 'Regenerating summary with speaker names', 'status' => 'started'];

                            try {
                                $openaiApiKey = env('OPENAI_API_KEY');
                                $speakerMapping = $tagResult['speakerMapping'] ?? [];

                                if ($openaiApiKey && !empty($speakerMapping)) {
                                    // Build transcript text using tagged speakers data (with actual names)
                                    $taggedData = $tagResult['taggedTranscript'];
                                    $transcriptForSummary = '';
                                    foreach ($taggedData as $segment) {
                                        $speaker = $segment['actualSpeaker'] ?? $segment['speaker'] ?? 'Speaker';
                                        $text = $this->sanitizeUtf8($segment['text'] ?? '');
                                        $transcriptForSummary .= "{$speaker}: {$text}\n";
                                    }

                                    if (!empty(trim($transcriptForSummary))) {
                                        // Truncate if too long
                                        $maxChars = 30000;
                                        if (strlen($transcriptForSummary) > $maxChars) {
                                            $transcriptForSummary = substr($transcriptForSummary, 0, $maxChars) . '... [transcript truncated]';
                                        }

                                        $language = $results['language_detected'] ?? 'unknown';
                                        $languageInstruction = '';
                                        if ($language === 'ur') {
                                            $languageInstruction = 'The transcript is in Urdu. Please provide the summary in Urdu language.';
                                        } elseif ($language !== 'en' && $language !== 'unknown') {
                                            $languageInstruction = "The transcript is in {$language}. Please provide the summary in the same language as the transcript.";
                                        }

                                        $client = OpenAI::client($openaiApiKey);
                                        $response = $client->chat()->create([
                                            'model' => 'gpt-4o-mini',
                                            'messages' => [
                                                [
                                                    'role' => 'system',
                                                    'content' => "You are an expert at summarizing video transcripts. Create a clear, concise summary in bullet points that captures the main topics discussed, key points, arguments, and any important conclusions. Do NOT mention or reference any speaker names, speaker labels, or who said what — focus purely on the subject matter and content of the discussion. {$languageInstruction}"
                                                ],
                                                [
                                                    'role' => 'user',
                                                    'content' => "Please summarize the topics and content discussed in the following video transcript:\n\n{$transcriptForSummary}"
                                                ]
                                            ],
                                            'max_tokens' => 1000,
                                            'temperature' => 0.3,
                                        ]);

                                        $newSummary = $response->choices[0]->message->content ?? null;

                                        if ($newSummary) {
                                            $results['summary'] = $newSummary;
                                            $results['summary_source'] = 'openai_with_speakers';
                                            $video->update(['summary' => $newSummary]);

                                            Log::info('Summary regenerated with actual speaker names', [
                                                'video_id' => $this->videoId,
                                                'speaker_mapping' => $speakerMapping,
                                                'summary_length' => strlen($newSummary)
                                            ]);

                                            // Retranslate summary with actual speaker names
                                            $detectedLang = $results['language_detected'] ?? 'unknown';
                                            $summaryUrdu = null;
                                            $summaryEnglish = null;

                                            if ($detectedLang === 'ur') {
                                                $summaryEnglish = $this->translateText($client, $newSummary, 'English');
                                                $summaryUrdu = $newSummary;
                                            } elseif ($detectedLang === 'en') {
                                                $summaryUrdu = $this->translateText($client, $newSummary, 'Urdu');
                                                $summaryEnglish = $newSummary;
                                            } else {
                                                $summaryUrdu = $this->translateText($client, $newSummary, 'Urdu');
                                                $summaryEnglish = $this->translateText($client, $newSummary, 'English');
                                            }

                                            $video->update([
                                                'summary_urdu' => $summaryUrdu,
                                                'summary_english' => $summaryEnglish,
                                            ]);

                                            Log::info('Summary translations updated with speaker names', [
                                                'video_id' => $this->videoId,
                                                'urdu_length' => $summaryUrdu ? strlen($summaryUrdu) : 0,
                                                'english_length' => $summaryEnglish ? strlen($summaryEnglish) : 0
                                            ]);

                                            $results['steps'][] = ['step' => '7.2', 'action' => 'Summary regenerated with speaker names', 'status' => 'completed'];
                                        } else {
                                            Log::warning('OpenAI returned empty summary on regeneration');
                                            $results['steps'][] = ['step' => '7.2', 'action' => 'Regeneration returned empty', 'status' => 'warning'];
                                        }
                                    } else {
                                        $results['steps'][] = ['step' => '7.2', 'action' => 'No tagged transcript text available', 'status' => 'skipped'];
                                    }
                                } else {
                                    $skipReason = !$openaiApiKey ? 'OpenAI API key not configured' : 'No speaker mapping available';
                                    $results['steps'][] = ['step' => '7.2', 'action' => 'Summary regeneration skipped', 'status' => 'skipped', 'reason' => $skipReason];
                                }
                            } catch (\Exception $summaryException) {
                                Log::error('Summary regeneration with speaker names failed', [
                                    'video_id' => $this->videoId,
                                    'error' => $summaryException->getMessage()
                                ]);
                                $results['steps'][] = ['step' => '7.2', 'action' => 'Summary regeneration failed', 'status' => 'error', 'error' => $summaryException->getMessage()];
                                // Don't throw - keep the original summary
                            }

                        } else {
                            Log::warning('Cannot auto-tag speakers - missing data', [
                                'has_speakers_data' => !empty($speakersData),
                                'has_identification_data' => !empty($identificationData)
                            ]);
                            $results['steps'][] = ['step' => '7.1', 'action' => 'Auto-tagging skipped', 'status' => 'skipped', 'reason' => 'Missing required data'];
                        }
                    } catch (\Exception $tagException) {
                        Log::error('Auto-tagging speakers failed', [
                            'video_id' => $this->videoId,
                            'error' => $tagException->getMessage()
                        ]);
                        $results['steps'][] = ['step' => '7.1', 'action' => 'Auto-tagging failed', 'status' => 'error', 'error' => $tagException->getMessage()];
                        // Don't throw - continue with the rest of processing
                    }
                }
                
                $results['steps'][] = ['step' => 7, 'action' => 'Speaker identification completed', 'status' => 'completed'];
            } catch (\Exception $e) {
                Log::error('Speaker identification failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $results['steps'][] = ['step' => 7, 'action' => 'Speaker identification failed', 'status' => 'error', 'error' => $e->getMessage()];
                // Don't throw - continue with the rest of processing
            }

            // ============================================
            // STEP 8: GENERATE EMBEDDINGS
            // ============================================
            Log::info('Step 8: Generating embeddings for search');
            $results['steps'][] = ['step' => 8, 'action' => 'Generating embeddings', 'status' => 'started'];

            try {
                // Check if we have the required data for embeddings
                if (!empty($video->speakers_data) && !empty($video->identification_data)) {
                    // Dispatch embedding generation job to dedicated embeddings queue
                    \App\Jobs\GenerateVideoEmbedding::dispatch($video)->onQueue('high');
                    
                    Log::info('Embedding generation job dispatched to embeddings queue', [
                        'video_id' => $this->videoId,
                        'queue' => 'embeddings'
                    ]);
                    
                    $results['embedding_job_dispatched'] = true;
                    $results['steps'][] = ['step' => 8, 'action' => 'Embedding generation queued', 'status' => 'completed'];
                } else {
                    Log::warning('Cannot generate embeddings - missing required data', [
                        'video_id' => $this->videoId,
                        'has_speakers_data' => !empty($video->speakers_data),
                        'has_identification_data' => !empty($video->identification_data)
                    ]);
                    
                    $results['embedding_job_dispatched'] = false;
                    $results['embedding_skip_reason'] = 'Missing speakers_data or identification_data';
                    $results['steps'][] = ['step' => 8, 'action' => 'Embedding generation skipped', 'status' => 'skipped', 'reason' => 'Missing required data'];
                }
            } catch (\Exception $e) {
                Log::error('Failed to dispatch embedding generation job', [
                    'video_id' => $this->videoId,
                    'error' => $e->getMessage()
                ]);
                
                $results['embedding_job_dispatched'] = false;
                $results['embedding_error'] = $e->getMessage();
                $results['steps'][] = ['step' => 8, 'action' => 'Embedding generation failed', 'status' => 'error', 'error' => $e->getMessage()];
                // Don't throw - continue with cleanup
            }

            // ============================================
            // STEP 9: CLEANUP
            // ============================================
            cleanup_step:
            Log::info('Step 9: Cleaning up temporary files');
            
            if (isset($localVideoPath) && file_exists($localVideoPath)) {
                @unlink($localVideoPath);
            }
            if (isset($audioPath) && file_exists($audioPath)) {
                @unlink($audioPath);
            }
            
            $results['steps'][] = ['step' => 9, 'action' => 'Cleanup completed', 'status' => 'completed'];

            // ============================================
            // FINAL RESULTS
            // ============================================
            $endTime = microtime(true);
            $results['processing_time_seconds'] = round($endTime - $startTime, 2);
            $results['processing_time_minutes'] = round($results['processing_time_seconds'] / 60, 2);
            $results['status'] = 'completed';
            $results['completed_at'] = now()->toDateTimeString();

            // Mark video as completed
            $video->markAsCompleted();

            Log::info('ProcessVideoJob completed successfully', [
                'user_id' => $this->userId,
                'video_id' => $youtubeVideoId,
                'processing_time_minutes' => $results['processing_time_minutes']
            ]);

            // Send email notification if user has enabled it
            if ($user->email_notifications) {
                try {
                    Mail::to($user->email)->send(
                        new VideoProcessedNotification(
                            $video,
                            $user,
                            $results['processing_time_minutes'],
                            'completed'
                        )
                    );
                    Log::info('Email notification sent to user', [
                        'user_id' => $this->userId,
                        'email' => $user->email,
                        'video_id' => $this->videoId
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send email notification', [
                        'user_id' => $this->userId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Send email notification to all admins
            try {
                $admins = User::role('admin')->get();
                Log::info('Sending notifications to admins', ['count' => $admins->count(), 'video_id' => $this->videoId]);
                
                foreach ($admins as $admin) {
                    try {
                        Mail::to($admin->email)->send(
                            new VideoProcessedNotification(
                                $video,
                                $admin,
                                $results['processing_time_minutes'],
                                'completed'
                            )
                        );
                        Log::info('Admin notification sent', [
                            'admin_id' => $admin->id,
                            'admin_email' => $admin->email,
                            'video_id' => $this->videoId
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send admin email notification', [
                            'admin_id' => $admin->id,
                            'admin_email' => $admin->email,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to retrieve admins for notification', [
                    'error' => $e->getMessage(),
                    'video_id' => $this->videoId
                ]);
            }

            // Store results in cache for retrieval
            cache()->put('video_processing_result_' . $this->userId . '_' . time(), $results, now()->addHours(24));

        } catch (\Exception $e) {
            $results['status'] = 'failed';
            $results['error'] = $e->getMessage();
            $results['error_trace'] = $e->getTraceAsString();

            // Mark video as failed
            if (isset($video)) {
                $video->markAsFailed($e->getMessage());
                
                // If AssemblyAI failed because there was no spoken audio, treat it as a video with no audio
                if (str_contains($e->getMessage(), 'language_detection cannot be performed on files with no spoken audio')) {
                    $video->update(['has_audio' => false]);
                }
            }

            // Send failure email notification if user has enabled it
            if (isset($user) && isset($video) && $user->email_notifications) {
                try {
                    Mail::to($user->email)->send(
                        new VideoProcessedNotification(
                            $video,
                            $user,
                            null,
                            'failed'
                        )
                    );
                    Log::info('Failure email notification sent to user', [
                        'user_id' => $this->userId,
                        'email' => $user->email,
                        'video_id' => $this->videoId
                    ]);
                } catch (\Exception $emailError) {
                    Log::error('Failed to send failure email notification', [
                        'user_id' => $this->userId,
                        'error' => $emailError->getMessage()
                    ]);
                }
            }

            // Send failure email notification to all admins
            if (isset($video)) {
                try {
                    $admins = User::role('admin')->get();
                    Log::info('Sending failure notifications to admins', ['count' => $admins->count(), 'video_id' => $this->videoId]);
                    
                    foreach ($admins as $admin) {
                        try {
                            Mail::to($admin->email)->send(
                                new VideoProcessedNotification(
                                    $video,
                                    $admin,
                                    null,
                                    'failed'
                                )
                            );
                            Log::info('Admin failure notification sent', [
                                'admin_id' => $admin->id,
                                'admin_email' => $admin->email,
                                'video_id' => $this->videoId
                            ]);
                        } catch (\Exception $emailError) {
                            Log::error('Failed to send admin failure email notification', [
                                'admin_id' => $admin->id,
                                'admin_email' => $admin->email,
                                'error' => $emailError->getMessage()
                            ]);
                        }
                    }
                } catch (\Exception $adminError) {
                    Log::error('Failed to retrieve admins for failure notification', [
                        'error' => $adminError->getMessage(),
                        'video_id' => $this->videoId
                    ]);
                }
            }

            Log::error('ProcessVideoJob failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Cleanup on failure
            if (isset($localVideoPath) && file_exists($localVideoPath)) {
                @unlink($localVideoPath);
            }
            if (isset($audioPath) && file_exists($audioPath)) {
                @unlink($audioPath);
            }

            // Store error result
            cache()->put('video_processing_result_' . $this->userId . '_' . time(), $results, now()->addHours(24));
            throw $e; // Re-throw to mark job as failed
        }
    }

    /**
     * Refine transcript text using OpenAI to fix glitches, duplications, and errors
     * 
     * @param \OpenAI\Client $client OpenAI client
     * @param string $text Raw transcript text
     * @param string $language Detected language code
     * @return string|null Refined transcript text
     */
    protected function refineTranscriptText($client, string $text, string $language): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        // Determine language name for better prompting
        $languageName = $this->getLanguageName($language);

        try {
            Log::info('Refining transcript text', [
                'language' => $languageName,
                'original_length' => strlen($text),
                'text_preview' => substr($text, 0, 200)
            ]);

            $prompt = "You are a professional transcript editor. Your task is to refine and clean the following {$languageName} transcript that was generated by an AI speech-to-text system.

IMPORTANT INSTRUCTIONS:
1. Fix word duplications (e.g., 'the the' → 'the')
2. Remove glitches and garbled text
3. Fix obvious transcription errors
4. Correct grammar and punctuation
5. Remove filler words only if excessive (um, uh, like, you know)
6. Maintain the EXACT same meaning and content
7. Keep all speaker information intact if present
8. Do NOT add, remove, or change any factual content
9. For {$languageName} text, preserve the original language and script
10. Return ONLY the refined transcript text, no explanations

TRANSCRIPT TO REFINE:
{$text}

REFINED TRANSCRIPT:";

            $response = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a professional transcript editor specializing in cleaning and refining AI-generated transcripts while preserving their exact meaning.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.3, // Low temperature for consistency
                'max_tokens' => 16000,
            ]);

            $refinedText = trim($response->choices[0]->message->content);

            Log::info('Transcript text refined successfully', [
                'original_length' => strlen($text),
                'refined_length' => strlen($refinedText),
                'characters_saved' => strlen($text) - strlen($refinedText)
            ]);

            return $refinedText;

        } catch (\Exception $e) {
            Log::error('Failed to refine transcript text', [
                'error' => $e->getMessage()
            ]);
            return $text; // Return original on error
        }
    }

    /**
     * Refine utterances (speaker segments) using OpenAI
     * 
     * @param \OpenAI\Client $client OpenAI client
     * @param array $utterances Array of utterances from AssemblyAI
     * @param string $language Detected language code
     * @return array Refined utterances in the same format
     */
    protected function refineUtterances($client, array $utterances, string $language): array
    {
        $refinedUtterances = [];
        $languageName = $this->getLanguageName($language);
        
        // Process utterances in batches to avoid token limits
        $batchSize = 15;
        $batches = array_chunk($utterances, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            try {
                Log::info("Refining utterances batch", [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'utterances_in_batch' => count($batch)
                ]);

                // Build transcript text from batch
                $batchText = [];
                foreach ($batch as $index => $utterance) {
                    $speaker = $utterance['speaker'] ?? 'Unknown';
                    $text = $utterance['text'] ?? '';
                    $batchText[] = "[{$index}] {$speaker}: {$text}";
                }
                $combinedText = implode("\n", $batchText);
                $utteranceCount = count($batch);

                $prompt = "You are a professional transcript editor. Refine the following {$languageName} transcript segments by fixing glitches, duplications, and errors.

CRITICAL RULES:
1. Fix word duplications (e.g., 'I I think' → 'I think')
2. Remove glitches and garbled text
3. Fix obvious transcription errors
4. Correct grammar and punctuation while preserving natural speech
5. Keep the speaker labels EXACTLY as they are
6. Maintain line numbers [0], [1], [2], etc.
7. Do NOT change speaker names
8. Do NOT add or remove content
9. For {$languageName}, preserve the original language and script
10. Return in the EXACT same format: [number] Speaker: text

CRITICAL: You MUST return EXACTLY {$utteranceCount} lines, one for each segment.
CRITICAL: Each line MUST follow this exact pattern: [number] Speaker: text
CRITICAL: Do NOT skip any segments. Do NOT combine segments. Do NOT add extra lines.

TRANSCRIPT SEGMENTS:
{$combinedText}

REFINED SEGMENTS:";

                $response = $client->chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a professional transcript editor. Refine transcript segments while preserving their structure and meaning exactly.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 8000,
                ]);

                $refinedText = trim($response->choices[0]->message->content);
                
                // Parse refined text back into utterances
                $batchRefinedUtterances = [];
                $lines = explode("\n", $refinedText);
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Match pattern: [index] Speaker: text
                    // More lenient regex to handle variations
                    if (preg_match('/^\[(\d+)\]\s*(.+?):\s*(.+)$/us', $line, $matches)) {
                        $index = (int)$matches[1];
                        $refinedSpeaker = trim($matches[2]);
                        $refinedTextContent = trim($matches[3]);
                        
                        // Use original utterance as base and update text
                        if (isset($batch[$index])) {
                            $originalUtterance = $batch[$index];
                            $originalUtterance['text'] = $refinedTextContent;
                            
                            // Update words array if it exists
                            if (isset($originalUtterance['words'])) {
                                // Keep words structure but note that text has been refined
                                // In future we could re-process words, but for now keep original timing
                            }
                            
                            $batchRefinedUtterances[] = $originalUtterance;
                        }
                    } else {
                        Log::warning("Could not parse refined line", [
                            'batch' => $batchIndex + 1,
                            'line' => substr($line, 0, 200)
                        ]);
                    }
                }
                
                // Validation: Check if we got all segments back
                if (count($batchRefinedUtterances) !== count($batch)) {
                    Log::error("Refinement lost segments! Falling back to original batch", [
                        'batch' => $batchIndex + 1,
                        'expected' => count($batch),
                        'received' => count($batchRefinedUtterances),
                        'raw_response' => substr($refinedText, 0, 1000)
                    ]);
                    
                    // Fallback to original batch
                    $refinedUtterances = array_merge($refinedUtterances, $batch);
                } else {
                    // Success - add refined batch
                    $refinedUtterances = array_merge($refinedUtterances, $batchRefinedUtterances);
                    
                    Log::info("Batch refined successfully", [
                        'batch' => $batchIndex + 1,
                        'refined_count' => count($batchRefinedUtterances)
                    ]);
                }

                // Small delay to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    usleep(500000); // 0.5 second
                }

            } catch (\Exception $e) {
                Log::error("Failed to refine utterances batch", [
                    'batch' => $batchIndex + 1,
                    'error' => $e->getMessage()
                ]);
                
                // On error, add original batch utterances
                $refinedUtterances = array_merge($refinedUtterances, $batch);
            }
        }
        
        return $refinedUtterances;
    }

    /**
     * Chunk long utterances into smaller sub-utterances based on maximum duration.
     * This prevents long continuous speeches from being grouped into massive blocks,
     * ensuring that timestamp seeking remains accurate.
     * 
     * @param array $utterances Array of utterances from AssemblyAI
     * @param int $maxDurationMs Maximum duration of an utterance in milliseconds
     * @return array Chunked utterances
     */
    protected function chunkUtterances(array $utterances, int $maxDurationMs = 15000): array
    {
        $chunked = [];

        foreach ($utterances as $utterance) {
            // Check if utterance has words and exceeds maximum duration
            if (isset($utterance['words']) && !empty($utterance['words']) && isset($utterance['start'], $utterance['end']) && ($utterance['end'] - $utterance['start'] > $maxDurationMs)) {
                $currentChunk = [
                    'speaker' => $utterance['speaker'] ?? 'A',
                    'text' => '',
                    'start' => null,
                    'end' => null,
                    'confidence' => $utterance['confidence'] ?? 0,
                    'words' => []
                ];
                $wordsText = [];

                foreach ($utterance['words'] as $word) {
                    if ($currentChunk['start'] === null) {
                        $currentChunk['start'] = $word['start'] ?? 0;
                    }

                    $wordsText[] = $word['text'] ?? '';
                    $currentChunk['end'] = $word['end'] ?? 0;
                    $currentChunk['words'][] = $word;

                    // If the current chunk's duration reaches maxDurationMs, finalize it and start a new one
                    if (($word['end'] - $currentChunk['start']) >= $maxDurationMs) {
                        $currentChunk['text'] = trim(implode(' ', $wordsText));
                        $chunked[] = $currentChunk;

                        // Reset for next chunk
                        $currentChunk = [
                            'speaker' => $utterance['speaker'] ?? 'A',
                            'text' => '',
                            'start' => null,
                            'end' => null,
                            'confidence' => $utterance['confidence'] ?? 0,
                            'words' => []
                        ];
                        $wordsText = [];
                    }
                }

                // Add any remaining words as the final chunk
                if (!empty($currentChunk['words'])) {
                    $currentChunk['text'] = trim(implode(' ', $wordsText));
                    $chunked[] = $currentChunk;
                }
            } else {
                // If it's short enough or has no words, leave it as is
                $chunked[] = $utterance;
            }
        }

        return $chunked;
    }

    /**
     * Get human-readable language name from language code
     * 
     * @param string $code Language code (e.g., 'en', 'ur')
     * @return string Language name (e.g., 'English', 'Urdu')
     */
    protected function getLanguageName(string $code): string
    {
        $languages = [
            'en' => 'English',
            'ur' => 'Urdu',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
        ];

        return $languages[$code] ?? 'Unknown Language';
    }

    /**
     * Translate utterances to a target language using OpenAI
     * 
     * @param \OpenAI\Client $client OpenAI client
     * @param array $utterances Array of utterances from AssemblyAI
     * @param string $targetLanguage Target language (e.g., 'Urdu', 'English')
     * @return array Translated utterances in the same format
     */
    protected function translateUtterances($client, array $utterances, string $targetLanguage): array
    {
        $translatedUtterances = [];
        
        // Process utterances in batches to avoid token limits
        $batchSize = 10;
        $batches = array_chunk($utterances, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            // Prepare text for translation
            $textsToTranslate = [];
            foreach ($batch as $index => $utterance) {
                $textsToTranslate[] = [
                    'index' => $index,
                    'text' => $this->sanitizeUtf8($utterance['text'] ?? '')
                ];
            }
            
            // Create JSON string for translation
            $jsonInput = json_encode($textsToTranslate, JSON_UNESCAPED_UNICODE);
            
            $maxRetries = 3;
            $attempt = 0;
            $success = false;
            $translatedTexts = $textsToTranslate; // Default fallback if all retries fail
            
            while ($attempt < $maxRetries && !$success) {
                $attempt++;
                try {
                    $response = $client->chat()->create([
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => "You are a professional translator. Translate the following JSON array of texts to {$targetLanguage}. 
                                Return ONLY a valid JSON object with a 'translations' key containing an array of objects with the same structure (index and text fields).
                                Preserve the exact meaning and context of each text.
                                Do not add any explanations or additional text outside the JSON.
                                If the text is already in {$targetLanguage}, return it as is." . 
                                ($targetLanguage === 'English' ? " HOWEVER, if the original text contains ANY Urdu/Arabic, you MUST translate it to English. You are strictly forbidden from outputting Urdu or Arabic characters. If you see an Islamic phrase or Quranic verse, translate its meaning to English. Your output must be 100% English text." : "")
                            ],
                            [
                                'role' => 'user',
                                'content' => $jsonInput
                            ]
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'max_tokens' => 4000,
                        'temperature' => 0.3 + ($attempt * 0.2), // Increase temperature slightly on retries
                    ]);
                    
                    $translatedJson = $response->choices[0]->message->content ?? '[]';
                    
                    // Clean the response (remove markdown code blocks if present)
                    $translatedJson = preg_replace('/^```json\s*|\s*```$/m', '', trim($translatedJson));
                    $translatedJson = preg_replace('/^```\s*|\s*```$/m', '', trim($translatedJson));
                    
                    $decoded = json_decode($translatedJson, true);
                    $candidateTexts = $decoded['translations'] ?? null;
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($candidateTexts)) {
                        $hasUrdu = false;
                        if ($targetLanguage === 'English') {
                            foreach ($candidateTexts as &$ct) {
                                if (isset($ct['text']) && preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $ct['text'])) {
                                    $hasUrdu = true;
                                    // If this is the final attempt, we brute-force remove the Urdu characters
                                    if ($attempt == $maxRetries) {
                                        $ct['text'] = preg_replace('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', '', $ct['text']);
                                        $ct['text'] = trim(preg_replace('/\s+/', ' ', $ct['text'])); // Clean up spaces
                                        $hasUrdu = false; // We just stripped it out, so treat it as successful
                                    }
                                }
                            }
                        }
                        
                        // Keep the latest candidate text so we don't fall back to 100% Urdu original text
                        $translatedTexts = $candidateTexts;

                        if (!$hasUrdu) {
                            $success = true;
                        } else {
                            Log::warning("Translation batch {$batchIndex} attempt {$attempt} contained Urdu. Retrying...");
                        }
                    } else {
                        Log::error("Translation JSON parse error for batch {$batchIndex} attempt {$attempt}", [
                            'target_language' => $targetLanguage,
                            'error' => json_last_error_msg(),
                            'raw_response' => substr($translatedJson, 0, 500)
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Translation exception for batch {$batchIndex} attempt {$attempt}: " . $e->getMessage());
                }
            }
                
                // Map translated texts back to utterances
                $batchTranslated = [];
                foreach ($batch as $index => $utterance) {
                    $translatedText = $utterance['text'] ?? ''; // Default to original
                    
                    // Find matching translated text
                    foreach ($translatedTexts as $translated) {
                        if (isset($translated['index']) && $translated['index'] == $index) {
                            $translatedText = $translated['text'] ?? $utterance['text'];
                            break;
                        }
                    }
                    
                    // Create translated utterance with same structure
                    $batchTranslated[] = [
                        'speaker' => $utterance['speaker'] ?? 'A',
                        'text' => $translatedText,
                        'start' => $utterance['start'] ?? 0,
                        'end' => $utterance['end'] ?? 0,
                        'confidence' => $utterance['confidence'] ?? 0,
                        'words' => $utterance['words'] ?? [],
                    ];
                }
                
                // Validation: Ensure all segments were processed
                if (count($batchTranslated) !== count($batch)) {
                    Log::error("Translation batch size mismatch", [
                        'batch' => $batchIndex + 1,
                        'expected' => count($batch),
                        'translated' => count($batchTranslated),
                        'target_language' => $targetLanguage
                    ]);
                }
                
                $translatedUtterances = array_merge($translatedUtterances, $batchTranslated);
                
                Log::info("Translation batch completed", [
                    'batch' => $batchIndex + 1,
                    'target_language' => $targetLanguage,
                    'segments' => count($batchTranslated)
                ]);
                
                // Small delay between batches to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    usleep(500000); // 0.5 second delay
                }
        }
        
        return $translatedUtterances;
    }

    /**
     * Translate a text string to a target language using OpenAI
     * 
     * @param \OpenAI\Client $client OpenAI client
     * @param string $text Text to translate
     * @param string $targetLanguage Target language (e.g., 'Urdu', 'English')
     * @return string|null Translated text
     */
    protected function translateText($client, string $text, string $targetLanguage): ?string
    {
        if (empty(trim($text))) {
            return null;
        }
        $maxRetries = 3;
        $attempt = 0;
        $success = false;
        $translatedText = $text; // Default fallback
        
        while ($attempt < $maxRetries && !$success) {
            $attempt++;
            try {
                $response = $client->chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are a professional translator. Translate the following text to {$targetLanguage}. 
                            Preserve the exact meaning and context.
                            Do not add any explanations, just return the translated text.
                            If the text is already in {$targetLanguage}, return it as is." . 
                            ($targetLanguage === 'English' ? " HOWEVER, if the original text contains ANY Urdu/Arabic, you MUST translate it to English. You are strictly forbidden from outputting Urdu or Arabic characters. If you see an Islamic phrase or Quranic verse, translate its meaning to English. Your output must be 100% English text." : "")
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->sanitizeUtf8($text)
                        ]
                    ],
                    'max_tokens' => 2000,
                    'temperature' => 0.3 + ($attempt * 0.2),
                ]);
                
                $candidateText = $response->choices[0]->message->content ?? $text;
                
                $hasUrdu = false;
                if ($targetLanguage === 'English' && preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $candidateText)) {
                    $hasUrdu = true;
                    // If final attempt, brute-force strip the characters
                    if ($attempt == $maxRetries) {
                        $candidateText = preg_replace('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', '', $candidateText);
                        $candidateText = trim(preg_replace('/\s+/', ' ', $candidateText));
                        $hasUrdu = false;
                    }
                }
                
                // Keep the latest text as fallback
                $translatedText = $candidateText;

                if (!$hasUrdu) {
                    $success = true;
                } else {
                    Log::warning("Text translation attempt {$attempt} contained Urdu. Retrying...");
                }
            } catch (\Exception $e) {
                Log::error("Text translation exception attempt {$attempt}: " . $e->getMessage());
            }
        }
        
        return $translatedText;
    }

    /**
     * Tag speakers in translated transcripts (Urdu and English)
     * 
     * @param Video $video The video model
     * @param array $identificationData Speaker identification data from Pyannote
     * @param \App\Services\SpeakerTaggingService $taggingService The tagging service
     */
    protected function tagTranslatedTranscripts($video, array $identificationData, $taggingService): void
    {
        // Tag Urdu transcript if exists
        if (!empty($video->transcript_urdu)) {
            try {
                $urduData = is_string($video->transcript_urdu) 
                    ? json_decode($video->transcript_urdu, true) 
                    : $video->transcript_urdu;

                if (!empty($urduData)) {
                    $taggedUrdu = $taggingService->tagSpeakers($urduData, $identificationData);
                    $video->update([
                        'transcript_urdu' => json_encode($taggedUrdu['taggedTranscript'])
                    ]);
                    
                    Log::info('Urdu transcript tagged with speaker names', [
                        'video_id' => $video->id,
                        'tagged_segments' => $taggedUrdu['statistics']['taggedSegments']
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to tag Urdu transcript', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Tag English transcript if exists
        if (!empty($video->transcript_english)) {
            try {
                $englishData = is_string($video->transcript_english) 
                    ? json_decode($video->transcript_english, true) 
                    : $video->transcript_english;

                if (!empty($englishData)) {
                    $taggedEnglish = $taggingService->tagSpeakers($englishData, $identificationData);
                    $video->update([
                        'transcript_english' => json_encode($taggedEnglish['taggedTranscript'])
                    ]);
                    
                    Log::info('English transcript tagged with speaker names', [
                        'video_id' => $video->id,
                        'tagged_segments' => $taggedEnglish['statistics']['taggedSegments']
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to tag English transcript', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Sanitize UTF-8 string to remove/fix malformed characters
     * This is especially important for Urdu and other non-ASCII text
     * 
     * @param string $text
     * @return string
     */
    protected function sanitizeUtf8(string $text): string
    {
        // First, try to detect and fix encoding
        $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        
        if ($encoding !== 'UTF-8' && $encoding !== false) {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }
        
        // Remove BOM if present
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        
        // Remove NULL bytes and other control characters (except newlines, tabs, carriage returns)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        
        // Use iconv to strip invalid UTF-8 sequences
        $text = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        
        if ($text === false) {
            // If iconv fails completely, try a more aggressive approach
            $text = preg_replace('/[\x80-\xFF]/', '', $text);
        }
        
        // Final validation - ensure it's valid JSON-encodable
        $test = json_encode($text, JSON_UNESCAPED_UNICODE);
        if ($test === false) {
            // Remove any remaining problematic characters
            $text = preg_replace('/[^\x20-\x7E\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\n\r\t]/u', '', $text);
        }
        
        return $text ?: '';
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessVideoJob permanently failed', [
            'user_id' => $this->userId,
            'video_path' => $this->dropboxVideoPath,
            'error' => $exception->getMessage()
        ]);
    }
}
