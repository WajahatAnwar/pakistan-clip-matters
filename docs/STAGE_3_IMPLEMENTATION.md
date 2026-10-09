# Stage 3 implementation and review — isolated, not deployed

## Boundary and verified environment

Production-connected checkout: `/home/1618941.cloudwaysapps.com/ptfmnnpjhn/public_html`.
Local baseline revision: `6ac18004db94e1576b8548d80a1b961914308646`, initially clean.
Stage 1–2 reports were inspected; they describe earlier snapshots and do not prove
current Railway revisions. All Stage 3 implementation is at `/tmp/clipsmatter-stage3`.
Review patch: `/tmp/clipsmatter-stage3-only.patch`. No production code, frontend
assets, migrations, queues, indexed payloads or vectors were changed by Stage 3.
Only normal read-only database/API inspections were performed against production.

Configured production Python host: `web-production-935af.up.railway.app`.
Read-only baseline probes and record inspection: 9 October 2026 (client timezone
Asia/Karachi). Railway API and worker immutable revisions remain unverified.
No isolated staging environment or Railway account/CLI access is available.
Local verification used PHP 8.2.34, PHPUnit 11.5.46, Python unittest, Qdrant client
1.19.1 persistent **local engine**, SQLite and Node 22.16 for the frontend build.
No real model calls, production failure injection or production backfill ran for
new-code validation. Source transcripts are not human audio-verified fixtures.

## Verified root causes and changes

- Transcript search previously accepted word coverage rather than the whole entered
  text. In the Urdu IPP phrase, matching a common component could pass its threshold.
  Dotted and spaced English variants were not canonicalized to IPP. The new versioned
  matcher separates literal, normalized and configured-alias contracts, with word
  boundaries and original-text spans. Query `words` cannot weaken a nonempty query.
- Laravel appended tag-only videos to transcript searches; PHP and React removed
  overlapping passages and treated segment counts as unspecified “matches”. Stage 3
  bypasses that enrichment path, uses Stage 2 Python consolidation, retains source
  identities and overlapping passages, and reports lexical occurrences separately.
- Title search scanned repeated segment payloads and could stop before a later exact
  match. Laravel now scans only ID/title projections in chunks, ranks all eligible
  titles, selects page IDs and hydrates only that page of result-card columns.
  Exact normalized full titles rank above all-term partials. React preserves order.
- The populated-title error is established in source: the UI sends `query`, while
  Python's old title branch requires `title`; Laravel's normalizer set the mode but
  did not populate `title`. Both `query` and `title` are now accepted and normalized
  into the title request contract. Title search now uses the database directly.
- Summary prefilter checked only `video_summary`, omitting translated summary fields.
  Returned summary metadata had empty text and zero timestamps, while the reranker
  actually saw the title. Laravel now checks all three stored summary columns;
  direct Python searches support five legacy/current summary payload names. Summary
  matches only identify candidate videos. Compatible existing query embeddings
  retrieve their transcript passages and Stage 2 validates full-query evidence.
  No summary-only timestamped card is produced. Provider failures remain retryable.
- The player rounded evidence to 15-second blocks and merged nearby clips. Evidence
  clips now retain exact seconds, source IDs and backend order. Clicking an individual
  passage sends that passage to the player; legacy non-evidence clips keep their
  previous contextual behavior. Unicode span rendering uses code points, not UTF-16.
- Search sessions now bind literal case/spacing, contract, mode and filters. Changing
  the contract cannot silently reuse another contract's cached results.

## Final contracts

| UI/API mode | Behavior |
| --- | --- |
| `smart` | Existing Stage 2 semantic retrieval and passage validation; unchanged ranking/model. |
| `transcript_literal` / `transcript_contract=literal` | Case-sensitive original text, preserving entered punctuation and whitespace; word-edge boundaries prevent substrings such as IPP inside shipping. No semantic expansion. |
| `transcript` / `transcript_contract=normalized` | Whole normalized phrase: NFKC/case folding, documented Arabic keyboard yeh/kaf variants, punctuation/whitespace to token separators. Explicit acronym rule folds the standalone tokens I P P (including dotted separators) to IPP. No fuzzy matching or cross-script alias expansion. |
| `transcript_alias` / `transcript_contract=alias` | Normalized phrase plus equivalents in the shared JSON configuration: IPP ↔ آئی پی پی. This is configured equivalence, not literal equivalence. |
| `title` | Stored title only. Exact normalized full title scores 1; partial requires every normalized title term and scores .9. Deterministic ID tie-break. No transcript/tag/filename contribution. Date/language/video filters apply. Filename is displayed separately. |
| `summary` | All normalized query terms must occur in a supported summary field to identify candidate videos. This candidate step is lexical, not an inferred cross-language translation. The complete query is then semantically validated against each returned transcript passage. A summary alone never supplies timestamps. |
| speaker/date/language/video filters | Existing behavior retained outside the new text/title/summary branch. |

