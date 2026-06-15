<?php

namespace App\Console\Commands;

use App\Jobs\GenerateVideoEmbedding;
use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Console\Command;

class EmbedApprovedVideos extends Command
{
    protected $signature = 'embeddings:embed-approved
                            {--force : Skip confirmation}
                            {--retry-failed : Also re-queue videos whose embedding status is failed}';

    protected $description = 'Dispatch embedding jobs for approved videos that have no completed embedding';

    public function handle()
    {
        // Base query: approved, eligible (has required transcript data)
        $baseQuery = Video::where('approval_status', 'approved')
            ->whereNotNull('speakers_data')
            ->whereNotNull('identification_data');

        $totalApproved = (clone $baseQuery)->count();

        // Videos with NO embedding record at all
        $noEmbedding = (clone $baseQuery)
            ->whereDoesntHave('embedding')
            ->count();

        // Videos whose embedding is pending or processing (stuck)
        $incompleteEmbedding = (clone $baseQuery)
            ->whereHas('embedding', fn ($q) => $q->whereIn('status', ['pending', 'processing']))
            ->count();

        // Videos whose embedding failed
        $failedEmbedding = (clone $baseQuery)
            ->whereHas('embedding', fn ($q) => $q->where('status', 'failed'))
            ->count();

        // Videos already completed
        $completedEmbedding = (clone $baseQuery)
            ->whereHas('embedding', fn ($q) => $q->where('status', 'completed'))
            ->count();

        $this->info("=== Approved Videos Embedding Status ===");
        $this->info("Total approved & eligible videos : {$totalApproved}");
        $this->info("  - No embedding record           : {$noEmbedding}");
        $this->info("  - Embedding pending/stuck       : {$incompleteEmbedding}");
        $this->info("  - Embedding failed              : {$failedEmbedding}");
        $this->info("  - Embedding completed           : {$completedEmbedding}");
        $this->line('');

        $toDispatch = $noEmbedding + $incompleteEmbedding;

        if ($this->option('retry-failed')) {
            $toDispatch += $failedEmbedding;
        }

        if ($toDispatch === 0) {
            $this->info('All approved videos already have completed embeddings. Nothing to do.');
            return 0;
        }

        $retryFailed = $this->option('retry-failed');
        $summary = "Will dispatch {$toDispatch} job(s): {$noEmbedding} missing";
        if ($incompleteEmbedding) {
            $summary .= ", {$incompleteEmbedding} stuck";
        }
        if ($retryFailed && $failedEmbedding) {
            $summary .= ", {$failedEmbedding} failed";
        }
        $this->warn($summary);

        if (!$this->option('force') && !$this->confirm('Proceed?')) {
            $this->info('Cancelled.');
            return 0;
        }

        // ── Build final query ────────────────────────────────────────────────
        $statuses = ['pending', 'processing'];
        if ($retryFailed) {
            $statuses[] = 'failed';
        }

        $videos = (clone $baseQuery)
            ->where(function ($q) use ($statuses) {
                $q->whereDoesntHave('embedding')
                  ->orWhereHas('embedding', fn ($q2) => $q2->whereIn('status', $statuses));
            })
            ->get(['id', 'title', 'approval_status']);

        $dispatched = 0;
        foreach ($videos as $video) {
            // Delete any stale (non-completed) embedding record so the job creates a fresh one
            VideoEmbedding::where('video_id', $video->id)
                ->whereIn('status', $statuses)
                ->delete();

            GenerateVideoEmbedding::dispatch($video->id);
            $this->line("  Queued: [{$video->id}] {$video->title}");
            $dispatched++;
        }

        $this->info('');
        $this->info("Dispatched {$dispatched} embedding job(s) to the 'high' queue.");
        $this->info('Monitor with: php artisan queue:work --queue=high');

        return 0;
    }
}
