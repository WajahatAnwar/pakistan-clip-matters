<?php
namespace App\Services;
final class SpeakerIdentityPolicy
{
 public const VERSION='speaker_identity_v1';
 public static function fingerprint($value): ?string {
  if(!is_string($value)||trim($value)===''||strlen($value)>100000)return null;
  $decoded=base64_decode($value,true);
  return $decoded===false||$decoded===''?null:hash('sha256',$decoded);
 }
 public function enroll(iterable $samples): array {
  $eligible=[];$issues=[];$hashes=[];
  foreach($samples as $s){$a=is_array($s)?$s:array_merge($s->toArray(),['voiceprint'=>$s->voiceprint]);$id=(string)($a['id']??'');$hash=self::fingerprint($a['voiceprint']??null);$identity=trim($a['identity_key']??'');
   $reason=null;$q=$a['quality_review']??[];if(is_string($q))$q=json_decode($q,true)??[];
   if(!$hash)$reason='missing_or_malformed_voiceprint';
   elseif(!$identity||empty($a['name']))$reason='missing_identity';
   elseif(($a['validation_state']??'')!=='approved')$reason='unreviewed';
   elseif(($a['voiceprint_model']??'')!==config('speaker-identification.model')||!config('speaker-identification.revision')||($a['voiceprint_revision']??'')!==config('speaker-identification.revision'))$reason='incompatible_version';
   elseif(($a['duration']??0)<15||($a['duration']??0)>30)$reason='unsupported_duration';
   elseif(($q['single_speaker']??false)!==true||($q['usable_speech']??false)!==true||($q['silence']??true)||($q['noise']??true)||($q['music']??true)||($q['overlap']??true)||($q['clipping']??true)||empty($q['sample_rate_hz']))$reason='quality_not_verified';
   if($hash)$hashes[$hash][$identity?:('unreviewed:'.($a['name']??$id))]=true;
   if($reason){$issues[$id]=$reason;continue;}
   $eligible[$id]=['label'=>'sample:'.$id,'voiceprint'=>$a['voiceprint'],'identity_key'=>$identity,'name'=>$a['name'],'fingerprint'=>$hash,'revision'=>config('speaker-identification.revision')];
  }
  foreach($eligible as$id=>$e)if(count($hashes[$e['fingerprint']]??[])>1){unset($eligible[$id]);$issues[$id]='cross_identity_duplicate';}
  // Provider limit is 50; fail closed rather than choose an arbitrary subset.
  if(count($eligible)>50){foreach($eligible as$id=>$e)$issues[$id]='enrollment_limit';$eligible=[];}
  return ['samples'=>array_values($eligible),'issues'=>$issues];
 }
 public function normalize(array $output,array $enrollment): array {
  if(!isset($output['diarization'],$output['voiceprints'])||!is_array($output['diarization'])||!is_array($output['voiceprints']))throw new \RuntimeException('Malformed identification response');
  $byLabel=[];foreach($enrollment as$e)$byLabel[$e['label']]=$e;
  $scores=[];foreach($output['voiceprints']as$v){$key=$v['speaker']??null;if(!is_string($key)||isset($scores[$key]))throw new \RuntimeException('Malformed speaker scores');$scores[$key]=$v;}
  $decisions=[];$turns=$output['diarization'];$durations=[];
  foreach($turns as$t){if(!isset($t['speaker'],$t['start'],$t['end'])||!is_numeric($t['start'])||!is_numeric($t['end'])||!is_finite((float)$t['end'])||!is_finite((float)$t['start'])||$t['start']<0||$t['end']<=$t['start'])throw new \RuntimeException('Malformed diarization interval');$intervals[$t['speaker']][]=[$t['start'],$t['end']];}
  foreach($intervals??[] as$source=>$ranges){usort($ranges,fn($a,$b)=>$a[0]<=>$b[0]);$until=-1;$durations[$source]=0;foreach($ranges as[$a,$b]){$durations[$source]+=max(0,$b-max($a,$until));$until=max($until,$b);}}
  foreach($durations as$source=>$duration){$v=$scores[$source]??[];$confidence=$v['confidence']??[];$best=null;$runner=null;$winner=null;$reason='insufficient_scores';$groups=[];
   if(is_array($confidence))foreach($confidence as$label=>$score){if(!isset($byLabel[$label]))continue;if(!is_numeric($score)||!is_finite((float)$score)||$score<0||$score>100)throw new \RuntimeException('Invalid identification score');$identity=$byLabel[$label]['identity_key'];if(!isset($groups[$identity])||$score>$groups[$identity]['score'])$groups[$identity]=['score'=>(float)$score,'sample'=>$byLabel[$label],'label'=>$label];}
   uasort($groups,fn($a,$b)=>$b['score']<=>$a['score']);$rank=array_values($groups);$best=$rank[0]['score']??null;$runner=$rank[1]['score']??null;$winner=$rank[0]['sample']??null;
   $threshold=config('speaker-identification.threshold');$margin=config('speaker-identification.margin');$minimum=config('speaker-identification.min_speech_seconds');
   if(!is_numeric($threshold)||!is_numeric($margin)||!is_numeric($minimum)||$threshold<0||$threshold>100||$margin<=0||$margin>100||$minimum<=0)$reason='acceptance_not_configured';
   elseif($duration<$minimum)$reason='short_speech';
   elseif($best===null||$runner===null)$reason='insufficient_competitors';
   elseif($best<$threshold)$reason='below_threshold';
   elseif($best-$runner<$margin)$reason='ambiguous';
   elseif(($v['match']??null)!==($rank[0]['label']??null))$reason='provider_disagrees';
   else $reason='accepted';
   $decisions[$source]=['status'=>$reason==='accepted'?'accepted':'unknown','reason'=>$reason,'recognized_identity'=>$reason==='accepted'?$winner['name']:null,'identity_key'=>$reason==='accepted'?$winner['identity_key']:null,'source_speaker'=>$source,'usable_speech_seconds'=>$duration,'duration_basis'=>'union_of_provider_diarized_intervals','best_score'=>$best,'runner_up_score'=>$runner,'score_kind'=>'provider_confidence_0_100','version'=>self::VERSION,'model'=>config('speaker-identification.model'),'revision'=>config('speaker-identification.revision'),'timestamp_unit'=>'seconds'];
  }
  $identified=[];foreach($turns as$t){$d=$decisions[$t['speaker']];foreach(['silence','noise','music','clipping'] as$flag)if(($t[$flag]??false)===true){$d['status']='unknown';$d['reason']='unusable_audio';$d['recognized_identity']=null;$d['identity_key']=null;}foreach($turns as$other)if($other['speaker']!==$t['speaker']&&min($other['end'],$t['end'])>max($other['start'],$t['start'])){$d['status']='unknown';$d['reason']='overlapping_speech';$d['recognized_identity']=null;$d['identity_key']=null;break;}$identified[]=$t+$d+['diarizationSpeaker'=>$t['speaker'],'match'=>$d['recognized_identity']];}
  return ['diarization'=>$turns,'identification'=>$identified,'identity_version'=>self::VERSION];
 }
}
