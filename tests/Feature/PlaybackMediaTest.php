<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
class PlaybackMediaTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();config(['scout.driver'=>'null']);\Spatie\Permission\Models\Role::findOrCreate('superAdmin','web');\Spatie\Permission\Models\Role::findOrCreate('admin','web');}
 private function fixture(): array {
  $user=User::factory()->create(['status'=>'active','dropbox_access_token'=>'fixture','dropbox_token_expires_at'=>now()->addDay(),
   'dropbox_team_member_id'=>'fixture','dropbox_root_namespace_id'=>'fixture']);
  $user->assignRole('superAdmin');
  $video=Video::withoutEvents(fn()=>Video::create(['user_id'=>$user->id,'dropbox_path'=>'/isolated-fixture.mp4','filename'=>'fixture.mp4','processing_status'=>'completed','approval_status'=>'approved','is_archived'=>false]));
  return [$user,$video];
 }
 public function test_guest_cannot_get_private_media_link():void {Http::preventStrayRequests();$this->postJson(route('video.link'),['video_id'=>999])->assertUnauthorized();Http::assertNothingSent();}
 public function test_unregistered_path_is_not_resolved_with_admin_credentials():void {[$u,$v]=$this->fixture();Http::preventStrayRequests();$this->actingAs($u)->postJson(route('video.link'),['dropbox_path'=>'/arbitrary-private.mp4'])->assertNotFound();Http::assertNothingSent();}
 public function test_cached_links_are_scoped_and_renewal_is_coalesced():void {
  [$u,$v]=$this->fixture();Http::fake(['api.dropboxapi.com/*'=>Http::sequence()->push(['link'=>'https://media.example/one'])->push(['link'=>'https://media.example/two'])]);
  $first=$this->actingAs($u)->postJson(route('video.link'),['video_id'=>$v->id])->assertOk()->assertJsonPath('video_id',$v->id)->json();
  $this->postJson(route('video.link'),['video_id'=>$v->id])->assertOk()->assertJsonPath('link_id',$first['link_id']);
  $input=['video_id'=>$v->id,'refresh'=>true,'link_id'=>$first['link_id']];
  $second=$this->postJson(route('video.link'),$input)->assertOk()->json();
  $this->assertNotSame($first['link_id'],$second['link_id']);
  $this->postJson(route('video.link'),$input)->assertOk()->assertJsonPath('link_id',$second['link_id']);Http::assertSentCount(2);
 }
 public function test_provider_failure_is_bounded_and_does_not_expose_provider_body():void {
  [$u,$v]=$this->fixture();Http::fake(['api.dropboxapi.com/*'=>Http::response(['private'=>'do not expose'],503)]);
  $r=$this->actingAs($u)->postJson(route('video.link'),['video_id'=>$v->id])->assertStatus(503)->assertJsonPath('code','media_unavailable');
  $this->assertStringNotContainsString('do not expose',$r->getContent());Http::assertSentCount(1);
 }
 public function test_other_viewer_cannot_get_unapproved_media():void {[$u,$v]=$this->fixture();Video::withoutEvents(fn()=>$v->update(['approval_status'=>'pending']));$other=User::factory()->create(['status'=>'active']);Http::preventStrayRequests();$this->actingAs($other)->postJson(route('video.link'),['video_id'=>$v->id])->assertForbidden();Http::assertNothingSent();}
 public function test_wrong_video_never_reuses_cached_link():void {
  [$u,$v]=$this->fixture();$w=Video::withoutEvents(fn()=>Video::create(['user_id'=>$u->id,'dropbox_path'=>'/other-fixture.mp4','filename'=>'other.mp4']));
  Http::fake(['api.dropboxapi.com/*'=>Http::sequence()->push(['link'=>'https://media.example/one'])->push(['link'=>'https://media.example/two'])]);
  $a=$this->actingAs($u)->postJson(route('video.link'),['video_id'=>$v->id])->assertOk()->json();
  $b=$this->postJson(route('video.link'),['video_id'=>$w->id])->assertOk()->json();$this->assertNotSame($a['temporary_link'],$b['temporary_link']);Http::assertSentCount(2);
 }
 public function test_missing_provider_file_has_specific_error():void {
  [$u,$v]=$this->fixture();Http::fake(['api.dropboxapi.com/*'=>Http::response(['error_summary'=>'path/not_found/fixture'],409)]);
  $this->actingAs($u)->postJson(route('video.link'),['video_id'=>$v->id])->assertNotFound()->assertJsonPath('code','media_missing');Http::assertSentCount(1);
 }
 public function test_provider_access_failure_is_distinct_from_viewer_denial():void {
  [$u,$v]=$this->fixture();Http::fake(['api.dropboxapi.com/*'=>Http::response([],401)]);
  $this->actingAs($u)->postJson(route('video.link'),['video_id'=>$v->id])->assertStatus(503)->assertJsonPath('code','provider_access');Http::assertSentCount(1);
 }

 public function test_expired_cached_link_is_resolved_again():void {
  [$u,$v]=$this->fixture();Http::fake(['api.dropboxapi.com/*'=>Http::sequence()->push(['link'=>'https://media.example/one'])->push(['link'=>'https://media.example/two'])]);
  $first=$this->actingAs($u)->postJson(route('video.link'),['video_id'=>$v->id])->assertOk()->json();
  $this->travel(181)->minutes();
  $next=$this->postJson(route('video.link'),['video_id'=>$v->id])->assertOk()->json();
  $this->assertNotSame($first['link_id'],$next['link_id']);Http::assertSentCount(2);
 }

}
