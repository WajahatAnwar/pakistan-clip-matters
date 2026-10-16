# Stage 5 — production playback reliability

Deployed 16 October 2026 (Asia/Karachi), release `stage5-20261016`. Base checkout revision `eaaed2f6f908756ae2276bd91f080032f1558889` plus preserved prior working-tree changes. This is a file-manifest release, not a new Git commit. Exact deployed file and asset-manifest SHA256 values are in `docs/stage5-evidence/release.json`.

## Baseline and verified causes

Inspected Stage 1–4 reports, production configuration, actual routes, frontend players, Dropbox integration and worker processes. The historical reports describe earlier isolated snapshots and do not prove current Railway revisions. Production is `/home/1618941.cloudwaysapps.com/ptfmnnpjhn/public_html`; frontend assets are served from `public/build`; Laravel uses database cache and Redis queues. Existing Cloudways workers for this application use high/default queues and 60-second timeouts. They were not restarted or reconfigured. Railway API/worker immutable revisions remain unavailable and were not changed by Stage 5.

Verified code defects:

- Native media error events initiated unlimited temporary-link requests, without preserving current time or tracking the failed link generation.
- Component-wide link state was reused without a database-video identity check. Late detail/link responses could mutate the next selected video's state.
- Playback fallback destroyed one player without a consistent pending seek or ready-state contract. YouTube player instances lacked reliable cleanup.
- Initial search seeking guessed readiness with a one-second timer. Legacy search clips rounded passage boundaries to 15-second blocks and merged adjacent results.
- `/videoLink` accepted arbitrary Dropbox paths without authentication and could resolve them using privileged integration credentials. Provider response bodies and client temporary URLs could be logged.

These defects are established by inspection and regression checks. QA failures 201/212 were not reproduced with their original video IDs; those IDs were unavailable. Representative production footage loaded successfully during this stage.

## Implementation

Changed production files:

- `resources/js/components/ReliableVideoPlayer.jsx` (new): native/YouTube lifecycle, bounded readiness and request timeouts, actual metadata events, provider cleanup, explicit loading/refreshing/ready/playing/failed display and retry action.
- `resources/js/utils/playbackSession.js` (new): deterministic valid-YouTube-first selection, Dropbox fallback for the same database video, one automatic native-link renewal, no provider bouncing, pending seek/position preservation, stale-response invalidation and autoplay-denial handling.
- `resources/js/Pages/UserSide/MainVideoPage/index.jsx`: integrates the unified player, aborts obsolete detail requests, clears old video/provider state, queues seeks instead of guessing readiness, preserves exact passage boundaries and IDs. Source transcript timestamps remain explicitly milliseconds; search passage timestamps remain seconds.
- `resources/js/Pages/UserSide/SearchedPage.jsx`: binds stored search passages to their database video ID to prevent cross-video reuse.
- `app/Http/Controllers/VideoController.php`: validates registered media identity and viewer eligibility; caches by viewer, video owner, database ID and path hash; serializes refresh requests and coalesces retries using a link generation ID; returns safe missing/access/provider failure messages.
- `app/Services/DropboxService.php`: HTTPS verification and request timeouts for temporary-link calls, optional typed failure reporting, no raw provider bodies or signed URLs in this path's logs.
- `routes/web.php`: authenticated, throttled temporary-media endpoint. No arbitrary external path can be resolved using admin credentials. Approved/completed/unarchived footage is accessible to authenticated viewers; owners and admin roles can access their permitted records.
- `tests/Feature/PlaybackMediaTest.php` and `tests/js/playbackSession.test.mjs` (new).

Server cache lifetime is a conservative three-hour application policy, not a claim that every provider URL remains valid for that exact duration. Client renewal is bounded to one automatic attempt per playback/retry session; an explicit user Retry starts a new bounded session. Simultaneous refreshes of the same failed generation share the new cached result. Responses are private/no-store. Refresh and provider fallback retain the pending passage or latest position; timestamp zero and fractional seconds are supported. Invalid/negative/out-of-duration seeks are reported rather than clamped to a different passage. Autoplay restrictions do not trigger fallback.

