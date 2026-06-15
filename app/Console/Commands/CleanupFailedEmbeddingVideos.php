<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupFailedEmbeddingVideos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:cleanup-failed-embeddings
        {--dry-run : Show what would be deleted without actually deleting}
        {--include-no-embedding : Also remove videos that have no embedding record at all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove videos that have failed embeddings (and optionally videos with no embedding record)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $includeNoEmbedding = $this->option('include-no-embedding');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No records will be deleted');
            $this->newLine();
        } else {
            $this->warn('⚠️  LIVE MODE - Videos with failed embeddings will be permanently deleted!');
            if (!$this->confirm('Do you want to continue?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
            $this->newLine();
        }

        $deletedCount = 0;

        // 1. Videos with failed embeddings
        $this->info('Searching for videos with failed embeddings...');
        $this->newLine();

        $failedEmbeddings = VideoEmbedding::where('status', 'failed')
            ->with('video')
            ->get();

        if ($failedEmbeddings->isNotEmpty()) {
            $this->warn("Found {$failedEmbeddings->count()} video(s) with failed embeddings:");
            $this->newLine();

            foreach ($failedEmbeddings as $embedding) {
                $video = $embedding->video;
                if (!$video) {
                    $this->line("   ⚠️  Embedding ID {$embedding->id} has no associated video (orphaned record)");
                    continue;
                }

                $this->line("   ✗ <fg=red>Video ID {$video->id}</> - <fg=cyan>{$video->filename}</>");
                $this->line("     Dropbox: {$video->dropbox_path}");
                $this->line("     Error: <fg=yellow>" . ($embedding->error_message ?: 'No error message') . "</>");
                $this->line("     Failed at: " . ($embedding->failed_at ?? 'N/A'));
                $this->newLine();

                if (!$isDryRun) {
                    // Delete the video (embedding will cascade-delete)
                    $video->delete();
                    $deletedCount++;

                    Log::info('Deleted video with failed embedding', [
                        'video_id' => $video->id,
                        'filename' => $video->filename,
                        'dropbox_path' => $video->dropbox_path,
                        'embedding_error' => $embedding->error_message,
                    ]);
                }
            }
        } else {
            $this->info('✅ No videos with failed embeddings found.');
            $this->newLine();
        }

        // 2. Videos with no embedding record at all (optional)
        $noEmbeddingCount = 0;
        if ($includeNoEmbedding) {
            $this->info('Searching for videos with no embedding record...');
            $this->newLine();

            $videosWithoutEmbedding = Video::whereDoesntHave('embedding')
                ->where('processing_status', 'completed')
                ->get();

            if ($videosWithoutEmbedding->isNotEmpty()) {
                $this->warn("Found {$videosWithoutEmbedding->count()} completed video(s) with no embedding:");
                $this->newLine();

                foreach ($videosWithoutEmbedding as $video) {
                    $this->line("   ✗ <fg=red>Video ID {$video->id}</> - <fg=cyan>{$video->filename}</>");
                    $this->line("     Dropbox: {$video->dropbox_path}");
                    $this->newLine();

                    if (!$isDryRun) {
                        $video->delete();
                        $noEmbeddingCount++;

                        Log::info('Deleted video with no embedding', [
                            'video_id' => $video->id,
                            'filename' => $video->filename,
                            'dropbox_path' => $video->dropbox_path,
                        ]);
                    }
                }
            } else {
                $this->info('✅ No completed videos without embeddings found.');
                $this->newLine();
            }
        }

        // Summary
        $this->newLine();
        $this->info('📊 Summary:');
        $failedCount = $failedEmbeddings->filter(fn($e) => $e->video !== null)->count();

        if ($isDryRun) {
            $this->line("   Videos with failed embeddings: {$failedCount}");
            if ($includeNoEmbedding) {
                $noEmbCount = $videosWithoutEmbedding->count() ?? 0;
                $this->line("   Videos with no embedding: {$noEmbCount}");
                $this->warn("   Would delete: " . ($failedCount + $noEmbCount) . " video(s)");
            } else {
                $this->warn("   Would delete: {$failedCount} video(s)");
            }
            $this->newLine();
            $this->info('💡 Run without --dry-run to actually delete:');
            $this->line('   php artisan videos:cleanup-failed-embeddings');
        } else {
            $this->info("   ✅ Deleted videos with failed embeddings: {$deletedCount}");
            if ($includeNoEmbedding) {
                $this->info("   ✅ Deleted videos with no embedding: {$noEmbeddingCount}");
            }
            $total = $deletedCount + $noEmbeddingCount;
            $this->line("   Total deleted: {$total} video(s)");
        }

        return Command::SUCCESS;
    }
}
