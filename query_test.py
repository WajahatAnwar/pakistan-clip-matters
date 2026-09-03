from qdrant_client import QdrantClient
from sentence_transformers import SentenceTransformer
client = QdrantClient("http://localhost:6333")
model = SentenceTransformer('sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2')
vec = model.encode("federalism")
res = client.search(collection_name="video_segments", query_vector=vec.tolist(), limit=3)
print("Federalism:")
for r in res:
    print(r.score, r.payload.get("text")[:100])

vec2 = model.encode("justice")
res2 = client.search(collection_name="video_segments", query_vector=vec2.tolist(), limit=3)
print("\nJustice:")
for r in res2:
    print(r.score, r.payload.get("text")[:100])
