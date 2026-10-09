# Stage 4 — local speaker identity safeguards

Status: implemented in `/tmp/clipsmatter-stage4` only. No production deployment, migration, audio transfer, or data repair was performed. Existing Stage 1–3 and translation/search changes were preserved. Identification model was not replaced.

## Verified defects

Repository inspection confirmed that `AssemblyAiService::identifySpeakers` supplied a shared hardcoded fallback voiceprint for missing enrollment, under different identity labels. It also used an unexplained threshold of 50. `SpeakerTaggingService` used nearby-time fallback and global speaker-label voting to fill unsupported turns; it could confuse the provider match label with a numeric confidence. Sample replacement could retain stale biometric enrollment and delete the old file before validating the replacement. Callback output was trusted without authenticated provider retrieval.

These defects can cause false identities. They do not establish the cause of the reported Hafiz Naeem → Elon Musk incident: QA tests 431–470 were untested and that incident was not reproduced with verified audio.

## Changes

- `SpeakerIdentityPolicy`: explicit enrollment, duplicate fingerprint review flags, model/revision/quality gates, per-source decisions, competing-identity margin, conservative Unknown, typed raw scores, interval validation and union duration.
- `SpeakerTaggingService`: removes nearest-time and voting propagation, preserves source labels and explicit manual corrections, separate recognized identity, clears unsupported automated names, retains formatted transcript API.
- `AssemblyAiService`: removes hardcoded biometric/key fallbacks, explicit model, authenticated callback verification, registered job enrollment snapshots, current job binding, versioned normalized results, TLS verification.
- `VoiceSample` and admin controller: hidden voiceprints, replacement invalidation, provider-verified callbacks, duplicate callback idempotence, human review required before enrollment, maximum 30-second uploads.
- `TranscriptSegmentMapper`: only current accepted or explicitly manual identities enter the speaker field; Unknown still indexes with distinct source speaker labels.
- `ProcessVideoJob`: passes video context, supports successful Unknown output, clears automated transcript attribution when identification is skipped/failed, preserves indexing without identity.
- `speakers:enrollment` and `speakers:recover`: explicit selected IDs, dry run by default; recovery uses existing audio and timestamps, updates source and translated attribution, optionally queues index refresh.
- Migration `2026_10_09_000004_add_speaker_identity_contract.php`: enrollment provenance/quality metadata and job snapshot table. Existing samples start unreviewed.
- New configuration and example environment keys; behavioral unit and provider failure tests.

## Enrollment and scores

The actual provider is pyannote.ai. Its voiceprint is an opaque Base64 value, not a documented numeric vector. Validate strict Base64 structure and nonempty content, model association, operator revision, provenance, and human quality review. No numeric dimension was invented. Structural validity alone does not prove biometric validity. Hash decoded content for cross-identity duplicate detection; do not print the raw content, merge identities, or delete samples. More than 50 eligible samples fails closed rather than silently truncating the identity set.

Model remains `precision-2`, pinned explicitly to the existing provider default. The provider does not expose an immutable weight revision here. `PYANNOTE_VOICEPRINT_REVISION` is operator provenance, not proof of immutable provider weights. An incompatible revision excludes enrollment; changing provenance requires review/re-enrollment.

Existing upload minimum 15 seconds is retained and maximum reduced to provider-supported 30 seconds. Enrollment requires reviewed single speaker and usable speech; reviewed silence, excessive noise, music, overlap or clipping excludes it. Sample rate must be recorded in review. Duration is probed by the existing upload pipeline. Other quality fields are human review attestations, not newly implemented signal detectors; automatic VAD/SNR/clipping measurement remains unavailable.

Provider confidence is 0–100, stored as `provider_confidence_0_100`; it is not a calibrated probability. Different samples of one identity are grouped before choosing the runner-up identity. Acceptance requires two scored distinct enrolled identities, provider agreement with the winning sample, threshold, margin and minimum diarized speech. One-identity enrollment returns Unknown because separation cannot be established. Duration is the union of provider diarized intervals, not an independent usable-speech measurement. Explicit unusable-audio flags and cross-speaker overlap reject a turn. The provider may not supply quality flags, so undetected noisy query audio remains an evaluation gap.

`SPEAKER_IDENTIFICATION_THRESHOLD`, `SPEAKER_IDENTIFICATION_MARGIN`, and `SPEAKER_IDENTIFICATION_MIN_SPEECH_SECONDS` default to unset. This deliberately disables automatic identity acceptance until calibration. Test values 75/15/3 are synthetic fixtures, not production recommendations. Temporal mapping requires 80% overlap with exactly one provider turn/source; this is a provisional geometric coverage rule, not a speaker score. AssemblyAI input timestamps stay milliseconds and provider timestamps seconds. Interruptions/multiple competing turns remain Unknown rather than borrowing an adjacent identity.

Explicit `identity_source=manual` corrections take precedence over automation. Unversioned names have no reliable manual-versus-automatic provenance; they require review and cannot be grandfathered into trusted indexing. Manual tags are preserved. No identity is inferred from text, title, tags, or introductions.

