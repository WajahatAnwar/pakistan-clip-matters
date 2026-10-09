# Stage 2 implementation and review — not deployed

## Production boundary and baseline

Implementation is isolated at `/tmp/clipsmatter-stage2`. The running checkout,
production database, queues, collections and frontend assets were not edited by
Stage 2. The Stage-2-only patch is `/tmp/clipsmatter-stage2-only.patch`; its baseline
is the working tree including prior local relevance and Stage 1 fixes, rather than
clean HEAD. Preserved comparison repository: `/tmp/clipsmatter-stage2-preserved-baseline`;
its baseline HEAD is `1b83d5bd9626f4da52844a1128fe859e872c97f6`, and its staged diff
contains Stage 2. The baseline commit is a local snapshot, not a deployed revision.
Local HEAD is `3119d7cafbf1610b172270aed4bacef8dcc4205f` plus uncommitted changes.
The deployment must include the reviewed Stage 1 dependencies separately.

Configured Railway service is `https://web-production-935af.up.railway.app`.
Earlier read-only health responses advertised the existing OpenAI 3-large/3072
configuration and a generic release name, not an immutable revision. Railway API
and worker SHAs remain unverified. No Stage 2 production or staging execution is
claimed. No human footage evaluation or paid model calls were made for Stage 2.

Stage 1 is not integration-approved: the earlier isolated Redis/RQ + Laravel/SQLite
+ persistent Qdrant local-engine test exposed 150 new points and 151 old points
when the second replacement upsert failed. Retry restored 301 consistent points,
but the old searchable index did **not** remain intact. This is a confirmed atomic
replacement gap. Concurrent MySQL callbacks, actual Qdrant server behavior, active
API/worker revisions, deployed schema/config and actual player seek still need
verification. This implementation does not repair that gap or authorize release.

## Verified causes and changes

- English query understanding automatically set language=en. Removed inferred
  language restrictions; explicit language filters remain. Original query intent
  is passed to the evidence judge, retaining subjects, actions and relationships.
  A faithful translated retrieval query takes precedence over broad synonyms.
- Single name components and model-inferred speakers could impose an identity.
  Only explicit speaker filters or named speaking/action wrappers resolve a speaker.
  Full configured names/acronyms are used; ambiguous standalone components are not
  expanded into a person. Identity is separately checked against configured names.
- Title/keyword score floors and video-level checks were treated as transcript
  proof. Every semantic passage now receives its own complete-intent judgment,
  with a consistent required-facet plan and exact evidence quotes in that passage.
  Titles/tags/summary metadata cannot bypass it. Literal modes retain their existing
  match logic. Semantic validation is mandatory even if USE_RERANKING=false.
- The fixed top-30 cutoff could hide later valid evidence. Validation expands in
  bounded batches beyond 30, with one best candidate per video considered first.
  It never appends unchecked candidates. Missing/duplicate/invalid judgments and
  fabricated quote locations cause an explicit retryable validation failure.
- Provider timeouts/invalid output previously collapsed to empty hits. Initial
  failure now produces HTTP 503; later failure retains verified passages with
  status=partial. Successfully evaluated empty candidates report no_matches.
  Candidate transport failures are retryable; query-understanding outages mark the search partial. Budget exhaustion is marked partial.
  Evaluation scope is retrieved candidates, not an exhaustive archive search.
- Python merged across speakers, PHP/React discarded distinct overlapping evidence,
  and React moved tags/counts above relevance. Python now consolidates before
  validation, preserving source IDs, explicit seconds and original speaker labels.
  It merges only same-speaker contiguous passages within 1 second, 60 seconds total
  and 2400 characters. Distinct overlaps survive. Missing identity AND missing
  diarization/transcript labels prevent merging. PHP only presents authoritative
  Python passages; React preserves order and merges repeated-page passage identities.
