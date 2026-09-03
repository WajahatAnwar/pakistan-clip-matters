<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Typesense\Client;
use Illuminate\Support\Str;

class SearchPhraseExtractor
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'nodes' => [
                [
                    'host' => config('scout.typesense.client-settings.nodes.0.host'),
                    'port' => config('scout.typesense.client-settings.nodes.0.port'),
                    'protocol' => config('scout.typesense.client-settings.nodes.0.protocol'),
                ]
            ],
            'api_key' => config('scout.typesense.client-settings.api_key'),
            'connection_timeout_seconds' => 2,
        ]);
    }

    /**
     * Create the search_phrases collection if it doesn't exist.
     */
    public function ensureCollectionExists(bool $fresh = false)
    {
        if ($fresh) {
            try {
                $this->client->collections['search_phrases']->delete();
            } catch (\Exception $e) {}
        }

        try {
            $this->client->collections['search_phrases']->retrieve();
        } catch (\Exception $e) {
            $this->client->collections->create([
                'name' => 'search_phrases',
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'phrase', 'type' => 'string'],
                    ['name' => 'romanized_phrase', 'type' => 'string'],
                    ['name' => 'frequency', 'type' => 'int32', 'sort' => true]
                ],
                'default_sorting_field' => 'frequency'
            ]);
        }
    }

    public static function romanizeUrdu(string $text): string
    {
        $map = [
            'ا' => 'a', 'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ٹ' => 't',
            'ث' => 's', 'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh',
            'د' => 'd', 'ڈ' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ڑ' => 'r',
            'ز' => 'z', 'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
            'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh',
            'ف' => 'f', 'ق' => 'q', 'ک' => 'k', 'گ' => 'g', 'ل' => 'l',
            'م' => 'm', 'ن' => 'n', 'ں' => 'n', 'و' => 'w', 'ہ' => 'h',
            'ھ' => 'h', 'ی' => 'y', 'ے' => 'ay', 'ي' => 'y', 'ك' => 'k',
            'َ' => 'a', 'ِ' => 'i', 'ُ' => 'u'
        ];
        return strtr($text, $map);
    }

    /**
     * Process a single video and extract phrases.
     */
    public function extractFromVideo(Video $video)
    {
        $text = [];
        
        // Add title and summaries
        if ($video->title) $text[] = $video->title;
        if ($video->summary_urdu) $text[] = $video->summary_urdu;
        if ($video->summary_english) $text[] = $video->summary_english;
        
        // Process transcript arrays
        if (is_array($video->transcript_urdu)) {
            foreach ($video->transcript_urdu as $segment) {
                if (isset($segment['text'])) $text[] = $segment['text'];
            }
        }
        if (is_array($video->transcript_english)) {
            foreach ($video->transcript_english as $segment) {
                if (isset($segment['text'])) $text[] = $segment['text'];
            }
        }

        $fullText = implode(' ', $text);
        return $this->extractNgrams($fullText);
    }

    /**
     * Extract 2-gram, 3-gram, 4-gram phrases.
     */
    protected function extractNgrams(string $text): array
    {
        // Clean text: keep only letters and numbers, lowercase
        $text = mb_strtolower($text, 'UTF-8');
        // Replace punctuation with spaces
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        
        $words = array_filter(explode(' ', trim($text)), function($word) {
            return mb_strlen($word) > 0;
        });
        $words = array_values($words);
        
        $phrases = [];
        $count = count($words);
        
        // We will generate phrases of length 2, 3, and 4
        for ($n = 2; $n <= 4; $n++) {
            for ($i = 0; $i <= $count - $n; $i++) {
                $slice = array_slice($words, $i, $n);
                $phrase = implode(' ', $slice);
                
                // Add to list if it doesn't start/end with common stop words (simplified)
                $phrases[] = $phrase;
            }
        }
        
        return $phrases;
    }

    /**
     * Upsert a list of phrases to Typesense.
     */
    public function uploadPhrases(array $phrasesToFrequencies)
    {
        $documents = [];
        foreach ($phrasesToFrequencies as $phrase => $freq) {
            // Generate deterministic ID
            $id = md5($phrase);
            
            // We want to fetch existing freq if it exists, or just do an upsert
            // Actually, doing a raw import with action=upsert will replace it.
            // If we replace, we might lose total frequency. 
            // In a production system we might aggregate, but for now we'll just upsert the document.
            $documents[] = [
                'id' => $id,
                'phrase' => $phrase,
                'romanized_phrase' => self::romanizeUrdu($phrase),
                'frequency' => $freq
            ];
        }

        if (empty($documents)) {
            return;
        }

        // Upload in batches of 500
        $chunks = array_chunk($documents, 500);
        foreach ($chunks as $chunk) {
            try {
                $this->client->collections['search_phrases']->documents->import($chunk, ['action' => 'upsert']);
            } catch (\Exception $e) {
                Log::error('Failed to import phrases to Typesense: ' . $e->getMessage());
            }
        }
    }
}
