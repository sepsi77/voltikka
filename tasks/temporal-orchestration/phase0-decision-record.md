# Phase 0 decision record (draft for user approval)

Written 2026-11-07. Status: **approved by the user 2026-11-07** (sections 2–8; open items are
listed at the end). Nothing in production is changed by this record.

## 1. Facts found

- **One container runs everything.** `supervisord.conf` starts FrankenPHP, `schedule:work` and
  `queue:work` in the `voltikka` service (europe-west4, one replica, volume on `/app/storage`).
  Every image deploy stops all three. Seven-day use: CPU average 0.08 vCPU (max 0.2), memory
  average 1.6 GB (max 2.8 GB).
- **The price cache is instance-local.** `CACHE_STORE` is the file store on the
  `/app/storage` volume (`app/Services/Caching/AGENTS.md`). `contracts:warm-cache --pending` and
  `cache:reclaim-expired-files` run per instance on purpose. A Railway volume belongs to one
  service. So a job that writes the price cache cannot move to another service unless the cache
  moves to a shared store. This applies to the minimal alternative and to Temporal.
- **Checkpoints already do the chaining.** `data_freshness_checkpoints` and
  `MorningConsumerExecution` (claims, owner UUID, deadline alert, no takeover) implement
  "wait for prerequisites with a deadline" with five-minute ticks. This works, but it is bespoke
  code per chain, and a deploy still kills a running producer.
- **Temporal Cloud pricing** (docs.temporal.io/cloud/pricing, read 2026-11-07): the Developer
  plan has no base fee; the plan charge is 10 % of usage. Actions cost $50 per million. Active
  storage $0.042/GBh, retained $0.00105/GBh. Billable Actions include workflow start, schedule
  trigger, activity schedule and retry, heartbeat, timer, signal and query.
- **Temporal Cloud regions in Europe:** AWS eu-central-1 (Frankfurt), eu-west-1 (Ireland),
  eu-west-2 (London). GCP europe-west3 is restricted for new namespaces.
- **Self-hosting on Railway:** templates exist (Temporal server + PostgreSQL + UI behind basic
  auth; about 410 MB idle RAM for the single-container variant).
- **PHP SDK:** `temporal/sdk` v2.20 needs PHP 8.2+ and a RoadRunner v3 binary as the worker
  process. Worker Versioning is experimental. There is no official Laravel integration; the
  community package `keepsuit/laravel-temporal` 2.3.2 (2026-08-07) allows Laravel 13 but pins
  `temporal/sdk ~2.17.0`.
- **The S model is Python** (pandas, numpy, scikit-learn in `tasks/own-forecast-model-data/research/`).
  So a Python process is necessary in production for S in all options.

## 2. Minimal alternative compared (spec "Minimal alternative to compare first")

Alternative: move `schedule:work` and `queue:work` to their own Railway service, use Laravel
queue batches for backfills and the existing checkpoint ticks for chaining.

| Requirement | Minimal alternative | Temporal |
|---|---|---|
| 1. Deploy-safe | Web deploys no longer stop jobs. A deploy of the job service still kills a running EEX import (1.7 h) or a backfill, so the deploy window rules stay for that service. Cache producers cannot move (instance-local cache). | A worker deploy stops one activity attempt; Temporal starts it again on the new worker. Long runs are split into units. |
| 2. Event chaining | Five-minute polling ticks plus checkpoints, new claim/deadline code for each chain. | Durable waits, timers for deadlines, signals and child workflows are built in. |
| 3. Short idempotent units | Possible with queued jobs, but a killed job waits `retry_after` (1,050 s). | Native (activity retry policy, heartbeat timeout). |
| 4. Rate limits in one place | Laravel `RateLimited` middleware with a shared cache store. Scheduled commands outside the queue are not covered. | Task-queue rate limit on the server, shared by every workflow and backfill. |
| 5. Backfills | `Bus::batch` gives progress in `job_batches`; no UI. | Workflow per backfill, progress and failed units in the Temporal UI. |
| 7. Observable | Logs and Sentry; stuck ticks are found by reading checkpoints. | Temporal UI shows running, waiting and failed workflows. |

Conclusion: the minimal alternative removes "a web deploy kills jobs", but it keeps deploy
arithmetic for the job service and needs new bespoke wait/deadline code for each chain (S needs
this on the first day: an input fan-in with a deadline). Temporal removes both. Its cost is a
new external service and a second language for workflows (see 4). **Proposal: continue with
Temporal**, and take the process split (section 5) in both cases, because it is necessary for
both.

## 3. Hosting

**Proposal: Temporal Cloud, Developer plan, namespace in AWS eu-central-1 (Frankfurt).**