- Videos remain contiguous in the flattened cache, with deterministic relevance
  ties, so cursor pages do not split their passages. Expired/mismatched sessions
  report HTTP 409 with a restart instruction instead of skipping into newly ranked
  results. Partial regular responses are immediately removed from the five-minute
  cache; retries re-evaluate. Cache keys use a new passage-validation namespace.
- Python and PHP share NFKC, case folding, Arabic keyboard yeh/kaf variants and
  punctuation normalization for semantic queries. Aspirated consonants and vowel
  distinctions are preserved. Literal transcript normalization was not rewritten.
- The frontend distinguishes partial empty results from genuine no_matches and
  offers Retry search. Pagination failures also offer an explicit fresh retry.
  Existing initial-search error displays and Search buttons allow retry after 503.

The evidence model can still make incorrect judgments. Exact quotes prove quote
location, not the truth of a semantic inference; human evaluation is required.

## Changed files

Python:
- `Clip matter py  embeding/embeddings_test.py`
- `Clip matter py  embeding/semantic_passage_evidence.py` (new)
- `Clip matter py  embeding/Dockerfile` (includes/import-checks new helper)
- `Clip matter py  embeding/tests/test_stage_two_evidence.py` (new)
- `Clip matter py  embeding/tests/test_semantic_query_decomposition.py`

Laravel:
- `app/Http/Controllers/VideoController.php`
- `app/Services/SemanticResultPresenter.php` (new)
- `app/Services/SearchTextNormalizer.php` (new)
- `tests/Feature/StageTwoSemanticSearchTest.php` (new)

Frontend:
- `resources/js/Pages/UserSide/SearchedPage.jsx`
- `resources/js/Pages/UserSide/SearchPage.jsx`
- `resources/js/utils/semanticResults.js` (new)
- `tests/js/semanticResults.test.mjs` (new)

Evaluation:
- `tests/fixtures/semantic_qa_201_231.json` (new)
- `tools/evaluation/score_semantic_qa.py` (new)
- `tools/evaluation/benchmark_synthetic_evidence.py` (new)
- This report.

## Defaults, latency and cost containment

No new environment variables or Stage 2 migrations. Existing OpenAI embedding
model/dimensions remain unchanged and must match document indexing. Evidence uses
existing GPT-4o-mini. PHP intl is required (available in tested CLI; check FPM too).
The deployment dependency already specifies qdrant-client>=1.17; verify its REST
per-request timeout propagation in the deployed image (local 1.19.1 does propagate).

Hard validation caps: 60 candidate passages, 20 per batch, 3 calls, a 30-second
validation admission deadline, at most 10 seconds per call, 2400 characters per
passage, 2400 output tokens per call. Overlong passages are not silently truncated:
they are unevaluated and the result is partial. Maximum passage input is 144,000
characters over all calls; output is capped at 7200 tokens. Query understanding is
one call with 8-second timeout/300 output tokens, and vector retrieval uses at most
two query variations/embedding calls, each with 8-second timeout and no SDK retries.
The request-local candidate-read budget is 35 seconds and 12 Qdrant reads, each
with at most 10-second REST/HTTP timeout. The previous global 120-second client is
unchanged for indexing. At most six OpenAI calls are attempted per fresh search;
caches can reduce understanding/embedding calls. The 35+30 seconds are phase
budgets, not a guaranteed total HTTP SLA: CPU, serialization, SQL and transport
bookkeeping add overhead. Do not tune proxy timeouts to hide failures.

This bounds provider work by calls, input characters and output tokens; it is not
an account-level dollar cap. No real provider latency, token usage or dollar cost
was measured. During staging record billed input/output tokens and enforce a
reviewed spend cap before canary traffic. Metadata reports attempted validation,
understanding, embedding and Qdrant counts separately; SDK retries are disabled
for these semantic provider requests. Occurrence counts are null because source
segments are not lexical occurrences. Videos, validated passages and constituent
source segments have distinct counts; returned counts are separate from evaluated
candidate counts.

## Verification and evaluation

