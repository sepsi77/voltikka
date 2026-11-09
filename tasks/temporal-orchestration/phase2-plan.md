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
| `Redis` (new) | Shared Laravel cache and locks. `maxmemory-policy noeviction` (PR #11 review: an LRU policy can remove an active lock, because locks have a TTL; a full Redis must fail writes visibly). |

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
a durable claim, lease or lock (the contract import and its deferred completion, morning
consumers, social posts, the price-cache warm) finish within the drain (`GRACEFUL_SHUTDOWN` 65 min, longer than every
claim-based step timeout; Railway draining seconds 4000), because a killed claim cannot be resumed.

## Groups and order of cut-over

| Group | Jobs | Needs Redis |
|---|---|---|
| A. Collectors | fingrid, gas storage, weather (+ ec46, seas5, solar_ghi, ecmwf_00z, prev_day1), entsoe, fmi (+ forecast), syke, fingrid 245 vintages, jao, eex-europe, nve | no |
| B. Maintenance | DB backup → clean → monitor, feedback purge, calibration, weekly offers video, price-cache warm/cleanup | yes (cache jobs) |
| C. Spot | spot hourly chain, neighbours, freshness check, third-party forecast | yes |
| D. Morning | `MorningChain` (EEX, contract import, consumers, evaluation) | yes |

## Production runbook (each step needs explicit user approval)

**Target changed (user decision 2026-11-09):** phase 2 goes live only on the planned VPS
(`tasks/vps-migration/`), never on Railway. PR #9 merged the code with the collectors already out
of `console.php`; the follow-up PR restored them, so `main` matches Railway production and a Railway
deploy stays safe. The steps below stay valid as the order of the cut-over, with Railway service
names replaced by their VPS counterparts in the VPS plan. R1 (collectors) is the first VPS cut-over.

Revised after the spec review of 2026-11-08 (PLT-001, PRC-001, PLT-006): each group leaves
`laravel/routes/console.php` in its own release, and no cache-writing job (queue, maintenance,
spot, morning) leaves the web container before every service uses the shared Redis store.

- **R1 (this branch): image + collectors.** The image has phpredis, the workflows, the queue
  entrypoint mode (not used yet) and `console.php` without the collector group only; the web still
  runs the queue worker and every other schedule. Worker variables: add references to every
  application variable of `voltikka` and `RAILWAY_DEPLOYMENT_DRAINING_SECONDS=4000` (longer than
  the 65-minute worker drain; check the Railway maximum before R3, when the first claim-based jobs
  move; if it is lower, keep a deploy guard for live claims until then). Deploy worker, then web; then `schedules --apply --group collectors`.
  Collectors write no cache. Timing (PR #9 review): the web deploy removes the collector entries,
  so apply the Schedules at once after the web deployment is `SUCCESS`, never before it (the old
  container would still run them). Do it between 16:00 and 16:40 Helsinki: after the hourly :45
  Fingrid vintage fetch and outside the 09:15–15:30 collector times. Recovery: a trigger missed
  in the gap runs once with `temporal schedule trigger --schedule-id <id>` (a missed hourly
  vintage is lost; it is a forecast snapshot); if the apply fails and cannot be fixed at once,
  deploy the previous web image (its collector entries return) and keep the Schedules unapplied.
- **R2 Redis.** Railway Redis service in europe-west4 with a volume (a restart must not empty the
  shared price cache: web GET reads are cache-only and return 503 until the next warm), `maxmemory`
  about 1 GB, `maxmemory-policy noeviction`, private network only. `REDIS_URL` references and
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
  import, completion, both lanes, evaluation. Before the PR: the real PHP + Temporal + database
  morning test, which also measures the `contracts:fetch` time (its step timeout is 60 min, inside
  the 65-minute drain).
- **R5 Phase 4.** After two good mornings, remove the deploy window from `scripts/deploy-image.sh`
  and root `AGENTS.md`.

Rollback of a group: pause its Schedules and let their running workflows end, then deploy the
previous web image (its scheduler entries return). Rollback of R2 after R3, in this order: pause
the maintenance/spot/morning Schedules, let their running workflows end, deploy the R2-era web
image (scheduler and queue worker return), stop `voltikka-queue`, and only then set
`CACHE_STORE=file`. A paused group never runs in both schedulers, and a file store with writers
outside the web container would keep stale prices.
