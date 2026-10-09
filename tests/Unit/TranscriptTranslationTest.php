<?php

namespace Tests\Unit;

use App\Jobs\ProcessVideoJob;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class TranscriptTranslationTest extends TestCase
{
    private function translate(array $translations): array
    {
        $client = new class($translations) {
            public function __construct(private array $translations) {}
            public function chat(): self { return $this; }
            public function create(array $request): object
            {
                return (object) ['choices' => [(object) ['message' => (object) [
                    'content' => json_encode(['translations' => $this->translations]),
                ]]]];
            }
        };
        $method = new ReflectionMethod(ProcessVideoJob::class, 'translateUtterances');

        return $method->invoke(new ProcessVideoJob(1, 1, '/test.mp4'), $client, [
            ['text' => 'سلام', 'start' => 1000, 'end' => 2000],
            ['text' => 'شکریہ', 'start' => 2000, 'end' => 3000],
        ], 'English');
    }

    public function test_it_maps_translations_by_index_and_preserves_timestamps(): void
    {
        $rows = $this->translate([['index' => 1, 'text' => 'Thank you'], ['index' => 0, 'text' => 'Hello']]);
        $this->assertSame(['Hello', 'Thank you'], array_column($rows, 'text'));
        $this->assertSame([1000, 2000], array_column($rows, 'start'));
    }

    public function test_it_rejects_partial_batches_instead_of_using_original_urdu(): void
    {
        $this->expectException(RuntimeException::class);
        $this->translate([['index' => 0, 'text' => 'Hello']]);
    }

    public function test_it_rejects_urdu_in_english_without_stripping_content(): void
    {
        $this->expectException(RuntimeException::class);
        $this->translate([['index' => 0, 'text' => 'Hello سلام'], ['index' => 1, 'text' => 'Thank you']]);
    }

    public function test_it_rejects_duplicate_segment_indexes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->translate([['index' => 0, 'text' => 'Hello'], ['index' => 0, 'text' => 'Thank you']]);
    }
}
