<?php
namespace App\Services;
use Illuminate\Support\Facades\Log;
class SpeakerTaggingService
{
 public function tagSpeakers(array $transcriptData,array $diarizationData): array {
  $mapping=[];$tagged=0;
  foreach($transcriptData as&$s){
   if(($s['identity_source']??null)==='manual'&&!empty($s['actualSpeaker'])){$tagged++;continue;}
   if(!empty($s['actualSpeaker'])&&($s['identity_version']??null)!==SpeakerIdentityPolicy::VERSION)$s['previous_identity_for_review']=$s['actualSpeaker'];
   unset($s['actualSpeaker'],$s['diarizationSpeaker'],$s['recognized_identity']);
   $s['identity_status']='unknown';$s['identity_source']='audio';$s['identity_version']=SpeakerIdentityPolicy::VERSION;
   $s['identity_reason']='no_supported_overlap';
   $match=$this->match($s,$diarizationData);
   if($match){$s['diarizationSpeaker']=$match['source_speaker'];$s['identity_reason']=$match['reason'];$s['identity_status']=$match['status'];
    if($match['status']==='accepted'){$s['actualSpeaker']=$match['recognized_identity'];$s['recognized_identity']=$match['recognized_identity'];$tagged++;}
    $s['identification_score']=$match['best_score']??null;$s['identification_score_kind']='provider_confidence_0_100';
   }
   if(isset($s['words'])&&is_array($s['words']))foreach($s['words']as&$word){if(($word['identity_source']??null)==='manual')continue;unset($word['actualSpeaker'],$word['diarizationSpeaker']);$m=$this->match($word,$diarizationData);if($m&&$m['status']==='accepted'){$word['actualSpeaker']=$m['recognized_identity'];$word['diarizationSpeaker']=$m['source_speaker'];}$word['identity_version']=SpeakerIdentityPolicy::VERSION;}unset($word);
  }unset($s);
  // No global voting and no propagation into an unsupported turn. The summary
  // mapping exists only for consistently recognized, fully supported source labels.
  $labels=[];foreach($transcriptData as$s){$label=$s['speaker']??null;if($label!==null)$labels[$label][]=($s['identity_source']??'')==='manual'?($s['actualSpeaker']??null):(($s['identity_status']??'')==='accepted'?($s['actualSpeaker']??null):null);}
  foreach($labels as$label=>$names)if(!in_array(null,$names,true)&&count(array_unique($names))===1)$mapping[$label]=$names[0];
  Log::info('Speaker identity tagging',['version'=>SpeakerIdentityPolicy::VERSION,'segments'=>count($transcriptData),'accepted'=>$tagged,'unknown'=>count($transcriptData)-$tagged]);
  return ['taggedTranscript'=>$transcriptData,'speakerMapping'=>$mapping,'statistics'=>['totalSegments'=>count($transcriptData),'taggedSegments'=>$tagged,'untaggedSegments'=>count($transcriptData)-$tagged,'speakerCounts'=>array_count_values(array_filter(array_column($transcriptData,'actualSpeaker')))]];
 }
 public function generateFormattedTranscript(array $segments): string {
  $lines=[];
  foreach($segments as $s){
   $trusted=($s['identity_source']??null)==='manual'||(($s['identity_status']??null)==='accepted'&&($s['identity_version']??null)===SpeakerIdentityPolicy::VERSION);
   $name=$trusted?($s['actualSpeaker']??'Unknown Speaker'):'Unknown Speaker';
   $label=$s['speaker']??'Unlabelled';
   $lines[]=$name.' ['.$label.']: '.($s['text']??'');
  }
  return implode("\n\n",$lines);
 }
 private function match(array $s,array $turns): ?array {
  if(!isset($s['start'],$s['end'])||!is_numeric($s['start'])||!is_numeric($s['end'])||$s['start']<0||$s['end']<=$s['start'])return null;
  // AssemblyAI source contract is milliseconds; no value-based inference.
  $start=$s['start']/1000;$end=$s['end']/1000;$sources=[];$matches=[];
  foreach($turns as$t){if(($t['version']??null)!==SpeakerIdentityPolicy::VERSION||($t['timestamp_unit']??null)!=='seconds'||!isset($t['start'],$t['end'],$t['source_speaker']))continue;
   $overlap=min($end,$t['end'])-max($start,$t['start']);if($overlap<=0)continue;$sources[$t['source_speaker']]=true;
   if($overlap/($end-$start)>=config('speaker-identification.mapping_coverage',.8))$matches[]=$t;
  }
  if(count($sources)!==1||count($matches)!==1)return null;
  $m=$matches[0];return in_array($m['status']??null,['unknown','accepted'],true)?$m:null;
 }
}
