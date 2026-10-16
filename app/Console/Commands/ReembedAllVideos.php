<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ReembedAllVideos extends Command
{
    protected $signature = 'embeddings:reembed-all 
        {--video= : Re-embed a single video ID}
        {--failed-only : Only re-embed previously failed videos}
        {--force : Skip confirmation}
        {--delay=3 : Seconds to wait between videos}
        {--dry-run : Show what would be done without doing it}';
    
    protected $description = 'Submit protected embedding generations; Python workers complete them asynchronously';

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
            $model = $root['ai_features']['embedding_model'] ?? null;
            $dimensions = $root['ai_features']['embedding_dimension'] ?? null;
            if ($model !== config('services.embedding.model', 'text-embedding-3-large')
                || (int) $dimensions !== (int) config('services.embedding.dimensions', 3072)) {
                $this->error('Python embedding model/dimensions do not match Laravel configuration.');
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Cannot reach Python service: " . $e->getMessage());
            return 1;
        }

        // ── Build list of videos to process ──
        $singleVideoId = $this->option('video');
        $failedOnly = $this->option('failed-only');

        $query = Video::whereNotNull('speakers_data');

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
                    $this->info("  ✓ Video {$video->id}: generation submitted; awaiting Python completion");
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
        $this->info("Submission complete; indexing continues in Python workers.");
        $this->info("  Submitted: {$succeeded}");
        $this->info("  Failed: {$failed}");
        $this->info("  Total: {$videos->count()}");
        $this->info("═══════════════════════════════════════");

        return $failed > 0 ? 1 : 0;
    }

    private function processVideo(Video $video): array
    {
        // This submits the same protected asynchronous generation used by uploads.
        $job = new \App\Jobs\GenerateVideoEmbedding($video->id);
        $job->handle();
        $embedding = VideoEmbedding::where('video_id', $video->id)->where('active_run_id', $job->runId)->first();
        if (!$embedding || (!$embedding->submitted_at && $embedding->status !== 'completed')) {
            return ['success' => false, 'error' => $embedding?->error_message ?? 'Generation was not submitted'];
        }
        return ['success' => true, 'queued' => true];
    }
}