- Python: 60 tests passed, including the preserved Stage 1 tests.
- Focused Laravel: 26 tests / 109 assertions passed; peak test-process memory 71 MB.
  Covers Stage 1 indexing/timestamps, transcript API, suggestions, benchmark, manual
  tags, semantic enrichment/order/overlaps/8-second links, provider retries, missing
  English language filter, no filler title cards, partial status and cache retries.
- JavaScript: four ordering, overlap, duplicate-page and stable append checks passed.
- Python compilation, PHP lint and Docker helper import smoke check passed.
  Patch whitespace checks pass with cr-at-eol to preserve the existing CRLF Python
  source; default Git whitespace checking treats those carriage returns as trailing
  whitespace. Reverse patch application check passed.
- Vite production build passed in 35.96 seconds using isolated Node 22.16.0, output
  `/tmp/clipsmatter-stage2-build`. System Node 20.5.1 failed Vite's crypto.hash
  requirement; do not replace the production runtime as part of this patch.
- Full Laravel suite: 51 tests / 148 assertions, 17 failures,
  2 errors; preserved working-tree baseline: 45 tests, same 17 failures/2 errors
  in auth/profile/default routes. The six additional Stage 2 cases passed. This differs
  from the earlier Stage 1 report's environment-dependent 13 failures; Stage 2
  deliberately has no production .env or config cache. Full suite is not green.

Reproduction from the isolated checkout:
```
python3 -m unittest discover -s 'Clip matter py  embeding/tests'
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php -r 'require "vendor/autoload.php"; exit((new PHPUnit\TextUI\Application)->run(array_merge(["phpunit"],array_slice($argv,1))));' -- --filter 'StageOneIndexingTest|StageTwoSemanticSearchTest|TranscriptApiTest|BenchmarkSemanticSearchCommandTest|VideoManualTagsCompletionTest|SearchSuggestionTest'
node tests/js/semanticResults.test.mjs
/tmp/clipsmatter-stage2-node/node_modules/node-linux-x64/bin/node node_modules/vite/bin/vite.js build --outDir /tmp/clipsmatter-stage2-build
python3 tools/evaluation/benchmark_synthetic_evidence.py
python3 tools/evaluation/score_semantic_qa.py --output /tmp/semantic-qa.json
```
Tests use in-memory SQLite, fake HTTP/OpenAI responses and synthetic passages.
They establish behavior, not multilingual retrieval or model precision in footage.
A controlled oracle finds five relevant videos below candidate 30: top-five video
relevance=5/5, displayed passage relevance=5/5, three judgment calls, ~0.012 seconds,
~104 KB peak Python allocations / ~11.2 MB process RSS. This is a synthetic pipeline
measurement, not a claimed model accuracy or production memory measurement.

QA fixtures preserve 201–231 and comments. QA206 corrects the duplicate Roman Urdu
query to an Urdu translation and explicitly requires reviewer confirmation. Twelve
reported Pass labels require passage-level retesting; QA218 (Palestine without
verified youth) and QA225 (education without verified fees) are explicitly flagged.
All 31 cases lack human-verified video/passage IDs and timestamps. Their real
metrics are therefore null/not_evaluable. No expected match was invented. The
scorer accepts saved results with explicit human passage review and records video
and passage relevance separately, with latency/provider counts.

## Staging, deployment and rollback — NOT executed

Production recommendation: **NO-GO** until atomic replacement, immutable API/worker
revisions, live callback concurrency and multilingual ground truth are resolved.

1. Review the Stage-2-only patch against the preserved working tree, and Stage 1
   separately. Record actual Railway API/worker revisions, Laravel release/FPM intl,
   Qdrant version/dimensions, model names and queue configuration. Use an isolated
   database, Redis namespace and Qdrant collection; no failure injection on real data.
2. Resolve the Stage 1 replacement defect before indexing/recovery. Verify active
   schema and legacy callback deployment compatibility, and drain/restart old jobs
   according to the Stage 1 report. Stage 2 itself requires no migration.