No proxy, CORS, codec, database schema, model, or archive-transcoding change was made. Old hashed frontend assets were retained for already-open pages; the new manifest was published last. Relevant official provider reference: [YouTube IFrame API](https://developers.google.com/youtube/iframe_api_reference).

## Automated verification

- Isolated Node 22.12 production build passed. System Node 20.5.1 was too old for the existing Vite version; the build used the already-installed Node 22 binary without changing the system runtime.
- 71 focused PHP tests / 268 assertions passed against isolated SQLite memory, including Stage 1–4 checks and 9 new media endpoint checks.
- 15 playback-state tests passed: primary/fallback/both unavailable, renewal success/failure, bounded concurrent retries, pending/zero/fractional/invalid seeks, same-video intent changes, stale responses, preserved position, autoplay restrictions, authorization, media identity, cleanup and YouTube URL validation.
- Existing JavaScript semantic-result and Stage 3 evidence checks passed.
- Existing Python discovery remains blocked by the pre-existing `transcript_search_contract.py` containing only `cd`: 78 discovered tests, 2 import errors. Stage 5 did not edit Python or deploy Railway. The earlier working-tree validation-error changes remain preserved and separate.

Mocked provider/state tests do not establish real YouTube embedding availability or model accuracy.

## Production delivery and browser checks

Evidence: `docs/stage5-evidence/` (no credentials or signed URLs).

| Video | Intended seek | Observed browser position | Actual media duration |
|---|---:|---:|---:|
| 1168 | 0 | 0 | 1522.84 seconds |
| 863 | 368.569 | 368.569 | 890.2 seconds |
| 1105 | 707.687 | 707.687 | 2118.84 seconds |

All three Dropbox media responses were HTTP 206, `video/mp4`, `Accept-Ranges: bytes`, with valid `Content-Range` and MP4 file headers. Delivery probes read only 1 KB per file; browser tests streamed normally rather than downloading the archive.

Authenticated Chromium tests with an isolated temporary viewer account verified initial seeks above, advancing playback on 1168, actual server link renewal returning 200, preservation of playback position, stable cached link generation, and guest denial (401). Additional browser checks verified a same-video transcript click at 15.757 seconds, same-URL/new-generation renewal, visible errors for negative and out-of-duration timestamps, and repeated navigation ending with the correct video identity. No page errors were reported in the primary smoke run. The account and its sessions were removed after testing.

Browser tooling and missing runtime libraries were installed/unpacked only under `/tmp`; no system package or server configuration changes were made. No populated YouTube URLs were found in the production record probe, so real YouTube playback/fallback remains unverified; that branch has mocked regression coverage.

## Deployment and rollback

Production source and built assets were published directly under the user's explicit Stage 5 authorization. Route/view caches were cleared. No migrations or service restarts were required; processing workers and translation-repair processes were preserved.

Recoverable checkpoint: `/tmp/clipsmatter-stage5-backup`. It contains affected pre-deployment source files, the prior complete asset build, baseline metadata and a rollback script. Run as the application owner:

```
bash /tmp/clipsmatter-stage5-backup/rollback.sh
```

The script restores only Stage 5's affected source files and prior asset manifest, removes newly added Stage 5 runtime/test files, and clears route/view caches. It preserves the speaker-query fix present before Stage 5 and does not reset unrelated Git work or database/customer records. Leave old hashed assets in place for open clients. Stage-5-only source patch: `/tmp/clipsmatter-stage5-only.patch`. No material regression requiring rollback was observed.

## Remaining issues / Stage 6

- Real YouTube embedding restrictions and dual-provider content equivalence require actual authorized YouTube-linked footage; no such populated record was available for the production probe.
- Unsupported codecs/containers receive an error/fallback, not automatic transcoding. Assess individual affected assets before changing media infrastructure.
- The broader transcript-details route authorization and legacy clip-download/FFmpeg workflows warrant a separate audit; Stage 5 did not redesign those flows.
- Restore the corrupted Python transcript-contract module before deploying any unrelated Python search changes.
- Historical speaker calibration and translation/content accuracy remain separate from playback reliability.

Concurrent translation work is separate: 283/288 repairs are saved and verified. The remaining videos 1019, 919, 1105, 1129 and 1079 have 431 segments remaining in total. A direct provider check returned HTTP 429, error type `insufficient_quota`, code `credit_balance_exhausted`; those five cannot finish until available API credit is restored. Their existing production translations were not overwritten with partial results.
