# Move scheduled pipelines and their orchestration to Temporal

Created 2026-11-02 (user request). Status: phase 0 approved 2026-11-07 (`phase0-decision-record.md`); phase 1 live; phase 2 decided and implemented locally 2026-11-08 (`phase2-plan.md`), production rollout pending approval.

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

## Forecast workflows (added 2026-11-06)

Three kinds of forecast work need orchestration. The own models are not in production yet
(`tasks/own-forecast-model-data/`, phase 4 needs user approval). Plan them as Temporal workflows
from the start, so they never get a clock-based scheduler version.

**1. Own short-term spot model S (hourly, 7 days ahead).**
- **Trigger: an event, not a clock.** The planned issue is right after the FI day-ahead result for
  D+1 is stored (SDAC result about 13:45 Helsinki; nominal issue time 14:30). Decided after
  E32 (`tasks/own-forecast-model-data/decisions.md`, 2026-11-06): one run per day; it forecasts
  D+2..D+7 (D+1 is the published prices). D+2 uses the afternoon inputs (prices through D+1,
  Fingrid before 14:00, outage plans and the third-party vintage at 14:30); D+3..D+7 use the
  morning inputs (the vintages as of D 11:30, Fingrid before 10:00). Both input sets must be ready
  before the run. The workflow starts when `spot:fetch` has stored all FI hours of D+1, not at a
  fixed time: on some days the auction result is late (decoupling).
- **Fan-in of inputs before the run.** Each input must be fresh for the issue time (strict as-of
  rule). Today the collectors run at fixed times that were set for other uses:
  - day-ahead prices FI, SE1, SE3, EE, DE-LU through D+1 (`spot:fetch` hourly,
    `spot:fetch-neighbours` hourly at :25);
  - Fingrid hourly data up to shortly before the issue (`fingrid:fetch` runs only at 10:20, so an
    afternoon issue needs one more fetch first);
  - ENTSO-E outage plans and border capacity at the issue time (`entsoe:fetch` 10:50);
  - the 00 UTC ECMWF run of D from FMI (`fmi:fetch` 11:05; the run is public at about 08:00 UTC),
    Open-Meteo neighbour wind/solar (`weather:fetch --only=solar_ghi` 11:05) and Fingrid 245
    vintages (hourly at :45);
  - FI futures with trade date ≤ D−1 (`futures:fetch-eex` 04:00).
  **Rule (user decision 2026-11-06): the model runs only when every input is ready.** The
  orchestration layer owns this check, not the model code. The workflow waits for each input (or
  starts its fetch), and it starts the model run only when all inputs are ready for this issue.
  There is no run with a missing input, with an empty value, or with an older vintage in place of
  the expected one. "Ready" must be defined per input as an exact, checkable fact (for example:
  all FI hours of D+1 stored; the 00 UTC ECMWF run of D stored for every point; Fingrid data up to
  the cut; an outage-plan fetch after a given time). If an input is not ready at the deadline,
  that issue gets no forecast, and a Sentry Issue names the input. Open: the deadline per run
  (how long a late forecast is still useful) and the exact readiness fact per input.
- **Weekly refit.** E30 showed that a model refitted each week is clearly better than a monthly
  or quarterly refit (HOLD MAE 25.7 against 27.0 €/MWh for the third-party forecast). So
  production needs a second workflow: weekly training on the full history, a validation gate
  (for example, no worse than the last model on the last weeks), and an atomic switch to the new
  model version. The daily run uses the last validated version. A failed refit keeps the old
  model and raises a Sentry Issue. Training is CPU-heavy (gradient-boosted quantile models), so
  it must not run in the web container.
- **Python runtime.** The models are Python (scikit-learn). Temporal can run a Python worker on
  its own task queue beside the PHP workers, so the PHP side can orchestrate and the Python worker
  can train and predict. This is one option for the open runtime decision of the own-model task
  (`tasks/own-forecast-model-data/spec.md`, "Runtime"). Decide both together in phase 0.
- **Storage and scoring.** Every run stores an append-only vintage with the model version, issue
  time and the input vintages that it used. A scoring workflow scores each target day when its
  official prices arrive (the same event as the trigger), against the third-party forecast too.

**2. Own medium-term model M (monthly means, 3 months).** Weekly issue (proposal Monday 11:00
Helsinki, after `weather:fetch --only=ecmwf_ec46` on Monday 10:50) and on the first working day
of the month for the monthly review. Inputs: EC46, SEAS5 (fetched on the 8th), NVE reservoirs
(Wednesday), Syke hydrology, gas storage, EEX futures. It is light (no long training) but has the
same fan-in rules as S, including "run only when every input is ready". Scoring starts when a target month is complete.

**3. Existing forecasts.**
- The fixed-term contract forecast (`forecasting:run-fixed-contracts`, evaluation 07:45) is
  already part of the morning chain above; no change to its method.
- The third-party feed `spot:fetch-forecast` (every 6 h) stays a simple scheduled fetch. It is
  the baseline for S, so S scoring needs its vintage at the S issue time; keep it append-only
  (`spot_price_forecast_runs`).

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
   Sentry. Pilot (user decision 2026-11-06): the own S forecast workflow (section "Forecast
   workflows", item 1): the event trigger after the D+1 result, the input fan-in, the weekly
   refit and the Python worker. S is new, so no scheduler entry moves, and its first weeks are a
   live test with no public output. The work is done in this task; the own-model task delivers
   the frozen model and the code that the workflow calls.
2. The morning chain (EEX + contract import → retail + forecast → evaluation) in shadow
   (read-only or dry-run) beside the scheduler, then its cut over (scheduler entries removed in
   the same change), then the spot chain, then the market-review inputs and the M workflows.
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
