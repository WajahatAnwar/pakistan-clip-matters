<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Video;
use App\Services\SearchPhraseExtractor;

class ExtractVideoPhrasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $video;

    /**
     * Create a new job instance.
     */
    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    /**
     * Execute the job.
     */
    public function handle(SearchPhraseExtractor $extractor): void
    {
        if ($this->video->processing_status !== 'completed' || $this->video->approval_status !== 'approved' || $this->video->is_archived) {
            return;
        }

        $extractor->ensureCollectionExists();
        $phrases = $extractor->extractFromVideo($this->video);
        
        $totalPhrases = [];
        foreach ($phrases as $phrase) {
            if (!isset($totalPhrases[$phrase])) {
                $totalPhrases[$phrase] = 0;
            }
            $totalPhrases[$phrase]++;
        }

        $extractor->uploadPhrases($totalPhrases);
    }
}
