<?php
namespace Tests\Feature;

use App\Models\Video;
use App\Models\User;
use App\Services\SearchTextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StageTwoSemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    private function video(string $title): Video
    {
        config(['scout.driver'=>'null']);
        return Video::withoutEvents(fn()=>Video::create([
            'user_id'=>User::factory()->create()->id, 'filename'=>$title.'.mp4', 'title'=>$title,
            'dropbox_path'=>'/'.$title.'.mp4', 'processing_status'=>'completed',
            'approval_status'=>'approved', 'is_archived'=>false, 'language_detected'=>'ur',
            'youtube_url'=>'https://www.youtube.com/watch?v=fixture',
            'speakers_data'=>[['text'=>str_repeat('private transcript ',10000)]],
        ]));
    }
    private function response(array $segments, string $status='completed'): array
    {
        return ['success'=>true, 'results'=>$segments, 'metadata'=>['passage_validation'=>[
            'authority'=>'python_passage_v1', 'status'=>$status, 'retryable'=>$status==='partial',
        ]], 'cursor'=>['next'=>null, 'has_more'=>false]];
    }
    private function segment(Video $video, string $id, float $score, float $start=8): array
    {
        return ['id'=>$id, 'segment_ids'=>[$id], 'video_id'=>$video->id, 'score'=>$score,
            'text'=>'طلبہ مہنگی تعلیمی فیسوں سے پریشان ہیں۔', 'speaker'=>'Unknown Speaker',
            'diarization_speaker'=>'A', 'start_time'=>$start, 'end_time'=>18, 'duration'=>18-$start,
            'match_types'=>['semantic','validated_passage'], 'passage_evidence'=>['fees'=>'مہنگی تعلیمی فیسوں']];
    }
    public function test_python_order_source_identity_overlap_and_early_seek_survive_enrichment(): void
    {
        $a=$this->video('students'); $b=$this->video('fees');
        Http::preventStrayRequests();
        Http::fake(['*'=>Http::response($this->response([
            $this->segment($b,'b',.95), $this->segment($a,'a',.90),
            $this->segment($b,'overlap',.85,9),
        ]))]);
        $response=$this->postJson(route('videos.embeddings.search'),[
            'search_type'=>'smart','query'=>'Students facing high education fees','use_incremental'=>true,
        ])->assertOk()->assertJsonPath('grouped_by_video.0.video.id',$b->id)
          ->assertJsonPath('grouped_by_video.1.video.id',$a->id)
          ->assertJsonCount(2,'grouped_by_video.0.segments')
          ->assertJsonPath('grouped_by_video.0.segments.0.start_time',8)
          ->assertJsonPath('grouped_by_video.0.segments.0.segment_ids.0','b')
          ->assertJsonPath('grouped_by_video.0.segments.0.youtube_timestamp_url','https://www.youtube.com/watch?v=fixture&t=8s')
          ->assertJsonPath('search_metadata.passage_validation.authority','python_passage_v1');
        $this->assertStringNotContainsString('private transcript',$response->getContent());
        Http::assertSent(fn($request)=>!isset($request['language']));
    }
    public function test_titles_never_pad_authoritatively_empty_semantic_results(): void
    {
        $this->video('fees');
        Http::preventStrayRequests(); Http::fake(['*'=>Http::response($this->response([],'no_matches'))]);
        $this->postJson(route('videos.embeddings.search'),[
            'search_type'=>'smart','query'=>'fees','use_incremental'=>true,
        ])->assertOk()->assertJsonCount(0,'grouped_by_video')->assertJsonPath('search_status','no_matches');
        Http::assertSentCount(1);
    }
    public function test_partial_results_are_not_reported_as_no_matches(): void
    {
        Http::preventStrayRequests(); Http::fake(['*'=>Http::response($this->response([],'partial'))]);
        $this->postJson(route('videos.embeddings.search'),[
            'search_type'=>'smart','query'=>'students fees','use_incremental'=>true,
        ])->assertOk()->assertJsonPath('search_status','partial')->assertJsonPath('retryable',true);
    }
    public function test_provider_failure_is_retryable_and_a_new_request_can_succeed(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*'=>Http::sequence()->push(['detail'=>['code'=>'passage_validation_unavailable','retryable'=>true]],503)
            ->push($this->response([],'no_matches'))]);
        $body=['search_type'=>'smart','query'=>'students fees','use_incremental'=>true];
        $this->postJson(route('videos.embeddings.search'),$body)->assertStatus(503)->assertJsonPath('retryable',true);
        $this->postJson(route('videos.embeddings.search'),$body)->assertOk()->assertJsonPath('search_status','no_matches');
        Http::assertSentCount(2);
    }
    public function test_partial_non_incremental_response_is_not_cached_across_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*'=>Http::sequence()->push($this->response([],'partial'))
            ->push($this->response([],'no_matches'))]);
        $body=['search_type'=>'smart','query'=>'students fees','use_incremental'=>false];
        $this->postJson(route('videos.embeddings.search'),$body)->assertOk()->assertJsonPath('search_status','partial');
        $this->postJson(route('videos.embeddings.search'),$body)->assertOk()->assertJsonPath('search_status','no_matches');
        Http::assertSentCount(2);
    }

    public function test_php_unicode_normalization_contract_matches_python_fixture(): void
    {
        $this->assertSame('تعلیم, کی "فیس"?',SearchTextNormalizer::normalize('  تعليم، كی “فيس”؟ '));
        $this->assertNotSame(SearchTextNormalizer::normalize('بھارت'),SearchTextNormalizer::normalize('بہارت'));
    }
}
