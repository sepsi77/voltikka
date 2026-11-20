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
  chain `MorningChain` → children `EexFutures` and `ContractImport`; `ImportReplay` (manual store of
  a saved payload). Rules below.
- `staged.py`: the staged-import contract shared by `jobs.py` and `s_forecast.py` (patch ID, fetch
  and store args, payload reference parsing, payload date, store options and payload expiry for
  retention, failure facts). See "Staged imports" below.
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
- **Gates in `SDailyForecast`** (user decision 2026-11-20, `../tasks/s-forecast-gates/`). The model
  runs only when every input of both views is ready (user decision 2026-11-06). Each input is a gate
  (`s_forecast.GATES`): one readiness key of `laravel/config/orchestration.php` (a test keeps the
  two equal) or one of the two ENTSO-E outage proofs. Every gate is its own `asyncio` task in the
  run (no workflows, no signals: the collector Schedules also serve the market review and keep
  running unchanged). From its start, every `GATE_INTERVAL` (10 min, measured from the pass start)
  a gate checks its key with one `orchestration:readiness` call of its view; when the key is not
  ready, it runs its fetch (staged, `staged_fetch()`). A failed fetch is not final: the next pass
  fetches again. A store that fails `fetch_incomplete` is a partial acquisition, not a store bug
  (the fetch exited 0 with failed units; the store kept the good units' rows and reports step
  `fetch`, `staged.failure_step()`), so the next pass fetches again too. Any other failed store
  never starts a new fetch (S13): the gate then only re-checks, so a collector's newer store (a
  concurrent gate store fails with `superseded`) can still open it.
  **Deadline rules (spec review PLT-002):** the last check starts `LAST_CHECK_BEFORE_DEADLINE`
  (2 min) before the deadline, and every check of a gate has schedule-to-close = min(30 min, time
  left to the deadline), so no readiness result can open a gate after its cut (upserted facts
  check only that a row exists, not when it arrived). A check whose budget the deadline cut and that
  still fails (Temporal stops retrying once the next attempt could not start in time) misses the
  gate, with `{"command": "orchestration:readiness", "error": …}` as its failure facts; a check that
  fails for its full 30 minutes well before the deadline, or returns no JSON, fails the run. A gate
  still closed after its last check is missed at its deadline. A scheduled gate whose pass begins at
  or after its deadline (a fetch that ran to the cut, a worker that resumed late) is missed with no
  new check or fetch, and a result that arrives after the deadline never opens it, whenever its check
  started. Only a gate that first wakes after its deadline (`retrospective`, decided once: a manual
  start after the cut) gets one check without these limits. An outage gate has no key: one
  successful fetch inside its 30-minute window opens it, a failure repeats every 5 minutes. Every
  fetch has the `_fetch` budget: schedule-to-close = the time left to the gate deadline, and a fetch
  starts only with at least `MIN_FETCH_BUDGET` (1 min) left, so neither it nor its store runs past
  the deadline (the Previous Runs vintages stay fetched before 10:00/14:00).

  | Gate | Fetch | Start | Deadline |
  |---|---|---|---|
  | `fingrid_morning` | `fingrid:fetch` | 10:05 | 11:30 |
  | `fingrid_afternoon` | `fingrid:fetch` | 14:00 | 18:00 |
  | `day_ahead_d` | `spot:fetch-neighbours` (FI: the hourly `spot-hourly`) | 09:00 | 11:30 |
  | `day_ahead_d1` | `spot:fetch-neighbours` | 13:00 | 18:00 |
  | `jao_capacity_d` | `jao:fetch` | 09:00 | 11:30 |
  | `jao_capacity_d1` | `jao:fetch` | 12:00 | 18:00 |
  | `weather_ecmwf_00z` | `weather:fetch --only=ecmwf_00z` | 09:15 | 18:00 |
  | `weather_prev_day1_morning` | `weather:fetch --only=ecmwf_prev_day1` | 09:30 | 10:00 |
  | `weather_prev_day1_afternoon` | `weather:fetch --only=ecmwf_prev_day1` | 13:30 | 14:00 |
  | `eex_futures` | none (the `MorningChain` owns EEX) | 09:00 | 11:30 |
  | `entsoe_outages_morning` | `OUTAGE_FETCH` | 11:00 | 11:30 |
  | `entsoe_outages_afternoon` | `OUTAGE_FETCH` | 14:00 | 14:30 |

  Why: from 2026-11-09 to 2026-11-20 no forecast was stored. One failed 10:20 Fingrid fetch cost
  the day (a failed command was never retried), and the single 14:00 Fingrid fetch missed capacity
  rows that publish 1–2 h late. The run collects the gates by deadline (morning ones at the 11:30
  cut, then 14:00, 14:30, 18:00) and ends at the first collection time at which a gate has missed
  (a 10:00 Previous Runs miss is reported at 11:30), with one Issue:
  `inputs_missing` (a morning, Previous Runs or outage gate) or `deadline_missed` (only 18:00
  gates). It names every missed gate (`missing`) and, under `failures`, the facts of each one's
  last failed attempt (`failure_facts()`, or the readiness failure). A fetch that a later pass
  recovers sends nothing. On an
  early end the other gates start no new activity; the run waits for those inside an activity
  (`_busy`, so a store is never cut off) and leaves the sleeping ones behind. Do not cancel their
  timers: when the stop cancelled two gate start timers that came due in the same workflow task, the
  time-skipping test server never completed that task (about 1 run in 5; root cause unconfirmed). A gate that raises (a readiness check that still failed after 30 minutes of retries, `READINESS_OPTIONS` as in `jobs.py`) fails the run
  at once. The model runs when every gate is open, never before the 14:30 cut, with the same
  `--morning-cut`/`--afternoon-cut`. The `stage` query reports every gate's state. The Schedule
  starts the run at 08:55, before the first gate.
