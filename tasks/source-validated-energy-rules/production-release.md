# Production release — VERIFIED, 2026-09-17

## Approval and scope

The user replied **“Approved”** to the manager's request to commit the reviewed changes on
`main` and run **`git push origin main`**. This approves the normal Git auto-deploy to
**Voltikka / production / voltikka**, including changes to live comparison prices. Read-only
deployment status, bounded logs and live application checks are included.

**DEFAULT-V4 DEPLOYMENT VERIFIED.** The manager committed the 246 intended files and pushed
`main`. The exact matching Railway deployment reached `SUCCESS`; subsequent bounded live checks
passed after the startup gap recorded below. This record is the current release authority.
Earlier pending/blocked records remain historical. All evidence and V5 limits remain in force.

## Exact production target

| Resource | Name | ID |
| --- | --- | --- |
| Project | Voltikka | `6d8cae01-1006-409f-8108-1d51f1abc676` |
| Environment | production | `9245cef8-41d0-486e-862f-193726511dba` |
| Service | voltikka | `700d0624-fa96-4266-876c-e37640d220ea` |

Historical deployment before this release (removed normally after replacement):

- Deployed SHA: `917de212fdc4ef4862903268c3d33ba5cd886e73`.
- Deployment ID: `13bfd4fa-c06f-4a27-b7a7-7e935823d903`.
- Recorded status: `SUCCESS`.

## Excluded actions

This approval does **not** authorize:

- V5 producer activation, five-key profile changes, pilot or reinterpretation/provider runs.
- Manual imports, manual cache flushes, history writes or historical rebuilds.
- Active SQLite replacement, data repair, restoration or queue changes.
- Direct Railway uploads, redeploys, restarts or rollbacks.
- Actual video posting.

Default V4 and Historical limits remain in force. These excluded actions need separate explicit
approval. On deployment failure or timeout, read bounded evidence and stop; do not restart,
redeploy or roll back without new approval.

## Accepted final technical gates

These are manager-supplied results, not new runs by the documentation executor:

- PHP: **2812 tests / 21214 assertions passed** (job 235).
- JavaScript: **12 tests / 0 failures** (job 228).
- Laravel production build and Remotion ESLint/TypeScript/build: **passed**.
- Pint: **140 changed/new Laravel PHP files passed**, plus all five preflight PHP files
  (job 237).
- Preflight/export smoke and final whitespace/context/hash checks: **passed**.

See `release-readiness.md` for logs, exact counts and evidence limits. Local checks do not prove
live HTTP, cache or ranking behavior. Policy conformity is not demonstrated forecast improvement.

## Verified manager execution record

| Item | Result |
| --- | --- |
| Release commit SHA | `111a101b6e0b3fa64d84b8cb3020237c9eec87b0` (246 intended files) |
| `git push origin main` | Completed after explicit user approval |
| Deployment ID | `19dc90e6-8d8c-4e54-afcc-56081ef82366` |
| Created | `2026-09-17T02:14:59.314Z` |
| Deployment source SHA | Matches the release commit above |
| Exact terminal status | `SUCCESS`, `stopped=false`; MCP confirmed current `SUCCESS` |

The manager ran `scripts/railway-poll-deployment.sh` with the exact project/environment/service/
deployment IDs above and `--interval 30 --timeout 900`. Evidence:
`/tmp/pi-bg/job-15523-246.log`. The old deployment was removed normally.

### Startup gap and recovery

The first four page GETs returned **502** while the existing startup cache warming ran. Do not
interpret Railway `SUCCESS` alone as application readiness. Logs show warming at **02:19:56**;
by **02:21:03**, warming was complete and Supervisor/server/worker/scheduler were `RUNNING`.
Subsequent `/up` and all checks below returned **200**. No manual restart, cache flush, redeploy
or rollback was used. The bounded **45 deployment log lines** showed startup completion and no
application error in that sample; this is not proof that all logs contain no errors.

**Separate hardening follow-up:** investigate the observed startup readiness/traffic gap. It is
not an ongoing failure in the final checks, and no infrastructure change is approved here.
Health evidence: `/tmp/voltikka-release-health-startup.json`.

### Live HTTP and pricing checks

All paths below are on `https://voltikka.fi` and returned **HTTP 200**:

- `/up`, `/`, `/sahkosopimus`, `/sahkosopimus/sahkotarjous`.
- `/sahkosopimus/sahkoyhtiot/aanekosken-energia-oy`. The initial mistyped slug without `-oy`
  returned 404; the corrected valid URL passed. This was not a product failure.
- `/sahkosopimus/sopimus/aqjvba-vaasan-sahko-myynti-oy-kiintea-6-kk-yosahko?kulutus=5000`.
- `/sahkosopimus/sopimus/bdfd4u-keravan-energia-oy-perusfiksu-24kk?kulutus=5000`.
- The four contract API URLs recorded in `summary.json`, for `aqjvba`, `bdfd4u`, `xam9ev`
  and `v7juwr`, each with `consumption=5000`.
- `/build/manifest.json` and all four manifest assets listed below.

Vaasa six-month annualized total: **868.2728315412186 = 2 × 434.1364157706093 EUR**.
Real-term benefit is **5.90 EUR**, annual-equivalent benefit **11.80 EUR**. The detail page shows
**“Vuositasolle laskettu vertailuhinta”**. Kerava `bdfd4u` is unavailable with
`ambiguous_energy_mechanisms`; Hehku `xam9ev` is unavailable with `insufficient_promotion_terms`.
`v7juwr` is available with `supplier_adjusted_forward_premium`, total **797.3225527777776 EUR**,
January 21 anchor, and lower-confidence market premium with controlled aggregate provenance.
All **four raw live calculated_cost payloads** passed local schema-19 hydration with network denied.

The live window is **September 17**, with **September 16** curve data. The sealed replay uses
**September 16**, with **September 15** curve data. This is **not a same-date financial replay**.
No exact replay/live match, all-market verification, rank correctness, cache accuracy or improved
forecast accuracy is claimed.

### Runtime profile and assets

Read-only SSH used pure PHP to read only whitelisted cached configuration, without Laravel boot.
Current producer is enabled at **V4/v19/v17**, with the correct V4 schema and V19 prompt paths.
Historical remains **V4/v19/v17/parser-v1**; canonical pricing is enabled. No V5 activation,
provider run, history write, manual import or video post was performed for this release.

The production manifest uses the exact prior **`app-BE-AUgaZ.css`**, not the local build's
`app-CXFUrNKw.css`. HTTP 200; **110597 bytes**; SHA-256
`e27279f29da59bdfc18093c71ee05e6d08d237bd673e9b618a58f4f9e5335528`.
The manifest HTTP SHA-256 matches the runtime file:
`44a19475ee552c7e184aa08c11ed8816722b4775e1e0d58dccde5a60e1f27af9`.
The other three manifest assets also returned 200: `app-Pr_ebGQi.js`,
`contract-price-statistics-BqTUOKJh.css`, and `contract-price-statistics-C235penD.js`.

Local evidence: `/tmp/voltikka-release-live-checks/{summary.json,pages-assets.json,runtime-profile.json,response-{0..6}.txt}`.
These are manager execution results; this documentation unit made no network or production calls.
Final result documents stay **local and uncommitted** to avoid an unnecessary second auto-deploy.
No code change, test/build rerun, staging, commit or push is part of this documentation unit.
