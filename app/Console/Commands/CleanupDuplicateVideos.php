<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupDuplicateVideos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:cleanup-duplicates {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate video records (keeps oldest record for each dropbox_path)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No records will be deleted');
            $this->newLine();
        } else {
            $this->warn('⚠️  LIVE MODE - Duplicate records will be permanently deleted!');
            if (!$this->confirm('Do you want to continue?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
            $this->newLine();
        }

        $this->info('Searching for duplicate videos...');

        // Find all duplicate dropbox_paths
        $duplicates = DB::table('videos')
            ->select('dropbox_path', DB::raw('COUNT(*) as count'), DB::raw('MIN(id) as keep_id'))
            ->groupBy('dropbox_path')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate videos found!');
            return Command::SUCCESS;
        }

        $this->warn("Found {$duplicates->count()} dropbox paths with duplicates:");
        $this->newLine();

        $totalDuplicates = 0;
        $deletedCount = 0;

        foreach ($duplicates as $duplicate) {
            $duplicateCount = $duplicate->count - 1; // -1 because we keep one
            $totalDuplicates += $duplicateCount;

            // Get all records for this dropbox_path
            $videos = Video::where('dropbox_path', $duplicate->dropbox_path)
                ->orderBy('id')
                ->get();

            $this->line("📁 <fg=cyan>{$duplicate->dropbox_path}</>");
            $this->line("   Found {$duplicate->count} records:");

            foreach ($videos as $index => $video) {
                $status = $video->processing_status;
                $statusColor = match($status) {
                    'completed' => 'green',
                    'failed' => 'red',
                    'processing' => 'yellow',
                    default => 'gray'
                };

                if ($video->id == $duplicate->keep_id) {
                    $this->line("   ✓ <fg=green>ID {$video->id}</> - {$status} (KEEPING - oldest)");
                } else {
                    $this->line("   ✗ <fg=red>ID {$video->id}</> - <fg={$statusColor}>{$status}</> (WILL DELETE)");
                }
            }

            if (!$isDryRun) {
                // Delete all except the oldest (keep_id)
                $deleted = Video::where('dropbox_path', $duplicate->dropbox_path)
                    ->where('id', '>', $duplicate->keep_id)
                    ->delete();
                
                $deletedCount += $deleted;
                $this->line("   <fg=yellow>→ Deleted {$deleted} duplicate(s)</>");
                
                Log::info('Deleted duplicate videos', [
                    'dropbox_path' => $duplicate->dropbox_path,
                    'kept_id' => $duplicate->keep_id,
                    'deleted_count' => $deleted
                ]);
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info('📊 Summary:');
        $this->line("   Total duplicate paths: {$duplicates->count()}");
        $this->line("   Total duplicate records: {$totalDuplicates}");
        
        if ($isDryRun) {
            $this->warn("   Would delete: {$totalDuplicates} records");
            $this->newLine();
            $this->info('💡 Run without --dry-run to actually delete duplicates:');
            $this->line('   php artisan videos:cleanup-duplicates');
        } else {
            $this->info("   ✅ Deleted: {$deletedCount} records");
            $this->line("   ✓ Kept: {$duplicates->count()} records (oldest for each path)");
        }

        return Command::SUCCESS;
    }
}
