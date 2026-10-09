<?php
namespace Tests\Feature;

use App\Models\Video;
use App\Models\User;
use App\Services\TranscriptSearchContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Synthetic records; reported IDs/titles are not verified footage fixtures. */
class StageThreeSearchTest extends TestCase
{
    use RefreshDatabase;
    private function video(string $title,array $extra=[]): Video
    {
        return Video::withoutEvents(fn()=>Video::create(array_merge([
            'user_id'=>User::factory()->create()->id,'dropbox_path'=>'/fixture','filename'=>'00089.mp4',
            'title'=>$title,'processing_status'=>'completed','approval_status'=>'approved','is_archived'=>false,
            'youtube_url'=>'https://youtube.com/watch?v=fixture','video_created_at'=>'2026-10-09',
            'speakers_data'=>[['text'=>str_repeat('private transcript ',10000)]],
        ],$extra)));
    }
    public function test_shared_acronym_unicode_and_title_rules(): void
    {
        foreach (['IPP','I.P.P.','I P P','ｉｐｐ'] as $query) $this->assertSame('ipp',TranscriptSearchContract::normalize($query));
        $this->assertSame('تعلیم کی فیس',TranscriptSearchContract::normalize('تعليم، کی “فيس”'));
        $this->assertSame(.9,TranscriptSearchContract::titleScore('Fuuast Graduation Ceremony','Fuuast'));
        $this->assertSame(0.0,TranscriptSearchContract::titleScore('00089','graduation ceremony'));
    }
    public function test_exact_title_after_many_partials_ranks_first_and_db_paginates_cards(): void
    {
        $a=$this->video('Fuuast Graduation Ceremony extended');
        $rows=[];
        for($i=0;$i<420;$i++)$rows[]=['user_id'=>$a->user_id,'dropbox_path'=>'/p'.$i,'filename'=>'p'.$i.'.mp4',
            'title'=>'Fuuast Graduation Ceremony extended','processing_status'=>'completed','approval_status'=>'approved','is_archived'=>false];
        DB::table('videos')->insert($rows);
        $exact=$this->video('Fuuast Graduation Ceremony');
        Http::preventStrayRequests();DB::enableQueryLog();
        $r=$this->postJson(route('videos.embeddings.search'),['search_type'=>'title','query'=>'FUUAST graduation ceremony!','per_page'=>1])
            ->assertOk()->assertJsonPath('grouped_by_video.0.video.id',$exact->id)
            ->assertJsonPath('total_videos',422)->assertJsonPath('counts.displayed_passages',0)
            ->assertJsonPath('grouped_by_video.0.video.filename','00089.mp4')
            ->assertJsonPath('grouped_by_video.0.is_video_only',true);
        $this->assertStringNotContainsString('private transcript',$r->getContent());
        $queries=collect(DB::getQueryLog())->pluck('query');
        $this->assertTrue($queries->contains(fn($q)=>str_contains($q,'"summary"')&&str_contains($q,'limit 1')));
        Http::assertNothingSent();
    }
    public function test_populated_title_and_query_inputs_accept_zero_title_and_preserve_date(): void
    {
        $video=$this->video('00089');$this->video('00089',['video_created_at'=>'2025-01-01']);
        foreach ([['query'=>'00089'],['title'=>'00089'],['query'=>'00089','title'=>'']] as $input) {
            $this->postJson(route('videos.embeddings.search'),$input+['search_type'=>'title','filter_year'=>2026])
                ->assertOk()->assertJsonPath('total_videos',1)->assertJsonPath('grouped_by_video.0.video.id',$video->id)
                ->assertJsonPath('grouped_by_video.0.video.title','00089');
        }
    }
    private function payload(Video $video): array
    {
        return ['results'=>[['id'=>'source1','video_id'=>$video->id,'segment_ids'=>['source1'],'text'=>'I.P.P. and IPP',
            'start_time'=>8,'end_time'=>18,'score'=>1,'match_types'=>['transcript_normalized'],'matched_field'=>'text',
            'occurrence_count'=>2,'match_spans'=>[['start'=>0,'end'=>5,'text'=>'I.P.P'],['start'=>10,'end'=>13,'text'=>'IPP']],
            'source_utterances'=>[['id'=>'source1','start_time'=>8,'end_time'=>18,'text'=>'I.P.P. and IPP']]]],
            'search_contract'=>['authority'=>'python_contract_v1','status'=>'completed','contract'=>'normalized','exhaustive'=>true]];
    }
    public function test_transcript_evidence_counts_seek_and_contract_forwarding_survive(): void
    {
        $video=$this->video('IPP');Http::preventStrayRequests();Http::fake(['*'=>Http::response($this->payload($video))]);
        foreach (['transcript'=>'normalized','transcript_literal'=>'literal','transcript_alias'=>'alias'] as $mode=>$contract) {
            $this->postJson(route('videos.embeddings.search'),['search_type'=>$mode,'query'=>'IPP','use_incremental'=>true])
                ->assertOk()->assertJsonPath('counts.matched_transcript_occurrences',2)
                ->assertJsonPath('counts.displayed_passages',1)->assertJsonPath('is_incremental',false)
                ->assertJsonPath('grouped_by_video.0.segments.0.youtube_timestamp_url','https://youtube.com/watch?v=fixture&t=8s')
                ->assertJsonPath('grouped_by_video.0.segments.0.match_spans.0.text','I.P.P');
            Http::assertSent(fn($r)=>$r['transcript_contract']===$contract && $r['filter_type']==='text');
        }
    }
    public function test_older_python_without_contract_fails_explicitly(): void
    {
        Http::preventStrayRequests();Http::fake(['*'=>Http::response(['results'=>[]])]);
        $this->postJson(route('videos.embeddings.search'),['search_type'=>'transcript','query'=>'IPP'])->assertStatus(503)->assertJsonPath('retryable',true);
    }
    public function test_metadata_only_or_unapproved_video_does_not_leak_into_transcript_results(): void
    {
        $video=$this->video('IPP');$data=$this->payload($video);$data['results'][0]['match_spans']=[];
        Http::preventStrayRequests();Http::fake(['*'=>Http::response($data)]);
        $this->postJson(route('videos.embeddings.search'),['search_type'=>'transcript','query'=>'IPP'])->assertOk()->assertJsonPath('total_videos',0);
    }
    public function test_summary_candidates_use_each_database_summary_field_and_preserve_evidence(): void
    {
        $a=$this->video('summary',['summary'=>'elite privileges']);$b=$this->video('english',['summary_english'=>'elite privileges']);
        $c=$this->video('urdu',['summary_urdu'=>'اشرافیہ کی مراعات']);
        Http::preventStrayRequests();
        $data=$this->payload($a);$data['results'][0]['passage_evidence']=['topic'=>'I.P.P.'];
        $data['results'][0]['match_spans']=[];$data['search_contract']['contract']='summary';
        Http::fake(['*'=>Http::response($data)]);
        $this->postJson(route('videos.embeddings.search'),['search_type'=>'summary','query'=>'elite privileges'])->assertOk()
            ->assertJsonPath('counts.matched_transcript_occurrences',null)->assertJsonPath('counts.displayed_passages',1);
        Http::assertSent(fn($r)=>array_column($r['summary_candidates'],'video_id')===[$a->id,$b->id]);
        $this->postJson(route('videos.embeddings.search'),['search_type'=>'summary','query'=>'اشرافیہ کی مراعات'])->assertOk();
        Http::assertSent(fn($r)=>array_column($r['summary_candidates'],'video_id')===[$c->id]);
    }
    public function test_summary_provider_failure_remains_retryable_503(): void
    {
        Http::preventStrayRequests();Http::fake(['*'=>Http::response(['detail'=>['message'=>'Validation unavailable','retryable'=>true]],503)]);
        $this->postJson(route('videos.embeddings.search'),['search_type'=>'summary','query'=>'elite privileges'])->assertStatus(503)->assertJsonPath('retryable',true);
    }
    public function test_page_counts_are_separate_from_full_result_counts(): void
    {
        $a=$this->video('a');$b=$this->video('b');$data=$this->payload($a);$row=$this->payload($b)['results'][0];$row['id']='source2';$data['results'][]=$row;
        Http::preventStrayRequests();Http::fake(['*'=>Http::response($data)]);
        foreach ([1,2] as $page) $this->postJson(route('videos.embeddings.search'),['search_type'=>'transcript','query'=>'IPP','per_page'=>1,'page'=>$page])
            ->assertOk()->assertJsonPath('counts.matching_videos',2)->assertJsonPath('counts.matched_transcript_occurrences',4)
            ->assertJsonPath('search_metadata.page_counts.matching_videos',1)->assertJsonPath('search_metadata.page_counts.matched_transcript_occurrences',2);
    }
}
