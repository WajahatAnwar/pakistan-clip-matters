<?php
namespace Tests\Feature;

use App\Models\Video;
use App\Models\VideoEmbedding;
use App\Models\User;
use App\Services\TranscriptSegmentMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StageOneIndexingTest extends TestCase
{
    use RefreshDatabase;
    public function test_explicit_units_and_unknown_speaker_preserve_labels(): void
    {
        $mapper = new TranscriptSegmentMapper;
        foreach ([['milliseconds', 8000, 8], ['milliseconds', 18000, 18], ['seconds', 8, 8], ['seconds', 18000, 18000]] as [$unit, $end, $expected]) {
            $segment = $mapper->map([['start'=>0, 'end'=>$end, 'text'=>'Valid speech', 'speaker'=>'A']], $unit)[0];
            $this->assertEquals($expected, $segment['end']);
            $this->assertSame('Unknown Speaker', $segment['speaker']);
            $this->assertSame('A', $segment['transcript_speaker']);
        }
    }
    public function test_transcript_without_identification_can_be_queued_and_dry_run_does_not_dispatch(): void
    {
        config(['scout.driver'=>'null']);
        $video=Video::withoutEvents(fn()=>Video::create([
            'user_id'=>User::factory()->create()->id, 'filename'=>'unknown.mp4', 'dropbox_path'=>'/unknown.mp4',
            'processing_status'=>'completed', 'approval_status'=>'approved', 'is_archived'=>false,
            'transcript_id'=>'assembly-transcript', 'identification_data'=>null,
            'speakers_data'=>[['start'=>1000,'end'=>8000,'speaker'=>'A','text'=>'Valid unknown speaker speech']],
        ]));
        \Illuminate\Support\Facades\Bus::fake();
        $this->artisan('search:recover-transcripts',['--id'=>[$video->id],'--dry-run'=>true])->assertSuccessful();
        \Illuminate\Support\Facades\Bus::assertNothingDispatched();
        \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['status'=>'queued'],202)]);
        (new \App\Jobs\GenerateVideoEmbedding($video->id))->handle();
        \Illuminate\Support\Facades\Http::assertSent(fn($request)=>
            $request['timestamp_unit']==='seconds'
            && $request['identification_segments'][0]['end']==8
            && $request['identification_segments'][0]['speaker']==='Unknown Speaker'
            && $request['identification_segments'][0]['transcript_speaker']==='A'
            && !empty($request['run_id']));
    }

    public function test_ambiguous_timestamp_source_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TranscriptSegmentMapper)->map([['start'=>0,'end'=>8,'text'=>'speech']], 'unknown');
    }

    public function test_database_search_paginates_before_loading_and_excludes_transcripts(): void
    {
        config(['scout.driver'=>'null']);
        $user = User::factory()->create();
        Video::withoutEvents(function () use ($user) {
            for ($i=0; $i<5; $i++) Video::create([
                'user_id'=>$user->id, 'filename'=>"test{$i}.mp4", 'dropbox_path'=>"/test{$i}.mp4",
                'processing_status'=>'completed', 'approval_status'=>'approved', 'is_archived'=>false,
                'language_detected'=>'ur', 'speakers_data'=>[['text'=>str_repeat('large transcript ',10000)]],
            ]);
        });
        $queries=[];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) { $queries[]=$query->sql; });
        $response=$this->postJson(route('videos.embeddings.search'),[
            'search_type'=>'language', 'language'=>'ur', 'page'=>2, 'per_page'=>2,
        ])->assertOk()->assertJsonPath('total_videos',5)->assertJsonCount(2,'grouped_by_video');
        $this->assertStringNotContainsString('large transcript',$response->getContent());
        $selects=array_filter($queries, fn($sql)=>str_contains($sql,'from "videos"') && !str_contains($sql,'count('));
        $this->assertNotEmpty($selects);
        foreach($selects as $sql) {
            $this->assertStringNotContainsString('select *',$sql);
            $this->assertStringNotContainsString('speakers_data',$sql);
            $this->assertStringContainsString('limit 2 offset 2',$sql);
        }
    }

    public function test_callbacks_are_idempotent_order_independent_and_generation_scoped(): void
    {
        config(['scout.driver'=>'null']);
        $video = Video::create(['user_id'=>User::factory()->create()->id, 'filename'=>'test.mp4', 'dropbox_path'=>'/test.mp4']);
        $run = (string) Str::uuid();
        $embedding = VideoEmbedding::create(['video_id'=>$video->id, 'status'=>'processing', 'active_run_id'=>$run, 'expected_batches'=>2]);
        $url = route('api.webhooks.qdrant', ['video_id'=>$video->id,'run_id'=>$run,'batch'=>2,'total'=>2]);
        $this->postJson($url, ['status'=>'completed','segments_embedded'=>7])->assertOk();
        $this->assertSame('processing', $embedding->fresh()->status);
        $this->postJson($url, ['status'=>'completed','segments_embedded'=>7])->assertOk();
        $this->assertEquals(7, $embedding->fresh()->segments_count);
        $this->postJson(route('api.webhooks.qdrant',['video_id'=>$video->id,'run_id'=>(string)Str::uuid(),'batch'=>1,'total'=>2]), ['status'=>'completed','segments_embedded'=>9])->assertOk();
        $this->postJson(route('api.webhooks.qdrant',['video_id'=>$video->id,'batch'=>1,'total'=>2]), ['status'=>'completed','segments_embedded'=>9])->assertOk();
        $this->assertEquals(7, $embedding->fresh()->segments_count);
        $this->postJson(route('api.webhooks.qdrant',['video_id'=>$video->id,'run_id'=>$run,'batch'=>1,'total'=>2]), ['status'=>'completed','segments_embedded'=>3])->assertOk();
        $this->assertSame('completed', $embedding->fresh()->status);
        $this->assertEquals(10, $embedding->fresh()->segments_count);
    }
}
