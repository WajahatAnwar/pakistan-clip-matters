<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;

class AuditVideoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:audit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit videos for corrupted chunks and transcription issues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Auditing videos in the database...");

        $videos = Video::whereNotNull('speakers_data')->get();
        
        $stats = [
            'total_checked' => $videos->count(),
            'long_chunks' => [],
            'translation_issues' => []
        ];

        foreach ($videos as $video) {
            $speakersData = is_string($video->speakers_data) ? json_decode($video->speakers_data, true) : $video->speakers_data;
            
            if (!is_array($speakersData)) {
                continue;
            }

            // 1. Check for abnormally long chunks (> 15 seconds)
            $hasLongChunks = false;
            foreach ($speakersData as $utterance) {
                if (isset($utterance['start'], $utterance['end']) && ($utterance['end'] - $utterance['start'] > 15500)) {
                    $hasLongChunks = true;
                    break;
                }
            }

            if ($hasLongChunks) {
                $stats['long_chunks'][] = $video->id;
            }

            // 2. Check for mixed transcription issues (Urdu text present in the English transcript)
            // If the video is detected as Urdu, and the English transcript contains many Urdu characters, the translation failed.
            if ($video->language_detected === 'ur') {
                $englishData = is_string($video->transcript_english) ? json_decode($video->transcript_english, true) : $video->transcript_english;
                if (is_array($englishData)) {
                    $urduCharCount = 0;
                    $totalChars = 0;
                    foreach ($englishData as $utterance) {
                        $text = $utterance['text'] ?? '';
                        $totalChars += mb_strlen($text);
                        // Count characters in the Arabic/Urdu Unicode range
                        $urduCharCount += preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
                    }
                    
                    // If more than 10% of characters in the "English" transcript are Urdu, the translation failed
                    if ($totalChars > 0 && ($urduCharCount / $totalChars) > 0.1) {
                        $stats['translation_issues'][] = $video->id;
                    }
                }
            }
        }

        $this->info("Audit Complete!");
        $this->newLine();
        $this->info("Total Videos Checked: " . $stats['total_checked']);
        
        $this->warn("Videos with Timestamp/Chunk issues (Long Chunks): " . count($stats['long_chunks']));
        if (count($stats['long_chunks']) > 0) {
            $this->line("Video IDs: " . implode(', ', $stats['long_chunks']));
        }
        $this->error("Videos with Transcription Issues (Urdu found in English text): " . count($stats['translation_issues']));
        if (count($stats['translation_issues']) > 0) {
            $this->line("Video IDs: " . implode(', ', $stats['translation_issues']));
        }
        
        $this->newLine();
        $this->info("To fix the timestamp chunks and translations for these videos, you can run:");
        $this->line("php artisan video:fix-chunks --all");
    }
}
