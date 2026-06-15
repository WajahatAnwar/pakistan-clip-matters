<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GenerateVideoEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $videoId;
    
    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 3600;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;
    
    /**
     * The number of seconds to wait before retrying the job.
     * Exponential backoff: 30s, 60s, 120s
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Create a new job instance.
     */
    public function __construct($videoId)
    {
        $this->videoId = is_object($videoId) ? $videoId->id : $videoId;
        $this->onQueue('high');
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Fetch the video model - if it doesn't exist, delete this job
        $video = Video::find($this->videoId);
        
        if (!$video) {
            Log::warning("Video ID {$this->videoId} not found, skipping embedding generation");
            return;
        }
        
        Log::info("Starting embedding generation for video ID: " . $video->id);

        // Get or create embedding record
        $embedding = VideoEmbedding::firstOrCreate(
            ['video_id' => $video->id],
            [
                'status' => 'pending',
                'qdrant_collection' => 'video_transcript_segments',
                'vector_dimensions' => 3072, // OpenAI text-embedding-3-large
            ]
        );

        // Mark as processing
        $embedding->markAsProcessing();

        try {
            // Parse all video data
            $identificationData = $this->parseJsonField($video->identification_data, 'identification_data', $video->id);
            $speakersData = $this->parseJsonField($video->speakers_data, 'speakers_data', $video->id);
            $diarizationData = $this->parseJsonField($video->diarization_data, 'diarization_data', $video->id);

            if (!$identificationData || empty($identificationData)) {
                throw new \Exception("identification_data is empty or invalid");
            }

            if (!$speakersData || empty($speakersData)) {
                throw new \Exception("speakers_data is empty or invalid - no text to embed");
            }

            // Merge identification_data with speakers_data to add text to segments
            $enrichedSegments = $this->enrichSegmentsWithText($identificationData, $speakersData);

            if (empty($enrichedSegments)) {
                throw new \Exception("No valid segments with text found after merging data");
            }

            // Add segment_index to each segment for consistent point IDs across batches
            // This ensures batch 2's segment 0 doesn't overwrite batch 1's segment 0
            foreach ($enrichedSegments as $idx => &$segment) {
                $segment['segment_index'] = $idx;
            }
            unset($segment); // break reference

            Log::info("Processing " . count($enrichedSegments) . " segments for video ID: " . $video->id);

            // Call Python embedding service (FastAPI)
            $pythonServiceUrl = config('services.embedding.url', 'https://web-production-935af.up.railway.app');
            $apiKey = config('services.embedding.api_key', '');
            Log::info("Using Python embedding service URL: " . $pythonServiceUrl);

            // Process in batches — 500 segments per batch keeps payload under 5MB
            // Python service handles batch_info to only delete existing data on batch 1
            $totalSegmentsEmbedded = 0;
            $collectionName = 'video_transcript_segments';
            $batchSize = 500;
            $totalBatches = ceil(count($enrichedSegments) / $batchSize);
            
            Log::info("Processing video in batches", [
                'video_id' => $video->id,
                'total_segments' => count($enrichedSegments),
                'batch_size' => $batchSize,
                'total_batches' => $totalBatches
            ]);

            for ($batchNum = 0; $batchNum < $totalBatches; $batchNum++) {
                $offset = $batchNum * $batchSize;
                $batchSegments = array_slice($enrichedSegments, $offset, $batchSize);
                
                // Send all metadata the Python service needs for enriched Qdrant payload.
                // No speakers_transcript or diarization_segments (unused, bloats payload by MBs)
                $videoData = [
                    'video_id' => $video->id,
                    'video_title' => $video->title ?? '',
                    'video_filename' => $video->filename ?? '',
                    'youtube_url' => $video->youtube_url ?? '',
                    'language' => $video->language_detected ?? '',
                    'identification_segments' => $batchSegments,
                    'batch_info' => [
                        'batch_number' => $batchNum + 1,
                        'total_batches' => $totalBatches,
                        'segments_in_batch' => count($batchSegments)
                    ],
                    // Enriched metadata for Qdrant payload (dual-mode search support)
                    'video_created_at' => $video->video_created_at?->toISOString(),
                    'processing_status' => $video->processing_status ?? 'completed',
                    'approval_status' => $video->approval_status ?? 'approved',
                    'is_archived' => (bool) $video->is_archived,
                    'user_id' => $video->user_id,
                    'speakers_count' => $video->speakers_count ?? 0,
                    'audio_duration_seconds' => $video->audio_duration_seconds ?? 0,
                    'video_description' => $video->description ?? '',
                    'video_summary' => $video->summary ?? '',
                    'video_summary_english' => $video->summary_english ?? '',
                    'video_summary_urdu' => $video->summary_urdu ?? '',
                ];

                Log::info("Sending batch to Python service", [
                    'video_id' => $video->id,
                    'batch' => $batchNum + 1,
                    'total_batches' => $totalBatches,
                    'segments_in_batch' => count($batchSegments)
                ]);

                // Retry up to 3 times with exponential backoff for transient errors (503, timeouts)
                $response = null;
                $maxRetries = 3;
                for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                    try {
                        $response = Http::timeout(600)
                            ->withHeaders([
                                'X-API-Key' => $apiKey,
                                'Content-Type' => 'application/json',
                            ])
                            ->post("{$pythonServiceUrl}/embed-video", $videoData);
                        
                        if ($response->successful()) {
                            break; // Success — exit retry loop
                        }
                        
                        $statusCode = $response->status();
                        // Only retry on 502, 503, 504 (transient server errors)
                        if (in_array($statusCode, [502, 503, 504]) && $attempt < $maxRetries) {
                            $backoff = $attempt * 10; // 10s, 20s, 30s
                            Log::warning("Batch {$batchNum}+1 got HTTP {$statusCode}, retrying in {$backoff}s (attempt {$attempt}/{$maxRetries})", [
                                'video_id' => $video->id,
                            ]);
                            sleep($backoff);
                            continue;
                        }
                        
                        // Non-retryable error or exhausted retries
                        throw new \Exception("Python embedding service failed on batch " . ($batchNum + 1) . " (HTTP {$statusCode}): " . substr($response->body(), 0, 500));
                        
                    } catch (\Illuminate\Http\Client\ConnectionException $e) {
                        // Timeout or connection error — retry
                        if ($attempt < $maxRetries) {
                            $backoff = $attempt * 15;
                            Log::warning("Batch connection error, retrying in {$backoff}s (attempt {$attempt}/{$maxRetries})", [
                                'video_id' => $video->id,
                                'error' => $e->getMessage(),
                            ]);
                            sleep($backoff);
                            continue;
                        }
                        throw new \Exception("Python service connection failed after {$maxRetries} attempts: " . $e->getMessage());
                    }
                }

                $result = $response->json();
                $segmentsEmbedded = $result['segments_embedded'] ?? 0;
                $totalSegmentsEmbedded += $segmentsEmbedded;
                $collectionName = $result['collection'] ?? $collectionName;

                Log::info("Batch processed successfully", [
                    'video_id' => $video->id,
                    'batch' => $batchNum + 1,
                    'segments_embedded' => $segmentsEmbedded,
                    'total_embedded_so_far' => $totalSegmentsEmbedded
                ]);
                
                // Brief pause between batches to avoid overwhelming Railway
                if ($batchNum + 1 < $totalBatches) {
                    sleep(2);
                }
            }

            Log::info("All batches processed successfully", [
                'video_id' => $video->id,
                'total_segments_embedded' => $totalSegmentsEmbedded,
                'collection' => $collectionName
            ]);

            // Mark embedding as completed
            $embedding->markAsCompleted(
                $totalSegmentsEmbedded,
                $collectionName
            );

            Log::info("Successfully stored embeddings for video ID: " . $video->id);

        } catch (\Exception $e) {
            Log::error("Failed to generate embedding for video ID " . $video->id . ": " . $e->getMessage());

            // Mark embedding as failed
            $embedding->markAsFailed($e->getMessage());
            
            // Only re-throw for critical errors, not for data validation issues
            if (!str_contains($e->getMessage(), 'identification_data')) {
                throw $e;
            }
        }
    }

    /**
     * Enrich identification segments with text from speakers_data
     * 
     * This merges the speaker identification (who spoke when) with the actual text content
     * by matching time ranges
     */
    private function enrichSegmentsWithText($identificationSegments, $speakersData)
    {
        $enriched = [];

        // Auto-detect if speakers_data timestamps are in milliseconds or seconds
        // by comparing against the identification_data time range
        $speakerTimeUnit = 1; // default: no conversion (already in seconds)
        if (!empty($speakersData) && !empty($identificationSegments)) {
            // Find the max end time in identification_data (which is always in seconds)
            $maxIdentEnd = 0;
            foreach ($identificationSegments as $seg) {
                $segEnd = $seg['end'] ?? 0;
                if ($segEnd > $maxIdentEnd) {
                    $maxIdentEnd = $segEnd;
                }
            }

            // Find the max end time in speakers_data
            $maxSpeakerEnd = 0;
            foreach ($speakersData as $seg) {
                $segEnd = $seg['end'] ?? 0;
                if ($segEnd > $maxSpeakerEnd) {
                    $maxSpeakerEnd = $segEnd;
                }
            }

            // If speakers_data max end is more than 3x the identification max end,
            // it's almost certainly in milliseconds (e.g., 755572ms vs 755.5s)
            // Also check absolute threshold for safety: if max speaker end > 1000
            // and identification data is < 1000, convert
            if ($maxIdentEnd > 0 && $maxSpeakerEnd > ($maxIdentEnd * 3)) {
                $speakerTimeUnit = 1000; // Convert ms to seconds
                Log::info("Detected speakers_data timestamps in milliseconds (speaker max: {$maxSpeakerEnd}, ident max: {$maxIdentEnd}), converting to seconds");
            } elseif ($maxSpeakerEnd > 10000) {
                // Fallback: if absolute value > 10000, likely milliseconds
                $speakerTimeUnit = 1000;
                Log::info("Detected speakers_data timestamps in milliseconds (absolute: {$maxSpeakerEnd} > 10000), converting to seconds");
            } else {
                Log::info("Detected speakers_data timestamps in seconds (speaker max: {$maxSpeakerEnd}, ident max: {$maxIdentEnd}), no conversion needed");
            }
        }

        foreach ($identificationSegments as $identSegment) {
            $start = $identSegment['start'] ?? 0;
            $end = $identSegment['end'] ?? 0;

            // Skip zero-length segments
            if ($end <= $start) {
                continue;
            }

            // Find matching speaker data by time overlap
            $matchingTexts = [];

            foreach ($speakersData as $speakerSegment) {
                $speakerStart = isset($speakerSegment['start']) ? $speakerSegment['start'] / $speakerTimeUnit : 0;
                $speakerEnd = isset($speakerSegment['end']) ? $speakerSegment['end'] / $speakerTimeUnit : 0;

                // Check if there's time overlap
                if ($this->hasTimeOverlap($start, $end, $speakerStart, $speakerEnd)) {
                    $text = $speakerSegment['text'] ?? '';
                    if (!empty(trim($text))) {
                        $matchingTexts[] = trim($text);
                    }
                }
            }

            // Combine all matching text, deduplicating
            $uniqueTexts = array_unique($matchingTexts);
            $combinedText = implode(' ', $uniqueTexts);

            // Only include segments with actual text content
            if (!empty(trim($combinedText))) {
                $enriched[] = array_merge($identSegment, [
                    'text' => trim($combinedText)
                ]);
            }
        }

        Log::info("Segment enrichment complete", [
            'input_segments' => count($identificationSegments),
            'enriched_with_text' => count($enriched),
            'skipped' => count($identificationSegments) - count($enriched)
        ]);

        return $enriched;
    }

    /**
     * Check if two time ranges overlap
     */
    private function hasTimeOverlap($start1, $end1, $start2, $end2)
    {
        return max($start1, $start2) < min($end1, $end2);
    }

    /**
     * Parse JSON field safely
     */
    private function parseJsonField($field, $fieldName, $videoId = null)
    {
        if (!$field) {
            return null;
        }

        if (is_array($field)) {
            return $field;
        }

        if (is_string($field)) {
            $decoded = json_decode($field, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Handle double/triple-encoded JSON (keep decoding until we get an array)
                $maxAttempts = 3;
                while (is_string($decoded) && $maxAttempts-- > 0) {
                    $innerDecoded = json_decode($decoded, true);
                    if (json_last_error() !== JSON_ERROR_NONE) break;
                    $decoded = $innerDecoded;
                }
                return $decoded;
            }
            Log::warning("Failed to decode {$fieldName}" . ($videoId ? " for video ID {$videoId}" : ""));
        }

        return null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $video = Video::find($this->videoId);
        
        Log::error('GenerateVideoEmbedding job permanently failed', [
            'video_id' => $this->videoId,
            'video_title' => $video->title ?? 'Unknown',
            'error' => $exception->getMessage(),
            'queue' => 'embeddings'
        ]);

        // Mark embedding as failed if it exists
        $embedding = VideoEmbedding::where('video_id', $this->videoId)->first();
        if ($embedding) {
            $embedding->markAsFailed($exception->getMessage());
        }
    }
}
