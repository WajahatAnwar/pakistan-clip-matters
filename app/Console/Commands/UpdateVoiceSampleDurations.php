<?php

namespace App\Console\Commands;

use App\Models\VoiceSample;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class UpdateVoiceSampleDurations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voice-samples:update-durations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update durations for all existing voice samples';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating voice sample durations...');

        $voiceSamples = VoiceSample::all();
        $updated = 0;
        $failed = 0;

        foreach ($voiceSamples as $sample) {
            $fullPath = storage_path('app/public/' . $sample->file_path);

            if (!file_exists($fullPath)) {
                $this->warn("File not found: {$sample->file_path}");
                $failed++;
                continue;
            }

            try {
                $process = new Process([
                    'ffprobe',
                    '-v',
                    'error',
                    '-show_entries',
                    'format=duration',
                    '-of',
                    'default=noprint_wrappers=1:nokey=1',
                    $fullPath
                ]);
                $process->run();

                if ($process->isSuccessful()) {
                    $duration = (float) trim($process->getOutput());
                    $sample->duration = $duration;
                    $sample->save();

                    $this->info("Updated {$sample->name}: {$duration} seconds");
                    $updated++;
                } else {
                    $this->error("Failed to get duration for {$sample->name}: " . $process->getErrorOutput());
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("Exception for {$sample->name}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->info("\nSummary:");
        $this->info("Updated: {$updated}");
        $this->info("Failed: {$failed}");

        return Command::SUCCESS;
    }
}