The UI explicitly offers all three transcript modes. A non-smart mode is no longer
silently overridden by the Hafiz Naeem smart-search toggle. An explicit API contract
can also be sent with `search_type=transcript`. Literal input is not lowercased by PHP.

Both runtimes use `search_contract_v1.json`. Indexed original text is normalized on
read, so existing payloads remain usable. Original text is never overwritten.
Spans are zero-based Unicode code-point `[start,end)` offsets in the displayed
original passage. Each occurrence carries source ID and original utterance times.
Merged passages retain original source utterance text/IDs and offset-adjusted spans.
Times locate utterances, not individually audio-aligned words. Existing unversioned
legacy timestamps remain subject to the separate Stage 1 audit; no value heuristic
or automatic timestamp repair was introduced.

## Counts, scope and pagination

- `counts.matching_videos`: distinct eligible video identities in the retained result
  set after PHP filters, before page slicing.
- `counts.displayed_passages`: retained timestamped passages across that result set,
  after authoritative Python consolidation. Title-only results contribute zero.
- `counts.matched_transcript_occurrences`: unique lexical spans across retained
  transcript passages; multiple mentions in one utterance count separately. Null for
  title and summary/semantic evidence because those are not lexical occurrence claims.
- `search_metadata.page_counts`: the same three measures for the current page only.
- Per-card `passage_count` and `occurrence_count` use these definitions. Legacy
  `total_segments` / `match_count` remain compatibility aliases for passage counts.

These are transcript occurrences, **not human-verified spoken occurrences**.
Counts apply to the active contract and filters. Text/summary paths retain at most
1000 matching source utterances / 60 summary candidate videos. Candidate reads keep
Stage 2's 35-second / 12-call transport budget, with 10-second per-read caps; scans
also cap at 10000 visited points. Text results keep complete video groups for PHP
page slicing. Scan/result limits, missing indexed summary candidates and capped
summary transcript retrieval mark coverage partial, never exhaustive archive counts.
The UI states that counts cover only retained matches when evaluation is incomplete.
Summary validation retains Stage 2's current 60-candidate, 10-per-batch, 6-call,
30-second budget and explicit retryable failures. No hidden HTTP retries are added.

Stage 3 Laravel requests use page pagination; `use_incremental` does not turn a
literal request into semantic retrieval. Python's direct incremental endpoint also
preserves the requested mode/contract and propagates contract metadata.

## Before / after evidence

Read-only deployed Python baseline, `max_scanned=1000`, `top_k=200`:

| Query / mode | HTTP | Returned rows | Distinct videos |
| --- | --- | --- | --- |
| IPP / text | 200 | 5 | 5 |
| I.P.P. / text | 200 | 0 | 0 |
| I P P / text | 200 | 0 | 0 |
| آئی پی پی / text | 200 | 200 | 116 |
| Full reported title / title | 200 | 1 video-level row | 1 (981) |
| Fuuast / title | 200 | 1 video-level row | 1 (981) |
| elite privileges / summary | 200 | 0 | 0 |

The old Urdu matches were broad component-word results, not verified full-phrase
occurrences. The direct title probes did **not** reproduce wrong ranking today;
PHP request mapping and frontend sorting defects are independently verified in source.
Live database inspection confirms 981 has the reported full title and 734 is stored
as `00089` / `00089.mp4`. Neither record was renamed. The summary case 387 is
reproduced as empty; a read-only audit found no summary column containing both
English substrings `elite` and `privileg`. This does not establish conceptual absence
in Urdu, transcription quality, or the historical failure's precise data cause.

New local matcher on a separate fixed sample of 500 real indexed segments (first
100 from each of five explicitly selected baseline IPP videos):

| Contract | IPP | I.P.P. | I P P | آئی پی پی |
| --- | --- | --- | --- | --- |
| Literal occurrences | 3 | 0 | 0 | 1 |
| Normalized occurrences | 3 | 3 | 3 | 1 |
| Configured alias occurrences | 4 | 4 | 4 | 4 |

