# Stage 1 implementation and release review

## Baseline and boundaries

Local HEAD: `3119d7cafbf1610b172270aed4bacef8dcc4205f`.
Before editing, five files already differed from HEAD: Python `.env.example`,
`embeddings_test.py`, `semantic_query_decomposition.py`, its existing test file,
and React `SearchedPage.jsx`. Those changes are earlier relevance/highlighting
work, not Stage 1. Their baseline was preserved in
`/tmp/clipsmatter-pre-stage1.patch` and reconstructed in
`/tmp/clipsmatter-stage1-baseline`. Stage-only review patch:
`/tmp/clipsmatter-stage1-only.patch` (regenerate if editing further).
Stage 1 makes no further semantic-ranking or React changes.

Configured service: `https://web-production-935af.up.railway.app`.
Repository has Railway Docker configuration and Redis/RQ worker support.
The deployed Railway revision, effective model settings and active worker versions
are NOT verified. Local code does not establish deployed behavior.

## Verified defects and fixes

1. Search loaded complete Video rows, including transcript JSON, and database-only
   paths loaded all rows before pagination. All ordinary enrichment uses explicit
   SEARCH_CARD_COLUMNS; database-only numeric/date/language/video branches count
   and apply SQL limit/offset before hydration. Existing summary/card metadata and
   filtering logic remain. Python candidate collections still paginate after
   relevance/grouping: applying SQL pagination to those candidate IDs would change
   ranking/filtering. Their rows now exclude transcript fields. Ranking unchanged.
2. Timestamp mapping inferred milliseconds from end > 10000. Explicit mapper
   supports milliseconds/seconds and rejects unknown units or invalid ranges.
   New AssemblyAI transcripts record milliseconds. Historical transcript_id-backed
   speakers_data uses the existing AssemblyAI contract, not a value heuristic.
   Explicit unit takes precedence. Unproven non-AssemblyAI formats fail closed.
   /embed-video requires seconds; original diarization transcript labels are kept
   separately from identity. Missing actualSpeaker becomes Unknown Speaker.
3. Processing unnecessarily required identification_data to dispatch embeddings.
   Valid transcript data is now sufficient. Recovery command inspects explicit IDs
   and supports --dry-run. It never transcribes, silently chooses IDs, or repairs
   speaker enrollment.
4. Webhook increments were non-idempotent and final batch number implied completion.
   Active run UUID, expected batch count and per-batch acknowledgments are persisted.
   Transaction/row lock serializes callbacks; successful batch duplicates do nothing;
   every batch must succeed; stale/identity-free callbacks cannot modify a run.
   Legacy callback URLs are acknowledged but ignored: drain old jobs before switching.
   Failed batch can be retried successfully. Old serialized Laravel jobs obtain a UUID.
5. Provider outages produced padded vectors from a different embedding space and
   indexing deleted old points before generating replacement vectors. Query/document
   OpenAI functions now share model/dimensions, validate response shape and raise
   retryable HTTP 503 without alternate vectors. Model/version metadata is stored.
   Laravel sends its model/dimension contract and Python rejects mismatch.
   New runs stage vectors by batch in Redis; only after every batch is generated
   does replacement upsert begin. Obsolete IDs are removed after successful upserts.
   Provider failure leaves existing Qdrant data intact. Legacy jobs no longer delete
   before embedding but do not get run staging; drain them before release.

## Verification

- 20 focused PHP tests, 80 assertions: passed (indexing, transcript API, benchmark,
  manual tags and suggestions).
- 39 Python tests: passed, including provider failures, model/dimension agreement,
  document outage without Qdrant writes, and existing relevance checks.
- PHP lint, Python compilation, whitespace checks: passed.
- Full PHP suite run before the final two added tests: 43 tests; 13 failures, 2 errors in auth/profile/example routing.
  Preserved baseline: 40 tests with the SAME 13 failures and 2 errors.
- Regression tests cover explicit 8000 ms, 18000 ms, seconds and long seconds,
  Unknown identity with original label, duplicate/out-of-order/stale/legacy callbacks,
  and SQL pagination/projection with large transcript fields.
- Tests use SQLite/mocks; live Redis/Qdrant multi-worker commit/concurrency and
  provider outages still require staging integration checks.

## Deployment sequence — NOT executed

1. Review Stage-only patch separately from earlier relevance changes. Record deployed
   Railway revision and model/dimension configuration. Back up SQL, Qdrant and Redis
   queue state. Pause new indexing dispatch and drain BOTH legacy Laravel embedding
   queues (`high`, `embeddings`) and Python RQ jobs. Search can remain available.
2. Apply additive migration `2026_10_08_000001_add_indexing_contract.php` in staging,
   then production only after approval. No production migration ran here.
