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
- **Deadline computation:** min(120, shortest gap between the job's starts − 5 − store timeout)
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
