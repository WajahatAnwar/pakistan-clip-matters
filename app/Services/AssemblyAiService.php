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
        if (empty($this->apiKey)) {
            throw new \RuntimeException('AssemblyAI API key is not configured.');
        }

        $audioData = fopen($audioPath, 'r');
        if ($audioData === false) {
            throw new \RuntimeException("Unable to open audio file for AssemblyAI upload: {$audioPath}");
        }

        $response = Http::timeout(300) // 5 minutes for large files
            ->withOptions([
                'verify' => true, // Disable SSL verification for local development
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'authorization' => $this->apiKey,
                'transfer-encoding' => 'chunked',
            ])
            ->withBody(stream_get_contents($audioData), 'application/octet-stream')
            ->post('https://api.assemblyai.com/v2/upload');
        fclose($audioData);

        $data = $response->json();
        if (!$response->successful() || empty($data['upload_url'])) {
            Log::error('AssemblyAI audio upload failed', [
                'status_code' => $response->status(),
                'error' => $data['error'] ?? $data['message'] ?? $response->body(),
            ]);

            throw new \RuntimeException(
                'AssemblyAI audio upload failed: ' .
                ($data['error'] ?? $data['message'] ?? "HTTP {$response->status()}")
            );
        }

        return $data['upload_url'];
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

        $registry = app(SpeakerIdentityPolicy::class)->enroll(VoiceSample::get());
        $enrolled = $registry['samples'];
        Log::info('Speaker enrollment validation', ['eligible'=>count($enrolled), 'issues'=>$registry['issues']]);
        if (!$enrolled) return ['error'=>'no_valid_enrollment','message'=>'No reviewed compatible voiceprints','status'=>'unknown'];
        if (!config('speaker-identification.revision') || !is_numeric(config('speaker-identification.threshold')))
            return ['error'=>'identification_not_configured','message'=>'Identification acceptance criteria unavailable','status'=>'unknown'];
        if (!env('PYANNOTE_API_KEY')) throw new \RuntimeException('Identification provider is not configured');
        $voiceprints = array_map(fn($e)=>['label'=>$e['label'],'voiceprint'=>$e['voiceprint']], $enrolled);

        // Build payload with optional webhook
        $payload = [
            'url' => $audioUrl,
            'voiceprints' => $voiceprints,
            'model' => config('speaker-identification.model'),
            'confidence' => true,
            'matching' => [
                'threshold' => (float) config('speaker-identification.threshold'),
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
            'voiceprints_count' => count($voiceprints),
            'voice_samples' => array_column($voiceprints, 'label'),
            'use_webhook' => $useWebhook
        ]);

        $response = Http::timeout(60)
            ->withOptions([
                'verify' => true,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . env('PYANNOTE_API_KEY')
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

        if (!$response->successful() || !is_string($jobId) || $jobId==='') throw new \RuntimeException('Identification provider submission failed');
        if ($jobId) {
            \Illuminate\Support\Facades\DB::table('speaker_identification_runs')->insert([
                'job_id'=>$jobId,'video_id'=>$videoId,'enrollment'=>json_encode(array_map(fn($e)=>array_diff_key($e,['voiceprint'=>true]),$enrolled)),
                'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            if ($videoId) Video::whereKey($videoId)->update(['pyannote_job_id'=>$jobId,'pyannote_status'=>'processing']);
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
                'verify' => true,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'Authorization' => 'Bearer ' . env('PYANNOTE_API_KEY'),
            ])->get("https://api.pyannote.ai/v1/jobs/{$jobId}");

        $json = $response->json();
        if (!$response->successful() || !is_array($json)) throw new \RuntimeException('Identification provider read failed');
        if (($json['status']??null)==='succeeded') {
            $run=\Illuminate\Support\Facades\DB::table('speaker_identification_runs')->where('job_id',$jobId)->first();
            if (!$run) throw new \RuntimeException('Unregistered identification job');
            $active=app(SpeakerIdentityPolicy::class)->enroll(VoiceSample::get())['samples'];
            $activeByLabel=array_column($active,null,'label');
            $snapshot=array_values(array_filter(json_decode($run->enrollment,true),fn($e)=>
                isset($activeByLabel[$e['label']]) && $activeByLabel[$e['label']]['fingerprint']===$e['fingerprint']
                && $activeByLabel[$e['label']]['identity_key']===$e['identity_key']
                && $activeByLabel[$e['label']]['name']===$e['name']
                && ($e['revision']??null)===config('speaker-identification.revision')));
            $json['output']=app(SpeakerIdentityPolicy::class)->normalize($json['output']??[],$snapshot);
        }


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
        if (empty($this->apiKey)) {
            throw new \RuntimeException('AssemblyAI API key is not configured.');
        }

        if (empty($audioUrl)) {
            throw new \InvalidArgumentException('AssemblyAI audio URL is missing.');
        }

        $default = [
            'speaker_labels' => true,
            'language_detection' => true,
            'speech_models' => ['universal-2'],
        ];
        $payload = array_merge($default, $options);
        $response = Http::timeout(60)
            ->withOptions([
                'verify' => true,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
                'authorization' => $this->apiKey,
                'content-type' => 'application/json',
            ])
            ->post('https://api.assemblyai.com/v2/transcript', $payload);

        $data = $response->json();
        if (!$response->successful() || empty($data['id'])) {
            Log::error('AssemblyAI transcript request failed', [
                'status_code' => $response->status(),
                'error' => $data['error'] ?? $data['message'] ?? $response->body(),
            ]);

            throw new \RuntimeException(
                'AssemblyAI transcript request failed: ' .
                ($data['error'] ?? $data['message'] ?? "HTTP {$response->status()}")
            );
        }

        return $data;
    }

    public function getTranscript($transcriptId)
    {
        $response = Http::timeout(60)
            ->withOptions([
                'verify' => true,
                'connect_timeout' => 30,
            ])
            ->withHeaders([
            'authorization' => $this->apiKey,
        ])->get("https://api.assemblyai.com/v2/transcript/{$transcriptId}");
        $data = $response->json();
        if (!$response->successful()) {
            Log::error('AssemblyAI transcript status request failed', [
                'transcript_id' => $transcriptId,
                'status_code' => $response->status(),
                'error' => $data['error'] ?? $data['message'] ?? $response->body(),
            ]);

            throw new \RuntimeException(
                'AssemblyAI transcript status request failed: ' .
                ($data['error'] ?? $data['message'] ?? "HTTP {$response->status()}")
            );
        }

        return $data;
    }

    public function pollTranscript($transcriptId, $interval = 5, $maxAttempts = 60)
    {
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $data = $this->getTranscript($transcriptId);
            $status = $data['status'] ?? null;
            if ($status === 'completed') {
                return $data;
            } elseif ($status === 'error') {
                throw new \RuntimeException('Transcription failed: ' . ($data['error'] ?? 'Unknown error'));
            } elseif ($status === null) {
                throw new \RuntimeException('AssemblyAI transcript response is missing its status.');
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

        if ($video->pyannote_status === 'completed') return ['success'=>true,'message'=>'Already processed'];
        $verified=(new self(new User()))->getJobData($jobId);
        $status=$verified['status']??null; $error=$verified['error']??null;
        if ($status === 'succeeded') {
            if (($verified['status']??null)!=='succeeded') return ['success'=>false,'message'=>'Provider has not completed'];
            return \Illuminate\Support\Facades\DB::transaction(function() use($video,$jobId,$verified) {
            $video=Video::whereKey($video->id)->lockForUpdate()->firstOrFail();
            if($video->pyannote_job_id!==$jobId) return ['success'=>false,'message'=>'Stale identification job'];
            if($video->pyannote_status==='completed') return ['success'=>true,'message'=>'Already processed'];
            $output=$verified['output'];
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
            if (is_array($identificationData)) {
                try {
                    $speakersData = $video->speakers_data;
                    if (is_string($speakersData)) {
                        $speakersData = json_decode($speakersData, true);
                    }

                    if (!empty($speakersData)) {
                        $taggingService = new \App\Services\SpeakerTaggingService();
                        $tagResult = $taggingService->tagSpeakers($speakersData, $identificationData);
                        foreach (['transcript_english','transcript_urdu'] as $field) {
                            $translated=$video->$field;
                            for($i=0;$i<3&&is_string($translated);$i++) $translated=json_decode($translated,true);
                            if(is_array($translated)) $video->$field=$taggingService->tagSpeakers($translated,$identificationData)['taggedTranscript'];
                        }

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
            });

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
