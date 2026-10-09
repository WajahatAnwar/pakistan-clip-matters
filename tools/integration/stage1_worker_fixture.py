"""Local-only production worker harness: real Redis/RQ + Qdrant local storage.
Synthetic embeddings intentionally replace the external provider; never use production URLs.
"""
import ast
import json as json_module
import logging
import os
import uuid
from datetime import datetime
from pathlib import Path
import requests
from redis import Redis
from qdrant_client import QdrantClient, models
from qdrant_client.models import PointStruct, Filter, FieldCondition, MatchValue

ROOT = Path(__file__).resolve().parents[2]
STATE = Path(os.environ['CLIPS_TEST_STATE'])
assert str(STATE).startswith('/tmp/')
redis_conn = Redis(unix_socket_path=str(STATE/'redis.sock'))
SEGMENTS_COLLECTION = 'isolated_stage1'
EMBEDDING_DIMENSION = 4
OPENAI_EMBEDDING_MODEL = 'synthetic-test-model'
USE_OPENAI_EMBEDDINGS = True
openai_client = object()
logger = logging.getLogger(__name__)
class WebhookDeliveryError(Exception): pass

client = QdrantClient(path=str(STATE/'qdrant'))
if not client.collection_exists(SEGMENTS_COLLECTION):
    client.create_collection(SEGMENTS_COLLECTION, vectors_config=models.VectorParams(size=4,distance=models.Distance.COSINE))

class FailureInjectionClient:
    def upsert(self, **kwargs):
        call = redis_conn.incr('fixture-upsert-calls')
        if redis_conn.get('fixture-fail-mid-upsert') and call == 2:
            points,_=client.scroll(SEGMENTS_COLLECTION,limit=1000)
            (STATE/'partial-replacement.json').write_text(json_module.dumps([p.payload for p in points]))
            raise RuntimeError('isolated injected Qdrant upsert failure')
        return client.upsert(**kwargs)
    def delete(self, **kwargs): return client.delete(**kwargs)
qdrant_client = FailureInjectionClient()

def get_openai_embeddings_batch(texts):
    if redis_conn.get('fixture-provider-fail'):
        raise RuntimeError('isolated injected provider failure')
    return [[1.0,0.0,0.0,0.0] for _ in texts]

def send_webhook_with_retry(url,payload):
    assert url.startswith('http://127.0.0.1:')
    response=requests.post(url,json=payload,timeout=10)
    response.raise_for_status()

source=ast.parse((ROOT/'Clip matter py  embeding/embeddings_test.py').read_text())
node=next(n for n in source.body if isinstance(n,ast.FunctionDef) and n.name=='process_video_task')
exec(compile(ast.Module(body=[node],type_ignores=[]),str(ROOT/'Clip matter py  embeding/embeddings_test.py'),'exec'),globals())

def execute(data):
    return process_video_task(data)