Each occurrence has original-text span evidence. The sample and live probes have
**different scopes**; this is not a full-archive before/after count or live Stage 3
result. The QA claims 10/8/9 cannot be replaced with invented expected counts.
Human footage and complete indexed-source coverage remain necessary.

Synthetic after tests demonstrate exact titles after 420 database partials and
1099 repeated indexed partials rank first; `Fuuast` matches relevant titles; `00089`
does not match graduation ceremony. Summary evidence tests return a genuine 8-second
supporting utterance and reject unrelated text despite matching summary metadata.
English, Urdu and legacy field selection, provider failure and page counts are tested.

## Verification

- Python: **81 tests pass**, including Stage 1–2 and 14 Stage 3 contract tests.
- Focused PHP: **35 tests, 180 assertions pass**, 71 MB peak PHPUnit memory.
- Full PHP: **64 tests, 224 assertions; 17 failures, 2 errors**. Preserved-baseline
  isolated run: **55 tests, 153 assertions; the same 17 failures and 2 errors**, with
  identical auth/profile/example failing test identities. No production DB used.
- JavaScript: existing four semantic checks and new Stage 3 Unicode-span, count,
  backend-order, replay, clip identity and exact-seek checks pass.
- Frontend build: passes with Node 22.16; artifact `/tmp/clipsmatter-stage3-build`.
  Existing media omitted from git was copied read-only into the isolated checkout
  for the build. No production build/manifest was published.
- Python compilation and changed PHP syntax checks pass.
- Real Qdrant local-engine integration: 1100 isolated synthetic points, lexical/
  alias counts, title ordering, summary candidate retrieval and oracle evidence.
  Explicit payload backfill dry-run leaves points unchanged; apply preserves vectors
  and original text; incomplete coverage falls back; full coverage visits only three
  matching points and returns nine normalized occurrences. Peak process RSS about
  104 MB including Qdrant engine, fixture storage and the client.
- Synthetic large-transcript benchmark: 2500 utterances across five videos, about
  1930 characters each, three repeated searches; bounded retention yields 1000
  passages / 2000 occurrences with explicit partial coverage. See evidence JSON for
  measured run times (6.18–6.46 seconds) and peak RSS (19.34 MiB). This excludes network/server/model memory and
  does not establish production concurrency capacity.

Evidence artifacts are in `docs/stage3-evidence/`. No full private source transcripts
or credentials are included. Synthetic evidence judgments do not establish actual
model relevance, live latency, browser playback or production integration success.

## Files changed

Python:
- `embeddings_test.py`: simple-contract routing, request/session contracts and new
  document payload representation (same model, vectors and timestamp contract).
- `transcript_search_contract.py` (new): spans, matching, consolidation, bounded
  existing-payload retrieval, summary evidence, normalized-index coverage gate.
- `search_contract_v1.json` (new): shared versioned acronym/alias rules.
- `backfill_transcript_contract.py` (new): targeted payload-field backfill CLI.
- `semantic_passage_evidence.py`: add bounded count transport for coverage probes.
- `.env.example`, Dockerfile: optional indexed path and packaged helper/config/CLI.
- `tests/test_stage_three_contracts.py` (new).

Laravel: `VideoController.php` routes contracts and populated title fields;
`TranscriptSearchContract.php` and `StageThreeSearch.php` are new services;
`tests/Feature/StageThreeSearchTest.php` is new.

React: `SearchPage.jsx`, `SearchedPage.jsx`, `MainVideoPage/index.jsx`;
new `utils/transcriptEvidence.js` and `tests/js/stageThreeEvidence.test.mjs`.

Tools: `tools/integration/run_stage3_local.py`,
`tools/evaluation/benchmark_stage3_transcripts.py`; this report and safe evidence JSON.

## Migrations, payload backfill and configuration — NOT executed in production

No SQL migration. No model replacement, embedding rebuild or retranscription required.
Existing source payloads can be matched on read immediately, with honest bounded
coverage. For efficient archive-wide normalized retrieval, new document writes add:
`search_contract_version=transcript_contract_v1` and `transcript_normalized_v1`.
Original text, speaker labels, timestamps and vectors remain unchanged.

`TRANSCRIPT_CONTRACT_INDEX_V1=false` is the default. After explicit review and payload
index creation, setting it true enables two bounded coverage counts per request.
The indexed prefilter is used **only if every eligible point has the current version**;
otherwise scans remain in use. The optimized filter also admits unversioned points inserted after coverage probes, protecting against a concurrent legacy writer. Literal mode always scans original text: acronym folding would otherwise hide literal searches for a component such as `I` inside `I P P`. Final original-text matching always checks candidates.
Targeted backfills alone do not establish global coverage. Approve an explicit ID
inventory before expanding coverage; do not silently backfill the whole collection.

