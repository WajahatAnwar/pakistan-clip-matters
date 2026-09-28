<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.transcript_api.key' => 'test-transcript-api-key']);
    }

    public function test_it_returns_original_urdu_and_english_transcripts_for_a_known_id(): void
    {
        $user = User::factory()->create();

        $urduTranscript = [
            [
                'speaker' => 'A',
                'text' => 'یہ مکمل اردو متن ہے۔',
                'words' => [['text' => 'یہ', 'start' => 0, 'end' => 100]],
            ],
            ['speaker' => 'B', 'text' => 'یہ دوسرا حصہ ہے۔'],
        ];
        $englishTranscript = [
            [
                'speaker' => 'A',
                'text' => 'This is the complete English transcript.',
                'words' => [['text' => 'This', 'start' => 0, 'end' => 100]],
            ],
            ['speaker' => 'B', 'text' => 'This is the second section.'],
        ];

        $video = Video::create([
            'user_id' => $user->id,
            'dropbox_path' => '/test/video.mp4',
            'filename' => 'video.mp4',
            'title' => 'API transcript test',
            'transcript_id' => 'transcript-123',
            'transcript_urdu' => $urduTranscript,
            'transcript_english' => $englishTranscript,
        ]);

        $response = $this->postJson('/api/transcripts', [
            'transcript_id' => $video->transcript_id,
        ], [
            'X-API-Key' => 'test-transcript-api-key',
        ]);

        $response->assertOk()->assertExactJson([
            'transcript_id' => 'transcript-123',
            'transcript_urdu' => "یہ مکمل اردو متن ہے۔\nیہ دوسرا حصہ ہے۔",
            'transcript_english' => "This is the complete English transcript.\nThis is the second section.",
        ]);
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
