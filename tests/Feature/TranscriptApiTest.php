<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranscriptApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.transcript_api.key' => 'test-transcript-api-key']);
    }

    public function test_it_returns_only_the_transcript_id_and_text_for_a_known_id(): void
    {
        $user = User::factory()->create([
            'assemblyai_api_key' => 'user-assemblyai-key',
        ]);

        $video = Video::create([
            'user_id' => $user->id,
            'dropbox_path' => '/test/video.mp4',
            'filename' => 'video.mp4',
            'title' => 'API transcript test',
            'transcript_id' => 'transcript-123',
        ]);

        $assemblyTranscript = [
            'id' => 'transcript-123',
            'status' => 'completed',
            'text' => 'This is the complete transcript.',
            'language_code' => 'en',
            'utterances' => [
                ['speaker' => 'A', 'text' => 'This is the complete transcript.'],
            ],
            'words' => [
                ['text' => 'This', 'start' => 0, 'end' => 200],
            ],
        ];

        Http::fake([
            'api.assemblyai.com/v2/transcript/transcript-123' => Http::response($assemblyTranscript),
        ]);

        $response = $this->postJson('/api/transcripts', [
            'transcript_id' => $video->transcript_id,
        ], [
            'X-API-Key' => 'test-transcript-api-key',
        ]);

        $response->assertOk()->assertExactJson([
            'transcript_id' => 'transcript-123',
            'text' => 'This is the complete transcript.',
        ]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.assemblyai.com/v2/transcript/transcript-123'
            && $request->hasHeader('authorization', 'user-assemblyai-key')
        );
    }

    public function test_it_lists_transcripts_with_text_length_and_pagination(): void
    {
        $user = User::factory()->create();

        Video::create([
            'user_id' => $user->id,
            'dropbox_path' => '/test/first.mp4',
            'filename' => 'first.mp4',
            'title' => 'First video',
            'transcript_id' => 'transcript-first',
            'transcript_text' => 'First transcript',
        ]);

        Video::create([
            'user_id' => $user->id,
            'dropbox_path' => '/test/second.mp4',
            'filename' => 'second.mp4',
            'title' => 'Second video',
            'transcript_id' => 'transcript-second',
            'transcript_text' => 'سلام world',
        ]);

        Video::create([
            'user_id' => $user->id,
            'dropbox_path' => '/test/no-transcript.mp4',
            'filename' => 'no-transcript.mp4',
            'transcript_id' => null,
        ]);

        $response = $this->getJson('/api/transcripts?per_page=1&page=1', [
            'X-API-Key' => 'test-transcript-api-key',
        ]);

        $response->assertOk()->assertExactJson([
            'data' => [[
                'transcript_id' => 'transcript-second',
                'title' => 'Second video',
                'length' => 10,
            ]],
            'pagination' => [
                'current_page' => 1,
                'per_page' => 1,
                'last_page' => 2,
                'total' => 2,
            ],
        ]);
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->postJson('/api/transcripts', [
            'transcript_id' => 'transcript-123',
        ])->assertUnauthorized();
    }

    public function test_it_validates_transcript_id(): void
    {
        $this->postJson('/api/transcripts', [], [
            'X-API-Key' => 'test-transcript-api-key',
        ])->assertUnprocessable()->assertJsonValidationErrors('transcript_id');
    }

    public function test_it_returns_not_found_for_an_unknown_transcript_id(): void
    {
        $this->postJson('/api/transcripts', [
            'transcript_id' => 'unknown-transcript',
        ], [
            'X-API-Key' => 'test-transcript-api-key',
        ])->assertNotFound()->assertJson([
            'message' => 'Transcript not found.',
            'transcript_id' => 'unknown-transcript',
        ]);
    }
}
