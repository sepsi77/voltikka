# Temporal orchestration (Python worker)

Temporal workflows and the worker for Voltikka data pipelines. Task, decisions and status:
`../tasks/temporal-orchestration/` (read `phase0-decision-record.md`, then `phase2-plan.md`).
Status 2026-11-16: production runs on the Breezily VPS since 2026-11-15 (`../tasks/vps-migration/`):
Temporal Cloud namespace `voltikka-prod-vps.lw6f1` (AWS eu-central-1) and the Kamal role `worker`
in `../config/deploy.yml`; see "Image and service" below. Applied groups: `s-forecast` (S pilot) and
`collectors` (since 2026-11-09); `maintenance`, `spot` and `morning` are listed in `config/deploy.yml`
since 2026-11-16 and live from their cut-over (`../tasks/vps-migration/decisions.md`). Only the
web-volume jobs (weekly files backup, `logos:download`) stay in `laravel/routes/console.php`. Recover a
missed morning by starting `MorningChain` for today's date, never with `contracts:fetch` by hand (no
scheduler would run its `contracts:complete-import`).

## Decisions that this code follows (approved 2026-11-07)

- **Python is the only Temporal SDK.** Workflows are Python. PHP work runs as an activity that
  starts one `php artisan …` process (`activities.run_command`, kind `artisan`). Domain logic,
  readiness facts and checkpoints stay in PHP. Do not add the PHP Temporal SDK or RoadRunner.
- **Temporal Cloud** (Developer plan) in production; the local dev server (`temporal server
  start-dev`) for development. Connection settings come from environment variables
  (`settings.py`); the API key is used only for the connection and is never logged.
- **Nothing secret or bulky goes through Temporal.** Activity inputs and results go to Temporal
  Cloud: pass only dates, IDs, status keys and counts. `run_command` returns only the last stdout
  line when it is a JSON object; other output goes to the container log. A failed command raises
  with its closed `error` key only.
- **Checkpoints stay the truth** (`data_freshness_checkpoints`); workflows read readiness through
  `php artisan orchestration:readiness`, never through workflow queries.
- **Patching plus replay tests** for workflow changes that affect running workflows
  (`workflow.patched()`); no Worker Deployment versioning yet.

## Files

- `src/voltikka_orchestration/settings.py`: environment settings (`TEMPORAL_ADDRESS`,
  `TEMPORAL_NAMESPACE`, `TEMPORAL_API_KEY`, `LARAVEL_DIR`, `PHP_BINARY`, `S_MODEL_MODULE`,
  `SENTRY_DSN`/`SENTRY_LARAVEL_DSN`, `TEMPORAL_TASK_QUEUE`).
- `activities.py`: `run_command` (one process per unit, heartbeats every 10 s, kills the child on
  cancellation, and on worker shutdown when `CommandRequest.interruptible`) and `report_issue` (one Sentry Issue per outcome that needs a person, fingerprint
  `temporal-workflow` + workflow + reason).
- `s_forecast.py`: `SDailyForecast` and `SWeeklyRefit` (the S pilot). Timeline and model interface:
  `../tasks/temporal-orchestration/s-workflow-interface.md` and
  `../tasks/own-forecast-model-data/s-model-freeze.md`.
- `jobs.py` (phase 2): `ArtisanJob` (a list of `php artisan` steps for one Schedule) and the morning
  chain `MorningChain` → children `EexFutures` and `ContractImport`. Rules below.
- `worker.py`: worker entrypoint (`python -m voltikka_orchestration.worker`). Logs go to stdout
  (Railway showed every stderr line as "error"; keep stdout).
- `sentry.py`: Sentry init and `SentryInterceptor`. Any exception that leaves workflow code (a bug,
  a sandbox restriction, an unhandled activity error) fails the workflow
  (`workflow_failure_exception_types=[Exception]`, also in the tests) and sends one Issue
  (fingerprint `temporal-workflow` + workflow + `unexpected_failure` + exception type). It never
  reports during replay. `LoggingIntegration(event_level=None)`: a log line is never an Issue, so
  `report_issue` sends exactly one event. Not covered: a nondeterminism error raised by the SDK
  outside workflow code (it fails the workflow, visible only in the Temporal UI).
