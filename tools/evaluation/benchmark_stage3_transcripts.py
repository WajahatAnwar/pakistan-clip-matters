"""Synthetic large transcript benchmark; no provider or production access."""
import pathlib,sys,time,resource,json
from types import SimpleNamespace as NS
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'Clip matter py  embeding'))
from transcript_search_contract import contract_search
from semantic_passage_evidence import BoundedRetrieval
class Models:
    @staticmethod
    def FieldCondition(**kw):return kw
    MatchValue=FieldCondition;Filter=FieldCondition
class Reader:
    def scroll(self,**kw):
        offset=kw.get('offset') or 0
        points=[NS(id=str(i),payload={'video_id':i//500,'text':('Education policy and investment. '*60)+'IPP I.P.P.',
            'start_time':8,'end_time':18,'speaker':'Unknown Speaker','transcript_speaker':str(i)}) for i in range(offset,min(offset+kw['limit'],2500))]
        end=offset+len(points);return points,end if end<2500 else None
req=NS(query='IPP',title=None,words=[],filter_type='text',transcript_contract='normalized',video_id=None,
    language=None,filter_date=None,filter_year=None,filter_month=None,max_scanned=10000,top_k=1000,time_range=None)
out=[]
for _ in range(3):
    start=time.monotonic();response=contract_search(req,BoundedRetrieval(Reader()),'synthetic',Models,None,None)
    assert response['search_contract']['counts']['matched_transcript_occurrences']==2000
    assert response['search_contract']['status']=='partial'
    out.append({'elapsed_seconds':round(time.monotonic()-start,3),'metadata':response['search_contract']})
print(json.dumps({'fixture':'2500 utterances across 5 large synthetic transcripts, 1930 characters per utterance',
    'human_audio_verified':False,'runs':out,'peak_rss_kib':resource.getrusage(resource.RUSAGE_SELF).ru_maxrss},indent=2))
