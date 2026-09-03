<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BenchmarkSemanticSearchCommandTest extends TestCase
{
    public function test_it_records_sanitized_search_results(): void
    {
        config()->set('services.embedding.url', 'https://semantic.example.test');
        config()->set('services.embedding.api_key', 'secret-value');

        Http::fake([
            'semantic.example.test/search' => Http::response([
                'returned' => 1,
                'unique_videos' => 1,
                'results' => [[
                    'id' => 'segment-1',
                    'video_id' => 913,
                    'video_title' => 'Example',
                    'speaker' => 'Hafiz Naeem Rehman',
                    'score' => 0.81,
                    'relevance_confidence' => 0.8,
                    'intent_match' => true,
                    'text' => 'Relevant transcript evidence',
                    'private_payload' => 'must not be recorded',
                ]],
            ], 200),
        ]);

        $output = storage_path('framework/testing/semantic-benchmark.json');

        $this->artisan('search:benchmark-semantic', [
            '--case' => ['speaker_topic_democracy'],
            '--delay-ms' => 0,
            '--decomposition-mode' => 'shadow',
            '--output' => $output,
        ])->assertSuccessful();

        $result = json_decode((string) file_get_contents($output), true);

        $this->assertSame('semantic.example.test', $result['service_host']);
        $this->assertSame(1, $result['cases'][0]['returned']);
        $this->assertSame('Relevant transcript evidence', $result['cases'][0]['results'][0]['text']);
        $this->assertSame(0.8, $result['cases'][0]['results'][0]['relevance_confidence']);
        $this->assertTrue($result['cases'][0]['results'][0]['intent_match']);
        $this->assertArrayNotHasKey('private_payload', $result['cases'][0]['results'][0]);
        $this->assertStringNotContainsString('secret-value', (string) file_get_contents($output));

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://semantic.example.test/search'
            && $request['search_mode'] === 'semantic'
            && $request['query_decomposition_mode'] === 'shadow'
        );
    }
}
