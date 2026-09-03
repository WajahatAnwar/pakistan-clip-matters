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
            $speakersData = $this->parseJsonField($video->speakers_data, 'speakers_data', $video->id);

            if (!$speakersData || empty($speakersData)) {
                throw new \Exception("speakers_data is empty or invalid - no text to embed");
            }

            // Map speakers_data directly to segments
            $enrichedSegments = $this->mapSpeakersDataToSegments($speakersData);

            if (empty($enrichedSegments)) {
                throw new \Exception("No valid segments with text found after mapping data");
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
                    'webhook_url' => route('api.webhooks.qdrant', [
                        'video_id' => $video->id,
                        'batch' => $batchNum + 1,
                        'total' => $totalBatches
                    ]),
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
                        $response = Http::timeout(300) // Increased to 5 minutes to prevent broken pipes during heavy embedding generation
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
                
                // Brief pause between batches to avoid overwhelming Railway ingress
                if ($batchNum + 1 < $totalBatches) {
                    sleep(2);
                }
            }

            Log::info("All batches queued successfully for background processing", [
                'video_id' => $video->id,
                'total_batches_queued' => $totalBatches
            ]);

            // NOTE: We no longer mark the embedding as completed here. 
            // The python background worker will hit the webhook to mark it completed.

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
    /**
     * Map speakers_data directly to Qdrant segments
     */
    private function mapSpeakersDataToSegments($speakersData)
    {
        $segments = [];

        foreach ($speakersData as $utterance) {
            // Check if timestamps are in milliseconds or seconds.
            // AssemblyAI usually returns milliseconds.
            $start = isset($utterance['start']) ? $utterance['start'] : 0;
            $end = isset($utterance['end']) ? $utterance['end'] : 0;
            
            // Convert to seconds if > 10000
            if ($end > 10000) {
                $start = $start / 1000;
                $end = $end / 1000;
            }

            $text = trim($utterance['text'] ?? '');
            
            if (empty($text)) {
                continue;
            }

            // Figure out the speaker name from actualSpeaker or speaker label
            $speakerName = $utterance['actualSpeaker'] ?? $utterance['speaker'] ?? 'Unknown Speaker';

            $segments[] = [
                'start' => $start,
                'end' => $end,
                'speaker' => $speakerName,
                'text' => $text,
            ];
        }

        Log::info("Mapped speakers_data to segments", [
            'input_segments' => count($speakersData),
            'mapped_segments' => count($segments)
        ]);

        return $segments;
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
