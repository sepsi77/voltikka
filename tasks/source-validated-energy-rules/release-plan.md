# Release and separate producer activation

## Current release authority

**DEFAULT-V4 DEPLOYMENT VERIFIED — 2026-09-17.** After the user's explicit approval, the manager
committed 246 intended files as `111a101b6e0b3fa64d84b8cb3020237c9eec87b0` and ran
`git push origin main`. Matching deployment `19dc90e6-8d8c-4e54-afcc-56081ef82366` reached
`SUCCESS`, `stopped=false`; MCP confirmed current success. Subsequent bounded live page/API,
health and asset checks passed. See `production-release.md` for exact IDs and evidence.

Initial four page GETs returned 502 during startup cache warming. Startup completed by 02:21:03;
subsequent checks returned 200 without manual intervention. Track the observed startup
readiness/traffic gap as a separate hardening follow-up, not an ongoing release failure.
The bounded log sample does not establish universal error absence. Production retains V4/v19/v17
and Historical V4/v19/v17/parser-v1. V5 activation and other excluded actions remain unapproved.

Live September 17/curve September 16 checks are not a same-date replay of sealed September 16/
curve September 15 evidence. Do not infer all-market, ranking, cache or forecast accuracy.
Final result documents stay local and uncommitted to avoid a second auto-deploy.
The dated records below retain their pre-approval statements as historical evidence; this record
supersedes pending release status only, not the preserved evidence limits or V5 approval gates.

## Historical renewed readiness gate — 2026-09-16

The user authorized local readiness work, not commit/push/deploy or producer activation.
`release-readiness.md` records the completed independent policy-conformity review, fresh sealed
export/hash, strict full-payload replay, Current repairs, asset compatibility and accepted full
synthetic video/template review. Final UI job 228 and network-denied PHP job 235 passed (2812 tests /
21214 assertions / 106.56 seconds); preflight/export smoke pass. The manager accepts **default-V4
technical readiness for user deployment approval**. Job 237 and subsequent manager context/hash
checks all passed; see `release-readiness.md` for exact counts and results. No runtime blockers remain. No V5 producer accuracy
or improved forecast accuracy is established; no full HTTP/live-cache/ranking replay is claimed.

## Prior status and target

Current branch: `main`. **Staged default-V4 release blocked as of 2026-09-16.** The approved
read-only production preflight failed originally. The NULL-reader repair, regressions, sealed
intermediate replay and query diagnosis are now complete locally. Further review proved legacy
no-discount and overlap-end anchor defects; repairs and local regressions are now complete.
Final full-suite/build passed (2773 tests / 18253 assertions; Laravel and Remotion checks passed).
The final same-sealed-database replay resolves broken anchors. Economic amount acceptance remains
pending. The report-only benefit noise correction and 14 smoke cases are complete; see `audit-fixes.md`.
Material pricing deltas and remaining audit
decisions are not accepted. See `audit-fixes.md` for current evidence; `production-preflight.md`
retains the original failed results. This is not only a V5 activation blocker. No deployment, production mutation, reinterpretation, queue change, commit or push was
performed or approved. The read-only preflight does not authorize these actions. The manager's new
combined LOCAL PHP acceptance gate passed after schema 19; see `final-verification.md`. Remotion
ESLint, TypeScript and bundle checks pass. Video repairs and bounded synthetic visual QA are
complete, not general full-video/feed/phone/compression verification. Local acceptance is not production acceptance or permission
to deploy. Earlier bounded tests remain historical evidence.

Use Git-only deployment. After explicit confirmation, `git push origin main` triggers production.
Do not use a direct Railway upload, restart, redeploy or rollback as a substitute.

Explicit production target (verify these IDs before any separately authorized operation):

- Project: `6d8cae01-1006-409f-8108-1d51f1abc676`
- Environment: `9245cef8-41d0-486e-862f-193726511dba`
- Service: `700d0624-fa96-4266-876c-e37640d220ea`

## Completed default-V4 release checklist

- [x] Independent 81-total/37-anchor/39-rate-fee review; manager accepts 27 numeric changes as policy-conformant, not better forecasts.
- [x] Fresh separate read-only export and verified SHA; production/defaults and original snapshot unchanged.
- [x] All 375 candidate full payloads pass strict public transport in all four phases; report gate exits 0. Fresh candidate lists 366, with 29 changed total/states including Kerava.
- [x] Current estimator, short-duration, privacy, ambiguous-mechanism and truthful public-copy repairs; Historical preserved.
- [x] Final UI job 228; old CSS retention; full synthetic video/template and transition review accepted within explicit limits. No posting approval.
- [x] Final network-denied full PHP gate after the fixture repair: 2812 tests / 21214 assertions; preflight/export smoke pass.
- [x] Manager accepts default-V4 code as technically ready for user deployment approval, with no runtime blockers.
- [x] Final manager Pint (job 237), context/mirror and snapshot/loaded-source-hash checks all pass; no technical conditions remain.
- [x] Receive explicit user approval to commit the reviewed changes on `main`, run `git push origin main` for normal Git auto-deploy, and perform read-only deployment/live checks.
- [x] Manager performed the approved Git release; exact matching deployment reached SUCCESS. `production-release.md` records SHA, deployment ID/status, initial startup 502s, subsequent successful live checks and evidence limits.

