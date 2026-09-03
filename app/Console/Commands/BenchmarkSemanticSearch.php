<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BenchmarkSemanticSearch extends Command
{
    protected $signature = 'search:benchmark-semantic
        {--queries=tests/Fixtures/semantic_search_golden.json : JSON fixture containing benchmark queries}
        {--output= : Optional JSON output path}
        {--case=* : Run only fixture case IDs (repeatable)}
        {--limit=0 : Maximum number of fixture queries to run; zero runs all}
        {--top-k=20 : Number of segments requested per query}
        {--min-score=0.35 : Minimum score sent to the search service}
        {--decomposition-mode=inherit : Canary mode: inherit Railway environment, off, shadow, or on}
        {--delay-ms=250 : Delay between requests to avoid production bursts}';

    protected $description = 'Run read-only semantic-search relevance benchmarks against the configured embedding service';

    public function handle(): int
    {
        $fixturePath = $this->absolutePath((string) $this->option('queries'));
        $outputPath = $this->option('output')
            ? $this->absolutePath((string) $this->option('output'))
            : null;

        try {
            $cases = $this->loadCases($fixturePath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $selectedCaseIds = array_values(array_filter((array) $this->option('case')));
        if ($selectedCaseIds !== []) {
            $cases = array_values(array_filter(
                $cases,
                fn (array $case) => in_array($case['id'] ?? null, $selectedCaseIds, true)
            ));

            if ($cases === []) {
                $this->error('None of the requested --case IDs exist in the benchmark fixture.');
                return self::FAILURE;
            }
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $cases = array_slice($cases, 0, $limit);
        }

        $serviceUrl = rtrim((string) config('services.embedding.url'), '/');
        $apiKey = (string) config('services.embedding.api_key', '');

        if ($serviceUrl === '') {
            $this->error('The semantic search service URL is not configured. Expected EMBEDDING_SERVICE_URL.');
            return self::FAILURE;
        }

        $decompositionMode = (string) $this->option('decomposition-mode');
        if (! in_array($decompositionMode, ['inherit', 'off', 'shadow', 'on'], true)) {
            $this->error('--decomposition-mode must be inherit, off, shadow, or on.');
            return self::FAILURE;
        }

        $run = [
            'schema_version' => 1,
            'started_at' => now()->toIso8601String(),
            'service_host' => parse_url($serviceUrl, PHP_URL_HOST),
            'settings' => [
                'top_k' => (int) $this->option('top-k'),
                'min_score' => (float) $this->option('min-score'),
                'query_decomposition_mode' => $decompositionMode,
            ],
            'cases' => [],
        ];

        foreach ($cases as $index => $case) {
            $this->line(sprintf('[%d/%d] %s', $index + 1, count($cases), $case['query']));
            $startedAt = microtime(true);

            try {
                $searchPayload = [
                    'query' => $case['query'],
                    'search_mode' => 'semantic',
                    'top_k' => (int) $this->option('top-k'),
                    'min_score' => (float) $this->option('min-score'),
                ];
                if ($decompositionMode !== 'inherit') {
                    $searchPayload['query_decomposition_mode'] = $decompositionMode;
                }

                $response = Http::timeout(150)
                    ->withHeaders([
                        'X-API-Key' => $apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->post($serviceUrl.'/search', $searchPayload);

                $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
                $payload = $response->json() ?: [];

                $run['cases'][] = array_merge($case, [
                    'http_status' => $response->status(),
                    'elapsed_ms' => $elapsedMs,
                    'returned' => $payload['returned'] ?? count($payload['results'] ?? []),
                    'unique_videos' => $payload['unique_videos'] ?? null,
                    'relevance_validation' => $payload['relevance_validation'] ?? null,
                    'results' => $this->sanitizeResults($payload['results'] ?? []),
                    'error' => $response->successful() ? null : Str::limit($response->body(), 500),
                ]);

                $this->info(sprintf('  HTTP %d, %d ms, %d results', $response->status(), $elapsedMs, $payload['returned'] ?? count($payload['results'] ?? [])));
            } catch (\Throwable $exception) {
                $run['cases'][] = array_merge($case, [
                    'http_status' => null,
                    'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'returned' => 0,
                    'unique_videos' => 0,
                    'results' => [],
                    'error' => $exception->getMessage(),
                ]);
                $this->warn('  Request failed: '.$exception->getMessage());
            }

            $delayMs = max(0, (int) $this->option('delay-ms'));
            if ($delayMs > 0 && $index < count($cases) - 1) {
                usleep($delayMs * 1000);
            }
        }

        $run['completed_at'] = now()->toIso8601String();

        if ($outputPath) {
            $directory = dirname($outputPath);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $this->error("Unable to create output directory: {$directory}");
                return self::FAILURE;
            }

            file_put_contents(
                $outputPath,
                json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
            );
            $this->info("Benchmark written to {$outputPath}");
        }

        return collect($run['cases'])->contains(fn (array $case) => $case['http_status'] !== 200)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function loadCases(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Benchmark fixture not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Benchmark fixture is not valid JSON: {$path}");
        }

        $cases = array_values(array_filter($decoded, fn ($case) => is_array($case) && ! empty($case['query'])));
        if ($cases === []) {
            throw new RuntimeException("Benchmark fixture contains no queries: {$path}");
        }

        return $cases;
    }

    private function sanitizeResults(array $results): array
    {
        return collect($results)->take(10)->map(fn (array $result) => [
            'id' => $result['id'] ?? null,
            'video_id' => $result['video_id'] ?? null,
            'video_title' => $result['video_title'] ?? null,
            'speaker' => $result['speaker'] ?? null,
            'score' => $result['score'] ?? null,
            'original_score' => $result['original_score'] ?? null,
            'llm_relevance_score' => $result['llm_relevance_score'] ?? null,
            'llm_complete_topic' => $result['llm_complete_topic'] ?? null,
            'llm_incidental_match' => $result['llm_incidental_match'] ?? null,
            'llm_required_facets' => $result['llm_required_facets'] ?? [],
            'llm_supported_facets' => $result['llm_supported_facets'] ?? [],
            'relevance_confidence' => $result['relevance_confidence'] ?? null,
            'intent_match' => $result['intent_match'] ?? null,
            'start_time' => $result['start_time'] ?? null,
            'end_time' => $result['end_time'] ?? null,
            'match_types' => $result['match_types'] ?? [],
            'text' => Str::limit((string) ($result['text'] ?? ''), 500, ''),
        ])->values()->all();
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
