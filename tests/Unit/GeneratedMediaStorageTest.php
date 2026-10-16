<?php
namespace Tests\Unit;
use App\Services\GeneratedMediaStorage;
use PHPUnit\Framework\TestCase;
class GeneratedMediaStorageTest extends TestCase
{
 public function test_counts_producer_outputs_and_excludes_source_copies_backups_and_links():void {
  $root=sys_get_temp_dir().'/generated-'.bin2hex(random_bytes(8));
  mkdir($root.'/app/temp',0700,true);mkdir($root.'/app/tmp');mkdir($root.'/app/private');
  $files=['temp/audio_test.mp3'=>'abc','temp/audio_test.webm'=>'abcd','temp/video_test.mp4'=>'12345','tmp/converted.mp4'=>'ab','tmp/original.MTS'=>'123456','temp/unknown.bin'=>'x','private/backup.json'=>'large backup'];
  try {
   foreach($files as$p=>$data)file_put_contents($root.'/app/'.$p,$data);
   symlink($root.'/app/temp/audio_test.mp3',$root.'/app/temp/audio_link.mp3');
   $result=GeneratedMediaStorage::measure($root);
   $this->assertSame(9,$result['bytes']);$this->assertSame(3,$result['files']);
   $this->assertSame(11,$result['downloaded_source_copy_bytes']);$this->assertSame(1,$result['unclassified_bytes']);
   $this->assertSame('measured_local',$result['status']);
  }finally{
   unlink($root.'/app/temp/audio_link.mp3');foreach($files as$p=>$data)unlink($root.'/app/'.$p);
   foreach(['/app/temp','/app/tmp','/app/private','/app','']as$p)rmdir($root.$p);
  }
 }
}