## Historical staged default-V4 checklist — superseded status

The unchecked amount/estimator/video review items below describe the earlier state. Use the
current checklist and `release-readiness.md`, not those historical pending statements.

- [x] Approved fresh production export and isolated baseline/candidate comparison completed.
  **Release gate FAILED**; see `production-preflight.md` for scope, evidence and diagnostic correction.
- [x] Repair SQL NULL success handling locally; retain fail-closed malformed/nonempty errors and
  test the normal writer representation.
- [x] Complete the intermediate three-consumption replay and identify further anchor defects.
- [x] Repair legacy inactive-discount and covered overlap-end boundaries; pass local regressions.
- [x] Diagnose SQLite query statistics and verify original nonunique indexes plus ANALYZE.
  These timings are not production latency measurements.
- [x] Record final combined full-suite/build and new sealed three-consumption replay.
- [x] Verify report-only benefit cent comparison: 14 smoke cases, raw fields retained.
  Corrected report: `audit-fixes-reviewed-comparison.json`; two benefit transport changes.
- [ ] Obtain renewed manager release acceptance after these checks.

- [x] Manager's fresh combined local PHP gate after schema 19: 2754 tests / 17909 assertions,
  Laravel production build and Pint on all 122 changed/untracked PHP files passed.
- [x] Remotion locked toolchain verification: user-approved `npm ci --no-audit --no-fund`
  installed 389 packages; `npm run lint` (ESLint and TypeScript) and `npm run build` passed.
  Package manifests are unchanged. npm blocked esbuild's optional postinstall; no script approval
  was needed. The earlier offline failure remains historical evidence in `video-output-check.md`.
- [x] Complete flash/Hybrid disclosure repairs and bounded synthetic 12-PNG visual checks.
  Full video/feed/phone/compression limits remain; see `video-visual-review.md`.
- [x] Verify the neutral short-term ContractDetail qualifier: 183 tests / 788 assertions.
- [ ] Accept remaining premium/unknown-restart amounts economically and resolve remaining audit
  decisions before renewed default-V4 release acceptance. Include Supplier/Reset hold-beta, floor,
  vintage metadata and guard review; SourceEnergyRule floor flags alone do not close this review.
- [ ] Review the complete uncommitted tree and exact release scope; preserve unrelated changes.
- [ ] Obtain separate permission for commit and `push origin main`. Record the exact approved SHA.
- [ ] After the authorized push, poll deployment status on the explicit target above. Match the
  deployment's source SHA to the approved commit; an unrelated successful deployment is not proof.
  Record deployment ID, SHA, terminal status and bounded build/runtime errors. Stop on failure or
  timeout; retries and production mutations need their own approval.
- [ ] Run separately authorized live checks against that exact deployment: list/detail/company and
  offer pages, consumed contract API payloads, typed estimate/benefit distinctions, public source
  privacy, assets and health. Review bounded errors and cache regeneration/performance. Record
  URLs, checks and results; do not label these completed from local tests.

Schema 19 safely regenerates calculated-cost caches. It does not rewrite statistics/history,
change annual-v2 or beta, or rebuild historical evidence. No historical rebuild belongs to release.

## Producer activation is NOT part of deploying this tree

The hardcoded current default stays V4. Deploying this staged tree does not start V5 production.
A later, separately approved activation change must switch **all five current profile keys** in
`laravel/config/contract_interpretation.php` together:

| Current key | Approved future target, not applied here |
| --- | --- |
| `schema_version` | `schema-v5` |
| `prompt_version` | `prompt-v20` |
| `validator_version` | `validator-v18` |
| `schema_path` | `resource_path('contract-interpretation/schema-v5.json')` |
| `prompt_path` | `resource_path('contract-interpretation/system-prompt-v20.md')` |

Parser identity remains v1. Historical config, V4/V19 assets and original hashes stay pinned.
No new CLI option, feature flag or schedule is needed. Reinterpretation needs separate explicit
approval; profile selection alone is not approval for provider calls or production data changes.

### Historical producer-readiness note — before user deployment approval

The original September 16 preflight failed and remains historical evidence. The fresh repaired
comparison, strict transport and full PHP gates pass. The manager accepts bounded default-V4
technical readiness with all static/context/hash checks passed. User deployment approval remains required.
There are no V5 production records, so prospective producer and price coverage are unmeasured.
A separately approved V5 pilot, activation and reanalysis need fresh tests and their own release
gate, including source coverage, exclusions, price/rank/offer changes and queue/provider scope.
Live application verification is not complete.

## Recovery and rollback limits

Once V5 publications or jobs exist, do not blindly deploy older code that cannot understand V5
or calculated-cost schema 19. Select and test a compatible recovery build first.

A compatible default-V4 build can stop NEW V5 production. It deliberately retains valid V5
publications for unchanged sources, so this is **not** a rollback of their prices. Review pending
V5 jobs separately; do not assume a default switch cancels them.

Any data restoration, republication or queue mutation requires explicit approval and reviewed
backup/recovery steps before execution. Preserve immutable source evidence and historical rows.
No automatic downgrade, historical rebuild or data repair is included in this plan.