In an isolated environment with its own configured QDRANT_URL/QDRANT_API_KEY:

```bash
cd 'Clip matter py  embeding'
python backfill_transcript_contract.py --collection stage3_isolated --video-id 1129 --video-id 877 --dry-run
```

The IDs above are explicit inspection examples, not an authorization to repair them.
Use the actual collection name from the reviewed environment. Default is dry-run even
if the flag is omitted. `--max-points 5000` aborts an oversized video before any writes
for that video. Review counts and snapshot payloads first. Pause/drain indexing writers
for approved videos during backfill so stale text is not normalized concurrently.
A deliberately authorized application would replace `--dry-run` with `--apply`;
`--ensure-indexes` additionally creates only the version keyword index and normalized
text index. The tool never deletes points, transcribes or calls embedding providers.
Payload-only application may be partial on a transport failure; rerun the same scope
idempotently after inspection. Version-coverage checks prevent treating incomplete
migration coverage as complete. Runtime rule changes require a new version/backfill.

Stage 1's six missing-index records and 26 timestamp candidates remain separate
recovery inventories. This command does not recover missing points or fix timestamps.
No production recovery commands were executed.

## Staging, deployment and rollback sequence — review only

1. Provision isolated Laravel SQL, Python API/worker, Redis and Qdrant collection.
   Record actual deployed API/worker SHAs and configuration; do not infer them from
   this checkout. Seed approved synthetic / human-reviewed records, never arbitrary
   production failures. Ensure PHP intl and existing embedding model/dimensions agree.
2. Deploy Python API and worker with helper/config files from the same reviewed
   revision; keep the optional normalized-index flag false initially. Exercise all
   three transcript modes, explicit filters, missing provider, real summary provider
   output, response metadata, early 8-second times, empty/partial results and sessions.
3. Deploy Laravel services/controller and built frontend in staging. Refresh optimized
   autoload if used. Confirm title order/populated query/date filters, filenames,
   spans, counts through page 2 and actual YouTube/Dropbox click-to-seek. Check process
   memory under representative transcript sizes and concurrent requests.
4. Dry-run approved payload backfill scopes in staging, snapshot, apply there, create
   the two indexes, enable the flag and verify coverage-gated behavior plus unchanged
   original text/times/vectors. Global exhaustive IPP counts require full eligible
   coverage and human review, not a successful small fixture alone.
5. Production release is **NO-GO pending staging/provider/browser/coverage validation**.
   If later authorized, deploy Python API/worker before Laravel (new PHP explicitly
   rejects old unversioned API responses rather than returning legacy matches), then
   publish the reviewed frontend assets/manifest atomically. Restart Python API/worker;
   follow existing PHP opcode-cache/worker release practices. No Stage 3 migration.
   Record real deployment revisions and retained asset manifest before release.
6. Rollback: revert Laravel/frontend to their recorded pre-release artifacts, then
   Python API/worker together. Disable TRANSCRIPT_CONTRACT_INDEX_V1. Retain additive
   payload fields/indexes initially; older code ignores them. No automatic vector
   deletion, re-embedding or schema-down repair. Payload snapshots allow explicit
   rollback of optional field backfill if later necessary. Existing search sessions
   must restart; new binding deliberately rejects legacy session metadata.

## Remaining uncertainty / reserved for Stages 4–6

- No live Stage 3 deployment, external staging, server-mode Qdrant, actual provider
  relevance/latency or browser-player integration validation is claimed.
- No human audio occurrence counts or full-archive QA ground truth. Sample counts and
  synthetic expected results cannot establish footage accuracy or repair transcription.
- The exact historical cause of QA 387 and the prior incorrect Fuuast UI results
  remains unproven; current probes and verified code gaps are reported separately.
- Legacy malformed indexed timestamps require Stage 1 source-versus-index inspection.
  Whole-video Qdrant replacement remains non-atomic as documented in Stage 1–2.
- Unbackfilled / capped searches remain partial. Full archive normalized coverage,
  throughput, large title catalog scan cost and real-provider summary quality need
  staging measurement before production approval. SQL title scan retains only ID/
  score matching inventory and hydrates one page; it never loads transcript JSON.
- Further playback readiness/media-source issues, editorial metadata quality, broader
  alias curation and archive repair work belong to subsequent reviewed stages.
