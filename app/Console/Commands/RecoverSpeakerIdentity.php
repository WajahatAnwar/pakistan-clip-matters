<?php
namespace App\Console\Commands;
use App\Models\Video;
use App\Models\User;
use App\Services\AssemblyAiService;
use App\Services\SpeakerTaggingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
class RecoverSpeakerIdentity extends Command {
 protected $signature='speakers:recover {--id=} {--audio-url=} {--apply} {--dry-run} {--refresh-index}';
 protected $description='Targeted identity recovery from existing audio only; default dry run, never retranscribes';
 public function handle(): int {
  $id=$this->option('id');if(!ctype_digit((string)$id)||(int)$id<1)return 1;
  if($this->option('apply')&&$this->option('dry-run'))return 1;
  $v=Video::findOrFail($id);$source=$v->speakers_data;for($i=0;$i<3&&is_string($source);$i++)$source=json_decode($source,true);
  if(!is_array($source))return 1;$this->info("{$id}: ".count($source).' source segments; '.($this->option('apply')?'apply':'dry run'));
  if(!$this->option('apply'))return 0;
  $url=$this->option('audio-url');if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https')return 1;
  $snapshot=$v->getRawOriginal('speakers_data');$result=(new AssemblyAiService(User::findOrFail($v->user_id)))->identifySpeakers($url,false,$v->id);
  if(isset($result['error'])||($result['status']??null)!=='succeeded'){$this->error('Identification unavailable; no changes applied');return 1;}
  $tagged=app(SpeakerTaggingService::class)->tagSpeakers($source,$result['output']['identification']);
  DB::transaction(function()use($v,$snapshot,$tagged,$result){$current=Video::whereKey($v->id)->lockForUpdate()->firstOrFail();if($current->getRawOriginal('speakers_data')!==$snapshot)throw new \RuntimeException('Concurrent transcript edit');foreach(['transcript_english','transcript_urdu'] as$field){$translated=$current->$field;for($i=0;$i<3&&is_string($translated);$i++)$translated=json_decode($translated,true);if(is_array($translated))$current->$field=app(SpeakerTaggingService::class)->tagSpeakers($translated,$result['output']['identification'])['taggedTranscript'];}
$current->update(['speakers_data'=>$tagged['taggedTranscript'],'speaker_mapping'=>$tagged['speakerMapping'],'identification_data'=>$result['output']['identification'],'diarization_data'=>$result['output']['diarization']]);});
  if($this->option('refresh-index'))\App\Jobs\GenerateVideoEmbedding::dispatch($v);
  $this->info('Identity updated; '.($this->option('refresh-index')?'derived index refresh queued':'derived index refresh still required'));
  return 0;
 }
}