- `schedules.py`: creates or updates the Temporal Schedules; prints the plan without `--apply`.
  `JOBS` is the table of every phase 2 job (Helsinki times, steps, group); `--group
  collectors|maintenance|spot|morning|s-forecast` or `--only <id>` selects schedules for the
  read-only plan; `--apply` takes whole groups only (`--group`), because `console.php` skips whole
  groups. Overlap
  policy SKIP replaces `withoutOverlapping`; one schedule start replaces `onOneServer`. Applying a
  production schedule is a production change and needs user approval. A group's Schedules and its
  `laravel/routes/console.php` entries must never both run: list the group in
  `TEMPORAL_SCHEDULE_GROUPS` of the web role, deploy the web role, confirm with `schedule:list` in
  the new container that the group is absent, and only then `--apply --group` it. Remove the group
  only after its Schedules are paused and their running workflows ended. `--apply` creates or
  updates one schedule at a time and is idempotent: after a partial apply (an error after some
  schedules were created), run the same `--apply --group` again; to roll back instead, pause every
  schedule of the group that exists (`temporal schedule list`), then remove the group. A re-apply
  replaces the whole schedule, so it unpauses a paused one. `tests/test_console_groups.py` checks
  both directions with full command strings; `cache:reclaim-expired-files` is the one console entry
  without a Temporal job (file cache only).

## Rules for workflow code

- **No time-zone objects in workflows.** The sandbox proxies `zoneinfo`, and a proxied ZoneInfo
  is not a tzinfo (`TypeError` at run time, a stuck workflow task). Use `helsinki_offset`,
  `local_instant` and `local_date` in `s_forecast.py` (EU summer-time rule; tests compare them with
  zoneinfo for every hour of 2024–2030).
- Use `workflow.now()` and `workflow.sleep()`, never the system clock.
- A failed input fetch inside `SDailyForecast` is a missing input, not a workflow error: the run
  ends with "no forecast" and one Sentry Issue. The model runs only when every input of both views
  is ready (user decision 2026-11-06).
- `SForecastInput.require_cut_fetches=False` skips the two ENTSO-E outage-fetch proofs. Only for a
  manual check after the cuts; the Schedule never sets it.
- `SWeeklyRefit` starts on Monday at 10:45 (`SRefitInput.run_date`, default today), runs
  `fingrid:fetch` itself, then train → activate. Sunday's Fingrid hours are training targets, so a
  refit before the 10:20 Fingrid fetch is not the backtest's refit (model session, 2026-11-07).
  Every step must finish before 14:15 (`REFIT_DEADLINE`): the active version must never change
  under the 14:30 predict run. After the deadline the run fails with `refit_deadline_passed` and
  the old version stays.
- A failed `predict` ends the day with "no forecast" and one Issue (`predict_failed`, missing
  `predict:<error key>`); a failed `score` keeps the stored forecast and sends one Issue
  (`score_failed`). `run_command` puts the model's closed `error` key in the failure details;
  `error_key()` reads it.

## Rules for phase 2 jobs (`jobs.py`)

- **No automatic retry of a failed command.** `run_command` raises `CommandFailed` for a non-zero
  exit, and every job retry policy lists it as non-retryable (as with the Laravel scheduler). Only a
  lost attempt is retried. Read-only readiness calls retry for up to 30 minutes (`READINESS_OPTIONS`), so a
  short database break does not end a morning; a longer one fails the run with one Issue. Social posting and the morning
  consumers use `max_attempts=1`.
- **Deploy drain.** Kamal sends SIGTERM to the old worker after the new one is running. The worker
  stops polling. An interruptible command (`Step.interruptible`, default true) is killed at once and
  repeats on the new worker after about 30 s. A claim-based command (`interruptible=False`:
  `contracts:fetch` and `contracts:complete-import` with the 30-minute import execution lock, both
  morning consumers, social posts, `contracts:warm-cache --pending` with its 30-minute producer
  lease) runs to its end within `GRACEFUL_SHUTDOWN` (65 min;
  `worker.py`), because a killed claim cannot be resumed and stays locked for inspection. Every
  claim-based step timeout must stay below that drain (contract import, consumers and the weekly
  video 60 min; a test checks it; measure the real import time in the R4 acceptance test). The Kamal `stop_timeout` of the worker role (3960 s) stays above that. A crash (SIGKILL) still
  waits for the 2-minute heartbeat timeout. Tests: `test_a_deploy_*` in `tests/test_jobs.py`.
