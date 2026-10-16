<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Services\OperationalMetrics;
class OperationalMetricsTest extends TestCase {
 public function test_legacy_mebibytes_are_not_labelled_decimal_gigabytes():void {$r=OperationalMetrics::storage(1024,null);$this->assertSame(1073741824,$r['registered_storage_estimated_bytes']);$this->assertSame('GiB',$r['registered_storage_unit']);$this->assertNull($r['source_storage_bytes']);$this->assertNull($r['generated_storage_bytes']);}
 public function test_source_snapshot_is_explicitly_scoped_and_index_observation_separate():void {$r=OperationalMetrics::storage(0,['source'=>['supported_video_source_bytes'=>4000000000,'scope'=>['folder'=>'/scope','cutoff_utc'=>'2026-10-16T00:00:00Z']],'index'=>['videos_with_points'=>3]]);$this->assertSame(4000000000,$r['source_storage_bytes']);$this->assertSame('/scope',$r['source_storage_scope']['folder']);$this->assertSame(3,$r['index_observation']['videos_with_points']);$this->assertTrue($r['definitions']['categories_overlap']);}
 public function test_durable_snapshot_survives_missing_cache():void {
  $original=$this->app->storagePath();$temporary=sys_get_temp_dir().'/metrics-'.bin2hex(random_bytes(8));
  $this->app->useStoragePath($temporary);
  \Illuminate\Support\Facades\Cache::shouldReceive('forever')->once();
  \Illuminate\Support\Facades\Cache::shouldReceive('get')->once()->andReturnNull();
  try {
   OperationalMetrics::publish(['source'=>['supported_video_source_bytes'=>649115257963,'scope'=>['folder'=>'/','finished_at_utc'=>'2026-10-16T11:01:03Z']],'index'=>['videos_with_points'=>340]]);
   $result=OperationalMetrics::current(0);
   $this->assertSame(649115257963,$result['source_storage_bytes']);
   $this->assertSame(340,$result['index_observation']['videos_with_points']);
   $this->assertNull($result['generated_storage_bytes']);
  } finally {
   $this->app->useStoragePath($original);
   @unlink($temporary.'/app/private/operations/reconciliation-v1.json');
   foreach(['/app/private/operations','/app/private','/app',''] as $directory)@rmdir($temporary.$directory);
  }
 }
}
