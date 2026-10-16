<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Services\DropboxService;
class ImportReconciledSources extends Command {
 protected $signature='operations:import-sources {--manifest=} {--source-id=*} {--ledger=} {--apply} {--dry-run}';
 protected $description='Import only selected, reverified missing source identities; defaults to dry run; never transcribes or deletes';
 public function handle():int {
  umask(0077);
  $manifestPath=$this->option('manifest');
  if(!$manifestPath||!is_file($manifestPath)||!is_readable($manifestPath)){$this->error('A readable inventory manifest is required.');return 1;}
  $ids=$this->option('source-id');if(!$ids||count($ids)>10||($this->option('apply')&&$this->option('dry-run'))){$this->error('Select 1–10 explicit source IDs; apply and dry-run are exclusive.');return 1;}
  $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
  if(empty($manifest['summary']['scope']['exhaustive_listing']))throw new \RuntimeException('Incomplete source inventory');
  $byId=array_column($manifest['files'],null,'source_id');$run=(string)\Illuminate\Support\Str::uuid();$owner=User::getSuperAdmin();
  $service=new DropboxService($owner);$failed=false;
  foreach($ids as$id){$entry=$byId[$id]??null;if(!$entry||$entry['state']!=='missing_import'){$this->warn('Not a verified missing-import candidate');$failed=true;continue;}
   $record=['run_id'=>$run,'source_id'=>$id,'reason'=>'missing_import_in_fixed_scope','previous_state'=>null,'action'=>'register_pending_only','dry_run'=>!$this->option('apply')];
   try {if($this->option('apply')){
    if(!$this->option('ledger'))throw new \RuntimeException('Apply requires a ledger path');
    $record=Cache::lock('operations:source:'.hash('sha256',$id),60)->block(3,function()use($id,$entry,$owner,$service,$record){
     $existing=DB::table('videos')->where('dropbox_file_id',$id)->orWhereRaw('LOWER(dropbox_path) = ?',[$entry['path']])->first(['id']);
     if($existing)return $record+['video_id'=>$existing->id,'outcome'=>'skipped_existing'];
     $r=Http::withToken($service->getAccessToken())->withHeaders($service->getApiHeaders())->connectTimeout(5)->timeout(20)
      ->post('https://api.dropboxapi.com/2/files/get_metadata',['path'=>$id]);
     if(!$r->successful()||$r->json('id')!==$id||$r->json('rev')!==$entry['revision']||(int)$r->json('size')!==$entry['bytes'])throw new \RuntimeException('Source changed or unavailable; no import');
     $path=$r->json('path_display');if(mb_strtolower($path)!==$entry['path'])throw new \RuntimeException('Source path changed; rerun inventory');
     $new=DB::table('videos')->insertGetId(['user_id'=>$owner->id,'filename'=>basename($path),'title'=>pathinfo($path,PATHINFO_FILENAME),'dropbox_path'=>$path,
      'dropbox_file_id'=>$id,'dropbox_observed_revision'=>$entry['revision'],'source_size_bytes'=>$entry['bytes'],'source_observed_at'=>now(),
      'size_mb'=>round($entry['bytes']/1048576,2),'processing_status'=>'pending','approval_status'=>'pending','is_archived'=>false,'created_at'=>now(),'updated_at'=>now()]);
     return $record+['video_id'=>$new,'outcome'=>'succeeded','verification'=>['source_revision'=>$entry['revision'],'bytes'=>$entry['bytes'],'pending_approval'=>true]];
    });
   }else{$record['outcome']='dry_run_ready';}
   }catch(\Throwable$e){$record['outcome']='failed';$record['error_type']=get_class($e);$failed=true;}
   if($this->option('ledger'))file_put_contents($this->option('ledger'),json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
   $this->line($id.': '.$record['outcome']);
  }
  return $failed?1:0;
 }
}
