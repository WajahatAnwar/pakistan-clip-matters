<?php

namespace App\Console\Commands;

use App\Jobs\GenerateVideoEmbedding;
use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Console\Command;

class RegenerateAllEmbeddings extends Command
{
    protected $signature = 'embeddings:regenerate-all {--force : Skip confirmation}';
    protected $description = 'Delete all existing embeddings and re-dispatch embedding jobs for all eligible videos';

    public function handle()
    {
        $eligible = Video::whereNotNull('speakers_data')
            ->whereNotNull('identification_data')
            ->count();

        $existing = VideoEmbedding::count();

        $this->info("Found {$eligible} eligible videos (with speakers_data + identification_data)");
        $this->info("Existing embedding records: {$existing}");

        if (!$this->option('force') && !$this->confirm('This will delete ALL existing embedding records and re-queue jobs for all videos. Continue?')) {
            $this->info('Cancelled.');
            return;
        }

        // Step 1: Truncate video_embeddings table
        $deleted = VideoEmbedding::query()->delete();
        $this->info("Deleted {$deleted} embedding records from database.");

        // Step 2: Dispatch jobs for all eligible videos
        $videos = Video::whereNotNull('speakers_data')
            ->whereNotNull('identification_data')
            ->get(['id', 'title']);

        $dispatched = 0;
        foreach ($videos as $video) {
            GenerateVideoEmbedding::dispatch($video->id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} embedding jobs to the 'high' queue.");
        $this->info('Monitor progress with: php artisan queue:work --queue=high');

        return 0;
    }
}