Primary provider references: [identify API](https://docs.pyannote.ai/api-reference/identify), [voiceprint API](https://docs.pyannote.ai/api-reference/voiceprint).

## Verification and evidence gaps

Focused PHP suite: **60 tests, 230 assertions passed**. Python regression suite: **88 tests passed**. New tests use synthetic opaque voiceprints and mocked provider responses; they cover missing/malformed/incompatible/duplicate enrollment, quality flags, supported versus ambiguous scores, unregistered identities, short/overlapping/silent speech, distinct Unknown labels, no nearest-time/majority transfer, explicit manual precedence, idempotent tagging, provider connection/read failure, unregistered completed jobs and stale callbacks. Existing Stage 1 indexing and Stage 2–3 search tests remain in the passing focused suite.

Full isolated PHP suite: 85 tests, 269 assertions, 17 failures and 2 errors in the auth/profile scaffold tests (including missing dashboard route and isolated view setup). This is not a globally green suite; these failures match the previously recorded baseline pattern. No production DB was used by these tests.

No real consented, human-verified multilingual speaker evaluation was available. False acceptance and false rejection rates are **unmeasured**. There is no evidence supporting a model replacement or a claim of zero real-world false matches. Staging evaluation must separately report false acceptances / known-impostor trials and false rejections / enrolled-speaker trials, plus Unknown rates, stratified by language, duration, recording quality and overlap. Include Hafiz Naeem Urdu/English/mixed speech, announcers, interviews, unseen speakers, music and silence. Calibrate on a development set and report held-out results before enabling thresholds.

## Targeted review/recovery

These are instructions only; no apply command was executed.

```
php artisan speakers:enrollment --id=123 --dry-run
php artisan speakers:enrollment --id=123 --review-file=/secure/review.json --dry-run
php artisan speakers:enrollment --id=123 --review-file=/secure/review.json --apply
php artisan speakers:recover --id=1168 --dry-run
php artisan speakers:recover --id=1168 --audio-url=https://authorized-existing-audio-url --apply --refresh-index
```

Review JSON is keyed by sample ID and supplies identity_key, voiceprint_model, voiceprint_revision, and quality_review with single_speaker, usable_speech, silence, noise, music, overlap, clipping and sample_rate_hz. Review flags must reflect listening/inspection of the actual sample, not guessed defaults. Commands require positive selected IDs; apply plus dry-run is rejected. Do not place signed audio URLs in shared logs or shell history. Apply sends audio to the existing provider and writes selected records; obtain appropriate authorization before executing. Back up selected video/sample records and derived Qdrant points first. Recovery aborts on concurrent source transcript edits and provider failures. No broad retranscription occurs.

## Staging, rollout and rollback

1. Apply the review patch to a clean staging copy with the existing Stage 1–3 changes. Back up DB and selected Qdrant points. Review the patch privately: deletion hunks contain formerly hardcoded sensitive material.
2. Run migration against staging; configure provider key and operator revision. Keep acceptance criteria unset initially. Audit selected enrollment; review samples before approval.
3. Run focused tests, HTTP callback/replacement/retry flows and real-audio evaluation. Validate Unknown display with distinct source labels, transcript timestamps and speaker-filtered retrieval after targeted reindexing. Calibrate criteria only after held-out false-acceptance/false-rejection analysis.
4. Drain identification workers before switching versions. Old jobs without current registered enrollment cannot establish names. Deploy matching Laravel code/config/migration together; restart workers and clear/rebuild config caches. No Python Stage 4 change is required; sanitized speaker values reach the existing indexer. Python general relationship validation changes from the earlier task remain separately pending Railway deployment.
5. Re-identify only reviewed selected videos. Inspect source/translation mappings, then refresh selected indexes and verify eligibility/search. Stage 1 replacement index atomicity is a pre-existing limitation; do not assume bulk replacement is transactional.
6. Roll back by draining workers, restoring prior application/config and selected backed-up records/index points. Leave additive migration columns/table in place initially; drop them only after old-version compatibility and backup verification. Never restore the insecure fallback merely to regain identity coverage.

## Remaining limitations

Historical summaries, titles, manual tags and already-indexed names have no attribution provenance. Targeted transcript identity recovery does not rewrite natural-language summaries or manual tags: review and regenerate affected summaries from corrected source before treating metadata/summary search as repaired. Existing Qdrant payloads remain stale until selected refresh succeeds. Historical display fallbacks can still expose old stored mappings until recovery/review; the new formatted transcript path shows Unknown and the original label. This patch does not claim historical-data cleanup.

Additional staging work is required before production rollout: real-audio calibration, query-quality telemetry, end-to-end replacement/callback tests against the provider, and review of provenance-free historical speaker-derived summaries. The provider model's private dimensions/weight version remain unavailable. Precision-first rejection may increase Unknown results and lose recall for transcripts spanning several diarization turns. These limitations are explicit rather than hidden by invented thresholds or identity propagation.
