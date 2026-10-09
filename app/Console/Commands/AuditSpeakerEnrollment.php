<?php
namespace App\Console\Commands;
use App\Models\VoiceSample;
use App\Services\SpeakerIdentityPolicy;
use Illuminate\Console\Command;
class AuditSpeakerEnrollment extends Command {
 protected $signature='speakers:enrollment {--id=*} {--review-file=} {--apply} {--dry-run}';
 protected $description='Audit enrollment, or apply explicit human-reviewed quality metadata; defaults to dry run';
 public function handle(): int {
  $ids=$this->option('id');if(!$ids||array_filter($ids,fn($x)=>!ctype_digit((string)$x)||(int)$x<1)){$this->error('Explicit positive --id required');return 1;}
  if($this->option('apply')&&$this->option('dry-run'))return 1;
  if($this->option('review-file')){
   $reviews=json_decode(file_get_contents($this->option('review-file')),true,512,JSON_THROW_ON_ERROR);
   foreach($ids as$id){$s=VoiceSample::findOrFail($id);$r=$reviews[$id]??null;if(!$r||empty($r['identity_key'])||empty($r['quality_review']))return 1;
    $candidate=$s->toArray();$candidate['voiceprint']=$s->voiceprint;$candidate=array_merge($candidate,$r,['validation_state'=>'approved']);
    $result=app(SpeakerIdentityPolicy::class)->enroll([$candidate]);if(!$result['samples']){$this->error("$id: review does not satisfy contract");return 1;}
    if($this->option('apply'))$s->update(array_intersect_key($candidate,array_flip(['identity_key','voiceprint_model','voiceprint_revision','validation_state','quality_review'])));
   }
  }
  // Include all samples when checking duplicate fingerprints across identities.
  $samples=VoiceSample::all()->map(fn($s)=>array_merge($s->toArray(),['voiceprint'=>$s->voiceprint]))->all();
  $result=app(SpeakerIdentityPolicy::class)->enroll($samples);foreach($ids as$id)$this->line($id.': '.($result['issues'][$id]??'eligible'));
  return 0;
 }
}
