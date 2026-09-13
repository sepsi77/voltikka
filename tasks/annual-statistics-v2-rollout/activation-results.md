# Public v2 activation — 2026-09-12

## Approved operation and deployment

The user explicitly approved public activation. A fresh current-data check passed before the change; the deployed code remained `f6518498c2b7598c87bd79e62855c18afba63c16`.

Railway MCP `railway_set_variables` set only `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v2`, with `skip_deploys=false`, on Voltikka / production / voltikka:

- Project `6d8cae01-1006-409f-8108-1d51f1abc676`
- Environment `9245cef8-41d0-486e-862f-193726511dba`
- Service `700d0624-fa96-4266-876c-e37640d220ea`

Deployment **`bcb5a9e7-2b88-4841-a97c-08708d990eb9`**, created at 16:24:53 UTC, reached **SUCCESS**. The exact-deployment poll was job `job-55742-174`. No code push, manual annual recalculation, or rollback was issued.

## Passed checks

- Effective public method is v2 in cached Laravel configuration on that exact deployment. Code SHA and schema 17/c1r1 match.
- Post-activation protected fingerprints match the original baseline. Historical v2 still has 213,755 annual rows and 7,618 aggregates; retained v1, historical non-v2 statistics, snapshots, and price components are unchanged.
- Current September 12 has 789 rows / 30 aggregates. Snapshot values, source IDs, current observation/publication pointers, and all provenance checks pass.
- Statistics and consumption-calculator readers load v2 annual rows only and select September 12.
- `CompanyMarketComparisonService` selects September 12 `current_canonical` data for **Helen Oy**.
- A process-local, read-only v1 switchback selects retained September 11 data. Public configuration stays v2. Seller-set index and Spot-margin payloads are identical under the two annual-method selections.
- Combined read-only reader checks use 102.5 MiB under a 128 MiB limit.
- Public statistics, consumption calculator, and the actual company URL `/sahkosopimus/sahkoyhtiot/helen-oy` return HTTP 200.

Two initial company diagnostics used an incorrect column (`slug`, instead of `name_slug`) and then an incorrect assumed slug (`helen`). These were diagnostic errors, not application regressions. The final diagnostic obtains the real company record and passes.

## Public CSV failure found after activation — now resolved

The public CSV starts with HTTP 200 but stops during July 28 data and appends a 500 HTML page. It contains only 6,281 active v2 aggregates instead of the expected 7,648 (historical plus current).

A bounded application-log read confirms the cause at 16:33:19 UTC:

`Maximum execution time of 30 seconds exceeded` at `Illuminate/Database/Connection.php:412`.

The audit CSV exports every version, including shadow rows. The added history increases its load independently of the activation flag; a v1 switchback would not remove that load. Its existing `chunk(500)` uses repeated sorted OFFSET queries over the enlarged table. Executor `9e03d71b-2276-416` completed a local fix in `tasks/contract-statistics-csv-streaming-fix/`: preserve schema, ordering, all versions, and active markers while using one memory-bounded sorted cursor. Parent reviewed it and completed a guarded read-only MySQL benchmark: all 28,819 rows / 7,648 active aggregates in 14.424 seconds, 36.5 MiB peak, within the unchanged 30-second limit. All 23,500 complete old CSV rows match the candidate prefix field for field. Buffer restoration and a later query pass. Full suite passed 2,285 tests / 10,862 assertions; production asset build passed. See that task's `decisions.md` and `/tmp/annual-v2-csv-candidate-benchmark.json`.

The user separately approved the Git release. Commit **`722582c71a32fbaa4eb329b98b69d08539c9a74d`** was pushed with `git push origin main`. Exact deployment **`6ce82497-7275-4402-babd-dcbad23127e1`** reached **SUCCESS**. No timeout change, method switch, historical recalculation, or rollback was issued.

Final public HTTP verification passed: **28,819 CSV rows in 6.811 seconds**, including **7,648 active v2 aggregates** through September 12. Every CSV field matches the complete reviewed candidate. Statistics, consumption, and Helen Oy pages return 200; all four referenced CSS/JS assets return 200 and match the production manifest, including entry CSS dependencies.

The exact new deployment has cached public v2 and passing current/source-provenance, statistics, consumption, and company reader checks. Process-local v1 switchback retains September 11; unit/index payloads remain unchanged. Final protected hashes at **20:04:53 Helsinki** exactly match the original baseline; historical v2 remains 213,755 rows / 7,618 aggregates.

**The rollout and final public-export verification are complete.** Post-release result records are local changes; no second documentation-only push was issued.

## Evidence

- `/tmp/annual-v2-activation-preflight.log`
- `/tmp/pi-bg/job-55742-174.log`
- `/tmp/annual-v2-activated-fingerprints.php`, `.json`, `.log` (verified 19:27:46 Helsinki)
- `/tmp/annual-v2-activated-readers-final.php`, `.log`, and `/tmp/annual-v2-activated-readers.json`
- `/tmp/annual-v2-active-public-statistics.html`, `-consumption.html`, `-company.html`
- `/tmp/annual-v2-active-public-csv.csv` (incomplete; includes error HTML)
- Activation session: `voltikka-annual-v2-activation-20260912-1`
- CSV release session: `voltikka-csv-release-20260912-1`
- Deployment poll: `/tmp/pi-bg/job-55742-194.log`
- Public verification: `/tmp/annual-v2-csv-release-public.json`, `-public-export.csv`, `-manifest.json`
- Read-only verification: `/tmp/annual-v2-csv-release-readers.json`, `-fingerprints.json`, corresponding PHP/log files
- Verification runner: `/tmp/annual-v2-csv-release-verify.py`; successful jobs `job-55742-196` and `job-55742-197`