- Cost estimate. Steady state after phase 2: about 500 Actions per day (hourly spot chain about
  150, morning chain about 50, S with five-minute readiness polls about 150, daily collectors
  about 100) = about 15,000 Actions per month = $0.75, plus 10 % plan charge, plus storage
  under $1 with a 7-day retention. A large backfill of 10,000 units at about 3 Actions per unit
  costs about $1.50. Expected total: **under $5 per month**; budget alarm at $20.
- Self-hosted on Railway: about $5–15 per month for the server and PostgreSQL, and we own
  upgrades, persistence backups, schema migrations and the UI authentication. That is more work
  and not cheaper. Reject unless a no-third-party rule applies.
- Latency Railway europe-west4 → Frankfurt is about 10 ms; not relevant for minute-scale jobs.
- Authentication: a namespace API key in a Railway variable of the worker service only.
- Data sent to Temporal Cloud: workflow inputs and activity results (dates, counts, readiness
  facts, unit IDs). Rule: no API keys, no source payloads, no personal data in workflow inputs or
  results. Data stays in MySQL; activities pass only references.

## 4. Runtime

**Proposal: Python is the only Temporal SDK. Workflows are Python. PHP work runs as activities
that start `php artisan …` as a subprocess in the same container.**

- Reasons:
  1. S needs a Python worker in any case; one SDK is simpler than two (PHP + Python).
  2. The Python SDK is a first-class Temporal SDK (deterministic workflow sandbox, replay testing). The PHP SDK needs RoadRunner, a community Laravel bridge pinned to an older
     SDK version, and a long-lived Laravel process (state can leak between activities, as with
     Octane).
  3. A fresh `php artisan` process per activity has the same semantics as the current scheduler.
     All existing commands, checkpoints, fences and Sentry reporting stay as they are.
- Rules for activities:
  - Each PHP activity calls one artisan command for one unit and reads one JSON line from stdout
    (status and safe facts). The Python side heartbeats while the subprocess runs.
  - Readiness facts are PHP code (Eloquent queries), exposed as one read-only command, for example
    `php artisan orchestration:readiness <fact> --for=<issue time> --json`. The domain logic stays
    in PHP; the workflow only decides when to ask.
  - A command that must not retry automatically today (social publishing, failed morning writers)
    gets `maximum_attempts=1`.
  - The S model activities (predict, train, validate) are Python functions in the same worker.
- Rejected alternatives: PHP SDK for workflows plus a Python worker for models (two SDKs,
  RoadRunner in the image); PHP workflows only (S needs Python anyway).

## 5. Process layout

**Proposal: a new Railway service `voltikka-worker`** in the Voltikka project, from the same GHCR
image tag as the web service, with a different start command (the Temporal Python worker). No
volume, no public domain, no scheduler, no FrankenPHP.

- One image: add Python 3, a venv with `temporalio`, `sentry-sdk`, pandas, numpy, scikit-learn and
  a MySQL driver to the Dockerfile. One tag keeps the "exact source commit" rule for both
  services. The image becomes bigger (estimate +300 MB); accept it.
- The worker entrypoint does not run migrations. Deploy order for a release with a migration: web
  first (it migrates), then the worker. `scripts/deploy-image.sh` gets a service argument in
  phase 1.
- Training runs in the worker service, not in the web container (CPU limit per service).
- Phase 1 activities write only to MySQL (S vintages and readiness reads), so the instance-local
  cache does not matter for the pilot. **Open for phase 2:** activities that write the price
  cache (contract import, warm-cache) either stay on a small Temporal worker in the web container
  (task queue `web-local`; a web deploy then kills one short activity, which is retried) or the
  cache moves to a shared store. Decide at the start of phase 2.

## 6. Worker versioning

**Proposal: patching plus replay tests, no Worker Deployment versioning at the start.**

- Workflows are short (S lives at most from 13:30 to its deadline the same day; the weekly
  refit some hours), so few runs are in flight during a deploy.
- A change to workflow code that affects running workflows uses `workflow.patched()`. CI replays
  saved histories of each workflow type with the new code (Python `Replayer`) before an image
  build.
- If a replay error reaches production anyway, the workflow task fails and Temporal retries it;
  the run is not lost and a fix or a rollback continues it.

## 7. Checkpoints

**Proposal: keep `data_freshness_checkpoints` as the truth that pages and gates read.**
Activities write them through the existing commands. Workflows read readiness through the
readiness command, not the reverse. No workflow query replaces a database read. A later phase can
remove the five-minute tick code of a chain only when that chain is cut over.

## 8. Pilot: the S forecast workflows

### 8.1 `SDailyForecast` (one per Helsinki date D)

- Start: a Temporal Schedule at 13:30 Helsinki starts the workflow for date D (workflow ID
  `s-forecast-D`, so a second start for the same date is rejected). This is not the trigger of
  the model run; it only opens the wait.
