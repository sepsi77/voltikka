# Phase 2 plan: every scheduled job moves to the Temporal worker side

Written 2026-11-08. User request: move all data fetch pipelines and their orchestration to
Temporal, so that fetching does not depend on the web application and no deploy window remains.
User decisions (2026-11-08, all four recommended options):

1. **Shared cache store: Railway Redis.** `CACHE_STORE=redis` for web, worker and queue. Reason:
   fetch commands write the Laravel cache (price-cache generations and demand, spot header,
   statistics, forecasts). The file store is on the web volume, and a Railway volume belongs to one
   service, so a worker cannot write it. Redis also makes the web start fast: the start-up warm
   finds the shared payloads.
2. **Queue worker: separate Railway service `voltikka-queue`** (same image, `queue:work`). The
   LLM interpretation jobs that each import dispatches then survive web and worker deploys.
3. **All scheduled jobs move to Temporal Schedules, except volume jobs.** The web scheduler keeps
   only the jobs that need the web volume: the weekly file backup (`storage/app/public`) and the
   company logo download (logos are on the public disk; the worker import passes `--skip-logos`).
4. **Direct cut-over, one group at a time.** One release adds the Temporal Schedules of a group and
   removes its `routes/console.php` entries; the two never both write. Rollback: pause the
   Schedules and deploy the earlier image.

## Target layout

| Service | Runs |
|---|---|
| `voltikka` (web) | FrankenPHP, `schedule:work` with two volume jobs. No data job, no queue. |
| `voltikka-worker` | Temporal Python worker; every scheduled job as a `php artisan` activity. |
| `voltikka-queue` (new) | `php artisan queue:work --queue=default,historical-interpretation --timeout=1020 --tries=3 --max-time=3600` |
| `Redis` (new) | Shared Laravel cache and locks. `maxmemory-policy volatile-lru`: forever price payloads are never evicted; TTL entries can be. |

Worker and queue services must reference **every application variable** of the web service
(not `PORT`, not Railway-generated names). The pricing flags (`CANONICAL_PRICING_ENABLED`,
`RESET_FORWARD_SHIFT_ENABLED`, `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION`,
`CONTRACT_INTERPRETATION_*`) are part of the cache keys and the calculations: a service with a
different value would write wrong or orphaned prices into the shared cache.

## Workflows

- `ArtisanJob`: generic. Input: a list of steps (`php artisan` argv, timeout, `always` flag). Steps
  run in order; a failed step stops later steps except `always` steps. Used for every simple job.
- `MorningChain` (one per Helsinki date, Schedule 04:00): child `EexFutures` and child
  `ContractImport` run at the same time; when both have ended (success or failure), the workflow
  ticks the two `--scheduled` consumers every 5 minutes until both checkpoints are terminal, and
  runs one tick at or after 12:00 for the existing deadline alert. Event chaining replaces the
  07:15/07:30 start times; the commands keep their own freshness gate, claims and alerts.
- `ContractImport` (child): waits until 06:00, `contracts:fetch --skip-logos`, then
  `contracts:complete-import` every minute while the checkpoint is `pending_completion` (at most
  2 h 10 min, the command's own bound is 2 h), then `forecasting:evaluate-fixed-contracts`.
- `EexFutures` (child): `futures:fetch-eex` as one heartbeating activity (timeout 6 h).
- `SpotHourly` = `ArtisanJob` steps `spot:fetch` → `social:publish-daily-spot` (`always`).

## Retry rule (requirement 6)

`run_command` raises `CommandFailed` when the command exits non-zero. Every job policy lists
`CommandFailed` as non-retryable: a command that failed is not run again automatically (same as
the scheduler today). Only a lost attempt (worker restart, heartbeat timeout) is retried. That is
the deploy safety: a worker deploy repeats the interrupted attempt, never a completed or failed
one. Exceptions with an explicit retry are listed in `jobs.py`.

## Groups and order of cut-over

| Group | Jobs | Needs Redis |
|---|---|---|
| A. Collectors | fingrid, gas storage, weather (+ ec46, seas5, solar_ghi, ecmwf_00z, prev_day1), entsoe, fmi (+ forecast), syke, fingrid 245 vintages, jao, eex-europe, nve | no |
| B. Maintenance | DB backup → clean → monitor, feedback purge, calibration, weekly offers video, price-cache warm/cleanup | yes (cache jobs) |
| C. Spot | spot hourly chain, neighbours, freshness check, third-party forecast | yes |
| D. Morning | `MorningChain` (EEX, contract import, consumers, evaluation) | yes |

## Production runbook (each step needs explicit user approval)

The working tree holds the final state of `laravel/routes/console.php`. At commit time it is split
into one commit per release below, so each release removes only its own group.

- **R0 Services.** Railway Redis service in europe-west4 with a volume (a restart must not empty the
  shared price cache: web GET reads are cache-only and would return 503 until the next warm),
  `maxmemory` about 1 GB, `maxmemory-policy volatile-lru`, private network only. Service
  `voltikka-queue` (same image and registry credential as the worker, start command
  `/usr/local/bin/docker-worker-entrypoint.sh queue`, restart ALWAYS, no volume, no domain). Worker
  and queue reference every application variable of `voltikka` (see `orchestration/AGENTS.md`).
  Set `QUEUE_SERVICE_ID` in `scripts/deploy-image.sh`.
- **R1 Image + collectors.** Commit 1: phpredis, `jobs.py`/`schedules.py`, readiness `morning`,
  queue entrypoint mode, supervisord without the queue worker, `console.php` without the collector
  group. Deploy order: queue → worker → web (two queue workers on the database queue are safe:
  MySQL `SKIP LOCKED`), then `schedules --apply --group collectors`.
- **R2 Redis.** `REDIS_URL` references and `CACHE_STORE=redis` on `voltikka` (worker and queue
  reference it). All three services restart; the web start-up `contracts:warm-cache` fills Redis
  before FrankenPHP starts. Check `/up`, the listing and a contract page, and the worker's next
  `contracts:warm-cache --pending`.
- **R3 Maintenance + spot.** Commit 2: `console.php` without those groups and without
  `cache:reclaim-expired-files`. Deploy web, then `--group maintenance --group spot`.
- **R4 Morning.** Commit 3: `console.php` with only the volume jobs. Deploy web outside 04:00–12:00
  (the old window still applies until this release), then `--group morning`. Check the next
  morning: EEX, import, completion, consumers, evaluation.
- **R5 Phase 4.** After two good mornings, remove the deploy window from `scripts/deploy-image.sh`
  and root `AGENTS.md`.

Rollback of a group: pause its Schedules and deploy the previous web image (its scheduler entries
return). Rollback of R2: set `CACHE_STORE=file` on `voltikka` (the web volume still holds the old
file cache).

## What stays and why

- `cache:reclaim-expired-files` is file-store only (no-op on Redis): its per-instance schedule is
  removed; the command stays for local file stores.
- The S workflows are unchanged.
- Readiness facts stay PHP (`orchestration:readiness morning --date=D` reads the four morning
  checkpoint statuses).
- Splitting `futures:fetch-eex` into per-request units (requirement 3) is not part of this step:
  a worker deploy during the run repeats the whole run (about 1.7 h) but loses no data, and the
  consumers wait for it. Do it only if worker deploys in the morning become a real problem.