- Input fetches and stores (`FETCH_OPTIONS`) keep the job retry policy: Temporal never repeats a
  `CommandFailed` activity, only a lost attempt; the gate loop is what fetches again. The model
  commands (`MODEL_OPTIONS`, `TRAIN_OPTIONS`) still retry a failed command.
- **Patching** (`GATES_PATCH`, `workflow.patched("s-forecast-gates")`, checked first in `run`): a
  run that started before the gates replays the clock-timed path (`_run_before_gates`: outage fetch
  at 11:00, morning check at 11:30, one `fingrid:fetch` at 14:00, afternoon polls every 5 min until
  18:00). Replay tests: `tests/histories/s_daily_forecast*.json`; without the patch both fail.
- `SForecastInput.require_cut_fetches=False` skips the two outage gates. Only for a manual check
  after the cuts; the Schedule never sets it. A manual start after a gate's deadline (the gate wakes
  retrospective) checks that gate once, with the normal 30-minute check, and never fetches: not
  ready = missed at once. With `require_cut_fetches=True`, a start
  after 11:30 ends at once (`started_after_morning_cut`).
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

- **No automatic retry of a failed command**, except the collector fetch retry below.
  `run_command` raises `CommandFailed` for a non-zero exit, and every job retry policy lists it as
  non-retryable (as with the Laravel scheduler), also the S forecast input fetches and stores
  (`s_forecast.FETCH_OPTIONS`; the gate loop fetches again, see above). Only a lost attempt is
  retried.
- **Collector fetch retry** (`../tasks/s-forecast-gates/`, requirements 10–12; scope: manager
  decision 2026-11-20 after spec review PLT-003). A staged step of a `collectors` job that starts at
  most every 6 hours (daily, weekly, monthly) carries `Step.fetch_retry_minutes = 120`
  (`schedules.py`). After an acquisition failure (a fetch that fails with `CommandFailed`, or a
  store that fails `fetch_incomplete`: its good units' rows are stored, so a repeat is safe;
  PLT-001), `run_step()` runs fetch and store again every `FETCH_RETRY_INTERVAL` (10 min) while
  the retry can start before 120 minutes after the job's start (`workflow_start_time`); a retried
  fetch's schedule-to-close ends then, a store is never cut off. Any other failed store is final. A
  recovered step sends no Issue; a step that still fails sends one, with its last attempt's facts.
  The hourly collectors (`fingrid-forecast-vintages`, `weather-fetch-ecmwf-00z`) and the twice-daily
  `weather-fetch-ecmwf-prev-day1` (4 h apart) get no retry: their next run is the retry, and a
  retrying run must not overlap its next start (overlap policy SKIP would drop it). Why: since the
  staged imports a fetch only writes a payload file, and a short API outage at 10:20 cost the S
  forecast a day. A self-reporting step (its command sends an Issue per failed run) is never
  retried; no collector step self-reports (test). Other groups keep the no-retry rule. The retry
  wait is a workflow timer, so a deploy does not lose it; an interrupted fetch repeats as before. No
  patch: the field comes from the Schedule input, a run that started before `schedules.py --apply
  --group collectors` has no field (default 0) and takes no retry path
  (`tests/histories/artisan_job_failed_collector_fetch.json`).
