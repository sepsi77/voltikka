# Decisions

## 2026-11-20 – Plan (user confirmed)

- The user proposed replacing clock-based triggers with gates: fetches keep trying until they
  succeed, each one opens a gate, and the forecast runs when every gate is open. Manager view,
  confirmed by the user the same evening:
  - A gate opens on the readiness fact (data in the database), not on a successful fetch. On
    2026-11-17 `fingrid:fetch` succeeded at 14:00 but the 13:00 capacity row was not published yet.
  - Gates are parallel loops inside `SDailyForecast`, not Temporal signals from other workflows:
    the collectors' Schedules also serve the market review, and a signal can arrive before the S
    workflow exists. Re-reading the readiness check after a fetch gives the same result with less
    coupling.
  - Clocks stay as deadlines: the model freeze defines the morning view as of 11:30 and the
    Previous Runs vintages as fetched before 10:00/14:00.
  - Failed collector fetches retry until a deadline. Since the staged imports, a fetch only writes
    a payload file, so repeating it is safe. Stores keep the no-retry rule.
- Retries before this task (2026-11-20): `jobs.step_options()` makes `CommandFailed`
  non-retryable (only a lost attempt repeats); `s_forecast.FETCH_OPTIONS` the same; the HTTP
  clients retry about 3 times a few seconds apart. So a short Fingrid outage at 10:20 cost the day.

## 2026-11-21 – Implementation (executor)

### Gates (requirements 1–9)

- **One readiness call per gate per pass, not shared per view.** Every gate is its own `asyncio`
  task and calls `orchestration:readiness` for its view once per pass (`_key_ready()` reuses
  `_readiness()` and looks up its key; a missing key raises, a config bug). A shared per-view call
  would need the gates of a view to run on one clock, but their starts differ (09:00, 09:15, 09:30,
  10:05, 12:00, 13:00, 13:30, 14:00) and fetch times vary. A normal day makes about one call per
  gate (10 calls); a gate that waits four hours makes 24. Deterministic: each task schedules its
  activities in workflow order.
- **Pass order:** check; if not ready and before the deadline, fetch (fetch, then store); sleep to
  the next pass (pass start + interval, at most the deadline). After a fetch, the next check is in
  the next pass, so the last check falls on the deadline, after the last fetch. "Row appears on the
  3rd check" = two fetches, then open.
- **Budget:** a fetch uses the existing `_fetch` rule: schedule-to-close = the time left to the gate
  deadline, and no fetch when none is left. So a fetch never starts at or after its deadline, and
  neither it nor its store runs past it. This reads requirement 3's "a fetch whose start-to-close
  would end after the gate deadline is not started" as the existing `_fetch` rule that the spec
  names. A literal check (start only when now + 15 min ≤ deadline) would allow only 2 attempts in
  the 30-minute windows. The Previous Runs vintages stay fetched before 10:00/14:00. The test checks
  every fetch and store against the cut, within the few milliseconds between `workflow.now()` and
  the server's scheduled time.
- **A failed store stops the gate's fetches** (S13: a failed store never starts a new fetch). The
  gate keeps re-checking. Reason: every `--from-payload` store refuses a payload that a newer one of
  the same command and options supersedes (`StagedImport::refuseStagedOlderPayload`). A gate store
  that races a collector fetch then fails `superseded`, while the collector's store makes the key
  ready. Cost: after a `store_busy` (another store held the lock for more than 5 min), a gate whose
  collector does not run again (Fingrid after 10:20) misses its deadline with the store facts. That
  failure points to a problem anyway.
- **Collection and early end:** the run collects the gates by deadline: the morning-view gates and
  the morning outage gate at 11:30 (the 10:00 Previous Runs gate too, so one Issue names every
  morning miss), then 14:00, 14:30 and 18:00. It ends at the first of these at which a gate missed.
  The spec requires the early end only for 11:30. The 14:00 and 14:30 ends apply the same reason:
  the day can no longer get a forecast. Reason `deadline_missed` only when every missed gate is an
  18:00 gate, otherwise `inputs_missing`. Issue facts: `issue_date`, `missing` (gate keys) and
  `failures` (gate → last failed fetch facts), only when a missed gate had a failed fetch.
- **Stopping without cancelling timers:** on an early end, gates start no new activity. The run
  waits until no gate is inside an activity (`_busy`), so a store is never cancelled. Sleeping gates
  are left behind when the run returns. The first version cancelled their timers through
  `wait_condition(timeout=…)`. When two gate start timers came due in the same workflow task as the
  stop, the time-skipping test server never completed that task (about 1 run in 5). The root cause
  is unconfirmed, so no timer is cancelled.
