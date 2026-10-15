# Index migration release — 2026-10-03 UTC

## Approval and target

The user explicitly authorised commit, push and deployment. The manager stated `main`, `git push origin main`, Voltikka project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment `9245cef8-41d0-486e-862f-193726511dba`, and app service `700d0624-fa96-4266-876c-e37640d220ea`. The Docker entrypoint runs the tracked migration at startup. No direct SSH DDL was run.

## Preflight

- Final reviewed migration changes only the two named nonunique annual-table indexes. It preserves financial/history rows and existing keys. No method switch, recalculation or history rebuild is required by this performance-only change.
- MySQL requires `ALGORITHM=INPLACE, LOCK=NONE`; session metadata lock wait is five seconds, restored in `finally`. A blocked operation fails instead of silently falling back to a table-copy build. The build itself is not time-bounded.
- Combined manager test gate passed: 54 tests, 278 assertions. Focused cases cover repeat/partial migration states, full row/key preservation and session-timeout restoration, including failed DDL.
- Disposable local MySQL 9.4 gate passed, including a real five-second metadata lock failure and safe retry; both MAX queries were optimized away. The temporary container was removed.
- Pint `--test`, migration PHP syntax, task JSON, context mirrors and `git diff --check` passed.
- `npm run build` passed; only the nonblocking stale Browserslist warning remains.
- Read-only production SSH showed 44G free on the MySQL volume (46G total, 2.7G used).
- Pre-release Turku Energia page returned HTTP 200 in 1.035787 seconds. This is not a cold-cache or load test.
- Refreshed origin/main has no conflicting commits. Local main already includes the documentation-only parent commit `97d4c63` after prior production code `6d43357`.

## Release

Commit `f241cc20729de5b8531a3b0d60d9d8a113ea9dac` — `Index annual cost fingerprints to avoid company-page scans` — was pushed with `git push origin main` (`6d43357..f241cc2`). Railway MCP matched that exact hash to deployment `d825120b-e41d-4166-be31-6caaa73fe5d4`, created `2026-10-03T14:36:59.008Z`, initially BUILDING.

The manager started `scripts/railway-poll-deployment.sh` with explicit project/environment/service/deployment IDs, 30-second interval and 900-second timeout. Stable caller/session: `skill:use-railway@1.2.2` / `voltikka-company-index-129678126`. Poll log: `/tmp/pi-bg/job-15810-27.log`.

Exact-deployment polling finished with **SUCCESS**, `stopped=False`. The successful status preceded application readiness. Post-release evidence is recorded locally; do not push a second documentation-only deployment to publish it.

## Production application and database acceptance

- Runtime logs identify the exact migration and report **33s DONE** at `2026-10-03T14:48:59.523915526Z`. Database migration ledger records the exact migration in batch 23.
- Both exact nonunique index definitions are present, in the intended column order. All four v3 MAX queries (date and updated time, canonical and observed basis) now report **Select tables optimized away**. EXPLAIN ANALYZE has only a `Rows fetched before execution` node; the earlier 229,573 actual method-row scans are absent. This node does not measure all optimizer work, so its tiny reported row-delivery time is not claimed as complete server latency.
- Indexed MAX client timings were 222.29–227.79 ms, approximately the same as the 223.40 ms network baseline in that connection. Before index application the same later verification connection measured 936.49–1,364.11 ms, with server scan/aggregate timings 758–1,101 ms. Initial investigation timings were 1,720.94 and 1,797.75 ms. These are bounded observations, not a concurrency or sustained-load benchmark.
- Full twelve-query fingerprint after release: **4,170.91 ms**, versus the earlier **7,428.88 ms**, both over the public proxy. The latter check had roughly 260 ms baseline per round trip. Remaining snapshot/statistics scans still exist; this patch addresses annual scans only. The final company joined-date check was 1,487.70 ms including network, compared with 351.06 ms in the earlier check. The unique lookup plan stayed unchanged; the timing variation is not a reason to claim the whole request is uniformly faster.
- All checked annual date/update maxima remain equal to pre-release values. No writer, history rebuild, method switch or manual cache flush was invoked by the manager. The migration contains only index DDL; no production full-row hash audit was run.
- Final ready company page: **HTTP 200**, **1.497413 seconds**. Initial readiness retry eventually returned 200 in 4.370877 seconds. This is ordinary GET acceptance, not an explicitly cold or concurrent page benchmark.

## Startup readiness issue observed

Initial public checks returned 502 after Railway reported SUCCESS. Read-only app SSH showed PID 1 `docker-entrypoi` and child `chmod` running at 01:56 elapsed, before the migration started. Runtime logs show container start at 14:45:37 and storage-link/migration start at 14:48:27; FrankenPHP, scheduler and queue worker reached RUNNING at 14:49:06. Thus the existing recursive permission step delayed startup before the 33-second index migration. Readiness must continue to be checked over HTTP, not inferred from platform SUCCESS. No startup command, health check, variable, restart or rollback was changed in this release. This separate readiness problem remains outside the index patch.

## Evidence and limits

Transient logs: `/tmp/pi-bg/job-15810-26.log` (commit/push), `job-15810-27.log` (exact deployment), `job-15810-28.log` (early check before application), `job-15810-29.log` (initial 502), `job-15810-30.log` (read-only startup processes), `job-15810-31.log` (bounded readiness retries), `job-15810-32.log` (applied indexes/plans), `job-15810-33.log` (ready page), `job-15810-34.log` (full fingerprint). Durable database verification is copied to `production-verification.ndjson`; the HTTP response bodies remain temporary local files.

The original Sentry timeout is not reproduced or proved absent under future load. The release does prove that the identified annual-table scans are removed in production and that the relevant page serves successfully after startup.