- **One Issue per failure.** A failed step sends one `report_issue` (`command_failed`), because the
  Laravel scheduler failure listener does not run here. `spot:fetch`, `futures:fetch-eex`,
  `contracts:fetch` and `contracts:complete-import` report a non-zero exit themselves
  (`self_reports=True`); the workflow still reports a lost or timed-out attempt of these, because
  it never reached the command's reporter (`reported_by_command`). A failed job, `EexFutures` or
  `ContractImport` then fails with `ReportedFailure` (red in the Temporal UI); the Sentry
  interceptor does not report that type again.
- A failed step skips the later steps of the job, except `always` steps (backup clean/monitor after
  the backup, price-cache cleanup after the warm, daily spot social post after `spot:fetch`).
- `MorningChain` (Schedule 04:00, one per Helsinki date): its default date is the date the run
  started (`workflow.info().workflow_start_time`), not the time of its first workflow task; it
  refuses a past date (the commands write the current Helsinki date) and waits until 04:00 of a
  later one. Every morning command
  carries its date (`CommandRequest.date`); `run_command` refuses it with `DatePassed` (one Issue)
  before PHP starts when the Helsinki date has changed, because a timer does not bound how long a
  workflow waits for a worker (an outage across midnight). `EexFutures` starts at once;
  `ContractImport` waits until 06:00, runs `contracts:fetch --skip-logos`, then
  `contracts:complete-import` every minute while `orchestration:readiness morning` says
  `pending_completion` (at most 2 h 10 min), then `forecasting:evaluate-fixed-contracts`. Exit 0
  with a checkpoint other than `ready` (a partial acquisition stores `incomplete`) fails the child. Each
  consumer has its own lane: it waits until both children have ended (or noon), then ticks its
  `--scheduled` command every five minutes until `scheduled_complete` is true. A manual subset
  `ready` row is not done. A `failed` claim gets no tick before noon. A separate noon check runs one
  tick at 12:00 for every consumer that is not complete, also while its producers or its own command
  still run, so `MorningConsumerExecution` reserves the deadline alert on time (a second noon tick
  from the lane is harmless). The lanes never wait for each other. An incomplete morning, or a
  scheduled run that finished after noon (`deadline_alerted`), sends one `morning_incomplete` Issue
  and fails. The PHP commands keep their gates, claims and alerts;
  their lower clock bound was removed, so the start is the producers' end.
- **Cut-over order** (`../tasks/temporal-orchestration/phase2-plan.md`): on the VPS only; every
  group runs in `laravel/routes/console.php` unless `TEMPORAL_SCHEDULE_GROUPS` lists it (production since 2026-11-16: all four groups). Maintenance and spot write the cache, so
  they needed a cache that the web and the worker share: on the VPS both roles use Redis. The queue
  stays in the web container (decision 2026-11-09); a web deploy stops a running interpretation job,
  and the queue retries it (`--tries=3`). A separate `queue` role is a later option.
  The morning chain also moves `logos:download` to the web scheduler (the worker import uses
  `--skip-logos`).

## S model command

The model is the separate package `../forecast/` (`voltikka_forecast.s`, owned by the own-model
session). It is a non-editable uv path dependency, so `uv.lock` here pins its exact runtime
(Python 3.13, numpy 2.5.4, pandas 3.1.0, scikit-learn 1.9.1, …); keep the two lock files at the
same versions (a fitted model is valid only with the same scikit-learn). The activities never
import it: they start `python -m $S_MODEL_MODULE predict|score|train|activate …` as a child process
(one JSON line on stdout; failure = exit 1 and `{"status": "failed", "error": <key>}`). The image
sets `S_MODEL_MODULE=voltikka_forecast.s`. Without the variable (local runs only), model activities
return `{"status": "stub"}`. The model process reads `DB_*` and the `FORECAST_BUCKET*` variables
(see `../forecast/AGENTS.md`); a deploy that changes the model code makes the active version
invalid (`artifact_mismatch`) until a refit and activation.