- Read-only readiness calls retry for up to 30 minutes (`READINESS_OPTIONS`), so a
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
- **Local hard deadline.** `run_command` kills its child when the attempt has run for its
  `start_to_close_timeout` (`activity.info()`; no timeout, no deadline), measured on the worker
  from the activity's start, and raises the retryable `ApplicationError` type `LocalTimeout`
  (key `local_timeout`). It does not wait for the server: a worker that has lost its Temporal
  connection never receives the cancellation, and the child would keep writing. The retry rules
  treat it like a server start-to-close timeout, as a lost attempt (only `CommandFailed` is
  non-retryable). Heartbeats, the deploy kill and the drain are unchanged. Test:
  `test_a_command_past_its_start_to_close_is_killed_without_the_server`.
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

## Staged imports (external API imports; `../tasks/staged-api-imports/`)

User decision 2026-11-19: every scheduled import from an external API runs as two activities, so a
parse or database failure loses no fetched data and the same payload can be stored again after a fix.
The PHP side (`StagedImport`, payload format, retention) is in
`../laravel/app/Services/ImportPayload/AGENTS.md`. **Every new external import step must be staged.**

- **Two activities per step** (`Step.staged=True`; `run_step()` in `jobs.py`, `staged_fetch()` in
  `s_forecast.py`): `run_command(args + ["--fetch-only"])` with the step's `interruptible`, then
  `run_command(args + ["--from-payload=<path>", "--payload-sha256=<hex>"])`. The store gets the same
  data args, because PHP compares them with the payload header. Only `path` and `sha256` of the
  fetch's last JSON line reach the store; the payload never passes through Temporal.
- **Retry rules stay** (S13): a failed fetch skips the store; a failed store never starts a new
  fetch. A failed command runs once (`CommandFailed` is non-retryable in `step_options()` and
  `s_forecast.FETCH_OPTIONS`), except a collector fetch (`Step.fetch_retry_minutes`) and the next
  pass of an S forecast gate (both above). A lost attempt repeats the same activity (a lost store repeats the
  store). Test fakes raise `CommandFailed` retryable, as `run_command` does, so only the workflow
  policy stops a retry. A fetch result
  without a valid reference (`status` `fetched`, `payload.path`, 64-hex `payload.sha256`) fails the
  step with `fetch_result_invalid` and one Issue, also for a self-reporting command (it printed no
  reference, so it reported nothing). `{"status": "skipped"}` (for example `gas-storage:fetch
  --if-configured` without an API key) succeeds with nothing to store.
- **Deploy drain** (S14): the fetch keeps the step's `interruptible` (EEX: interruptible, so a
  deploy restarts the fetch; `contracts:fetch`: not interruptible, as before). Every store is not
  interruptible and has a timeout of at most `STORE_TIMEOUT_MINUTES` (60), below the 65-minute
  drain; `test_claim_based_steps_finish_during_a_deploy_drain` checks it. The PHP store-lock
  leases (`contract_import_store_v1`, `spot_forecast_store_v1:*`: 65 min,
  `../tasks/staged-api-imports/decisions.md` LAR-201) must stay above every store's
  start-to-close (60 min) and so above the local hard deadline of `run_command`, which kills the
  store at 60 min even without the server: a lease then never expires under a live store. Raise a
  store timeout only together with those leases.
- **Issues and outcomes:** `self_reports` holds for both activities (the PHP command reports its own
  fetch and store failures). A workflow Issue for a staged step names `command`, `error`, `step`
  (`fetch` or `store`) and, for a store, `payload_path`/`payload_sha256` for a replay
  (`staged.failure_facts()`). The same facts go into the one existing Issue of `SDailyForecast`
  (`inputs_missing` or `deadline_missed`, per missed gate under `failures`), `SWeeklyRefit`
  (`refit_failed`) and `ImportReplay`; no extra Issue. One `StepOutcome` per step: its
  `result` is the store's JSON line; a failed store keeps `{"payload": {path, sha256}}`.
