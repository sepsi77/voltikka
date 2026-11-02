# Move scheduled pipelines and their orchestration to Temporal

Created 2026-11-02 (user request). Status: planning; nothing decided beyond the goal.

## Goal

Run Voltikka's scheduled data pipelines as Temporal workflows instead of Laravel scheduler
entries that guess each other's timing. A pipeline that finishes either signals the workflows
that wait for it or starts the next workflow itself. A deploy must not kill or lose a run, and
the "deployment arithmetic" (which job runs now, which window is safe) must go away.

## Why (evidence from 2026-11-01/02)

- **One container runs everything.** `supervisord.conf` starts FrankenPHP (web),
  `schedule:work` (scheduler) and `queue:work` in the app container. Every deploy stops all
  three, and Railway never overlaps two deployments of the volume-backed service. So a web-only
  change interrupts data jobs.
- **Clock-based coupling.** `contracts:fetch` 06:00 → `contracts:complete-import` (every minute)
  → `contracts:warm-cache --pending` (every minute) → `retail-premiums:collect --scheduled`
  (five-minute ticks 07:15–12:00) and `forecasting:run-fixed-contracts --scheduled` (07:30–12:00)
  → `forecasting:evaluate-fixed-contracts` 07:45. `futures:fetch-eex` 04:00 (1.5–3.5 h) is a
  second prerequisite of both consumers. The links are freshness checkpoints
  (`data_freshness_checkpoints`) polled by later ticks, not completion events.
- **Deploy kills runs.** 2026-10-02: a 04:18 deploy stopped the EEX run; its checkpoint stayed
  `running` and two trade dates arrived a day late. Root `AGENTS.md` therefore forbids deploys
  04:00–09:00 unless all three morning checkpoints have finished. On 2026-11-02 a deploy had to be
  timed between `contract_import` = `ready` (about 06:20) and the 07:15 consumer start, and
  before a 12:30 manual backfill.
- **Long manual backfills run in the app container** (`/app/storage/logs/backfill-*.log`). On
  2026-11-01 they blocked every deploy for about 10 hours and had to be coordinated by hand
  between agent sessions. Failed units (3 ENTSO-E outage units, 2 weather points) needed manual
  reruns.
- **Shared API rate limits are coordinated by people.** Fingrid, ENTSO-E and Open-Meteo limits
  are shared by schedules, backfills and local checks; the 10,000-call Open-Meteo day limit
  split one backfill over two days.

## Current scheduled work (routes/console.php, 2026-11-02)

| Group | Commands | Trigger today |
|---|---|---|
| Contract import chain | contracts:fetch, contracts:complete-import, contracts:warm-cache --pending, contracts:cleanup-price-cache | 06:00; every minute; every 5 min |
| Morning consumers | futures:fetch-eex, retail-premiums:collect --scheduled, forecasting:run-fixed-contracts --scheduled, forecasting:evaluate-fixed-contracts | 04:00; 5-min ticks 07:15/07:30–12:00; 07:45 |
| Spot | spot:fetch, spot:check-freshness, spot:fetch-neighbours, spot:fetch-forecast, social:publish-daily-spot | hourly :00/:10/:25/:15; every 6 h |
| Market review and own-model inputs | fingrid:fetch, gas-storage:fetch, weather:fetch (+ ec46, seas5, solar_ghi), entsoe:fetch, fmi:fetch (+ forecast), syke:fetch, fingrid:fetch-forecast-vintages, jao:fetch, futures:fetch-eex-europe, nve:fetch-reservoirs | 10:20–12:40 daily, 23:05, hourly :45, weekly, monthly |
| Backups | feedback:purge-text, backup:run --only-db/--only-files, backup:clean, backup:monitor | 02:15–03:45 |
| Other | retail-premiums:calibrate, social:weekly-offers-video, cache:reclaim-expired-files | monthly, weekly, every minute |
| Manual backfills | spot:backfill, fingrid:fetch --from, weather:fetch --from, entsoe/jao/nve/gas history | by hand on the app container |

## Requirements