3. Deploy Python API AND RQ worker from the same revision; restart both. Configure
   USE_OPENAI_EMBEDDINGS=true, valid OPENAI_API_KEY, OPENAI_EMBEDDING_MODEL and
   EMBEDDING_DIMENSION matching the existing OpenAI collection. No model replacement.
   Laravel services.embedding.model/dimensions must agree. Do not point another
   model at the primary collection. Confirm Qdrant collection dimensions separately.
4. Deploy Laravel migration-dependent code, refresh its config cache and restart
   queue workers. Resume indexing only after both sides understand run identity.
5. In staging exercise two-batch out-of-order/retry/provider outage, inspect Redis
   staging keys, callbacks, Qdrant writes and counts. Redis staging expires in 24h;
   active/registration keys retain seven days. Runs longer than 24h require an
   explicit retention review. Perform small search/card and timestamp smoke checks.
6. Review repairs below; execute only explicitly approved IDs after dry-run and
   provider compatibility checks. No recovery command was executed here.

## Rollback

Pause new dispatch; let active runs finish or record them as interrupted. Revert
Laravel/Python API/RQ together to the preserved pre-Stage-1 code and restart workers.
Keep additive columns initially; do not drop run audit data while callbacks remain.
Older code cannot safely process new-generation callbacks, so drain/isolate them
before rollback. Schema down migration drops only added columns, and is optional
until retention review. No data repairs occurred in this stage.
Qdrant replacement is not an atomic whole-video transaction: a Qdrant failure
DURING upsert may leave a partial replacement. Recover from the snapshot or retry
that same run after inspection. Provider-generation failures are protected; a
fully atomic Qdrant generation cutover is deferred rather than claimed here.

## Targeted repair inventory — NOT executed

Read-only audit reconfirmed all six completed/approved/unarchived records lack
identification and an embedding record: 826 (1 utterance), 828 (1), 948 (17),
1014 (716), 1073 (106), 1104 (167). Inspect source validity for one-utterance
records and 1014's historical processing error before approving dispatch.

After deploying/migrating, inspect without dispatch:

```bash
php artisan search:recover-transcripts --dry-run --id=826 --id=828 --id=948 --id=1014 --id=1073 --id=1104
```

Omitting --dry-run queues explicit records only; do NOT run until approved.

Historical audit: 346 transcript records, 130 arrays and 216 encoded JSON;
all have transcript_id. 26 videos contain text-bearing early intervals affected
by the heuristic: 33, 39, 594, 734, 826, 828, 831, 834, 839, 841, 844, 872, 886,
911, 939, 947, 1008, 1014, 1023, 1071, 1091, 1114, 1117, 1123, 1126, 1146.
826/828/1014 have no embedding record. For the others, compare current indexed
start/end against the normalized source; repair only confirmed mismatches.
The same recovery command supports --dry-run with each selected --id, including
completed embeddings. It intentionally does not silently skip completed embeddings.

Old vectors lack model provenance. Potential contamination cannot be identified
from dimensions alone. Audit historical OpenAI fallback logs and Qdrant payloads;
inspect 3072-dimensional vectors with trailing 2688 zeros as SUSPECTS, not proof.
Snapshot suspected video IDs, verify the intended model, then re-embed an explicit
approved subset using the targeted command. Do not delete a whole collection or
assume all old vectors are bad. No live vector audit/re-embedding ran here.

## Remaining limits / next stage

No live Railway revision, source-audio ground truth, or Qdrant provenance audit.
Read-only database audit is point-in-time; it does not prove all indexed timestamps.
Redis staging uses JSON vectors and whole-video materialization at commit: test
memory with the largest speeches and Redis capacity before release. Cross-process
locks and Qdrant failure recovery need staging validation. Search full transcript
loading is contained, but staging/index worker memory is a separate operational risk.

Next stage: fixed multilingual evaluation set and search relevance/count contracts,
passage evidence, frontend ordering, title/summary support and playback diagnostics.
No semantic tuning, model replacement or broad retranscription was performed.

## Stage 1 file inventory

- app/Http/Controllers/VideoController.php — search projection/pagination and retryability flag.
- app/Models/Video.php — result-card projection and timestamp field.
- app/Services/TranscriptSegmentMapper.php — explicit timestamp/identity mapping.
- app/Jobs/ProcessVideoJob.php — source unit and identification-independent dispatch.
- app/Jobs/GenerateVideoEmbedding.php — run/batch request contract and mapper.
- app/Models/VideoEmbedding.php — run/batch fields and casts.
- app/Http/Controllers/Api/QdrantWebhookController.php — transactional acknowledgments.
- app/Console/Commands/RecoverTranscriptIndex.php — explicit-ID inspection/recovery.
- database/migrations/2026_10_08_000001_add_indexing_contract.php — additive columns.
- config/services.php — client model/dimension contract.
- Clip matter py  embeding/embeddings_test.py — provider failure, compatibility and staging.
- tests/Feature/StageOneIndexingTest.php and Python tests/test_stage_one_embedding.py.
- This release review. Earlier changed files not listed here remain untouched by Stage 1.
