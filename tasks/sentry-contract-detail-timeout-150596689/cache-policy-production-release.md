# Cache-policy production release — 2026-10-02 UTC

## Approval and scope

The user explicitly authorised commit and push to deploy. The manager stated `main`, `git push origin main`, Voltikka project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment `9245cef8-41d0-486e-862f-193726511dba`, app service `700d0624-fa96-4266-876c-e37640d220ea`, and the scheduled producer effect before pushing. No separate manual producer, cache flush, configuration switch or history rebuild was run.

## Preflight and Git release

- Full local tests/build/formatting evidence: `cache-policy-final-verification.md`.
- Refreshed `origin/main` matched local HEAD before committing.
- Read-only Railway MCP confirmed source `sepsi77/voltikka` / `main`, one replica, persistent `/app/storage` volume, no staged changes and a successful previous deployment.
- Existing `supervisord.conf` starts `php /app/artisan schedule:work`; this is configuration evidence, not proof that the new scheduled producer executed.
- Staged only intended cache code/tests/task files and cache-specific root/Laravel context hunks. Unrelated annual-history task files and their context hunks remain uncommitted.
- Commit: `6d433571191ae9a9c869aa7d23b2b5c7ce93fe0a` — `Serve retained annual prices and rebuild verified caches in background` (104 files).
- `git push origin main` succeeded: `61b0be0..6d43357 main -> main`.

## Railway verification

Railway MCP matched the exact commit to deployment `e8475044-b6d3-4665-affe-c25509b4e2d6`, created `2026-10-02T01:18:35.438Z`, initially `BUILDING`.

Exact-deployment polling is running through `scripts/railway-poll-deployment.sh` with explicit project/environment/service/deployment IDs, 30-second interval and 900-second timeout. Stable CLI caller/session: `skill:use-railway@1.2.2` / `voltikka-cache-release-20261001`. Log: `/tmp/pi-bg/job-57171-82.log`.

## Terminal status and public acceptance

The exact-deployment poll completed with **SUCCESS**, `stopped=False`. Build/deploy took about 5m20s. Initial public probes returned HTTP 502 while container startup was still in progress. Bounded runtime logs then showed storage/migration/config/route/view setup, automatic startup cache warming, and FrankenPHP/scheduler/queue worker entering RUNNING state. No manual restart, redeploy, rollback, cache flush or producer invocation was performed.

The existing `docker-entrypoint.sh` invokes the normal `contracts:warm-cache` before starting Supervisor. Runtime logs report **contract/company cache version 808 activated**. This proves an automatic startup producer and activation under the released path; it does not identify a subsequent `--pending` scheduler execution. The scheduler process itself is RUNNING.

After startup, public checks passed:

| Route | Status | Observed end-to-end seconds |
|---|---|---|
| `/up` | 200 | 0.589 |
| `/sahkosopimus` | 200 | 0.981 |
| Lapinjarvi city | 200 | 1.178 |
| Mikkeli city | 200 | 0.906 |
| Original Turku contract detail | 200 | 1.400 |
| Contract API index at 5,000 kWh | 200 | 0.389 |
| Active Aalto contract API at 5,000 kWh | 200 | not separately timed |

The active Aalto API returned `current_pricing.availability=available` and finite cached annual total `444.9658454301076`. The first three unfiltered API-index rows were unavailable/null, not zero; index includes historical contracts. The initial acceptance script incorrectly required those first rows to be priced and looked up Retry-After case-sensitively. A targeted active-contract check and case-insensitive HEAD check resolved these verification assumptions; no application change was needed.

Unprepared custom `/sahkosopimus?consumption=7313` GET returned the expected **503**, `Cache-Control: no-store, private`. HEAD confirmed **Retry-After: 30**. No custom profile was manually prepared in production.

Public artifacts: `/tmp/voltikka-cache-release-http/{results,ready-results,supplement}.json`, captured response bodies and `active-api.json`. Logs: `/tmp/pi-bg/job-57171-83.log` (initial startup 502s), `job-57171-84.log` (HTTP checks plus overly strict verification assumptions), `job-57171-85.log` (final active API and cold HEAD pass).

## Remaining limits

Initial readiness must be checked over HTTP: Railway SUCCESS preceded app readiness on this release. No Railway health configuration was changed. These are bounded production smoke checks, not a concurrency/load test, full financial/provenance parity audit or browser custom-action test. Retained serving during a real update, subsequent scheduled pending replacement, browser custom handoff and sustained CPU/timeout improvement are not yet proved. Original incident-level cause correlation remains open.

This follow-up record is local and not part of the release commit. Do not trigger another production deployment merely to publish verification notes.