- Wait: every 5 minutes one readiness activity checks all inputs. The model run starts at the
  first check where every input is ready, but not before the nominal issue time 14:30 (the
  afternoon-view inputs are defined as of 14:30). In phase 2, when the spot chain is a workflow,
  the spot workflow signals `SDailyForecast` instead of the poll.
- Deadline: if not every input is ready at the deadline, the workflow ends without a forecast and
  sends one Sentry Issue that names the missing inputs. **Proposal: deadline D 18:00 Helsinki**
  (open, user decision; there is no public consumer yet).
- Run: one activity builds both views from append-only vintages with the exact cuts (morning view
  as of D 11:30, afternoon view as of D 14:30), predicts D+2..D+7 with the active validated model
  version and stores one append-only vintage with model version, issue time and the input vintage
  IDs.
- Scoring: a separate `SScoreTarget` workflow, started by the same readiness check when a target
  day's official prices are complete, scores the own vintage and the third-party vintage at
  14:30.

### 8.2 Readiness facts (superseded 2026-11-07)

The draft table below is superseded by the model freeze
(`../own-forecast-model-data/s-model-freeze.md` section 2) and `laravel/config/orchestration.php`.
The FMI, solar GHI, Fingrid 245 and third-party rows are not model inputs; JAO capacity and the
Open-Meteo `ecmwf_00z` run were added. The workflow timeline in 8.1 changed only in its start time
(10:55, for the morning outage fetch before the 11:30 cut).

Original draft:

| Input | Ready when |
|---|---|
| FI day-ahead D+1 | Every Helsinki hour of D+1 (23/24/25 with DST) has an FI row. |
| Neighbour day-ahead D+1 (SE1, SE3, EE, DE-LU) | Every hour of D+1 for each zone used by the model. |
| Fingrid, morning view | Hourly values of each used dataset through D 09:00 (cut "before 10:00"). |
| Fingrid, afternoon view | Same through D 13:00 (cut "before 14:00"). The 10:20 schedule is too early, so the workflow runs one `fingrid:fetch` activity after 14:00 first. |
| ENTSO-E outages and capacity | A successful `entsoe:fetch` finished after D 10:30 (morning view) and one after D 14:00 (afternoon view; the workflow starts it). |
| ECMWF 00 UTC run of D (FMI) | A stored FMI forecast run with model time D 00 UTC for every used point. |
| Open-Meteo solar GHI | A stored vintage of the 00 UTC run of D for every used point. |
| Fingrid 245 vintages | A vintage stored before D 11:30 and one before D 14:30 (the newest such vintage is used, never a newer one). |
| Third-party forecast | A `spot_price_forecast_runs` vintage stored before D 14:30 and after D 08:30. |
| FI futures | EEX FI Base rows with trade date D−1 (or the last trading day before D). |

The age limits in the last three rows are draft values; phase 1 fixes them from the trained
model's assumptions.

### 8.3 `SWeeklyRefit`

- Temporal Schedule, Monday 03:00 Helsinki (outside the morning chain, after the night backup).
- Activities: train, then activate in one database write. **Changed 2026-11-07 by the model
  freeze:** the frozen method has no validation gate; `train` fails unless the refit is
  technically valid (every part fitted, finite p50 for the current issue).
- A failed or rejected refit keeps the active version and sends one Sentry Issue.
- The training activity heartbeats; a deploy during training repeats only the training step.

## 9. Observability

- The Python worker uses `sentry-sdk` with a small Temporal interceptor (Temporal's
  `samples-python` has one; there is no official Sentry integration). Workflow outcomes that need
  a person (missed deadline, failed refit, failed activity after its last retry) send one Sentry
  Issue each. Artisan commands keep their own Sentry reporting; the Python side does not report
  the same command failure a second time.
- Temporal UI (cloud.temporal.io) for running, waiting and failed workflows.

## 10. Phase 1 work list (after approval)

1. Temporal Cloud account and namespace (user creates it; agent never prints the API key).
2. Worker code: `orchestration/` (Python package at the repository root), worker entrypoint,
   replay tests, `orchestration/AGENTS.md`.
3. `orchestration:readiness` artisan command with tests.
4. Dockerfile Python layer; `scripts/deploy-image.sh` service argument.
5. Railway service `voltikka-worker` (production mutation, explicit approval).
6. S workflows with a stub model activity first (readiness and deadline live test), then the
   frozen model when `tasks/own-forecast-model-data/` phase 3/4 delivers it.
7. Two weeks of live runs with no public output; report missed deadlines and readiness timing.

## Approval and open items

Approved 2026-11-07 (user: "Approved"): sections 2–8, including the S deadline D 18:00
Helsinki. The user sets up Temporal Cloud. For shared storage in phase 2, a Railway storage
bucket is permitted (user, 2026-11-07).

The refit gate margin is no longer open: the frozen method has no validation gate (section 8.3).