1. **Deploy-safe.** A web deploy never stops a running pipeline. A worker deploy loses at most
   one short activity attempt, which Temporal retries. No deploy window rules remain for data jobs.
2. **Event chaining, not clocks.** Only the start of a chain is time-based (Temporal Schedule).
   Later steps start when their prerequisites complete (child workflow, signal, or a waiting
   workflow). The morning consumers start when `contract_import` and `eex_futures` are ready for
   the day, with the existing 12:00 deadline kept as a workflow timer.
3. **Short, idempotent activities.** Split long commands into units (one EEX request, one
   Fingrid dataset window, one weather point-year) so a retry repeats one unit, not 3 hours.
   Every activity must be safe to repeat (existing upserts, payload hashes, append-only
   vintages).
4. **Rate limits in one place.** One task queue (or a shared limiter) per external API, so
   scheduled runs, backfills and checks share the Fingrid, ENTSO-E, EEX and Open-Meteo limits
   automatically.
5. **Backfills are workflows.** Resumable, visible progress, per-unit retry, no logs on the app
   volume as the only record.
6. **Keep the existing guarantees.** Freshness rules, completion proofs, checkpoint semantics
   (or their Temporal equivalent), Sentry Issues for failed imports, one-server execution, and
   "no automatic retry" where it exists on purpose (social publishing, failed morning writers
   that need inspection) stay. Each such rule needs an explicit decision before it changes.
7. **Observable.** Failed and stuck workflows are visible (Temporal UI and Sentry) without
   reading container logs.
8. **Simplest working setup.** Temporal is a large new dependency (server, persistence, worker
   runtime). Record why each piece is needed; prefer the managed option if its cost is acceptable.

## Open decisions (phase 0)

- **Hosting:** Temporal Cloud vs. self-hosted Temporal on Railway (server + PostgreSQL/MySQL
  persistence + UI). Cost, operations, backups, network latency from Railway.
- **PHP runtime:** the Temporal PHP SDK (`temporal/sdk`) runs workers under RoadRunner. Check
  Laravel integration (for example a community package), PHP 8.4/FrankenPHP base image fit, and
  whether workflows/activities can call existing services directly instead of `artisan` commands.
- **Process layout:** separate Railway worker service(s) from the web service, so a web deploy
  does not touch workers. Which processes keep the `/app/storage` volume (file cache)?
- **Worker versioning:** how a worker deploy handles in-flight workflows (Temporal worker
  versioning / build IDs, or patching).
- **Checkpoints:** keep `data_freshness_checkpoints` as the public truth that pages and gates
  read, written by activities, or replace the reads with workflow queries.
- **Minimal alternative to compare first:** moving `schedule:work` and `queue:work` to their own
  Railway service already removes "web deploy kills jobs"; Laravel job chains/batches cover some
  chaining. Phase 0 must state what Temporal adds beyond that (durable waits, deploy-safe
  long runs, signals, shared rate limits, backfill visibility) and whether it is worth it.

## Phases

0. Decision record: hosting, runtime, process layout, versioning, checkpoint role, cost; compare
   with the minimal alternative. User approval before any infrastructure.
1. Infrastructure and one pilot: Temporal namespace, worker service, CI/image for the worker,
   Sentry. Pilot: the morning chain (EEX + contract import → retail + forecast → evaluation),
   running in shadow (read-only or dry-run) beside the scheduler.
2. Cut over the morning chain (scheduler entries removed in the same change), then the spot
   chain, then the market-review inputs and own-model inputs (fan-in for the own-model run).
3. Backfills as workflows with per-API rate limits.
4. Remove the deploy-window rules from root `AGENTS.md` and `scripts/deploy-image.sh` once no
   data job runs in the web service; update context files.

## Constraints

- No production infrastructure, variable, deploy or schedule change without explicit user
  approval (root `AGENTS.md` safe-operation rules).
- One pipeline is cut over at a time; the scheduler entry and the workflow never both write.
- The deploy rules in root `AGENTS.md` stay in force until phase 4 is approved and done.

## Out of scope

- Changing what the pipelines calculate (pricing, statistics, forecasts).
- Web request handling and Livewire.
