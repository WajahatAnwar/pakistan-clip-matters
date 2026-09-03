<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Typesense\Client;

class SearchSuggestionService
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
     * Get search suggestions from Typesense search_phrases collection.
     *
     * @param string $query
     * @return array
     */
    public function getSuggestions(string $query): array
    {
        $query = trim($query);
        
        // Reject empty searches or less than 2 characters
        if (mb_strlen($query) < 2) {
            return [];
        }

        // Limit query length
        $query = mb_substr($query, 0, 100);

        $suggestions = [];
        $queryTokens = array_filter(explode(' ', strtolower($query)));
        
        $getMatchCount = function($text) use ($queryTokens) {
            $count = 0;
            $text = strtolower($text);
            foreach ($queryTokens as $t) {
                if ($t !== '' && strpos($text, $t) !== false) {
                    $count++;
                }
            }
            return $count;
        };

        // 1. Fetch tags from DB
        try {
            $dbTags = \App\Models\VideoTag::where('tag', 'LIKE', '%' . $query . '%')
                ->select('tag')
                ->distinct()
                ->limit(5)
                ->pluck('tag')
                ->toArray();

            foreach ($dbTags as $tag) {
                $suggestions[] = [
                    'id' => 'tag_' . md5($tag),
                    'phrase' => $tag,
                    'frequency' => 9999,
                    'match_count' => $getMatchCount($tag),
                    'is_video' => false,
                    'is_tag' => true,
                ];
            }
        } catch (\Exception $e) {
            Log::error('DB tag suggestions failed: ' . $e->getMessage());
        }

        try {
            $videoCollection = (new \App\Models\Video)->searchableAs();
            $raw = $this->client->multiSearch->perform([
                'searches' => [
                    [
                        'collection' => 'search_phrases',
                        'q' => $query,
                        'query_by' => 'phrase,romanized_phrase',
                        'prefix' => true,
                        'num_typos' => 1,
                        'drop_tokens_threshold' => 2,
                        'drop_tokens_mode' => 'both_sides',
                        'prioritize_exact_match' => true,
                        'per_page' => 6,
                        'sort_by' => 'frequency:desc'
                    ],
                    [
                        'collection' => $videoCollection,
                        'q' => $query,
                        'query_by' => 'title', // Removed manual_tags here as they caused index errors
                        'prefix' => true,
                        'num_typos' => 1,
                        'drop_tokens_threshold' => 2,
                        'drop_tokens_mode' => 'both_sides',
                        'prioritize_exact_match' => true,
                        'per_page' => 12
                    ]
                ]
            ]);
            
            $phraseHits = $raw['results'][0]['hits'] ?? [];
            $videoHits = $raw['results'][1]['hits'] ?? [];
            
            Log::info("Raw Typesense Suggestions Response", ['phrase_hits' => count($phraseHits), 'video_hits' => count($videoHits)]);
            
            // Add video titles
            foreach ($videoHits as $hit) {
                $doc = $hit['document'];
                if (!empty($doc['title'])) {
                    $exists = false;
                    foreach ($suggestions as $s) {
                        if (strtolower($s['phrase']) === strtolower($doc['title'])) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $suggestions[] = [
                            'id' => 'video_' . $doc['id'],
                            'phrase' => $doc['title'],
                            'frequency' => 8000,
                            'match_count' => $getMatchCount($doc['title']),
                            'is_video' => true,
                        ];
                    }
                }
            }

            // Add phrases
            foreach ($phraseHits as $hit) {
                $doc = $hit['document'];
                $exists = false;
                foreach ($suggestions as $s) {
                    if (strtolower($s['phrase']) === strtolower($doc['phrase'])) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $suggestions[] = [
                        'id' => 'phrase_' . (string) $doc['id'],
                        'phrase' => $doc['phrase'],
                        'frequency' => $doc['frequency'],
                        'match_count' => $getMatchCount($doc['phrase']),
                        'is_video' => false,
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error('Typesense search suggestions failed: ' . $e->getMessage());
        }

        // Sort by match_count (desc), then is_tag (tags first), then video priority, then frequency
        usort($suggestions, function($a, $b) {
            if ($a['match_count'] !== $b['match_count']) {
                return $b['match_count'] <=> $a['match_count'];
            }
            $aIsTag = $a['is_tag'] ?? false;
            $bIsTag = $b['is_tag'] ?? false;
            if ($aIsTag !== $bIsTag) {
                return $bIsTag <=> $aIsTag; // true (1) before false (0)
            }
            if ($a['is_video'] !== $b['is_video']) {
                return $b['is_video'] <=> $a['is_video'];
            }
            return $b['frequency'] <=> $a['frequency'];
        });

        // Return up to 8 total suggestions
        return array_slice($suggestions, 0, 8);
    }
}
