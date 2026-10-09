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
                
                $duration = $getDuration($filePath);
                
                // Validate duration: must be between 15 seconds and 30 seconds
                if ($duration < 15) {
                    // Delete the uploaded file since it's invalid
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    return $this->errorResponse('Audio duration must be at least 15 seconds. Current duration: ' . round($duration, 1) . ' seconds.', 422);
                }
                
                if ($duration > 30) {
                    // Delete the uploaded file since it's invalid
                    if (Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    return $this->errorResponse('Audio duration must not exceed 30 seconds. Current duration: ' . round($duration, 1) . ' seconds.', 422);
                }
                
                $oldPath=$voiceSample->file_path;
                $voiceSample->file_path = $filePath;
                $voiceSample->duration = $duration;
            }
            $voiceSample->name = $request->input('name');
            if ($voiceSample->isDirty(['name','file_path'])) {
                $voiceSample->voiceprint=null;$voiceSample->pyannote_job_id=null;$voiceSample->validation_state='unreviewed';
                $voiceSample->quality_review=null;$voiceSample->voiceprint_fingerprint=null;
            }
            $voiceSample->save();
            if(isset($oldPath)&&$oldPath!==$voiceSample->file_path)Storage::disk('public')->delete($oldPath);

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
        
        // Validate duration: must be between 15 seconds and 30 seconds
        if ($duration < 15) {
            // Delete the uploaded file since it's invalid
            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }
            return $this->errorResponse('Audio duration must be at least 15 seconds. Current duration: ' . round($duration, 1) . ' seconds.', 422);
        }
        
        if ($duration > 30) {
            // Delete the uploaded file since it's invalid
            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }
            return $this->errorResponse('Audio duration must not exceed 30 seconds. Current duration: ' . round($duration, 1) . ' seconds.', 422);
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

    private function createVoiceprint(VoiceSample $sample)
    {
        // A new generation clears the previous biometric before any request.
        $sample->update(['voiceprint'=>null,'pyannote_job_id'=>null,'validation_state'=>'unreviewed',
            'quality_review'=>null,'voiceprint_fingerprint'=>null,'voiceprint_model'=>config('speaker-identification.model'),
            'voiceprint_revision'=>config('speaker-identification.revision')]);
        if (!env('PYANNOTE_API_KEY') || !config('speaker-identification.revision')) return;
        try {
            $response=Http::connectTimeout(10)->timeout(60)->withToken(env('PYANNOTE_API_KEY'))
                ->post('https://api.pyannote.ai/v1/voiceprint',[
                    'url'=>url('voice-samples/'.basename($sample->file_path)),
                    'model'=>config('speaker-identification.model'),'webhook'=>url('/webhook/pyannote/voiceprint')]);
            if (!$response->successful() || !is_string($response->json('jobId'))) throw new \RuntimeException('Voiceprint submission failed');
            $sample->update(['pyannote_job_id'=>$response->json('jobId')]);
        } catch (\Throwable $e) {
            Log::warning('Voiceprint provider unavailable',['sample_id'=>$sample->id,'exception_type'=>get_class($e)]);
        }
    }

    public function handleVoiceprintWebhook(Request $request)
    {
        $job=$request->input('jobId');
        if (!is_string($job) || !$sample=VoiceSample::where('pyannote_job_id',$job)->first()) return response()->json(['message'=>'Stale job'],404);
        // Verify callback contents using authenticated provider retrieval.
        try {
            $response=Http::connectTimeout(10)->timeout(60)->withToken(env('PYANNOTE_API_KEY'))->get('https://api.pyannote.ai/v1/jobs/'.rawurlencode($job));
            if (!$response->successful()) return response()->json(['message'=>'Provider unavailable'],503);
            if ($response->json('status')!=='succeeded') return response()->json(['message'=>'Not completed'],202);
            $value=$response->json('output.voiceprint');$hash=\App\Services\SpeakerIdentityPolicy::fingerprint($value);
            if (!$hash) return response()->json(['message'=>'Invalid voiceprint'],422);
            if ($sample->voiceprint_fingerprint===$hash) return response()->json(['message'=>'Already processed']);
            // A replacement upload invalidates old callbacks via the current job ID.
            VoiceSample::where('id',$sample->id)->where('pyannote_job_id',$job)->update([
                'voiceprint'=>$value,'voiceprint_fingerprint'=>$hash,'validation_state'=>'unreviewed']);
            Log::info('Voiceprint ready for review',['sample_id'=>$sample->id,'model'=>$sample->voiceprint_model]);
            return response()->json(['message'=>'Voiceprint awaiting quality review']);
        } catch (\Throwable $e) { return response()->json(['message'=>'Provider unavailable'],503); }
    }

    /**
     * Handle Pyannote speaker identification webhook callback
     * This is called by Pyannote API when the identify job completes
     */
    public function handleIdentifyWebhook(Request $request)
    {
        try {
            Log::info('Pyannote identify webhook received', [
                'job_id' => $request->input('jobId')
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
