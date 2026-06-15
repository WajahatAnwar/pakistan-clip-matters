<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReembedAllVideos extends Command
{
    protected $signature = 'embeddings:reembed-all 
        {--video= : Re-embed a single video ID}
        {--failed-only : Only re-embed previously failed videos}
        {--force : Skip confirmation}
        {--delay=3 : Seconds to wait between videos}
        {--dry-run : Show what would be done without doing it}';
    
    protected $description = 'Re-embed all videos synchronously with OpenAI 3072-dim vectors (no queue needed)';

    private $pythonServiceUrl;
    private $apiKey;

    public function handle()
    {
        $this->pythonServiceUrl = config('services.embedding.url', 'https://web-production-935af.up.railway.app');
        $this->apiKey = config('services.embedding.api_key', '');

        // ── Verify Python service is online ──
        $this->info("Checking Python embedding service at: {$this->pythonServiceUrl}");
        try {
            $health = Http::timeout(10)->get("{$this->pythonServiceUrl}/");
            if (!$health->successful()) {
                $this->error("Python service is not responding. Aborting.");
                return 1;
            }
            $root = $health->json();
            $this->info("Service online: " . ($root['version'] ?? 'unknown version'));
            $openaiEnabled = $root['ai_features']['openai_embeddings'] ?? false;
            if (!$openaiEnabled) {
                $this->warn("WARNING: OpenAI embeddings are NOT enabled. Vectors will be 384-dim (FastEmbed fallback).");
                if (!$this->option('force') && !$this->confirm('Continue anyway?')) {
                    return 1;
                }
            }
        } catch (\Exception $e) {
            $this->error("Cannot reach Python service: " . $e->getMessage());
            return 1;
        }

        // ── Build list of videos to process ──
        $singleVideoId = $this->option('video');
        $failedOnly = $this->option('failed-only');

        $query = Video::whereNotNull('speakers_data')
            ->whereNotNull('identification_data')
            ->where('identification_data', '!=', '[]')
            ->where('identification_data', '!=', '""');

        if ($singleVideoId) {
            $query->where('id', $singleVideoId);
        }

        if ($failedOnly) {
            $failedIds = VideoEmbedding::where('status', 'failed')->pluck('video_id')->toArray();
            $query->whereIn('id', $failedIds);
        }

        $videos = $query->get();

        if ($videos->isEmpty()) {
            $this->info("No eligible videos found.");
            return 0;
        }

        $this->info("Found {$videos->count()} videos to re-embed.");

        if ($this->option('dry-run')) {
            $this->table(['ID', 'Title', 'Current Status'], $videos->map(function ($v) {
                $emb = VideoEmbedding::where('video_id', $v->id)->first();
                return [$v->id, substr($v->title, 0, 50), $emb ? $emb->status : 'none'];
            })->toArray());
            return 0;
        }

        if (!$this->option('force') && !$singleVideoId) {
            if (!$this->confirm("Re-embed {$videos->count()} videos? This will replace existing Qdrant data.")) {
                $this->info('Cancelled.');
                return 0;
            }
        }

        // ── Process each video ──
        $succeeded = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($videos->count());
        $bar->start();

        foreach ($videos as $video) {
            $bar->setMessage("Processing: {$video->title}");
            
            try {
                $result = $this->processVideo($video);
                if ($result['success']) {
                    $succeeded++;
                    $this->line(""); // newline
                    $this->info("  ✓ Video {$video->id}: {$result['segments']} segments embedded");
                } else {
                    $failed++;
                    $this->line("");
                    $this->error("  ✗ Video {$video->id}: {$result['error']}");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->line("");
                $this->error("  ✗ Video {$video->id}: Exception - " . substr($e->getMessage(), 0, 200));
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->line("");
        $this->info("═══════════════════════════════════════");
        $this->info("Re-embedding complete!");
        $this->info("  Succeeded: {$succeeded}");
        $this->info("  Failed: {$failed}");
        $this->info("  Total: {$videos->count()}");
        $this->info("═══════════════════════════════════════");

        return $failed > 0 ? 1 : 0;
    }

    private function processVideo(Video $video): array
    {
        // ── Parse data ──
        $identificationData = $this->parseJsonField($video->identification_data);
        $speakersData = $this->parseJsonField($video->speakers_data);

        if (empty($identificationData)) {
            return ['success' => false, 'error' => 'Empty identification_data', 'segments' => 0];
        }

        if (empty($speakersData)) {
            return ['success' => false, 'error' => 'Empty speakers_data', 'segments' => 0];
        }

        // ── Enrich segments with text ──
        $enrichedSegments = $this->enrichSegmentsWithText($identificationData, $speakersData);
        
        if (empty($enrichedSegments)) {
            return ['success' => false, 'error' => 'No segments with text after enrichment', 'segments' => 0];
        }

        // Add segment_index to each segment for consistent point IDs across batches
        // This ensures batch 2's segment 0 doesn't overwrite batch 1's segment 0
        foreach ($enrichedSegments as $idx => &$segment) {
            $segment['segment_index'] = $idx;
        }
        unset($segment); // break reference

        // ── Send to Python service in batches of 500 ──
        $batchSize = 500;
        $totalBatches = (int) ceil(count($enrichedSegments) / $batchSize);
        $totalEmbedded = 0;
        $collection = 'video_transcript_segments';

        for ($batchNum = 0; $batchNum < $totalBatches; $batchNum++) {
            $batchSegments = array_slice($enrichedSegments, $batchNum * $batchSize, $batchSize);
            
            $videoData = [
                'video_id' => $video->id,
                'video_title' => $video->title,
                'video_filename' => $video->filename,
                'youtube_url' => $video->youtube_url,
                'language' => $video->language_detected,
                'identification_segments' => $batchSegments,
                'batch_info' => [
                    'batch_number' => $batchNum + 1,
                    'total_batches' => $totalBatches,
                    'segments_in_batch' => count($batchSegments)
                ]
            ];

            // Retry up to 3 times with exponential backoff
            $response = null;
            $lastError = '';
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $response = Http::timeout(600)
                        ->withHeaders([
                            'X-API-Key' => $this->apiKey,
                            'Content-Type' => 'application/json',
                        ])
                        ->post("{$this->pythonServiceUrl}/embed-video", $videoData);
                    
                    if ($response->successful()) {
                        break;
                    }
                    
                    $statusCode = $response->status();
                    $lastError = "HTTP {$statusCode}: " . substr($response->body(), 0, 200);
                    
                    if (in_array($statusCode, [502, 503, 504]) && $attempt < 3) {
                        $backoff = $attempt * 15;
                        Log::warning("Batch " . ($batchNum + 1) . " got HTTP {$statusCode}, retrying in {$backoff}s", ['video_id' => $video->id]);
                        sleep($backoff);
                        continue;
                    }
                    break;
                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    if ($attempt < 3) {
                        sleep($attempt * 15);
                        continue;
                    }
                }
            }

            if (!$response || !$response->successful()) {
                VideoEmbedding::updateOrCreate(
                    ['video_id' => $video->id],
                    [
                        'status' => 'failed',
                        'error_message' => "Batch " . ($batchNum + 1) . ": " . substr($lastError, 0, 500),
                        'vector_dimensions' => 3072,
                        'failed_at' => now(),
                    ]
                );
                return ['success' => false, 'error' => "Batch " . ($batchNum + 1) . ": " . $lastError, 'segments' => $totalEmbedded];
            }

            $result = $response->json();
            $totalEmbedded += $result['segments_embedded'] ?? 0;
            $collection = $result['collection'] ?? $collection;
            
            // Brief pause between batches
            if ($batchNum + 1 < $totalBatches) {
                sleep(2);
            }
        }

        // Update embedding record as completed
        VideoEmbedding::updateOrCreate(
            ['video_id' => $video->id],
            [
                'status' => 'completed',
                'segments_count' => $totalEmbedded,
                'vector_dimensions' => 3072,
                'qdrant_collection' => $collection,
                'error_message' => null,
                'completed_at' => now(),
            ]
        );

        return ['success' => true, 'error' => null, 'segments' => $totalEmbedded];
    }

    /**
     * Enrich identification segments with text from speakers_data
     * (Same logic as GenerateVideoEmbedding job)
     */
    private function enrichSegmentsWithText(array $identificationSegments, array $speakersData): array
    {
        $enriched = [];

        // Auto-detect timestamp units (milliseconds vs seconds)
        $speakerTimeUnit = 1;
        if (!empty($speakersData) && !empty($identificationSegments)) {
            $maxIdentEnd = 0;
            foreach ($identificationSegments as $seg) {
                $segEnd = $seg['end'] ?? 0;
                if ($segEnd > $maxIdentEnd) $maxIdentEnd = $segEnd;
            }

            $maxSpeakerEnd = 0;
            foreach ($speakersData as $seg) {
                $segEnd = $seg['end'] ?? 0;
                if ($segEnd > $maxSpeakerEnd) $maxSpeakerEnd = $segEnd;
            }

            if ($maxIdentEnd > 0 && $maxSpeakerEnd > ($maxIdentEnd * 3)) {
                $speakerTimeUnit = 1000;
            } elseif ($maxSpeakerEnd > 10000) {
                $speakerTimeUnit = 1000;
            }
        }

        foreach ($identificationSegments as $identSegment) {
            $start = $identSegment['start'] ?? 0;
            $end = $identSegment['end'] ?? 0;
            if ($end <= $start) continue;

            $matchingTexts = [];
            foreach ($speakersData as $speakerSegment) {
                $speakerStart = isset($speakerSegment['start']) ? $speakerSegment['start'] / $speakerTimeUnit : 0;
                $speakerEnd = isset($speakerSegment['end']) ? $speakerSegment['end'] / $speakerTimeUnit : 0;

                if (max($start, $speakerStart) < min($end, $speakerEnd)) {
                    $text = $speakerSegment['text'] ?? '';
                    if (!empty(trim($text))) {
                        $matchingTexts[] = trim($text);
                    }
                }
            }

            $uniqueTexts = array_unique($matchingTexts);
            $combinedText = implode(' ', $uniqueTexts);

            if (!empty(trim($combinedText))) {
                $enriched[] = array_merge($identSegment, ['text' => trim($combinedText)]);
            }
        }

        return $enriched;
    }

    /**
     * Parse JSON field handling double-encoded data
     */
    private function parseJsonField($field): ?array
    {
        if (!$field) return null;
        if (is_array($field)) return $field;
        
        if (is_string($field)) {
            $decoded = json_decode($field, true);
            if (json_last_error() !== JSON_ERROR_NONE) return null;
            
            // Handle double/triple-encoded JSON
            $maxAttempts = 3;
            while (is_string($decoded) && $maxAttempts-- > 0) {
                $innerDecoded = json_decode($decoded, true);
                if (json_last_error() !== JSON_ERROR_NONE) break;
                $decoded = $innerDecoded;
            }
            
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