- **A gate that raises fails the run at once**, not at its group's collection. An example is a
  readiness check that failed after its 3 attempts. The run fails as before (one `unexpected_failure`
  Issue through the interceptor).
- **The model runs at 14:30 at the earliest**, after every gate is open. The afternoon view is as of
  its cut, and an outage proof fetched before 14:30 must not be newer than the model's cut.
- **Manual starts (requirement 9):** a gate past its deadline checks once and never fetches; not
  ready = missed at once. This keeps `require_cut_fetches=False` useful for a manual check after the
  cuts. With `require_cut_fetches=True`, a start after 11:30 still ends at once with
  `started_after_morning_cut`.
- **Schedule start moves from 10:55 to 08:55** (`schedules.py`). The first gates start at 09:00.
  With the old 10:55 start the code still works, but the 09:30–10:00 Previous Runs gate would be
  past its deadline. Applying it (`schedules.py --apply --group s-forecast`) is a production change
  that needs approval, after the deploy.
- **Patch:** `workflow.patched("s-forecast-gates")` is the first call in `run`. A run that started
  before the deploy replays the old clock-timed path (`_run_before_gates`, unchanged). The predict and
  score code moved into the shared `_predict` with the same commands. New history
  `tests/histories/s_daily_forecast_staged.json` was recorded from the code before this change (the
  staged clock path, which production runs now). With the patch check disabled, both
  `s_daily_forecast` and `s_daily_forecast_staged` fail replay with a nondeterminism error. With it,
  both pass. A new gate run replays on the production workflows
  (`test_late_fingrid_capacity_rows_open_the_afternoon_gate`).
- Overlap with the collectors: a gate fetch and a collector fetch of the same command can run at the
  same time (for example `weather:fetch --only=ecmwf_prev_day1` at 09:40 after a failed 09:30
  gate fetch). Stores serialize on `staged_import_store_v1:<command>`. Append-only vintages can get
  two near-identical rows, which is harmless for the readiness facts.

### Collector fetch retries (requirements 10–12)

- `Step.fetch_retry_minutes` (default 0). `schedules.py` sets it for every staged, non-self-reporting
  step of the `collectors` group. `jobs.fetch_step()` repeats a `CommandFailed` fetch every 10
  minutes while `now + 10 min < start + fetch_retry_minutes`. The first attempt keeps the step's own
  options, and a retry gets schedule-to-close = the time left to the deadline. Measured from
  `workflow_start_time`, as `MorningChain` does. A 30-minute catch-up start shifts the window by that
  much.
