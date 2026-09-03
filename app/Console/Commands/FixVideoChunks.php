<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use OpenAI;

class FixVideoChunks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:fix-chunks {video_id?} {--all} {--max-duration=15000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-chunks long video transcript utterances into smaller pieces to fix timestamp seeking bugs, and re-translates them.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $videoId = $this->argument('video_id');
        $all = $this->option('all');
        $maxDurationMs = (int) $this->option('max-duration');

        if (!$videoId && !$all) {
            $this->error('Please provide a video_id or use the --all flag.');
            return;
        }

        $videos = $videoId ? Video::where('id', $videoId)->get() : Video::whereNotNull('speakers_data')->get();

        $this->info("Found " . $videos->count() . " videos to process.");

        foreach ($videos as $video) {
            $this->info("Processing video {$video->id}...");
            
            $speakersData = is_string($video->speakers_data) ? json_decode($video->speakers_data, true) : $video->speakers_data;
            
            if (!$speakersData || !is_array($speakersData)) {
                $this->warn("Video {$video->id} has invalid speakers_data. Skipping.");
                continue;
            }

            // Check if it actually needs chunking
            $needsChunking = false;
            foreach ($speakersData as $utterance) {
                if (isset($utterance['start'], $utterance['end']) && ($utterance['end'] - $utterance['start'] > $maxDurationMs)) {
                    $needsChunking = true;
                    break;
                }
            }

            if (!$needsChunking) {
                $this->info("Video {$video->id} chunks are already small enough. Skipping.");
                continue;
            }

            $this->info("Chunking utterances for video {$video->id}...");
            $chunked = $this->chunkUtterances($speakersData, $maxDurationMs);
            
            // Refine and Translate
            $client = OpenAI::client(env('OPENAI_API_KEY'));
            
            $this->info("Re-translating into Urdu...");
            $transcriptUrdu = $this->translateUtterances($client, $chunked, 'Urdu');
            
            $this->info("Re-translating into English...");
            $transcriptEnglish = $this->translateUtterances($client, $chunked, 'English');

            $video->speakers_data = $chunked;
            $video->transcript_urdu = $transcriptUrdu;
            $video->transcript_english = $transcriptEnglish;
            $video->save();

            // Dispatch embeddings update job
            $this->info("Dispatching embeddings job for video {$video->id}...");
            \App\Jobs\GenerateVideoEmbedding::dispatch($video)->onQueue('high');

            $this->info("Successfully fixed video {$video->id}.");
        }
    }

    protected function chunkUtterances(array $utterances, int $maxDurationMs = 15000): array
    {
        $chunked = [];

        foreach ($utterances as $utterance) {
            if (isset($utterance['words']) && !empty($utterance['words']) && isset($utterance['start'], $utterance['end']) && ($utterance['end'] - $utterance['start'] > $maxDurationMs)) {
                $currentChunk = [
                    'speaker' => $utterance['speaker'] ?? 'A',
                    'actualSpeaker' => $utterance['actualSpeaker'] ?? null,
                    'diarizationSpeaker' => $utterance['diarizationSpeaker'] ?? null,
                    'text' => '',
                    'start' => null,
                    'end' => null,
                    'confidence' => $utterance['confidence'] ?? 0,
                    'words' => []
                ];
                $wordsText = [];

                foreach ($utterance['words'] as $word) {
                    if ($currentChunk['start'] === null) {
                        $currentChunk['start'] = $word['start'] ?? 0;
                    }

                    $wordsText[] = $word['text'] ?? '';
                    $currentChunk['end'] = $word['end'] ?? 0;
                    $currentChunk['words'][] = $word;

                    if (($word['end'] - $currentChunk['start']) >= $maxDurationMs) {
                        $currentChunk['text'] = trim(implode(' ', $wordsText));
                        $chunked[] = $currentChunk;

                        $currentChunk = [
                            'speaker' => $utterance['speaker'] ?? 'A',
                            'actualSpeaker' => $utterance['actualSpeaker'] ?? null,
                            'diarizationSpeaker' => $utterance['diarizationSpeaker'] ?? null,
                            'text' => '',
                            'start' => null,
                            'end' => null,
                            'confidence' => $utterance['confidence'] ?? 0,
                            'words' => []
                        ];
                        $wordsText = [];
                    }
                }

                if (!empty($currentChunk['words'])) {
                    $currentChunk['text'] = trim(implode(' ', $wordsText));
                    $chunked[] = $currentChunk;
                }
            } else {
                $chunked[] = $utterance;
            }
        }

        return $chunked;
    }

    protected function translateUtterances($client, array $utterances, string $targetLanguage): array
    {
        $translatedUtterances = [];
        $batchSize = 20;
        $batches = array_chunk($utterances, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            $textsToTranslate = [];
            foreach ($batch as $index => $utterance) {
                $textsToTranslate[] = [
                    'index' => $index,
                    'text' => $utterance['text'] ?? ''
                ];
            }
            
            $jsonInput = json_encode($textsToTranslate, JSON_UNESCAPED_UNICODE);
            
            $maxRetries = 3;
            $attempt = 0;
            $success = false;
            $translatedTexts = $textsToTranslate; // Default fallback

            while ($attempt < $maxRetries && !$success) {
                $attempt++;
                try {
                    $systemPrompt = "You are a professional translator. Translate the following JSON array of texts to {$targetLanguage}. Return ONLY a valid JSON array with the same structure (index and text fields).";
                    
                    if ($targetLanguage === 'English') {
                        $systemPrompt .= " IMPORTANT: You are translating to ENGLISH. Your output MUST NOT contain ANY Urdu or Arabic characters under any circumstances. Even if the original text contains Islamic quotes, names, or Arabic/Urdu terms, you MUST translate or transliterate them into the English alphabet. Do NOT output original scripts.";
                    }

                    $response = $client->chat()->create([
                        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $systemPrompt
                            ],
                            [
                                'role' => 'user',
                                'content' => $jsonInput
                            ]
                        ],
                        'max_tokens' => 4000,
                        'temperature' => 0.3 + ($attempt * 0.1), // Increase temp on retries
                    ]);
                    
                    $translatedJson = $response->choices[0]->message->content ?? '[]';
                    $translatedJson = preg_replace('/^```json\s*|\s*```$/m', '', trim($translatedJson));
                    $translatedJson = preg_replace('/^```\s*|\s*```$/m', '', trim($translatedJson));
                    
                    $decodedTexts = json_decode($translatedJson, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedTexts)) {
                        $hasUrduArabic = false;
                        if ($targetLanguage === 'English') {
                            // Check for Urdu/Arabic characters in the returned payload
                            foreach ($decodedTexts as &$ct) {
                                if (isset($ct['text']) && preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $ct['text'])) {
                                    $hasUrduArabic = true;
                                    // If this is the final attempt, we brute-force remove the Urdu characters
                                    if ($attempt == $maxRetries) {
                                        $ct['text'] = preg_replace('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', '', $ct['text']);
                                        $ct['text'] = trim(preg_replace('/\s+/', ' ', $ct['text'])); // Clean up spaces
                                        $hasUrduArabic = false; // We just stripped it out, so treat it as successful
                                    }
                                }
                            }
                        }

                        // Keep the latest candidate text
                        $translatedTexts = $decodedTexts;

                        if (!$hasUrduArabic) {
                            $success = true;
                        } else {
                            Log::warning("FixVideoChunks: Translation batch contained Urdu/Arabic on attempt {$attempt}. Retrying...");
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("FixVideoChunks: Translation error on attempt {$attempt}: " . $e->getMessage());
                }
            }
            
            foreach ($batch as $index => $utterance) {
                $translatedText = $utterance['text'] ?? '';
                foreach ($translatedTexts as $translated) {
                    if (isset($translated['index']) && $translated['index'] == $index) {
                        $translatedText = $translated['text'] ?? $utterance['text'];
                        break;
                    }
                }
                
                $newUtterance = $utterance;
                $newUtterance['text'] = $translatedText;
                $translatedUtterances[] = $newUtterance;
            }
        }
        
        return $translatedUtterances;
    }
}
