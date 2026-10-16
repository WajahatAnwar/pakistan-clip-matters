<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Video;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class SourceReconciliationTest extends TestCase {
 use RefreshDatabase;
 public function test_empty_source_listing_preserves_database_video():void {
  $user=User::factory()->create(['status'=>'active']);
  $video=Video::withoutEvents(fn()=>Video::create(['user_id'=>$user->id,'filename'=>'fixture.mp4','title'=>'Fixture','dropbox_path'=>'/fixture.mp4','processing_status'=>'completed','approval_status'=>'approved']));
  $mock=\Mockery::mock(VideoProcessingService::class);$mock->shouldReceive('getAvailableVideos')->once()->andReturn([]);$mock->shouldReceive('getUserVideos')->once()->andReturn(collect([$video]));$this->app->bind(VideoProcessingService::class,fn()=>$mock);
  Http::preventStrayRequests();
  $this->actingAs($user)->getJson('/list-videos?auto_process=false')->assertOk()->assertJsonPath('source_unavailable.0.id',$video->id);
  $this->assertDatabaseHas('videos',['id'=>$video->id,'dropbox_path'=>'/fixture.mp4']);Http::assertNothingSent();
 }
 public function test_source_import_requires_explicit_readable_manifest():void {
  $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \App\Console\Commands\ImportReconciledSources);
  $this->artisan('operations:import-sources',['--source-id'=>['fixture']])->assertExitCode(1);
 }
}
