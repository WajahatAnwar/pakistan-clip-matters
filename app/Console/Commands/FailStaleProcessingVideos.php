<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FailStaleProcessingVideos extends Command
{
    protected $signature = 'videos:fail-stale-processing
        {--hours= : Override the configured stale-processing threshold}
        {--dry-run : List stale videos without changing them}';

    protected $description = 'Mark videos that have remained processing beyond the allowed time as failed';

    public function handle(): int
    {
        $hours = $this->option('hours') ?? config('video-processing.stale_after_hours', 12);

        if (!is_numeric($hours) || (float) $hours <= 0) {
            $this->error('The --hours value must be greater than zero.');

            return self::FAILURE;
        }

        $cutoff = now()->subMinutes((int) round((float) $hours * 60));
        $staleVideos = Video::query()
            ->where('processing_status', 'processing')
            ->whereNotNull('processing_started_at')
            ->where('processing_started_at', '<=', $cutoff)
            ->orderBy('id');

        if ($this->option('dry-run')) {
            $count = (clone $staleVideos)->count();
            $this->info("{$count} video(s) have been processing since {$cutoff} or earlier.");

            return self::SUCCESS;
        }

        $error = "Processing exceeded the {$hours}-hour limit and was marked as failed automatically.";
        $marked = 0;

        $staleVideos->select('id')->chunkById(100, function ($videos) use ($cutoff, $error, &$marked): void {
            foreach ($videos as $video) {
                // Re-check in the UPDATE so a concurrently completed video is never overwritten.
                $updated = Video::query()
                    ->whereKey($video->id)
                    ->where('processing_status', 'processing')
                    ->where('processing_started_at', '<=', $cutoff)
                    ->update([
                        'processing_status' => 'failed',
                        'processing_error' => $error,
                        'processing_completed_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated === 1) {
                    $marked++;
                    Log::warning('Stale video processing marked as failed', [
                        'video_id' => $video->id,
                        'cutoff' => $cutoff->toDateTimeString(),
                    ]);
                }
            }
        });

        $this->info("Marked {$marked} stale processing video(s) as failed.");

        return self::SUCCESS;
    }
}
