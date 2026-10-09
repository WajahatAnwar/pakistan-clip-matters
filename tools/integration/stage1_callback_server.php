<?php
// Local HTTP integration harness. All SQL goes to the explicitly isolated SQLite file.
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$state=getenv('CLIPS_TEST_STATE');
if (!$state || !str_starts_with($state,'/tmp/')) throw new RuntimeException('Isolated /tmp state required');
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$state.'/callbacks.sqlite','scout.driver'=>'null','queue.default'=>'sync','cache.default'=>'array']);
Illuminate\Support\Facades\DB::purge();
if (PHP_SAPI==='cli') {
 touch($state.'/callbacks.sqlite');
 Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
 $user=App\Models\User::factory()->create();
 App\Models\Video::withoutEvents(fn()=>App\Models\Video::create(['id'=>1,'user_id'=>$user->id,'filename'=>'isolated.mp4','dropbox_path'=>'/isolated.mp4']));
 App\Models\VideoEmbedding::create(['video_id'=>1,'status'=>'processing','active_run_id'=>'11111111-1111-4111-8111-111111111111','expected_batches'=>2]);
 echo 'Isolated migrations applied'.PHP_EOL; exit;
}
try {
 if ($_SERVER['REQUEST_METHOD']==='GET') {
  header('Content-Type: application/json'); echo App\Models\VideoEmbedding::where('video_id',1)->first()->toJson(); exit;
 }
 $request=Illuminate\Http\Request::createFromGlobals();
 $response=app(App\Http\Controllers\Api\QdrantWebhookController::class)->handleWebhook($request);
 $response->send();
} catch(Throwable $e) {http_response_code(500); echo json_encode(['error'=>get_class($e),'message'=>$e->getMessage()]);}
