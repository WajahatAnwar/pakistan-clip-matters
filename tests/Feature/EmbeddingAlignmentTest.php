<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideoEmbedding;
use App\Models\Video;
use App\Models\VideoEmbedding;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmbeddingAlignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'services.embedding.url' => 'https://python.test', 'services.embedding.api_key' => 'shared-secret',
            'services.embedding.callback_url' => 'https://laravel.test/api/embeddings/callback']);
        Http::preventStrayRequests();
        Schema::create('videos', function (Blueprint $t) {
            $t->id(); $t->string('title')->nullable(); $t->string('filename')->nullable();
            $t->string('youtube_url')->nullable(); $t->string('youtube_video_id')->nullable();
            $t->string('processing_status')->default('completed'); $t->string('approval_status')->default('approved');
            $t->boolean('is_archived')->default(false); $t->string('language_detected')->nullable();
            $t->boolean('has_audio')->default(true); $t->float('audio_duration_seconds')->nullable();
            $t->text('summary_english')->nullable(); $t->text('summary_urdu')->nullable();
            $t->string('transcript_timestamp_unit')->nullable(); $t->string('transcript_id')->nullable();
            $t->timestamp('video_created_at')->nullable(); $t->text('summary')->nullable();
            $t->json('identification_data')->nullable(); $t->json('speakers_data')->nullable(); $t->timestamps();
        });
        Schema::create('video_embeddings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('video_id'); $t->string('qdrant_collection')->default('video_transcript_segments');
            $t->integer('segments_count')->default(0); $t->integer('vector_dimensions')->default(3072);
            $t->string('status')->default('pending'); $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamp('failed_at')->nullable();
            $t->string('active_run_id')->nullable(); $t->integer('expected_batches')->nullable(); $t->json('batch_acknowledgments')->nullable(); $t->json('queued_job_ids')->nullable(); $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
        });
    }

    private function video(array $attributes = []): Video
    {
        return Video::create(array_merge(['title' => 'Children rights', 'filename' => 'speech.mp4',
            'processing_status' => 'completed', 'approval_status' => 'approved', 'is_archived' => false, 'transcript_timestamp_unit' => 'seconds'], $attributes));
    }

    private function passage(Video $video, string $id = 'first'): array
    {
        return ['id' => $id, 'video_id' => $video->id, 'score' => .9, 'rerank_score' => .74,
            'text' => 'Children deserve protection.', 'start_time' => 10, 'end_time' => 20,
            'segment_ids' => [$id], 'match_types' => ['validated_passage'], 'independently_verified' => true,
            'passage_evidence' => ['rights' => 'Children deserve protection.'],
            'match_spans' => [['start' => 0, 'end' => 8, 'text' => 'Children']], 'occurrence_count' => 1];
    }

    public function test_exact_title_and_zero_matches_cannot_bypass_python_validation(): void
    {
        $this->video();
        Http::fake(['python.test/*' => Http::response(['results' => [], 'metadata' => ['passage_validation' => ['status' => 'no_matches']]])]);
        $this->postJson('/api/videos/embeddings/search', ['query' => 'Children rights'])
            ->assertOk()->assertJsonPath('grouped_by_video', []);
        Http::assertSentCount(1);
    }

    public function test_forwards_filters_contract_and_summary_candidates_unchanged(): void
    {
        Http::fake(['python.test/*' => Http::response(['results' => [], 'search_contract' => ['authority' => 'python_contract_v1', 'status' => 'no_matches']])]);
        $this->postJson('/api/videos/embeddings/search', ['query' => 'IPP', 'search_mode' => 'simple', 'filter_type' => 'summary',
            'transcript_contract' => 'alias', 'filter_year' => 2026, 'filter_month' => 10, 'filter_date' => '2026-10-16',
            'time_range' => ['start' => 1, 'end' => 20], 'summary_candidates' => [['video_id' => 7, 'matched_summary_fields' => ['summary_en']]]])
            ->assertOk();
        Http::assertSent(fn ($r) => $r->url() === 'https://python.test/search' && $r['filter_date'] === '2026-10-16'
            && $r['transcript_contract'] === 'alias' && $r['summary_candidates'][0]['video_id'] === 7
            && $r['filter_month'] === 10 && $r->hasHeader('X-API-Key', 'shared-secret'));
    }

    public function test_preserves_evidence_repeated_occurrences_and_python_ranking(): void
    {
        $first = $this->video(); $second = $this->video(['title' => 'Another']);
        $one = $this->passage($first); $two = $this->passage($second, 'second');
        $repeated = array_merge($two, ['id' => 'third', 'start_time' => 30, 'end_time' => 40]);
        Http::fake(['python.test/*' => Http::response(['results' => [$one, $two, $repeated],
            'metadata' => ['passage_validation' => ['status' => 'partial', 'retryable' => true]]])]);
        $response = $this->postJson('/api/videos/embeddings/search', ['query' => 'rights'])->assertOk();
        $response->assertJsonPath('grouped_by_video.0.video.id', $first->id)
            ->assertJsonPath('grouped_by_video.1.match_count', 2)
            ->assertJsonPath('grouped_by_video.0.segments.0.passage_evidence.rights', 'Children deserve protection.')
            ->assertJsonPath('grouped_by_video.0.segments.0.occurrence_count', 1)
            ->assertJsonPath('grouped_by_video.0.segments.0.score', .9)
            ->assertJsonPath('grouped_by_video.0.segments.0.rerank_score', .74)
            ->assertJsonPath('search_metadata.status', 'partial');
    }

    public function test_incremental_cursor_and_totals_are_not_sliced_again(): void
    {
        $video = $this->video();
        Http::fake(['python.test/*' => Http::response(['results' => [$this->passage($video)],
            'cursor' => ['next' => 'opaque-python-cursor', 'has_more' => true, 'total_available' => 40],
            'search_session_id' => 'python-session', 'metadata' => ['cache_hit' => false]])]);
        $this->postJson('/api/videos/embeddings/search', ['query' => 'rights', 'use_incremental' => true, 'page' => 2, 'per_page' => 10])
            ->assertOk()->assertJsonPath('grouped_by_video.0.video.id', $video->id)
            ->assertJsonPath('cursor.next', 'opaque-python-cursor')->assertJsonPath('cursor.has_more', true)
            ->assertJsonPath('total_videos', 40);
        Http::assertSent(fn ($r) => $r->url() === 'https://python.test/search/incremental');
    }

    public function test_typed_errors_are_forwarded_without_retry_or_fallback(): void
    {
        Http::fake(['python.test/*' => Http::sequence()
            ->push(['detail' => ['code' => 'search_session_expired', 'message' => 'Run the search again.',
                'retryable' => true, 'diagnostic_id' => 'diagnostic-1']], 409)
            ->push(['detail' => ['code' => 'passage_validation_timeout', 'message' => 'Run the search again.',
                'retryable' => true, 'diagnostic_id' => 'diagnostic-1']], 503)]);
        foreach ([409 => 'search_session_expired', 503 => 'passage_validation_timeout'] as $status => $code) {
            $this->postJson('/api/videos/embeddings/search', ['query' => 'rights', 'use_incremental' => true])
                ->assertStatus($status)->assertJsonPath('code', $code)->assertJsonPath('diagnostic_id', 'diagnostic-1');
        }
        Http::assertSentCount(2);
    }

    public function test_old_php_cursor_is_expired_instead_of_silently_restarted(): void
    {
        $this->postJson('/api/videos/embeddings/search', ['query' => 'rights', 'cursor' => 'php:2:old-session'])
            ->assertStatus(409)->assertJsonPath('code', 'search_session_expired');
        Http::assertNothingSent();
    }

    public function test_rechecks_sql_eligibility_without_inserting_fallback_videos(): void
    {
        $video = $this->video(['approval_status' => 'pending']);
        Http::fake(['python.test/*' => Http::response(['results' => [$this->passage($video)]])]);
        $this->postJson('/api/videos/embeddings/search', ['query' => 'rights'])->assertOk()
            ->assertJsonPath('grouped_by_video', [])->assertJsonPath('search_metadata.status', 'partial');
    }

    public function test_current_public_search_types_are_preserved(): void
    {
        Http::fake(['python.test/*' => Http::response(['results' => [],
            'search_contract' => ['authority' => 'python_contract_v1', 'status' => 'no_matches']])]);
        foreach (['speaker', 'transcript', 'transcript_literal', 'transcript_alias', 'summary'] as $type) {
            $this->postJson('/api/videos/embeddings/search', ['search_type' => $type, 'query' => 'rights', 'use_incremental' => true])
                ->assertOk()->assertJsonPath('is_incremental', false);
        }
        Http::assertSentCount(5);
    }

    public function test_embedding_submission_stays_processing_and_sends_generation_contract(): void
    {
        $video = $this->video(['identification_data' => [['start' => 0, 'end' => 5, 'speaker' => 'Alice']],
            'speakers_data' => [['start' => 0, 'end' => 5, 'text' => 'Children deserve protection.']]]);
        Http::fake(['python.test/embed-video' => Http::response(['status' => 'queued', 'job_id' => 'rq-1', 'segments_queued' => 1], 202)]);
        $job = new GenerateVideoEmbedding($video->id); $job->handle();
        $embedding = VideoEmbedding::where('video_id', $video->id)->first();
        $this->assertSame('processing', $embedding->status);
        $this->assertSame(['rq-1'], $embedding->queued_job_ids);
        $this->assertNull($embedding->completed_at);
        Http::assertSent(fn ($r) => $r['run_id'] === $job->runId && $r['timestamp_unit'] === 'seconds'
            && $r['embedding_model'] === 'text-embedding-3-large' && $r['embedding_dimensions'] === 3072
            && $r['webhook_url'] === 'https://laravel.test/api/embeddings/callback'
            && $r['identification_segments'][0]['segment_index'] === 0);
    }

    public function test_large_upload_keeps_global_indices_and_one_generation_across_batches(): void
    {
        $identification = []; $speakers = [];
        for ($i = 0; $i < 501; $i++) {
            $identification[] = ['start' => $i * 2, 'end' => $i * 2 + 1, 'speaker' => 'Alice'];
            $speakers[] = ['start' => $i * 2, 'end' => $i * 2 + 1, 'text' => 'Children deserve protection.'];
        }
        $video = $this->video(['identification_data' => $identification, 'speakers_data' => $speakers]);
        Http::fake(['python.test/embed-video' => Http::sequence()
            ->push(['status' => 'queued', 'job_id' => 'rq-1'], 202)
            ->push(['status' => 'queued', 'job_id' => 'rq-2'], 202)]);
        $job = new GenerateVideoEmbedding($video->id); $job->handle();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['run_id'] === $job->runId && $r['batch_info']['batch_number'] === 2
            && $r['batch_info']['total_batches'] === 2 && $r['identification_segments'][0]['segment_index'] === 500);
        $embedding = VideoEmbedding::where('video_id', $video->id)->first();
        $this->assertSame(['rq-1', 'rq-2'], $embedding->queued_job_ids);
        $this->assertSame('processing', $embedding->status);
    }

    public function test_only_authenticated_current_generation_callback_can_complete_indexing(): void
    {
        $video = $this->video();
        $embedding = VideoEmbedding::create(['video_id' => $video->id, 'active_run_id' => 'current', 'status' => 'processing']);
        $payload = ['video_id' => $video->id, 'run_id' => 'current', 'status' => 'completed', 'segments_embedded' => 42];
        $this->postJson('/api/embeddings/callback', $payload)->assertUnauthorized();
        $this->withHeader('X-API-Key', 'shared-secret')->postJson('/api/embeddings/callback', array_merge($payload, ['run_id' => 'old']))
            ->assertOk()->assertJsonPath('reason', 'stale_generation');
        $this->assertSame('processing', $embedding->fresh()->status);
        $this->postJson('/api/embeddings/callback', $payload)->assertOk();
        $this->assertSame('completed', $embedding->fresh()->status);
        $this->assertSame(42, $embedding->fresh()->segments_count);
        $this->postJson('/api/embeddings/callback', array_merge($payload, ['status' => 'processing']))->assertOk();
        $this->assertSame('completed', $embedding->fresh()->status);
    }
}
