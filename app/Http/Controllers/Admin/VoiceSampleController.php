<?php

namespace App\Http\Controllers\Admin;

use App\Models\VoiceSample;
use Illuminate\Http\Request;
use App\Http\Traits\ResponseTrait;
use App\Http\Controllers\Controller;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\AssemblyAiService;

class VoiceSampleController extends Controller
{
    use ResponseTrait;
    public function index()
    {
        return $this->inertiaResponse('VoiceSample');
    }

    public function getVoiceSamples(Request $request)
    {
        $page = isset($request['pagination']['page']) ? max((int) $request['pagination']['page'], 0) : 0;
        $pageSize = isset($request['pagination']['pageSize']) ? max((int) $request['pagination']['pageSize'], 1) : 10;
        $sortOrder = (!empty($request['sort']) && in_array(strtolower($request['sort']), ['asc', 'desc']))
            ? strtolower($request['sort'])
            : 'desc';

        // Get all voice samples (shared across all admins)
        $voiceSamplesQuery = VoiceSample::orderBy('created_at', $sortOrder);
        $totalVoiceSamples = $voiceSamplesQuery->count();
        $voiceSamples = $voiceSamplesQuery
            ->skip($page * $pageSize)
            ->take($pageSize)
            ->get();
        return $this->successResponse([
            'voiceSamples' => $voiceSamples,
            'total' => $totalVoiceSamples,
        ]);
    }
    public function uploadVoiceSample(Request $request)
    {
        Log::info('UploadVoiceSample request received', [
            'user_id' => auth()->id(),
            'request_data' => $request->except('file'),
            'has_file' => $request->hasFile('file'),
        ]);
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required_without:id|file|mimes:mp3,wav,ogg|max:10240',
        ]);

        $getDuration = function ($filePath) {
            $fullPath = storage_path('app/public/' . $filePath);
            try {
                // Use system ffprobe command (works on both Linux and Windows if ffprobe is in PATH)
                $process = new Process([
                    'ffprobe',
                    '-v',
                    'error',
                    '-show_entries',
                    'format=duration',
                    '-of',
                    'default=noprint_wrappers=1:nokey=1',
                    $fullPath
                ]);
                $process->run();
                
                if ($process->isSuccessful()) {
                    $duration = (float) trim($process->getOutput());
                    Log::info('Audio duration extracted', [
                        'file_path' => $filePath,
                        'duration' => $duration
                    ]);
                    return $duration;
                } else {
                    Log::error('ffprobe failed to get duration', [
                        'file_path' => $filePath,
                        'error' => $process->getErrorOutput()
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Exception while getting audio duration', [
                    'file_path' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }
            return 0;
        };
        if ($request->has('id') && $request->id != '') {
            $voiceSample = VoiceSample::find($request->input('id'));
            if (!$voiceSample) {
                return $this->errorResponse('Voice sample not found', 404);
            }
            
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $filePath = $file->store('voice_samples', 'public');
                
                // Check if file upload failed
                if (!$filePath) {
                    Log::error('Failed to store voice sample file', [
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize()
                    ]);
                    return $this->errorResponse('Failed to upload file. File may be too large or storage is unavailable. Maximum allowed size is ' . ini_get('upload_max_filesize'), 422);
                }
                
                // Delete old file only after successful new upload
                if (Storage::disk('public')->exists($voiceSample->file_path)) {
                    Storage::disk('public')->delete($voiceSample->file_path);
                }
                
                $duration = $getDuration($filePath);
                
                // Validate duration: must be between 15 seconds and 2 minutes (120 seconds)
                if ($duration < 15) {
                    // Delete the uploaded file since it's invalid
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    return $this->errorResponse('Audio duration must be at least 15 seconds. Current duration: ' . round($duration, 1) . ' seconds.', 422);
                }
                
                if ($duration > 120) {
                    // Delete the uploaded file since it's invalid
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    return $this->errorResponse('Audio duration must not exceed 2 minutes (120 seconds). Current duration: ' . round($duration, 1) . ' seconds.', 422);
                }
                
                $voiceSample->file_path = $filePath;
                $voiceSample->duration = $duration;
            }
            $voiceSample->name = $request->input('name');
            $voiceSample->save();

            // Call Pyannote API to create voiceprint for updated file
            if ($request->hasFile('file')) {
                $this->createVoiceprint($voiceSample);
            }

            return $this->successResponse();
        }

        // Create new
        $file = $request->file('file');
        $filePath = $file->store('voice_samples', 'public');
        
        // Check if file upload failed
        if (!$filePath) {
            Log::error('Failed to store voice sample file', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize()
            ]);
            return $this->errorResponse('Failed to upload file. File may be too large or storage is unavailable. Maximum allowed size is ' . ini_get('upload_max_filesize'), 422);
        }
        
        $duration = $getDuration($filePath);
        
        // Validate duration: must be between 15 seconds and 2 minutes (120 seconds)
        if ($duration < 15) {
            // Delete the uploaded file since it's invalid
            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }
            return $this->errorResponse('Audio duration must be at least 15 seconds. Current duration: ' . round($duration, 1) . ' seconds.', 422);
        }
        
        if ($duration > 120) {
            // Delete the uploaded file since it's invalid
            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }
            return $this->errorResponse('Audio duration must not exceed 2 minutes (120 seconds). Current duration: ' . round($duration, 1) . ' seconds.', 422);
        }

        $voiceSample = VoiceSample::create([
            'user_id' => auth()->id(),
            'name' => $request->input('name'),
            'file_path' => $filePath,
            'duration' => $duration,
        ]);

        // Call Pyannote API to create voiceprint
        $this->createVoiceprint($voiceSample);

        return $this->successResponse();
    }
    public function deleteVoiceSample($id)
    {
        $voiceSample = VoiceSample::find($id);
        if (!$voiceSample) {
            return $this->errorResponse('Voice sample not found', 404);
        }
        
        // Delete the file from storage using Storage facade
        if (Storage::disk('public')->exists($voiceSample->file_path)) {
            Storage::disk('public')->delete($voiceSample->file_path);
        }

        // Delete the database record
        $voiceSample->delete();

        return $this->successResponse();
    }

    private function createVoiceprint(VoiceSample $voiceSample)
    {
        try {
            // Extract filename from file_path (e.g., voice_samples/filename.mp3 -> filename.mp3)
            $filename = basename($voiceSample->file_path);

            // Generate public URL using the voice-samples route
            $publicUrl = url('voice-samples/' . $filename);
            Log::info('Generated public URL for voice sample', [
                'voice_sample_id' => $voiceSample->id,
                'public_url' => $publicUrl
            ]);

            Log::info('Creating voiceprint for voice sample', [
                'voice_sample_id' => $voiceSample->id,
                'voice_sample_name' => $voiceSample->name,
                'filename' => $filename,
                'url' => $publicUrl
            ]);

            // Call Pyannote API with webhook
            $webhookUrl = url('/webhook/pyannote/voiceprint');
            Log::info('Using webhook URL for Pyannote', [
                'webhook_url' => $webhookUrl
            ]); 

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('PYANNOTE_API_KEY', 'sk_0573c2ea6e7c472ca657e41a61e1287a'),
                'Content-Type' => 'application/json',
            ])->post('https://api.pyannote.ai/v1/voiceprint', [
                        'url' => $publicUrl,
                        'webhook' => $webhookUrl
                    ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Pyannote voiceprint created successfully', [
                    'voice_sample_id' => $voiceSample->id,
                    'job_id' => $data['jobId'] ?? null,
                    'status' => $data['status'] ?? null,
                    'response' => $data
                ]);

                // Save job_id to database
                if (isset($data['jobId'])) {
                    $voiceSample->update(['pyannote_job_id' => $data['jobId']]);
                    $this->checkVoiceprintStatus($data['jobId'], $voiceSample->id);
                }
            } else {
                Log::error('Pyannote voiceprint creation failed', [
                    'voice_sample_id' => $voiceSample->id,
                    'status' => $response->status(),
                    'error' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while creating voiceprint', [
                'voice_sample_id' => $voiceSample->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function checkVoiceprintStatus($jobId, $voiceSampleId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('PYANNOTE_API_KEY', 'sk_0573c2ea6e7c472ca657e41a61e1287a'),
            ])->get("https://api.pyannote.ai/v1/jobs/{$jobId}");

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Pyannote voiceprint status checked', [
                    'voice_sample_id' => $voiceSampleId,
                    'job_id' => $jobId,
                    'status' => $data['status'] ?? null,
                    'voiceprint' => $data['output']['voiceprint'] ?? null,
                ]);

                // If voiceprint is ready (status = succeeded), save it to database
                if (isset($data['status']) && $data['status'] === 'succeeded' && isset($data['output']['voiceprint'])) {
                    $voiceSample = VoiceSample::find($voiceSampleId);
                    if ($voiceSample) {
                        $voiceSample->update(['voiceprint' => $data['output']['voiceprint']]);
                        // Log::info('Voiceprint saved to database', [
                        //     'voice_sample_id' => $voiceSampleId,
                        //     'voiceprint_length' => strlen($data['output']['voiceprint'])
                        // ]);
                    }
                }
            } else {
                Log::error('Pyannote status check failed', [
                    'voice_sample_id' => $voiceSampleId,
                    'job_id' => $jobId,
                    'status' => $response->status(),
                    'error' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception while checking voiceprint status', [
                'voice_sample_id' => $voiceSampleId,
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function handleVoiceprintWebhook(Request $request)
    {
        try {
            Log::info('Pyannote webhook received', [
                'payload' => $request->all()
            ]);

            $jobId = $request->input('jobId');
            $status = $request->input('status');
            $voiceprint = $request->input('output.voiceprint');

            // Find voice sample by job ID
            $voiceSample = VoiceSample::where('pyannote_job_id', $jobId)->first();

            if (!$voiceSample) {
                Log::warning('Voice sample not found for webhook', [
                    'job_id' => $jobId
                ]);
                return response()->json(['message' => 'Voice sample not found'], 404);
            }

            // If job succeeded and voiceprint is available, save it
            if ($status === 'succeeded' && $voiceprint) {
                $voiceSample->update(['voiceprint' => $voiceprint]);

                Log::info('Voiceprint saved from webhook', [
                    'voice_sample_id' => $voiceSample->id,
                    'job_id' => $jobId,
                    'voiceprint_length' => strlen($voiceprint)
                ]);
            } elseif ($status === 'failed') {
                Log::error('Voiceprint job failed', [
                    'voice_sample_id' => $voiceSample->id,
                    'job_id' => $jobId,
                    'error' => $request->input('error')
                ]);
            }

            return response()->json(['message' => 'Webhook processed'], 200);

        } catch (\Exception $e) {
            Log::error('Exception in voiceprint webhook handler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Handle Pyannote speaker identification webhook callback
     * This is called by Pyannote API when the identify job completes
     */
    public function handleIdentifyWebhook(Request $request)
    {
        try {
            Log::info('Pyannote identify webhook received', [
                'payload' => $request->all()
            ]);

            // Process the webhook using AssemblyAiService
            $result = AssemblyAiService::processIdentifyWebhook($request->all());

            if ($result['success']) {
                Log::info('Pyannote identify webhook processed successfully', [
                    'result' => $result
                ]);
                return response()->json(['message' => $result['message']], 200);
            } else {
                Log::warning('Pyannote identify webhook processing returned failure', [
                    'result' => $result
                ]);
                return response()->json(['message' => $result['message']], 404);
            }

        } catch (\Exception $e) {
            Log::error('Exception in identify webhook handler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Internal server error'], 500);
        }
    }
}
