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
  `ContractImport` run at the same time. Each consumer has its own lane: it waits until both
  children have ended (or noon), then ticks its `--scheduled` command every 5 minutes until the
  scheduled scope is complete (`scheduled_complete`, not a manual subset `ready`). At or after
  12:00 every incomplete lane runs one tick (also a failed claim or while a producer still runs)
  for the existing deadline alert. An incomplete morning sends one Issue and fails. Event chaining
  replaces the 07:15/07:30 start times (the PHP lower clock bound is removed); the commands keep
  their own freshness gate, claims and alerts.
- `ContractImport` (child): waits until 06:00, `contracts:fetch --skip-logos`, then
  `contracts:complete-import` every minute while the checkpoint is `pending_completion` (at most
  2 h 10 min, the command's own bound is 2 h), then `forecasting:evaluate-fixed-contracts`.
- `EexFutures` (child): `futures:fetch-eex` as one heartbeating activity (timeout 6 h).
- `SpotHourly` = `ArtisanJob` steps `spot:fetch` → `social:publish-daily-spot` (`always`).

## Retry rule (requirement 6)

`run_command` raises `CommandFailed` when the command exits non-zero. Every job policy lists
`CommandFailed` as non-retryable: a command that failed is not run again automatically (same as
the scheduler today). Only a lost attempt (worker restart, heartbeat timeout) is retried. Deploy
safety: on a worker deploy, interruptible commands stop and repeat on the new worker; commands with
a durable claim or lease (deferred import completion, morning consumers, social posts, the
price-cache warm) finish within the drain (`GRACEFUL_SHUTDOWN` 38 min, Railway draining seconds
2400), because a killed claim cannot be resumed.

## Groups and order of cut-over

| Group | Jobs | Needs Redis |
|---|---|---|
| A. Collectors | fingrid, gas storage, weather (+ ec46, seas5, solar_ghi, ecmwf_00z, prev_day1), entsoe, fmi (+ forecast), syke, fingrid 245 vintages, jao, eex-europe, nve | no |
| B. Maintenance | DB backup → clean → monitor, feedback purge, calibration, weekly offers video, price-cache warm/cleanup | yes (cache jobs) |
| C. Spot | spot hourly chain, neighbours, freshness check, third-party forecast | yes |
| D. Morning | `MorningChain` (EEX, contract import, consumers, evaluation) | yes |

## Production runbook (each step needs explicit user approval)

Revised after the spec review of 2026-11-08 (PLT-001, PRC-001, PLT-006): each group leaves
`laravel/routes/console.php` in its own release, and no cache-writing job (queue, maintenance,
spot, morning) leaves the web container before every service uses the shared Redis store.

- **R1 (this branch): image + collectors.** The image has phpredis, the workflows, the queue
  entrypoint mode (not used yet) and `console.php` without the collector group only; the web still
  runs the queue worker and every other schedule. Worker variables: add references to every
  application variable of `voltikka` and `RAILWAY_DEPLOYMENT_DRAINING_SECONDS=2400` (check the
  Railway maximum). Deploy worker, then web; then `schedules --apply --group collectors`.
  Collectors write no cache.
- **R2 Redis.** Railway Redis service in europe-west4 with a volume (a restart must not empty the
  shared price cache: web GET reads are cache-only and return 503 until the next warm), `maxmemory`
  about 1 GB, `maxmemory-policy volatile-lru`, private network only. `REDIS_URL` references and
  `CACHE_STORE=redis` on `voltikka` and the worker. All writers still run in the web container, so
  this changes only the store; the web start-up `contracts:warm-cache` fills Redis before FrankenPHP
  starts. Check `/up`, the listing, a contract page, and the next per-instance warm.
- **R3 (own PR): queue + maintenance + spot.** Service `voltikka-queue` (same image and registry
  credential, start command `/usr/local/bin/docker-worker-entrypoint.sh queue`, restart ALWAYS, no
  volume, no domain, all application variables incl. `REDIS_URL`/`CACHE_STORE`); set
  `QUEUE_SERVICE_ID` in `scripts/deploy-image.sh`. The PR removes the queue worker from
  `supervisord.conf` and the maintenance and spot groups (and `cache:reclaim-expired-files`) from
  `console.php`. Start the queue service first (two queue workers on the database queue are safe:
  MySQL `SKIP LOCKED`), then deploy web, then `--group maintenance --group spot`.
- **R4 (own PR): morning.** The PR removes the morning chain from `console.php` and adds
  `logos:download` (the worker import uses `--skip-logos`). Deploy web outside 04:00–12:00 (the old
  window still applies until this release), then `--group morning`. Check the next morning: EEX,
  import, completion, both lanes, evaluation.
- **R5 Phase 4.** After two good mornings, remove the deploy window from `scripts/deploy-image.sh`
  and root `AGENTS.md`.

Rollback of a group: pause its Schedules, then deploy the previous web image (its scheduler
entries return). Rollback of R2 after R3: first return the cache writers to the web (deploy the
R2-era web image, pause the maintenance/spot/morning Schedules, stop `voltikka-queue`), then set
`CACHE_STORE=file`. A file store with writers outside the web container would keep stale prices.
