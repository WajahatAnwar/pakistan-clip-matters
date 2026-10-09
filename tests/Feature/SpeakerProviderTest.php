<?php
namespace Tests\Feature;
use App\Services\AssemblyAiService;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class SpeakerProviderTest extends TestCase {
 use RefreshDatabase;
 public function test_missing_enrollment_never_calls_provider():void {
  Http::fake();$result=(new AssemblyAiService(new User))->identifySpeakers('https://example.test/audio.wav');
  $this->assertArrayHasKey('error',$result);Http::assertNothingSent();
 }
 public function test_provider_read_failure_is_not_successful_unknown():void {
  Http::fake(['api.pyannote.ai/*'=>Http::response([],503)]);
  $this->expectException(\RuntimeException::class);(new AssemblyAiService(new User))->getJobData('test-job');
 }
 public function test_provider_connection_failure_is_not_successful_unknown():void {
  Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('fixture timeout'));
  $this->expectException(\Illuminate\Http\Client\ConnectionException::class);(new AssemblyAiService(new User))->getJobData('test-job');
 }
 public function test_unregistered_completed_job_is_rejected():void {
  Http::fake(['api.pyannote.ai/*'=>Http::response(['status'=>'succeeded','output'=>['diarization'=>[],'voiceprints'=>[]]])]);
  $this->expectException(\RuntimeException::class);(new AssemblyAiService(new User))->getJobData('unregistered');
 }
 public function test_stale_webhook_does_not_fetch_or_update():void {
  Http::fake();$result=AssemblyAiService::processIdentifyWebhook(['jobId'=>'old','status'=>'succeeded','output'=>['forged'=>true]]);
  $this->assertFalse($result['success']);Http::assertNothingSent();
 }
}
