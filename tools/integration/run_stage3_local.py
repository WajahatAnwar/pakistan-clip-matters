"""Real isolated Qdrant local-engine checks; evidence judge is a synthetic oracle.
No production credentials, servers, collections, jobs or data are used.
"""
import json,pathlib,resource,sys,tempfile,time
from types import SimpleNamespace as NS
ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'Clip matter py  embeding'))
from qdrant_client import QdrantClient,models
from semantic_passage_evidence import BoundedRetrieval
from transcript_search_contract import contract_search

def request(**kw):
    d=dict(query='IPP',title=None,words=[],filter_type='text',transcript_contract='normalized',video_id=None,
        language=None,filter_date=None,filter_year=None,filter_month=None,max_scanned=10000,top_k=1000,
        speaker=None,time_range=None)
    d.update(kw);return NS(**d)

def judge(query,batch,timeout,facets):
    return {'required_facets':['elite_privileges'],'passages':[{'index':i,'score':.9,'complete':'elite privileges' in row['text'],
        'evidence':{'elite_privileges':'elite privileges'}} for i,row in enumerate(batch)]}

with tempfile.TemporaryDirectory(prefix='clipsmatter-stage3-qdrant-') as temp:
    client=QdrantClient(path=temp)
    client.create_collection('stage3_isolated',vectors_config=models.VectorParams(size=3,distance=models.Distance.COSINE))
    rows=[]
    for i in range(1100):
        rows.append(models.PointStruct(id=i+1,vector=[1.,0.,0.],payload={'video_id':i+1,
            'video_title':'Fuuast graduation ceremony extended' if i<1099 else 'Fuuast graduation ceremony',
            'text':'IPP I.P.P. I P P آئی پی پی' if i<3 else 'Unrelated transcript',
            'speaker':'Unknown Speaker','transcript_speaker':'A','start_time':8,'end_time':18,
            'timestamp_unit':'seconds','processing_status':'completed','approval_status':'approved','is_archived':False,
            'video_created_at':'2026-10-09','language':'ur','video_summary_english':'elite privileges' if i==4 else ''}))
    rows[4].payload['text']='A policy grants elite privileges without accountability.'
    for i in range(0,len(rows),100):client.upsert('stage3_isolated',rows[i:i+100])
    out={'transport':'Qdrant persistent local engine','human_footage_verified':False,'summary_judge':'synthetic oracle','results':[]}
    for req in [request(),request(query='I.P.P.'),request(query='I P P'),request(query='آئی پی پی',transcript_contract='alias'),
        request(query='Fuuast graduation ceremony',filter_type='title'),request(query='elite privileges',filter_type='summary')]:
        started=time.monotonic()
        result=contract_search(req,BoundedRetrieval(client),'stage3_isolated',models,lambda q,t:[1.,0.,0.],judge)
        meta=result['search_contract']
        if req.filter_type=='text':
            assert meta['counts']['matching_videos']==3
            assert meta['counts']['matched_transcript_occurrences']==(12 if req.transcript_contract=='alias' else 9)
        if req.filter_type=='title':assert result['results'][0]['video_id']==1100
        if req.filter_type=='summary':
            assert len(result['results'])==1 and result['results'][0]['video_id']==5
            assert result['results'][0]['start_time']==8
        out['results'].append({'query':req.query,'type':req.filter_type,'contract':req.transcript_contract,
            'elapsed_seconds':round(time.monotonic()-started,3),'metadata':meta})
    from backfill_transcript_contract import backfill_video
    before=client.retrieve('stage3_isolated',[1],with_payload=True,with_vectors=True)[0]
    preview=backfill_video(client,'stage3_isolated',1)
    assert preview['payload_updates']==1 and not preview['applied']
    assert 'search_contract_version' not in client.retrieve('stage3_isolated',[1])[0].payload
    incomplete=contract_search(request(),BoundedRetrieval(client),'stage3_isolated',models,None,None,use_normalized_index=True)
    assert not incomplete['search_contract']['normalized_index_used']
    for video_id in range(1,1101):backfill_video(client,'stage3_isolated',video_id,apply=True)
    after=client.retrieve('stage3_isolated',[1],with_payload=True,with_vectors=True)[0]
    assert before.vector==after.vector and before.payload['text']==after.payload['text']
    indexed=contract_search(request(query='I.P.P.'),BoundedRetrieval(client),'stage3_isolated',models,None,None,use_normalized_index=True)
    assert indexed['search_contract']['normalized_index_used']
    assert indexed['search_contract']['exhaustive']
    assert indexed['search_contract']['scanned_segments']==3
    assert indexed['search_contract']['counts']['matched_transcript_occurrences']==9
    aliases=contract_search(request(query='آئی پی پی',transcript_contract='alias'),BoundedRetrieval(client),'stage3_isolated',models,None,None,use_normalized_index=True)
    assert aliases['search_contract']['counts']['matched_transcript_occurrences']==12
    literal=contract_search(request(query='I',transcript_contract='literal'),BoundedRetrieval(client),'stage3_isolated',models,None,None,use_normalized_index=True)
    assert not literal['search_contract']['normalized_index_used']
    assert literal['search_contract']['counts']['matched_transcript_occurrences']==6
    class LegacyWriterRace:
        def __init__(self):self.counts=0
        def count(self,**kwargs):
            result=client.count(**kwargs);self.counts+=1
            if self.counts==2:
                client.upsert('stage3_isolated',[models.PointStruct(id=2000,vector=[1.,0.,0.],payload={
                    'video_id':2000,'text':'IPP','start_time':8,'end_time':18,
                    'processing_status':'completed','approval_status':'approved','is_archived':False})])
            return result
        def scroll(self,**kwargs):return client.scroll(**kwargs)
    raced=contract_search(request(),BoundedRetrieval(LegacyWriterRace()),'stage3_isolated',models,None,None,use_normalized_index=True)
    assert raced['search_contract']['counts']['matched_transcript_occurrences']==10
    time_filtered=contract_search(request(time_range={'start':9}),BoundedRetrieval(client),'stage3_isolated',models,None,None)
    assert not time_filtered['results']
    out['normalized_index']={'dry_run':preview,'fallback_when_incomplete':True,
        'vectors_and_original_text_preserved':True,'metadata':indexed['search_contract']}
    out['peak_rss_kib']=resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    client.close()
    print(json.dumps(out,indent=2,ensure_ascii=False))
