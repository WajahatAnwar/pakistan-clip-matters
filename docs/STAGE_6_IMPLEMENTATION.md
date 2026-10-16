# Stage 6 — production reconciliation and remaining closure work

Status: **incomplete**. Laravel changes are deployed; the user will deploy the reviewed Python API/worker update. Human audio and case-specific relevance ground truth remain unavailable. Baseline commit: `26eb0c89073d6b4094fd43999dd3bdebf8021999`; Stage 6 changes are currently uncommitted. File hashes for the Python update are in `stage6-evidence/python-update-sha256.json`.

## Deployed behavior and source scope

Dashboard separates registered videos, completed embedding records, observed index coverage, exact source bytes, and legacy registered storage estimates. GB is decimal; GiB is binary. Generated/proxy bytes are explicitly not measured. Counts overlap. Source listing no longer deletes database records when a Dropbox path is absent; absence requires review.

The fixed inventory was the existing authorized Dropbox integration root `/`, recursive, cutoff **2026-10-16 11:00:58 UTC** (16:00:58 Pakistan time), five pages, exhausted. It observed 467 current files and 184 deleted tombstones; tombstones are not current videos. Current supported video bytes: **649,115,257,963 = 649.115 GB = 604.536 GiB**. Excluded nonvideo bytes: 51,846,610. Eight content-hash groups were flagged, without deleting or merging copies. The reported account-wide 4 TB total is not verified by this integration-root inventory.

Four missing imports were reverified by stable source ID, current revision, size and path and registered as **1169–1172**, pending processing and approval; no transcription jobs were dispatched. These observed revisions do not establish historical transcript provenance. `operations:import-sources` defaults to dry run and accepts at most ten explicit source IDs, requires an exhaustive manifest and an apply ledger, rechecks source metadata and locks each source identity.

Production dashboard verification: **461 registered, 378 completed, 73 failed, 10 pending, 0 processing, 346 transcript records, 340 completed embedding records**. This does not imply 461 searchable videos or human-verified transcripts.

## Index observations and deployment

The database-scoped Qdrant read audit inspected the original 457 records and observed 35,620 points for 340 videos. Collection health reported 36,988 points: the difference, **1,368**, is unattributed, not proven orphan data. Classifications: 315 observed indexes, 25 timestamp-mismatch videos (32 affected points), six missing indexes, 111 with no expected index or transcript. Missing IDs remain **826, 828, 948, 1014, 1073, 1104**. No existing vectors were replaced or deleted.

The old API omits actual point identities, vectors, model/generation metadata and source revisions. Safe repairs remain pending until the new authenticated operations endpoints are deployed and point-level payload/vector backups are taken. Do not broadly re-embed or infer timestamp units from magnitude. The reconstructed transcript-contract module replaces an unusable two-byte `cd` file; it was reconstructed from the versioned contract and tests, not recovered verbatim.

Deploy `storage/app/private/operations/stage6-python-update.tar.gz` into the existing Python build context for **both API and embedding worker**, retaining existing environment settings. Dockerfile now copies `index_operations.py`. `API_KEY` must be configured. Verify authenticated `GET /operations/points?limit=1` returns `index_operations_v1`; unauthenticated access must fail. Snapshot selected points with `with_vectors=true` into private storage before a repair. `POST /operations/patch-timestamps` defaults to dry run, allows 1–5 points, requires exact prior payload hashes and complete valid seconds intervals, shares the worker commit lock, changes no vectors, verifies writes and attempts rollback on failure. Rollback failure is explicitly reported; this is not a transactional whole-video replacement mechanism. Do not run repairs until deployment is verified.

Typesense initially returned 503, blocking its full-archive reconciliation. Later, all five translation-repair documents were individually snapshotted/upserted and verified; two were absent and restored. This verifies those five documents only, not the entire Typesense archive.

## Translation completion and cleanup

Remaining videos **1019, 919, 1105, 1129, 1079** are now repaired: **1,035 source segments**, using the existing authorized OpenAI gpt-4o-mini provider after credits were updated. Every saved translation was checked for nonempty English without Arabic-script characters, identical source metadata except translated text and empty translated word timestamps, unchanged source data, and exact post-save persistence. Concurrent source/translation changes abort the transaction. This is structural validation, not human translation-accuracy certification. All five Typesense documents were verified against the repaired database records. Together with the previously completed 283 videos, the requested 288-video translation repair is complete.

Private rollback files and hashes: `storage/app/private/operations/english-repair-remaining/`. Restore only the saved `transcript_english` value under a transaction after verifying current source/output hashes, then restore or resync the affected Typesense document. Do not overwrite concurrent changes or restore entire video rows blindly.

Cleanup deleted **42 old unreferenced temporary media files, 3,249,535,897 bytes (3.25 GB)**. All were older than seven days; zero processing records and zero waiting/reserved/delayed jobs were observed in the checked queues. Original source files were untouched. Completed segment checkpoints, worker logs and disposable repair scripts were removed. Five database rollback backups, Typesense snapshots and cleanup evidence are retained outside `/tmp`. The earlier Stage 6 `/tmp` staging and audit-backup directories were already absent when the latest request began; they are not available as recovery artifacts. Baseline code remains recoverable from Git, but the earlier full database/index snapshots cannot be claimed retained.

## Verification and honest QA status

Focused PHP regression: **76 tests, 284 assertions**; Python: **96 tests**; local Qdrant transcript-contract integration passed; frontend build passed. The broad PHP run also exposed existing auth/profile scaffold failures (missing `dashboard` route and differing redirects); those are not reported as passing. Automated synthetic checks do not establish real-footage accuracy.

Production browser: dashboard HTTP 200, source card 649.12 GB, no page errors. Twenty sequential title searches all returned 200; latency **132–351 ms**, median **170 ms**, largest payload **226,432 bytes**. Request sizes 100/500 were tested with 50-per-page pagination: representative query had 156 matches, so this does not prove an actual 500-hit result set. The previously failing **زیادتی** query completed: browser 200 / eight videos / 30.6 seconds; separate production-controller execution 200 / nine videos / 29.6 seconds with PHP peak **48,758,784 bytes (46.5 MiB)**. Different semantic outputs were recorded, not claimed stable or human relevant. PHP memory is from the CLI controller harness, not web-worker RSS. Provider-call counts and Railway worker resource limits remain unverified.

`stage6-evidence/qa-500-reassessment.json` preserves all original tracker rows and separately records **2 Pass, 316 Blocked, 182 Not Tested**. QA206 retains the original Roman Urdu query and corrected Urdu fixture; the correction needs human review. Unavailable audio, unexecuted case-specific browser checks and unverified model judgments are not Pass. No newer full tracker contents were invented.

Remaining closure: deploy and verify Python API/worker; take fresh durable point/vector backups; repair the 32 timestamp points in bounded verified batches; safely recover the six missing indexes; complete full Typesense reconciliation and source revision/model/generation checks; measure provider calls/worker resources; execute the remaining original QA cases with identified footage and human ground truth. Stage 6 is not fully fixed or complete.
