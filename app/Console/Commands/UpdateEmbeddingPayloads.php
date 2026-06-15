<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateEmbeddingPayloads extends Command
{
    protected $signature = 'embeddings:update-payloads
        {--video= : Update a single video ID}
        {--batch-size=20 : Number of videos per batch sent to Python service}
        {--force : Skip confirmation}
        {--dry-run : Show what would be done without doing it}
        {--delay=1 : Seconds to wait between batches}';

    protected $description = 'Update Qdrant point payloads with latest metadata from the database WITHOUT re-generating embeddings (vectors stay untouched)';

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
            $this->info("✅ Service online");
        } catch (\Exception $e) {
            $this->error("Cannot reach Python service: " . $e->getMessage());
            return 1;
        }

        // ── Build video query ──
        if ($this->option('video')) {
            $videoId = (int) $this->option('video');
            $videos = Video::where('id', $videoId)->get();
            if ($videos->isEmpty()) {
                $this->error("Video ID {$videoId} not found.");
                return 1;
            }
        } else {
            // All searchable videos (completed + approved + not archived)
            $videos = Video::searchable()->get();
        }

        $this->info("Found {$videos->count()} video(s) to update.");

        if ($videos->isEmpty()) {
            $this->warn("No videos found. Nothing to do.");
            return 0;
        }

        // ── Confirmation ──
        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm("Update Qdrant payloads for {$videos->count()} video(s)? (Vectors won't be touched)")) {
                $this->info("Aborted.");
                return 0;
            }
        }

        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        $isDryRun = $this->option('dry-run');

        $totalUpdated = 0;
        $totalPoints = 0;
        $totalErrors = 0;
        $batches = $videos->chunk($batchSize);

        $bar = $this->output->createProgressBar($videos->count());
        $bar->start();

        foreach ($batches as $batchIndex => $batch) {
            $payloadBatch = [];

            foreach ($batch as $video) {
                $payloadData = [
                    'video_id' => $video->id,
                    'video_title' => $video->title ?? '',
                    'video_filename' => $video->filename ?? '',
                    'youtube_url' => $video->youtube_url ?? '',
                    'language' => $video->language_detected ?? '',
                    'video_created_at' => $video->video_created_at?->toISOString(),
                    'processing_status' => $video->processing_status ?? 'completed',
                    'approval_status' => $video->approval_status ?? 'approved',
                    'is_archived' => (bool) $video->is_archived,
                    'user_id' => $video->user_id,
                    'speakers_count' => $video->speakers_count ?? 0,
                    'audio_duration_seconds' => (float) ($video->audio_duration_seconds ?? 0),
                    'video_description' => $video->description ?? '',
                    'video_summary' => $video->summary ?? '',
                    'video_summary_english' => $video->summary_english ?? '',
                    'video_summary_urdu' => $video->summary_urdu ?? '',
                ];

                $payloadBatch[] = $payloadData;
            }

            if ($isDryRun) {
                foreach ($batch as $video) {
                    $this->line("  [DRY RUN] Would update video #{$video->id}: {$video->title}");
                    $bar->advance();
                }
                continue;
            }

            try {
                $response = Http::timeout(120)
                    ->withHeaders([
                        'X-API-Key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->pythonServiceUrl}/update-video-payload-batch", $payloadBatch);

                if ($response->successful()) {
                    $result = $response->json();
                    $batchPoints = $result['total_points_affected'] ?? 0;
                    $batchUpdated = count($result['results'] ?? []);
                    $batchErrors = count($result['errors'] ?? []);

                    $totalUpdated += $batchUpdated;
                    $totalPoints += $batchPoints;
                    $totalErrors += $batchErrors;

                    if ($batchErrors > 0) {
                        foreach ($result['errors'] as $err) {
                            $this->warn("  ⚠ Video #{$err['video_id']}: {$err['error']}");
                        }
                    }

                    foreach ($result['results'] ?? [] as $r) {
                        if ($r['status'] === 'skipped') {
                            $this->line("  ⏭ Video #{$r['video_id']}: skipped (no points in Qdrant)");
                        }
                    }
                } else {
                    $this->error("  ✗ Batch {$batchIndex} failed (HTTP {$response->status()}): " . $response->body());
                    $totalErrors += $batch->count();
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Batch {$batchIndex} exception: " . $e->getMessage());
                $totalErrors += $batch->count();
            }

            foreach ($batch as $v) {
                $bar->advance();
            }

            if ($delay > 0 && $batchIndex < $batches->count() - 1) {
                sleep($delay);
            }
        }

        $bar->finish();
        $this->newLine(2);

        if ($isDryRun) {
            $this->info("🔍 Dry run complete. {$videos->count()} video(s) would be updated.");
        } else {
            $this->info("✅ Done! Updated {$totalUpdated} video(s), {$totalPoints} Qdrant points affected.");
            if ($totalErrors > 0) {
                $this->warn("⚠ {$totalErrors} error(s) encountered.");
            }
        }

        Log::info("UpdateEmbeddingPayloads completed", [
            'videos' => $totalUpdated,
            'points' => $totalPoints,
            'errors' => $totalErrors,
            'dry_run' => $isDryRun,
        ]);

        return $totalErrors > 0 ? 1 : 0;
    }
}
