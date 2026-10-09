"""Run only against fresh /tmp Redis, SQLite callback server and local Qdrant."""
import json
import os
import subprocess
import uuid
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import requests
from redis import Redis
from rq import Queue, Retry
from qdrant_client import QdrantClient, models

STATE=Path(os.environ['CLIPS_TEST_STATE'])
assert str(STATE).startswith('/tmp/')
ROOT=Path(__file__).resolve().parents[2]
RUN='11111111-1111-4111-8111-111111111111'
URL='http://127.0.0.1:18765'
r=Redis(unix_socket_path=str(STATE/'redis.sock'))
q=Queue('isolated-stage1',connection=r)
client=QdrantClient(path=str(STATE/'qdrant'))
client.create_collection('isolated_stage1',vectors_config=models.VectorParams(size=4,distance=models.Distance.COSINE))
client.upsert('isolated_stage1',points=[models.PointStruct(id=str(uuid.uuid5(uuid.NAMESPACE_DNS,f'video_1_seg_{i}')),vector=[0.,1.,0.,0.],payload={'video_id':1,'text':'old-index','segment_index':i,'start_time':0,'end_time':8000}) for i in range(301)])
client.close()
r.set('index-active:1',RUN)

def data(batch,indices,callback=True):
 return dict(video_id=1,run_id=RUN,timestamp_unit='seconds',language='en',batch_info={'batch_number':batch,'total_batches':2},
             identification_segments=[dict(segment_index=i,start=1,end=8,speaker='Unknown Speaker',transcript_speaker='A',text='new-index') for i in indices],
             webhook_url=f'{URL}/?video_id=1&run_id={RUN}&batch={batch}&total=2' if callback else None)
def work():
 code="from redis import Redis; from rq import Queue,SimpleWorker; import os; r=Redis(unix_socket_path=os.environ['CLIPS_TEST_STATE']+'/redis.sock'); SimpleWorker([Queue('isolated-stage1',connection=r)],connection=r).work(burst=True)"
 result=subprocess.run(['python3','-c',code],env=os.environ.copy(),capture_output=True,text=True)
 (STATE/'rq-worker.log').open('a').write(result.stdout+result.stderr)
 assert result.returncode==0,result.stderr

def payloads():
 c=QdrantClient(path=str(STATE/'qdrant'));points,_=c.scroll('isolated_stage1',limit=1000);c.close();return [p.payload for p in points]

r.set('fixture-provider-fail','1')
failed=q.enqueue('tools.integration.stage1_worker_fixture.execute',data(1,range(151),False))
work()
assert all(p['text']=='old-index' for p in payloads())
r.delete('fixture-provider-fail')
# Batch two acknowledges before batch one; completion must remain false.
q.enqueue('tools.integration.stage1_worker_fixture.execute',data(2,range(151,301)))
work()
state=requests.get(URL).json();assert state['status']=='processing' and state['segments_count']==150,state
r.set('fixture-fail-mid-upsert','1')
job=q.enqueue('tools.integration.stage1_worker_fixture.execute',data(1,range(151)),retry=Retry(max=1,interval=0))
work()
partial=json.loads((STATE/'partial-replacement.json').read_text())
assert sum(p['text']=='new-index' for p in partial)==150
assert sum(p['text']=='old-index' for p in partial)==151
final=payloads();assert len(final)==301 and all(p['text']=='new-index' and p['end_time']==8 and p['speaker']=='Unknown Speaker' and p['transcript_speaker']=='A' for p in final)
state=requests.get(URL).json();assert state['status']=='completed' and state['segments_count']==301,state
# Real simultaneous HTTP requests; SQLite + single PHP server serializes these.
callback=f'{URL}/?video_id=1&run_id={RUN}&batch=1&total=2'
with ThreadPoolExecutor(max_workers=8) as pool:
 responses=list(pool.map(lambda _:requests.post(callback,json={'status':'completed','segments_embedded':151}),range(16)))
assert all(x.status_code==200 for x in responses)
requests.post(f'{URL}/?video_id=1&run_id=22222222-2222-4222-8222-222222222222&batch=1&total=2',json={'status':'completed','segments_embedded':999}).raise_for_status()
state=requests.get(URL).json();assert state['segments_count']==301
report={'environment':'isolated local Redis/RQ + persistent Qdrant local engine + HTTP Laravel/SQLite',
 'provider':'synthetic controlled provider, not OpenAI integration','provider_failure_preserved_old_index':True,
 'out_of_order_incomplete_status':'processing','partial_replacement':{'old_points':151,'new_points':150,'previous_index_intact':False},
 'rq_retry_restored_points':301,'final_count':state['segments_count'],'duplicate_http_requests':16,
 'concurrency_limit':'PHP development server serializes; no MySQL multi-worker locking validated',
 'timestamp_indexed_seconds':8,'unknown_identity_and_original_label':True,
 'player_seek':'not exercised','api_worker_revision':'local extracted production worker, HEAD plus uncommitted changes'}
(STATE/'results.json').write_text(json.dumps(report,indent=2));print(json.dumps(report,indent=2))