## Tests and local run

```bash
cd orchestration
uv sync
uv run pytest -q          # time-skipping Temporal test server; downloads it on first use

# End to end on this machine (local SQLite database through php artisan):
temporal server start-dev --headless
LARAVEL_DIR=$PWD/../laravel uv run python -m voltikka_orchestration.worker
temporal workflow execute --type SDailyForecast --task-queue voltikka-main \
  --input '{"issue_date":"2026-11-05","require_cut_fetches":false}'
```

Unset `SENTRY_LARAVEL_DSN` for local runs, or local outcomes become production Sentry Issues.

## Image and service

The production image (`../Dockerfile`) installs Python 3.13 and `uv.lock` with uv into
`/opt/orchestration/.venv`, with `../forecast` copied to `/opt/forecast` (about +570 MB on the
base image with the model libraries). `../.dockerignore` is an allowlist: it must keep
`orchestration`, `forecast` and `docker-worker-entrypoint.sh`. The worker role starts `../docker-worker-entrypoint.sh`: no
migrations, no web server, no scheduler. With the argument `queue` the same script
starts `php artisan queue:work` for the planned service `voltikka-queue` (phase 2). The web service owns migrations, so deploy the
web service first when a release has a migration.

Production (since 2026-11-15): the Kamal role `worker` in `../config/deploy.yml` on `breezily-vps`,
same GHCR image as the web role. Its entrypoint option `/usr/local/bin/docker-worker-entrypoint.sh`
replaces the image ENTRYPOINT, so the web start script and its migrations never run here. It mounts
the `voltikka-storage` volume (S model files in `FORECAST_STORE_DIR=/app/storage/app/forecast-models`,
backup temp), has no proxy and `stop_timeout: 3960` (above the 65-minute `GRACEFUL_SHUTDOWN`). Its
Docker health check (`health-cmd` in `config/deploy.yml`) tests `/tmp/voltikka-worker-ready`, which
`worker.py` deletes at start and writes after `connect()` succeeds (`READY_FILE`). Kamal waits for
`healthy` (up to `deploy_timeout`, 1200 s) before it stops the old worker; a new worker that never
connects is stopped and the old one keeps running (2026-11-16). Keep the file in the container's own
`/tmp`: on the shared volume, the old worker's file would make a new one look healthy. The health
command also passes when the image's `worker.py` has no `READY_FILE` (images up to
`deploy-000027`), so a rollback to such an image works as before; keep `READY_FILE` in `worker.py`. Deploy: `../scripts/deploy-image.sh` (both roles; Kamal boots the worker only
after the new web container is healthy, so migrations run first) or `--role worker` (production
mutation). The 04:00–09:00 window applies to every worker deploy: the worker runs the
`MorningChain`, and a deploy restarts its interruptible EEX step.
Variables: the shared `env` of `config/deploy.yml` (so the pricing flags `CANONICAL_PRICING_ENABLED`,
`RESET_FORWARD_SHIFT_ENABLED`, `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION` and
`CONTRACT_INTERPRETATION_*`, which are part of the cache keys, are equal on both roles), plus
`TEMPORAL_ADDRESS` (`voltikka-prod-vps.lw6f1.tmprl.cloud:7233`), `TEMPORAL_NAMESPACE`
(`voltikka-prod-vps.lw6f1`) and the secret `TEMPORAL_API_KEY` (`voltikka-temporal-api-key`; the old
Railway namespace `voltikka-prod` refuses it). Both roles use the shared Redis cache.

The Railway service `voltikka-worker` (`73a37ca9-6280-4695-856a-7674e548e74c`, namespace
`voltikka-prod`, S Schedules paused) has had no deployment since 2026-11-16; see the root
`AGENTS.md`, "Retired Railway project".
