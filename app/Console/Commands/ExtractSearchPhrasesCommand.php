<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use App\Services\SearchPhraseExtractor;

class ExtractSearchPhrasesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:extract-phrases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract common phrases from all videos and upload to Typesense search_phrases collection';

    /**
     * Execute the console command.
     */
    public function handle(SearchPhraseExtractor $extractor)
    {
        $this->info("Initializing Typesense search_phrases collection (Fresh)...");
        $extractor->ensureCollectionExists(true);

        $this->info("Extracting phrases from videos...");
        
        $totalPhrases = [];
        $videos = Video::where('processing_status', 'completed')
                      ->where('approval_status', 'approved')
                      ->where('is_archived', false)
                      ->get();

        $bar = $this->output->createProgressBar(count($videos));
        $bar->start();

        foreach ($videos as $video) {
            $phrases = $extractor->extractFromVideo($video);
            foreach ($phrases as $phrase) {
                if (!isset($totalPhrases[$phrase])) {
                    $totalPhrases[$phrase] = 0;
                }
                $totalPhrases[$phrase]++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info("Extracted " . count($totalPhrases) . " unique phrases.");
        
        // Filter out low frequency phrases to keep the index lean
        $frequentPhrases = array_filter($totalPhrases, fn($freq) => $freq >= 2);
        
        $this->info("Uploading " . count($frequentPhrases) . " frequent phrases to Typesense...");
        
        $extractor->uploadPhrases($frequentPhrases);
        
        $this->info("Upload complete!");
    }
}
