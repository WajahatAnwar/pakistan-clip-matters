#!/usr/bin/env python3
"""Controlled pipeline measurements. These are NOT live model accuracy results."""
import json
import pathlib
import resource
import sys
import time
import tracemalloc
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'Clip matter py  embeding'))
from semantic_passage_evidence import validate_passages

rows = [{'id':str(i),'video_id':i,'start_time':8,'end_time':18,'speaker':'Unknown Speaker',
         'transcript_speaker':'A','text': ('Students face high education fees.' if 35<=i<40 else 'Young people attend a political gathering today.'),
         'score':1-i*.001,'language':'ur'} for i in range(100)]

def oracle(query,batch,timeout,facets):
    facets=facets or ['students_with_high_education_fees']
    return {'required_facets':facets,'passages':[
        {'index':i,'score':.95 if 35<=int(r['id'])<40 else .1,
         'complete':35<=int(r['id'])<40, 'evidence':{f:r['text'] for f in facets}}
        for i,r in enumerate(batch)]}

tracemalloc.start();started=time.monotonic()
results,metadata=validate_passages('Students facing high education fees',rows,oracle)
latency=time.monotonic()-started
_,peak=tracemalloc.get_traced_memory()
output={'scope':'synthetic oracle; no OpenAI or human footage evaluation',
        'top_five_video_relevance':len({r['video_id'] for r in results})/5,
        'displayed_passage_relevance':sum(35<=int(r['id'])<40 for r in results)/len(results),
        'passages':len(results),'provider_calls':metadata['provider_calls'],
        'latency_seconds':latency,'python_allocation_peak_bytes':peak,
        'process_peak_rss_kib':resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
        'evaluation_status':metadata['status']}
print(json.dumps(output,indent=2))
