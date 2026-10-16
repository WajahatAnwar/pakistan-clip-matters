<?php
namespace App\Services;
use Illuminate\Support\Facades\Cache;
final class OperationalMetrics {
 public static function storage(float $legacyMiB, ?array $audit):array {
  return ['registered_storage_estimated_bytes'=>(int)round($legacyMiB*1048576),
   'registered_storage_unit'=>'GiB','registered_storage_provenance'=>'Sum of rounded legacy size_mb values, recorded in MiB; estimate only.',
   'source_storage_bytes'=>$audit['source']['supported_video_source_bytes']??null,
   'source_storage_scope'=>$audit['source']['scope']??null,
   'generated_storage_bytes'=>$audit['generated']['bytes']??null,'generated_storage_status'=>$audit['generated']['status']??'not_measured',
   'generated_storage_observation'=>$audit['generated']??null,
   'index_observation'=>$audit['index']??null,
   'definitions'=>['uploads'=>'Registered database videos, not the number of files in Dropbox.',
    'transcripts'=>'Records with transcript ID and non-null stored transcript; accuracy checked separately.',
    'embedding_records'=>'Completed embedding database records; not proof of current vectors.',
    'source_storage'=>'Bytes of current video files observed in the fixed Dropbox scope, including unregistered copies.','categories_overlap'=>true]];
 }
 public static function current(float $legacyMiB):array {
  $audit=Cache::get('operations:reconciliation:v1');
  if(!is_array($audit)){
   $path=storage_path('app/private/operations/reconciliation-v1.json');
   $audit=is_readable($path)?json_decode(file_get_contents($path),true):null;
  }
  $generatedPath=storage_path('app/private/operations/generated-media-v1.json');
  $generated=is_readable($generatedPath)?json_decode(file_get_contents($generatedPath),true):null;
  if(is_array($generated)){$audit=is_array($audit)?$audit:[];$audit['generated']=$generated;}
  return self::storage($legacyMiB,is_array($audit)?$audit:null);
 }
 public static function publish(array $audit):void {
  $path=storage_path('app/private/operations/reconciliation-v1.json');
  if(!is_dir(dirname($path))&&!mkdir(dirname($path),0750,true)&&!is_dir(dirname($path)))throw new \RuntimeException('Cannot create audit directory');
  $temporary=tempnam(dirname($path),'reconciliation-');
  if($temporary===false)throw new \RuntimeException('Cannot stage audit snapshot');
  try {
   if(file_put_contents($temporary,json_encode($audit,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new \RuntimeException('Cannot write audit snapshot');
   chmod($temporary,0640);
   if(!rename($temporary,$path))throw new \RuntimeException('Cannot publish audit snapshot');
  } finally {if(is_file($temporary))unlink($temporary);}
  Cache::forever('operations:reconciliation:v1',$audit);
 }
}