3. Deploy Laravel presentation/error/cache handling and frontend support to staging
   first; they accept old Python responses. Build frontend in an isolated release
   directory with supported Node. Deploy Python API including the helper afterward.
   Record API/worker SHA; workers importing this module also need the new helper.
   Keep the existing models/collections. Never deploy Python alone to old PHP that
   can re-add title-only filler or discard overlapping evidence.
4. Verify real server timeouts and provider failures, no mixed embedding vectors,
   late valid candidates, partial empty/filled responses, fresh retries, pagination
   duplicates/expiry, explicit language and speaker filters, and player seeking at
   8 seconds. Record total latency and billed token usage under representative load.
   In-memory Python cursor caches require session affinity/single API process for
   stable pagination; another replica now safely returns an explicit restart error.
5. Obtain human-reviewed positive/negative passages for QA201–231, including queries
   with five genuine matching videos. Score top-five videos and every displayed
   passage separately. Do not accept mocked synthetic scores as release evidence.
6. After staging approval, use a reviewed, coordinated production release with
   immutable artifacts and a small canary. No production deployment is authorized
   by this task.

Rollback: retain prior API and Laravel/frontend artifacts and release config.
Restore the coordinated versions; restart the API and affected workers, and restart
search sessions. The new Laravel cache prefix isolates previous semantic entries;
remove only the release-specific search cache keys if necessary, never global
Redis/queues. Stage 2 writes no document vectors or database schema, so it needs
no data rollback. Do not reverse Stage 1 additive migration while jobs depend on it.

## Targeted repairs — separate approval and execution

Stage 2 does not change document embedding representation, model or vector space;
no archive reindex or retranscription is required for this search-only patch.
Original transcript_speaker payloads already added in Stage 1 improve safe merging;
older unlabeled Unknown passages are left separate, not assigned invented labels.

Prior read-only inspection artifact `/tmp/clipsmatter-candidate-inspection.json`
contains 29 unique records (union of six indexing gaps and 26 timestamp candidates).
It is a snapshot, not a fresh production assertion. Nothing was repaired.

- Six indexing gaps: 826, 828, 948, 1014, 1073, 1104 had valid transcripts, no
  identification and no indexed segments. Inspect each transcript, eligibility and
  unit before queuing Unknown-identity indexing. Record 1014's historical ffmpeg
  processing error separately; do not retranscribe an already valid transcript.
  Prepared inspection only:
  `php artisan search:recover-transcripts --id=826 --id=828 --id=948 --id=1014 --id=1073 --id=1104 --dry-run`
- Of 26 timestamp candidates, 23 existing indexes were confirmed to retain early
  millisecond values: 33, 39, 594, 734, 831, 834, 839, 841, 844, 872, 886, 911, 939,
  947, 1008, 1023, 1071, 1091, 1114, 1117, 1123, 1126, 1146. The other three
  (826, 828, 1014) are missing-index recovery cases, not existing timestamp repairs.
  Example confirmed mismatch: video33 segment0 expects 2.883–9.123 seconds but had
  indexed 2883–9123. Recheck source units, every selected payload and stored point
  IDs before selecting repairs. Example dry run for that verified record only:
  `php artisan search:recover-transcripts --id=33 --dry-run`
  The command rebuilds selected videos rather than patching only bad timestamp
  payloads. A future payload-only repair could reuse compatible vectors, but must
  export old payloads and explicitly select confirmed bad points. No such write
  command is executed or implied here. Do not automatically rebuild all 26.
- Potential old padded fallback vectors remain a separate Stage 1 provenance
  investigation. Unmarked vectors cannot be classified by dimension alone. Audit
  provider/model metadata and job history before a targeted export/re-embed plan;
  do not delete/re-embed them automatically.

Next work: resolve Stage 1 atomic replacement and staging revision/concurrency
checks; obtain multilingual passage ground truth and measure real-model cost,
recall and precision; verify playback/source availability for QA201/212/224/231;
address title/document search and other tracker failures separately. Long passages
and distributed cursor state are explicit remaining limitations.