- **Deadline computation (superseded by PLT-003, see "Spec review 2026-11-20T21-20 fixes"):** min(120, shortest gap between the job's starts − 5 − store timeout)
  minutes. The store timeout is min(step timeout, 60). The cap keeps the worst-case last store ending
  5 minutes before the next start, because overlap policy SKIP would drop that start. With this
  rule the spec's hourly `fingrid-forecast-vintages` gets deadline 45, so its last retry starts at
  +40, the "40 minutes" in requirement 10.

  | Job | Starts (Helsinki) | Step timeout | Retry deadline (min after start) | Retries at |
  |---|---|---|---|---|
  | `fingrid-fetch` | 10:20 daily | 60 | 120 | +10 … +110 |
  | `gas-storage-fetch` | 10:35 daily | 30 | 120 | +10 … +110 |
  | `weather-fetch` | 10:40 daily | 60 | 120 | +10 … +110 |
  | `weather-fetch-ec46` | Mon 10:50 | 60 | 120 | +10 … +110 |
  | `weather-fetch-seas5` | day 8 11:00 | 60 | 120 | +10 … +110 |
  | `entsoe-fetch` | 10:50 daily | 60 | 120 | +10 … +110 |
  | `fmi-fetch` | 11:05 daily | 30 | 120 | +10 … +110 |
  | `fmi-fetch-forecast` | 23:05 daily | 30 | 120 | +10 … +110 |
  | `weather-fetch-solar-ghi` | 11:05, 23:05 | 30 | 120 | +10 … +110 |
  | `weather-fetch-ecmwf-00z` | hourly 09:15–14:15 | 30 | 25 | +10, +20 |
  | `weather-fetch-ecmwf-prev-day1` | 09:40, 13:40 | 30 | 120 | +10 … +110 |
  | `fingrid-forecast-vintages` | hourly :45 | 10 | 45 | +10 … +40 |
  | `syke-fetch` | 11:10 daily | 30 | 120 | +10 … +110 |
  | `eex-europe-fetch` | 12:30 daily | 30 | 120 | +10 … +110 |
  | `jao-fetch` | 12:40 daily | 30 | 120 | +10 … +110 |
  | `nve-fetch-reservoirs` | Wed 15:30 | 30 | 120 | +10 … +110 |

  "Retries at" assumes failures that return at once. Each retry is 10 minutes after the previous
  failure ends. `test_only_staged_collector_steps_retry_a_failed_fetch_and_none_self_reports` holds
  this table.
- **No patch for `jobs.py`:** the retry path needs `fetch_retry_minutes > 0`, and the field comes
  from the Schedule input. A run started by a Schedule from before `schedules.py --apply --group
  collectors` has no such field. The converter fills the default 0 and ignores unknown fields, so an
  old worker ignores the field after an early apply. No recorded or running history can take the
  new path. Evidence: `tests/histories/artisan_job_failed_collector_fetch.json` (a failed staged
  collector fetch from the old code, then its Issue) replays on the new code. The retries take
  effect only after the deploy and a separately approved `schedules.py --apply --group collectors`.
- The store still runs once. A failed store, a lost or timed-out fetch, and a `fetch_result_invalid`
  are not retried. A self-reporting step is never retried, also when the field is set (`fetch_step`
  checks it).

### D2 – replay check of the 2026-11-15..19 afternoon failures

Facts (spec, "Why" and D2): on 2026-11-15 to 2026-11-19 `fingrid_afternoon` was missing. It needs a
row for every dataset (75, 124, 188, 191, 194, 267, 268) at the hour 13:00–14:00 Helsinki (11:00
UTC in winter). The old workflow fetched Fingrid once at 14:00 and then only re-read the database
until 18:00. The capacity datasets 267/268 publish an hour late. Measured 2026-11-20: at 17:57 UTC
the latest 267/268 row started 16:00 UTC, so hour H was there within 1 h 57 min of its start.

Replay with the gates: the 13:00 Helsinki hour (11:00 UTC) is published by about 12:57 UTC = 14:57
Helsinki. The `fingrid_afternoon` gate checks at 14:00 and fetches (row not yet there). It checks
and fetches again every 10 minutes. The fetch at about 15:00 stores the row, and the check at about
15:10 opens the gate, almost 3 h before the 18:00 deadline. On 2026-11-15 to 2026-11-18,
`fingrid_afternoon` was the only missing input (spec, "Why"), so each of those days gets a forecast
at about 15:10 unless the lag reaches about 5 h (17:50 is the last fetch). On 2026-11-19 the
`fingrid_afternoon` gate also opens, but `day_ahead_d1` stays closed (DE-LU sequence 1 missing at
ENTSO-E, fixed separately by PR #35), so that day ends with `deadline_missed` on `day_ahead_d1` alone. The time-skipping test
`test_late_fingrid_capacity_rows_open_the_afternoon_gate` replays this pattern: the row is
published after the 14:00 store, the 14:10 store has it, the gate opens at 14:20, and predict runs
at 14:30. That run's history also replays deterministically on the production workflows.

The morning margin is thinner. `fingrid_morning` needs the hour 09:00–10:00 Helsinki (07:00 UTC).
With the same 1 h 57 min lag it arrives at about 10:57 Helsinki, and the gate's last fetch is at
11:25, a 30-minute margin before the 11:30 cut. The 11:30 cut is frozen, so a capacity lag above
about 2 h 25 min still costs the day. D4 will show whether that happens.

### Verification (2026-11-21)

- `cd orchestration && uv run pytest`: 118 passed, 98 before this change. Five full runs in a row
  passed. The S file passed 10 more runs, and the earlier hanging case passed 48 targeted runs.
- No PHP change, so the Laravel suite is unaffected (D3).

## 2026-11-20 – Manager review of the implementation

- `s_forecast.READINESS_OPTIONS` now retries for up to 30 minutes, as `jobs.READINESS_OPTIONS`
  does (before: 3 attempts over about 40 s). The gates make many more readiness calls per day, so
  a short database break would otherwise end the run. Activity options are not part of the replay
  check, so no patch is needed. `uv run pytest`: 118 passed.
- `laravel/config/orchestration.php`: the `weather_ecmwf_00z` comment now names its gate (comment only).

## Spec review 2026-11-20T21-20 fixes (commit 2d0645ff)

Reports: `reviews/2026-11-20T21-20/platform-reviewer.md`, `test-reviewer.md`. The manager decided the
fixes; the executor implemented them.

- **PLT-001 (acquisition failures looked like store failures).** Confirmed in
  `laravel/app/Console/Concerns/StagedImport.php`: a fetch with failed units finishes its payload and
  prints `status=fetched` (exit 0). The store commits the good units' rows and exits 1 with
  `{"status":"failed","step":"fetch","error":"fetch_incomplete"}` (`ImportStoreResult.errorStep`).
  `run_command` raises `ApplicationError(…, <error>, type="CommandFailed")`, so `error_key()` reads
  `fetch_incomplete` from the activity failure details. `staged.failure_step()` maps a store that
  failed `fetch_incomplete` to step `fetch`, in both loops:
  - `staged_fetch()` then reports step `fetch` (with the payload reference), and the S gate keeps
    fetching.
  - `jobs.run_step()` repeats fetch and store within the collector retry window.
  - Any other failed store keeps the no-retry rule.
  - The Issue names the last attempt as the store reported it.
  - Tests: `test_a_partial_acquisition_is_fetched_again`,
    `test_a_partial_acquisition_that_never_completes_names_the_fetch_step`,
    `test_a_partial_acquisition_repeats_fetch_and_store_and_recovers_without_an_issue`,
    `test_a_partial_acquisition_that_never_completes_sends_one_issue_naming_the_fetch`.
  - Existing tests keep a failed `test_error` store final.
  - spec.md "Unchanged" and requirement 10 name the exception.
- **PLT-002 (readiness could open a gate after its cut).**
  - The last check starts `LAST_CHECK_BEFORE_DEADLINE` (2 min) before the deadline.
  - Each readiness call of a gate gets schedule-to-close = min(30 min, deadline − now).
  - A check the deadline cut that still fails misses the gate. Its failure facts are
    `{"command": "orchestration:readiness", "error": …}`. A result that arrives after the deadline
    is not used.
  - Temporal stops retrying once the next attempt could not start before schedule-to-close, so such a
    check fails a few minutes before the cut, not at it. The rule therefore keys on "the budget was
    cut by the deadline", not on the clock.
  - A check that failed for its full 30 minutes well before the deadline, or that has no JSON, still
    fails the run.
  - A manual start after a deadline keeps its one check, with the normal 30-minute budget.
  - A gate still closed after its last check is missed at its deadline.
  - A missed run ends at its collection time (11:30, 14:00, 14:30, 18:00). The Issue and the close
    therefore fall on the cut, also for a 10:00 Previous Runs miss.
  - Added `MIN_FETCH_BUDGET` (1 min): a gate starts a fetch only with at least 1 minute left. The
    time-skipping server fired a deadline timer 1 ms early, and the pass then started a fetch with a
    1 ms budget.
  - Tests: `test_a_readiness_check_failing_across_the_cut_misses_the_gate`,
    `test_a_row_that_appears_after_the_cut_never_opens_the_gate`.
  - No new patch: the gate code has never run in production (not deployed), so no history from
    `ed1db1e1` exists outside the tests.
- **PLT-003 (collector cap).** Manager decision: only collector jobs whose shortest gap between
  starts is at least 6 hours retry, until 120 minutes after the start. Hourly collectors get no
  retry: their next run is the retry, and a retrying run must not overlap its next start. The
  twice-daily `weather-fetch-ecmwf-prev-day1` (09:40/13:40, 4 h apart) falls under the same rule and
  gets no retry. The S gate covers its 10:00/14:00 vintages.
  - The window is measured from `workflow_start_time` (as in `MorningChain`). A Schedule catch-up
    start (at most 30 minutes) shifts it by that much.

  | Job | Shortest gap | Retry window |
  |---|---|---|
  | `fingrid-fetch`, `gas-storage-fetch`, `weather-fetch`, `entsoe-fetch`, `fmi-fetch`, `fmi-fetch-forecast`, `syke-fetch`, `eex-europe-fetch`, `jao-fetch` | 24 h (daily) | 120 min |
  | `weather-fetch-solar-ghi` (11:05, 23:05) | 12 h | 120 min |
  | `weather-fetch-ec46` (Mon), `nve-fetch-reservoirs` (Wed), `weather-fetch-seas5` (day 8) | ≥ 24 h by time of day | 120 min |
  | `weather-fetch-ecmwf-prev-day1` (09:40, 13:40) | 4 h | none |
  | `weather-fetch-ecmwf-00z` (hourly 09:15–14:15), `fingrid-forecast-vintages` (hourly :45) | 1 h | none |

  `test_only_staged_collector_steps_retry_a_failed_fetch_and_none_self_reports` holds this table.
- **TST-001.** `test_each_gate_obeys_its_fetch_command_and_deadline` runs `SDailyForecast.run` with
  an independent table: each key gate is kept closed in turn. It checks the exact fetch command and
  options (none for `eex_futures`), every fetch and store time, the budget end against the deadline,
  the last check 2 min before it, the reason, the report and close time, and that no predict runs.
  `test_each_outage_gate_obeys_its_window` covers both outage gates.
- **TST-002.** `test_all_missed_gates_report_their_latest_failures_once` has two afternoon gates
  closed, with errors that change on every attempt. Jao ends with a failed store. The test expects
  one Issue with both gates and each last error and step.
  `test_an_exhausted_collector_fetch_retry_sends_one_issue_with_the_last_error` expects 12 attempts,
  the Issue with `down_12`, and no store.
- **TST-003.** The early-exit test asserts the report activity time and the workflow close time are
  11:30 Helsinki. The tolerance is 1 s before (timer jitter) to 5 s after.

Verification: `cd orchestration && uv run pytest`: 136 passed, in three runs in a row (118 before
these fixes). `git diff --check` is clean.

### Verification pass fixes (commit 2aa704bf)

Reports: `reviews/2026-11-20T21-20/verification-platform-reviewer.md`,
`verification-test-reviewer.md`.

- **PLT-002 remainder (blocking).** A scheduled gate whose pass began at or after its deadline got a
  normal 30-minute readiness check and could open on post-cut data. That happens when the last fetch
  runs to the cut or a worker resumes the gate late.
  - The gate now decides `retrospective = workflow.now() >= deadline` once, when it wakes at its
    start. That is true only for a manual start after the deadline.
  - A non-retrospective pass that begins at or after the deadline is missed, with no new check or
    fetch.
  - `_key_ready()` gets the flag. For a scheduled gate it never starts a check at or after the
    deadline, and it never accepts a result that arrives after it, whenever the check started.
  - The retrospective manual path keeps its one unrestricted check.
- **TST-V-002 (blocking).**
  `test_a_scheduled_gate_cannot_open_after_its_last_fetch_runs_to_the_cut`: the 11:25
  `fingrid:fetch` ends at 11:31, and the row is ready from 11:31. The test expects `no_forecast`,
  missed `fingrid_morning`, one `inputs_missing` Issue, no predict, and no morning readiness call
  after 11:30.
  - Mechanism: the time-skipping server does not advance its clock while any activity is
    outstanding. Tried and confirmed by hangs: an attempt that blocks without heartbeats,
    `env.sleep()` during that attempt, an async-completing attempt, and a worker gap (a pending
    workflow task holds the clock too).
  - The test therefore runs on an unsandboxed worker and replaces only that one
    `SDailyForecast._fetch` call with a workflow timer of the same length. The gates, checks and
    collection run as real workflow code.
  - On the code before this fix it fails with `forecast_stored` (verified by temporarily restoring
    the old `s_forecast.py`).
- **TST-V-001 (normal).** `test_each_outage_gate_obeys_its_window` and
  `test_each_gate_obeys_its_fetch_command_and_deadline` compare full scheduled instants with
  `near()` (1 s early to 5 s late) instead of minute strings. A timer firing 1 ms early turned 14:25
  into "14:24". Counts, order, commands and deadlines stay exact.

Verification: `cd orchestration && uv run pytest`, five runs in a row: 137, 137, 137, 137, 137
passed. `git diff --check` is clean.

## 2026-11-20 – PR #36 external review (CodeRabbit, Greptile)

- **Late gate timer (both reviewers).** `retrospective` came from the time the gate's start timer
  fired, so a scheduled run whose worker resumed after a gate deadline skipped that deadline. It now
  comes from `workflow.info().workflow_start_time` (the Schedule's start, 08:55). Test
  `test_a_scheduled_gate_whose_start_timer_fires_after_its_deadline_misses` fails on the code before.
- **Late collector retry wake (both reviewers).** A retry timer that fired after the retry deadline
  started an attempt with a negative schedule-to-close. The step now reports its last failure once.
  Test `test_a_retry_that_wakes_after_its_deadline_reports_the_last_failure`; on the code before, the
  run hangs (the attempt is never scheduled).
- **Retry-interval helper across midnight (CodeRabbit).** `minutes_after_start` now subtracts full
  scheduled instants.
- `uv run pytest`: 139 passed, three runs in a row.
- **`workflow_start_time`, not `start_time` (CodeRabbit round 2).** In temporalio 1.35.0
  `WorkflowInfo.start_time` is the start of the first workflow task, `workflow_start_time` the
  creation of the workflow. A Schedule run created at 08:55 whose first task runs late must not be
  retrospective, so the gate reads `workflow_start_time`.