- **Retention** (S6; manager decision 2026-11-19, no reference registry): `ImportPayloadCleanup`
  deletes a payload when its Helsinki date directory is more than `staged.RETENTION_DAYS` (14) days
  old; a test keeps the two values equal. An open run never uses a deleted file, because (1) every
  store activity (staged steps, S inputs, `ImportReplay`) has a schedule-to-close of at most
  `STORE_SCHEDULE_TO_CLOSE` (1 day; `store_options()`), so a store that cannot start and end within
  a day fails as a timed-out store with one Issue and its payload stays for the operator, and (2)
  no store is scheduled on a payload whose date directory could pass retention before that bound
  ends (`payload_expired()`: older than today − 13 days). Such a step fails with
  `payload_expired` and one Issue before PHP starts. (2) covers a store scheduled late because the
  worker was down after its fetch, and a hand-started `ImportReplay` of an old file. A path outside
  the date directories (`/manual/copy.ndjson.gz`) is outside cleanup and never expires.
- **Payload volume** (S12): Temporal does not guarantee that the fetch and its store run on the
  same worker. Every container that polls the task queue must mount the `voltikka-storage` volume
  on `/app/storage` (today one worker; the web role mounts it too and can read the files). A new
  worker or queue role without the volume breaks every store.
- **What is staged:** every `JOBS` step whose command is in the spec scope table, `EEX_STEP`,
  `CONTRACTS_STEP`, the S forecast `entsoe:fetch` outage fetches and both `fingrid:fetch` runs
  (`SDailyForecast`, `SWeeklyRefit`). `test_exactly_the_external_api_imports_are_staged` holds the
  list. `ContractImport` runs fetch, store, then its unchanged completion loop
  (`complete_contract_import()`); `MorningChain` is unchanged.
- **The Schedules carry the `staged` flag** in their `ArtisanJobInput`, so collector and spot jobs
  become staged only after `schedules.py --apply --group collectors|spot` with the new code (a
  production change, approval needed). Until then they run the one-process command, which the PHP
  commands keep as their default mode.
- **Patching** (`staged.PATCH`, `workflow.patched("staged-api-imports")`): the code, not the input,
  decides staging for `EEX_STEP`, `CONTRACTS_STEP` and the S forecast fetches, so a `MorningChain`
  child, `SDailyForecast` or `SWeeklyRefit` that started before the deploy would otherwise schedule
  different activities on replay. With the patch it keeps its one-process commands (the patch is
  memoized per run, so the whole run stays on one path); a run that starts after the deploy is
  staged. No deploy-time rule is needed. Replay tests: `tests/histories/*.json` (recorded from the
  code before this change) replay on the new code (`test_a_workflow_from_before_staged_imports_*`);
  without the patch four of them fail. Remove the patch branches (`workflow.deprecate_patch`) only in
  a later release, after every run that started before this one has closed.

### `ImportReplay` (spec S16)

Started by hand, after a fix, with the user's approval; it has no Schedule. Input
`ImportReplayInput(command, args, payload_path, sha256, date=None)`: `args` are the data args of the
original fetch without the command (`["--skip-logos"]` for the contract import). It runs only the
store activity (never a fetch), not interruptible. For `contracts:fetch`, `futures:fetch-eex` and
`futures:fetch-eex-europe` (spec S9) it refuses before PHP starts, with one `wrong_date` Issue, when
`date` or the payload path date (`…/<source>/<YYYY-MM-DD>/<run-id>.ndjson.gz`) is not today's
Helsinki date or the two differ; it passes the date to `CommandRequest.date`. A full-scope contract
replay (no `--postcodes` value in `args`; `jobs.full_contract_scope()` follows the PHP option parse)
then runs the `ContractImport` completion loop (not the forecast evaluation). A scoped replay
(`--postcodes=…`) ends after its store: a scoped import never writes the global import checkpoint,
so the loop would turn a successful store into a false `import_not_ready` Issue. A running `MorningChain`
needs nothing else: its consumer lanes tick until noon and pass once the checkpoint is `ready`.

```bash
temporal workflow start --type ImportReplay --task-queue voltikka-main --workflow-id import-replay-<date>-<n> \
  --input '{"command":"contracts:fetch","args":["--skip-logos"],"payload_path":"/app/storage/app/import-payloads/<source>/<date>/<run>.ndjson.gz","sha256":"<hex>"}'
```

### Payload cleanup

`import-payloads:cleanup --apply` runs daily at 03:20 Helsinki as the maintenance job
`import-payloads-cleanup` (and in `laravel/routes/console.php` while maintenance is not on Temporal).
Its safety against open runs is the "Retention" rule above, not a check in the cleanup.
Applying the new Schedule is part of `schedules.py --apply --group maintenance` (approval needed).

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
