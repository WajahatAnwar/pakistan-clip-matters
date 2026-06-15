<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Video;
use App\Models\VoiceSample;

class AssemblyAiService
{
    protected $user;
    protected $apiKey;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->apiKey = $user->assemblyai_api_key ?? env('ASSEMBLYAI_API_KEY');
    }

    public function uploadAudio($audioPath)
    {
        $audioData = fopen($audioPath, 'r');
        $response = Http::timeout(300) // 5 minutes for large files
            ->withOptions([
                'verify' => false, // Disable SSL verification for local development
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'authorization' => $this->apiKey,
                'transfer-encoding' => 'chunked',
            ])
            ->withBody(stream_get_contents($audioData), 'application/octet-stream')
            ->post('https://api.assemblyai.com/v2/upload');
        fclose($audioData);
        return $response->json()['upload_url'] ?? null;
    }

    /**
     * Identify speakers using Pyannote API with webhook support
     * 
     * @param string $audioPath Path to audio file or URL
     * @param int|null $videoId Video ID for webhook callback (if provided, uses webhook)
     * @param bool $useWebhook Whether to use webhook (async) or polling (sync)
     * @return array Response data or job info if using webhook
     */
    public function identifySpeakers($audioPath,$useWebhook = false,$videoId = null)
    {
        // Convert local file path to public URL if needed
        $audioUrl = $this->convertToPublicUrl($audioPath);

        // Fetch all voice samples with voiceprints from database
        $voiceSamples = VoiceSample::get();

        // Build voiceprints array from database
        $voiceprints = [];
        foreach ($voiceSamples as $sample) {
            $voiceprints[] = [
                'label' => $sample->name ?? 'Speaker ' . $sample->id,
                'voiceprint' => $sample->voiceprint ?? '2CP7vnIJwL5r+5q/JcBYviZ85T5dJNG+tNUTv8OyI72PrK69CyA0PxsjtD+ICD0/mJPuPkZW+L4Irgu/3Dnyvwnehj3li50+3hT6PQlD5L70yZ2+CDEzPk+jJL9Dh0A/p7i9PvCtfj35NZw+fXgcvrUbJj64IZk/mJKUPljDVD9qUWO+fMjBPZhPhT59neY9RsQPP/miWL9cExi//3trvdohlL6Ump6+SR7wvi65TT7/DNo+w/oJP14UZj6Gg8k9CFKLO1DimLvcplc+sYxTvxuEnz94WsG7ELYfP5TQaz/6eYE+enHFvYqklb5dgNS9mezkPtaNfb/Y0Tc/om5lPlPb5Tx31cu+4Gv5O6STi78ttKm/LZJKvzAlNb6QhKc+pkkvvkxZkj2QTEI+tS1CP+bK4L0Qpba860/Wvm42UT8mRAi/MOlpPhnWjr9Yzc6+CXsuP8oe/T4fs9G+Yw5Pv7T6cT0ziru9OGwavRweB78G9De/xCNtP1xsDj4KI7E9kIXGPjk3PL8FsBU/PET0PKsZ8D3pFBu/mGkrP+QhvTy9c+S+Og6RvwDVmD43lYW9tBoevETvtD939wE/uC8cvqCqlzrueDy/yEJpvkRCN7/Bg2K+nhhivUbJsT43gQy/B9ZUv4GwNL/LARA/DE5KvvN5KD8YJDE/01lYvnLl7b7mK+u9bih2P8hy2T5SRSm/gPhnutZ+nj6EUea+hTTrvmwI/L/Lpji+ouAjvq3TRbymW6A+qvG0vjtbrz+CGpS+HFinv2qCUj8OzdW/ulAHv40Bz77s2hU/w7AIP5uIoj4mIpC+XOkAPzB7dD1xe+U+rw5JP0Mnjz0d2Py+GOEOPk58Fb/wS6E/g7W3PlpQUT49/+8+tKEBP/vxLb/wFv+9pS+Zv309l76/lv++CFw+v6m/Ir4W7ly+/m46vvkfAr0tttA++3dCPg5JP7/3hz8/Eihsvzqgm7091fy+llkUP8eJ+z6PSVc/aQGuPuKL1L3GmGO+qnzWPxuEWL8gCXc/8yxRP1Attj2gZ5Q6KOh6PgDS7L0QFYm8DJaHP6iDBD/pxCQ+lUiuvnJ5uj0zD5E+/I9/vrU9QT4/KXa+8emOPHHfF78qH7M+Zlshvo7J3r78DGO+bD5kvy5i0D3CM5M+kNqDPh0ZKj2MHB6+asnVPTglqb68ovg+1Hp6v4CHpD4CQuS+mpvEvwBwcT9pfK2/IEQmvzRaf720V9W94E2Xv1jL0r68RpS/fIwYP6d5bj9rmlM9MmNZPxIxlD70WNc+D6F7vxrVUT9oSbS+6h6RvgRmYj+6YIy+KnSzPhQDID2yBeI+JF0tv3TyMb6GV16/F7sDPmOXjz4tsWg+T5zMvg==',
            ];
        }

        // If no voiceprints found, log warning and return empty result
        if (empty($voiceprints)) {
            Log::warning('No voiceprints found in database for speaker identification');
            return [
                'error' => 'No voiceprints available',
                'message' => 'Please create voice samples first'
            ];
        }

        // Build payload with optional webhook
        $payload = [
            'url' => $audioUrl,
            'voiceprints' => $voiceprints,
            'matching' => [
                'threshold' => 50,
                'exclusive' => true
            ]
        ];

        // Add webhook URL if using webhook mode and videoId is provided
        if ($useWebhook && $videoId) {
            $webhookUrl = url('/webhook/pyannote/identify');
            $payload['webhook'] = $webhookUrl;
            
            Log::info('Using webhook for Pyannote identify', [
                'webhook_url' => $webhookUrl,
                'video_id' => $videoId
            ]);
        }

        Log::info('Calling Pyannote identify API', [
            'audio_url' => $audioUrl,
            'voiceprints_count' => count($voiceprints),
            'voice_samples' => array_column($voiceprints, 'label'),
            'use_webhook' => $useWebhook
        ]);

        $response = Http::timeout(60)
            ->withOptions([
                'verify' => false,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('PYANNOTE_API_KEY', 'sk_0573c2ea6e7c472ca657e41a61e1287a')
            ])
            ->post('https://api.pyannote.ai/v1/identify', $payload);

        $responseData = $response->json();

        Log::info('Pyannote identify API response', [
            'status_code' => $response->status(),
            'job_id' => $responseData['jobId'] ?? null,
            'status' => $responseData['status'] ?? null,
            'error' => $responseData['error'] ?? null,
            'message' => $responseData['message'] ?? null,
            'use_webhook' => $useWebhook
        ]);

        $jobId = $responseData['jobId'] ?? null;

        if ($jobId) {
            // If using webhook, store the job ID and return immediately
            if ($useWebhook && $videoId) {
                // Update video with the job ID for webhook tracking
                $video = Video::find($videoId);
                if ($video) {
                    $video->update([
                        'pyannote_job_id' => $jobId,
                        'pyannote_status' => 'processing'
                    ]);
                    
                    Log::info('Pyannote job submitted with webhook', [
                        'job_id' => $jobId,
                        'video_id' => $videoId,
                        'status' => 'processing'
                    ]);
                }
                
                return [
                    'jobId' => $jobId,
                    'status' => 'processing',
                    'message' => 'Job submitted, waiting for webhook callback',
                    'webhook_mode' => true
                ];
            }
            
            // Otherwise, poll the job until it completes (synchronous mode)
            $jobData = $this->pollPyannoteJob($jobId);
            return $jobData;
        }

        return $responseData;
    }

    /**
     * Poll Pyannote job until completion
     */
    public function pollPyannoteJob($jobId, $interval = 5, $maxAttempts = 60)
    {
        Log::info('Starting to poll Pyannote job', [
            'job_id' => $jobId,
            'interval' => $interval,
            'max_attempts' => $maxAttempts
        ]);

        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $data = $this->getJobData($jobId);
            $status = $data['status'] ?? null;

            Log::info('Pyannote job poll attempt', [
                'job_id' => $jobId,
                'attempt' => $attempts + 1,
                'status' => $status
            ]);

            if ($status === 'succeeded') {
                Log::info('Pyannote job completed successfully', [
                    'job_id' => $jobId,
                    'attempts' => $attempts + 1,
                    'diarization_count' => count($data['output']['diarization'] ?? []),
                    'identification_count' => count($data['output']['identification'] ?? [])
                ]);
                return $data;
            } elseif ($status === 'failed' || $status === 'error') {
                Log::error('Pyannote job failed', [
                    'job_id' => $jobId,
                    'status' => $status,
                    'error' => $data['error'] ?? 'Unknown error'
                ]);
                throw new \Exception('Pyannote job failed: ' . ($data['error'] ?? 'Unknown error'));
            }

            sleep($interval);
            $attempts++;
        }

        Log::error('Pyannote job polling timed out', [
            'job_id' => $jobId,
            'attempts' => $attempts
        ]);
        throw new \Exception('Pyannote job polling timed out after ' . ($maxAttempts * $interval) . ' seconds');
    }

    /**
     * Convert local file path to public URL
     */
    private function convertToPublicUrl($audioPath)
    {
        // If already a URL, return as is
        if (filter_var($audioPath, FILTER_VALIDATE_URL)) {
            return $audioPath;
        }

        // Extract filename from the local path
        // From: C:\xampp\htdocs\Clip Matters\storage\app/temp/audio_1763475306_691c7f6a7f12c.webm
        // To: audio_1763475306_691c7f6a7f12c.webm
        $filename = basename($audioPath);

        // Convert to public URL format
        // Pattern: https://clip.digitalmatters.pk/voice-samples/{filename}
        $publicUrl = 'https://clip.digitalmatters.pk/voice-samples/' . $filename;
        
        Log::info('Converted local path to public URL', [
            'original_path' => $audioPath,
            'public_url' => $publicUrl
        ]);

        return $publicUrl;
    }

    public function getJobData($jobId)
    {
        $response = Http::timeout(60)
            ->withOptions([
                'verify' => false,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'Authorization' => 'Bearer ' . env('PYANNOTE_API_KEY', 'sk_0573c2ea6e7c472ca657e41a61e1287a'),
            ])->get("https://api.pyannote.ai/v1/jobs/{$jobId}");

        $json = $response->json();

        // Log::info("Pyannote Job Status", [
        //     'job_id' => $json['jobId'] ?? null,
        //     'status' => $json['status'] ?? null,
        //     'error' => $json['error'] ?? null,
        //     'message' => $json['message'] ?? null,
        //     'full_response' => $json,
        //     'diarization_count' => count($json['output']['diarization'] ?? []),
        //     'identification_count' => count($json['output']['identification'] ?? []),
        // ]);

        // Individual speaker segments
        foreach ($json['output']['identification'] ?? [] as $segment) {
            // Log::info("Speaker Segment", [
            //     'speaker' => $segment['speaker'] ?? null,
            //     'start' => $segment['start'] ?? null,
            //     'end' => $segment['end'] ?? null,
            //     'match' => $segment['match'] ?? null,
            //     'dia_spk' => $segment['diarizationSpeaker'] ?? null,
            // ]);
        }

        // Voiceprints summary
        foreach ($json['output']['voiceprints'] ?? [] as $print) {
            Log::info("Voiceprint Summary", [
                'diarization_speaker' => $print['speaker'] ?? null,
                'match' => $print['match'] ?? null,
                'confidence' => $print['confidence'] ?? [],
            ]);
        }

        return $json;

        // Response format:
        // {
        //     "jobId": "999c886b-9335-452c-a067-7630ac9e9144",
        //     "status": "succeeded",
        //     "createdAt": "2025-11-18T12:37:14.750Z",
        //     "updatedAt": "2025-11-18T12:37:20.405Z",
        //     "output": {
        //         "diarization": [
        //             {
        //                 "speaker": "SPEAKER_01",
        //                 "start": 0.025,
        //                 "end": 11.045
        //             },
        //             {
        //                 "speaker": "SPEAKER_00",
        //                 "start": 11.545,
        //                 "end": 15.565
        //             }
        //         ],
        //         "identification": [
        //             {
        //                 "speaker": "Mr Imran Khan",
        //                 "start": 0.025,
        //                 "end": 11.045,
        //                 "diarizationSpeaker": "SPEAKER_01",
        //                 "match": "Mr Imran Khan"
        //             },
        //             {
        //                 "speaker": "Mr Junaid  Akram",
        //                 "start": 11.545,
        //                 "end": 15.565,
        //                 "diarizationSpeaker": "SPEAKER_00",
        //                 "match": "Mr Junaid  Akram"
        //             }
        //         ],
        //         "voiceprints": [
        //             {
        //                 "speaker": "SPEAKER_00",
        //                 "match": "Mr Junaid  Akram",
        //                 "confidence": {
        //                     "Mr Junaid  Akram": 90,
        //                     "Mr Imran Khan": 22
        //                 }
        //             },
        //             {
        //                 "speaker": "SPEAKER_01",
        //                 "match": "Mr Imran Khan",
        //                 "confidence": {
        //                     "Mr Junaid  Akram": 31,
        //                     "Mr Imran Khan": 82
        //                 }
        //             }
        //         ]
        //     }
        // }
    }

    public function requestTranscript($audioUrl, $options = [])
    {
        $default = [
            'audio_url' => $audioUrl,
            'speaker_labels' => true,
            'language_detection' => true,
            'summarization' => true,
            'summary_model' => 'informative',
            'summary_type' => 'bullets',
        ];
        $payload = array_merge($default, $options);
        $response = Http::timeout(60)
            ->withOptions([
                'verify' => false,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'authorization' => $this->apiKey,
                'content-type' => 'application/json',
            ])
            ->post('https://api.assemblyai.com/v2/transcript', $payload);
        return $response->json();
    }

    public function getTranscript($transcriptId)
    {
        $response = Http::timeout(60)
            ->withOptions([
                'verify' => false,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
            'authorization' => $this->apiKey,
        ])->get("https://api.assemblyai.com/v2/transcript/{$transcriptId}");
        return $response->json();
    }

    public function pollTranscript($transcriptId, $interval = 5, $maxAttempts = 60)
    {
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $data = $this->getTranscript($transcriptId);
            if ($data['status'] === 'completed') {
                return $data;
            } elseif ($data['status'] === 'error') {
                throw new \Exception('Transcription failed:' . ($data['error'] ?? 'Unknown error'));
            }
            sleep($interval);
            $attempts++;
        }
        throw new \Exception('Transcription polling timed out');
    }

    /**
     * Process Pyannote identify webhook callback
     * This method handles the async result from Pyannote API
     * 
     * @param array $payload Webhook payload from Pyannote
     * @return array Result of processing
     */
    public static function processIdentifyWebhook(array $payload)
    {
        $jobId = $payload['jobId'] ?? null;
        $status = $payload['status'] ?? null;
        $output = $payload['output'] ?? null;
        $error = $payload['error'] ?? null;

        Log::info('Processing Pyannote identify webhook', [
            'job_id' => $jobId,
            'status' => $status,
            'has_output' => !empty($output),
            'error' => $error
        ]);

        if (!$jobId) {
            Log::warning('Pyannote webhook missing jobId');
            return ['success' => false, 'message' => 'Missing jobId'];
        }

        // Find video by pyannote_job_id
        $video = Video::where('pyannote_job_id', $jobId)->first();

        if (!$video) {
            Log::warning('Video not found for Pyannote job', ['job_id' => $jobId]);
            return ['success' => false, 'message' => 'Video not found for job'];
        }

        if ($status === 'succeeded' && $output) {
            // Extract data from webhook payload
            $diarizationData = $output['diarization'] ?? [];
            $identificationData = $output['identification'] ?? [];
            $voiceprintsData = $output['voiceprints'] ?? [];

            Log::info('Pyannote identify webhook succeeded', [
                'job_id' => $jobId,
                'video_id' => $video->id,
                'diarization_count' => count($diarizationData),
                'identification_count' => count($identificationData),
                'voiceprints_count' => count($voiceprintsData)
            ]);

            // Update video with speaker identification data
            $video->update([
                'diarization_data' => $diarizationData,
                'identification_data' => $identificationData,
                'pyannote_status' => 'completed'
            ]);

            // Auto-tag speakers in transcript if we have identification data
            if (!empty($identificationData)) {
                try {
                    $speakersData = $video->speakers_data;
                    if (is_string($speakersData)) {
                        $speakersData = json_decode($speakersData, true);
                    }

                    if (!empty($speakersData)) {
                        $taggingService = new \App\Services\SpeakerTaggingService();
                        $tagResult = $taggingService->tagSpeakers($speakersData, $identificationData);

                        $video->update([
                            'speakers_data' => json_encode($tagResult['taggedTranscript']),
                            'speaker_mapping' => json_encode($tagResult['speakerMapping']),
                        ]);

                        Log::info('Auto-tagged speakers from webhook', [
                            'video_id' => $video->id,
                            'tagged_segments' => $tagResult['statistics']['taggedSegments'] ?? 0
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Error auto-tagging speakers from webhook', [
                        'video_id' => $video->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'success' => true, 
                'message' => 'Speaker identification completed',
                'video_id' => $video->id
            ];

        } elseif ($status === 'failed' || $status === 'error') {
            Log::error('Pyannote identify job failed', [
                'job_id' => $jobId,
                'video_id' => $video->id,
                'error' => $error
            ]);

            $video->update([
                'pyannote_status' => 'failed',
                'pyannote_error' => $error
            ]);

            return [
                'success' => false, 
                'message' => 'Job failed: ' . ($error ?? 'Unknown error'),
                'video_id' => $video->id
            ];
        }

        // Status is still processing or unknown
        // Log::info('Pyannote job status update', [
        //     'job_id' => $jobId,
        //     'video_id' => $video->id,
        //     'status' => $status
        // ]);

        return ['success' => true, 'message' => 'Status: ' . $status];
    }

    /**
     * Check Pyannote job status manually (can be used as fallback)
     * 
     * @param string $jobId Pyannote job ID
     * @return array Job status data
     */
    public function checkJobStatus($jobId)
    {
        $data = $this->getJobData($jobId);
        
        $video = Video::where('pyannote_job_id', $jobId)->first();
        
        if ($video && isset($data['status'])) {
            if ($data['status'] === 'succeeded') {
                // Process the result
                self::processIdentifyWebhook($data);
            } elseif ($data['status'] === 'failed') {
                $video->update([
                    'pyannote_status' => 'failed',
                    'pyannote_error' => $data['error'] ?? 'Unknown error'
                ]);
            }
        }
        
        return $data;
    }
}
