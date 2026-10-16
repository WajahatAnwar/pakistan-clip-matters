# Python search integration

Laravel delegates search meaning, evidence validation and ordering to
`embeddings_service.py`. It forwards the original query, filters, transcript
contract (`literal`, `normalized`, `alias`) and Python cursors. AI Smart Search
uses `/search/incremental`; speaker, summary and transcript filters use `/search`.
Exact title, date, language and video metadata filters retain paginated SQL. Laravel rechecks current database eligibility
and adds display information. Semantic search never adds title fallbacks or rescales scores; the UI never
sorts by match counts. Incomplete searches display a notice and expired
sessions require a fresh search.

## Environment and deployment

Use [embedding.env.example](embedding.env.example). `EMBEDDING_SERVICE_URL`
takes precedence; the older `PYTHON_EMBEDDING_SERVICE_URL` is supported as a
fallback. The existing `OPENAI_EMBEDDING_MODEL` / `EMBEDDING_DIMENSION` names remain supported
when the new Laravel-specific model/dimension variables are absent.
The Laravel `EMBEDDING_API_KEY` must equal Python's `API_KEY` in both
its API and RQ worker. Embedding model/dimensions must match Python and the
source Qdrant collection; these settings do not migrate existing vectors.

Deploy both projects together. In Laravel run:

```sh
php artisan migrate --force
npm ci
npm run build
php artisan config:cache
php artisan queue:restart
```

Run the new Python API and restart its RQ worker with the same configuration.
The worker must reach the HTTPS Laravel callback. Set `APP_URL` correctly or
provide `EMBEDDING_CALLBACK_URL`; reverse proxy routing must allow POST to
`/api/embeddings/callback`. The callback uses `X-API-Key`, requires no browser
session, and rejects unauthenticated or stale-generation completion messages.

Upload jobs and `embeddings:reembed-all` submit one generation with global
segment indices, seconds timestamps and stable run IDs across retries. HTTP 202
only means queued: the local status stays processing. Python stages batches
and commits after all embeddings succeed. Only the completed-generation
callback sets the final count and completed status. Repeated callbacks are
idempotent; stale callbacks cannot overwrite a newer run. Existing in-flight
legacy jobs lack this contract; let them finish before switching deployments.

## Verification

```sh
php artisan test --filter=EmbeddingAlignmentTest
npm run build
```

Tests fake Python HTTP responses and use isolated SQLite. They exercise filters,
evidence/order preservation, pagination, typed errors, submission, and callback
authentication/generation checks. They do not call OpenAI or measure live relevance,
latency, or cost. Benchmark representative multilingual queries before rollout.
