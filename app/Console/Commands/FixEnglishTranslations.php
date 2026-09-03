<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use OpenAI;
use ReflectionMethod;

class FixEnglishTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:fix-english-translations {--ids= : Comma-separated list of video IDs to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and fix English translations that incorrectly contain Urdu text';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $idsParam = $this->option('ids');
        
        $query = Video::query();
        
        if ($idsParam) {
            $ids = explode(',', $idsParam);
            $query->whereIn('id', $ids);
        }
        
        $videos = $query->get();
        
        if ($videos->isEmpty()) {
            $this->info('No videos found to process.');
            return;
        }

        $this->info("Scanning {$videos->count()} videos for Urdu/Arabic text in English translations...");
        
        $openaiApiKey = env('OPENAI_API_KEY');
        if (!$openaiApiKey) {
            $this->error('OPENAI_API_KEY is not set.');
            return;
        }
        
        $client = OpenAI::client($openaiApiKey);
        $processJob = app(\App\Jobs\ProcessVideoJob::class, [
            'userId' => 1, // Dummy data for job instantiation
            'videoId' => 1,
            'dropboxVideoPath' => 'dummy'
        ]);
        
        // We need to use reflection to access the protected translation methods
        $translateUtterancesMethod = new ReflectionMethod(\App\Jobs\ProcessVideoJob::class, 'translateUtterances');
        $translateUtterancesMethod->setAccessible(true);
        
        $translateTextMethod = new ReflectionMethod(\App\Jobs\ProcessVideoJob::class, 'translateText');
        $translateTextMethod->setAccessible(true);

        $urduRegex = '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';
        $unicodeEscapeRegex = '/\\\\u(06[0-9a-fA-F]{2}|fb[5-9a-fA-F][0-9a-fA-F]|fc[0-9a-fA-F]{2}|fd[0-9a-fA-F]{2}|fe[7-9a-fA-F][0-9a-fA-F])/i';
        $fixedCount = 0;

        foreach ($videos as $video) {
            $this->info("Checking Video ID: {$video->id}");
            $needsFixing = false;
            
            // Check Transcript
            $transcriptEnglish = $video->transcript_english;
            $transcriptHasUrduArabic = false;
            
            // Decode string if necessary
            if (is_string($transcriptEnglish)) {
                $decoded = json_decode($transcriptEnglish, true);
                if (is_string($decoded)) {
                    $decoded = json_decode($decoded, true);
                }
                if (is_array($decoded)) {
                    $transcriptEnglish = $decoded;
                }
            }
            
            if (is_array($transcriptEnglish)) {
                foreach ($transcriptEnglish as $utterance) {
                    if (isset($utterance['text'])) {
                        if (preg_match($urduRegex, $utterance['text']) || preg_match($unicodeEscapeRegex, $utterance['text'])) {
                            $transcriptHasUrduArabic = true;
                            break;
                        }
                    }
                }
            } elseif (is_string($transcriptEnglish) && (preg_match($urduRegex, $transcriptEnglish) || preg_match($unicodeEscapeRegex, $transcriptEnglish))) {
                $transcriptHasUrduArabic = true;
            }
            
            // Check Summary
            $summaryEnglish = $video->summary_english;
            $summaryHasUrduArabic = is_string($summaryEnglish) && (preg_match($urduRegex, $summaryEnglish) || preg_match($unicodeEscapeRegex, $summaryEnglish));
            
            if ($transcriptHasUrduArabic) {
                $this->warn("  - Found Urdu/Arabic in English transcript for Video ID {$video->id}");
                $needsFixing = true;
            }
            if ($summaryHasUrduArabic) {
                $this->warn("  - Found Urdu/Arabic in English summary for Video ID {$video->id}");
                $needsFixing = true;
            }
            
            if ($needsFixing) {
                $this->info("  - Fixing translations for Video ID {$video->id}...");
                $updates = [];
                
                try {
                    if ($transcriptHasUrduArabic) {
                        // The source text for translation should be the original speakers_data
                        $sourceUtterances = $video->speakers_data;
                        if (!$sourceUtterances && $video->transcript_urdu) {
                            $sourceUtterances = $video->transcript_urdu;
                        }
                        
                        // Decode string if necessary
                        if (is_string($sourceUtterances)) {
                            $decodedSource = json_decode($sourceUtterances, true);
                            if (is_string($decodedSource)) {
                                $decodedSource = json_decode($decodedSource, true);
                            }
                            if (is_array($decodedSource)) {
                                $sourceUtterances = $decodedSource;
                            }
                        }
                        
                        if ($sourceUtterances && is_array($sourceUtterances)) {
                            $this->info("    -> Re-translating transcript utterances...");
                            $newTranscriptEnglish = $translateUtterancesMethod->invoke($processJob, $client, $sourceUtterances, 'English');
                            $updates['transcript_english'] = $newTranscriptEnglish;
                        } else {
                            $this->error("    -> Cannot re-translate transcript: source utterances not found or not array.");
                        }
                    }
                    
                    if ($summaryHasUrduArabic) {
                        // The source text for summary should be the original summary
                        $sourceSummary = $video->summary;
                        if (!$sourceSummary && $video->summary_urdu) {
                            $sourceSummary = $video->summary_urdu;
                        }
                        
                        if ($sourceSummary) {
                            $this->info("    -> Re-translating summary...");
                            $newSummaryEnglish = $translateTextMethod->invoke($processJob, $client, $sourceSummary, 'English');
                            $updates['summary_english'] = $newSummaryEnglish;
                        } else {
                            $this->error("    -> Cannot re-translate summary: source summary not found.");
                        }
                    }
                    
                    if (!empty($updates)) {
                        $video->update($updates);
                        $this->info("  - Successfully updated Video ID {$video->id}");
                        $fixedCount++;
                    }
                } catch (\Exception $e) {
                    $this->error("  - Failed to fix Video ID {$video->id}: " . $e->getMessage());
                    Log::error("Failed to fix English translation for video {$video->id}", ['error' => $e->getMessage()]);
                }
            } else {
                $this->line("  - No Urdu text found in English translations.");
            }
        }
        
        $this->info("Finished. Fixed $fixedCount videos.");
    }
}
