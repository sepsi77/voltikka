# S forecast input gates and collector fetch retries

User decision 2026-11-20: the own S forecast (`SDailyForecast`, pilot) stops relying on one
clock-timed fetch per input. Each input becomes a gate that fetches and checks until the input is
ready or its deadline passes; the model runs when every gate is open. Failed collector fetches are
retried until a deadline instead of waiting a day.

## Why

`SDailyForecast` has stored no forecast since the VPS cut-over (2026-11-09 to 2026-11-20). Causes,
from the workflow histories (`tasks/staged-api-imports/decisions.md`, 2026-11-20):

- 2026-11-10 to 2026-11-14 `fingrid_morning`: the only morning Fingrid fetch is the 10:20
  `fingrid-fetch` Schedule; it failed (`fingrid:fetch exited 1`) on 2026-11-10 to 2026-11-15 and a
  failed command is never retried (`CommandFailed` is non-retryable), so the 11:30 check failed.
- 2026-11-15 to 2026-11-19 `fingrid_afternoon`: datasets 267/268 (solar/wind capacity) publish an
  hour about 1–2 h late. The workflow fetches Fingrid once at 14:00, then only re-reads the database
  every 5 minutes until 18:00, so the 13:00 row arrived only with the next morning's fetch.
- 2026-11-19 and 2026-11-20 `day_ahead_d1`/`day_ahead_d`: DE-LU sequence 1 missing at ENTSO-E
  (fixed separately by PR #35, the SMARD fill).

## Unchanged (out of scope)

- The model freeze (`tasks/own-forecast-model-data/s-model-freeze.md`): the input list, the two
  views, the cuts 11:30 and 14:30, the vintage fetch cuts (Previous Runs fetched before 10:00 and
  14:00) and the rule that the model runs only when every input of both views is ready.
- The PHP readiness facts (`laravel/app/Services/Orchestration/SForecastReadiness.php`,
  `config/orchestration.php`): a gate opens only on its existing readiness key. No new fallback.
- `SWeeklyRefit`, `MorningChain`, `ImportReplay`, the spot, maintenance and morning groups.
- The store retry rule: a failed store is never retried automatically (it points to a bug).

## Requirements

### A. Gates in `SDailyForecast`

1. **One gate per readiness input key** of both views (`day_ahead_d`, `day_ahead_d1`,
   `jao_capacity_d`, `jao_capacity_d1`, `fingrid_morning`, `fingrid_afternoon`, `weather_ecmwf_00z`,
   `weather_prev_day1_morning`, `weather_prev_day1_afternoon`, `eex_futures`) plus the two ENTSO-E
   outage fetches (`entsoe_outages_morning`, `entsoe_outages_afternoon`), which stay fetch-only
   proofs.
2. **A gate loop:** check readiness; when the key is not ready, run its fetch command (staged:
   fetch, then store, through the existing `staged_fetch()`), check again; repeat every
   `GATE_INTERVAL` (10 minutes) until the key is ready or the gate's deadline passes. A failed
   fetch inside the loop is not final: the loop waits and fetches again. A gate without a fetch
   command only re-checks.
3. **Gate table** (fetch command, earliest start, deadline; Helsinki times on D):

   | Gate | Fetch | Start | Deadline |
   |---|---|---|---|
   | `fingrid_morning` | `fingrid:fetch` | 10:05 | 11:30 (morning cut) |
   | `fingrid_afternoon` | `fingrid:fetch` | 14:00 | 18:00 |
   | `day_ahead_d` | `spot:fetch-neighbours` (FI comes from the hourly `spot-hourly`; the gate never runs `spot:fetch`) | 09:00 | 11:30 |
   | `day_ahead_d1` | `spot:fetch-neighbours` | 13:00 | 18:00 |
   | `jao_capacity_d` | `jao:fetch` | 09:00 | 11:30 |
   | `jao_capacity_d1` | `jao:fetch` | 12:00 | 18:00 |
   | `weather_ecmwf_00z` | `weather:fetch --only=ecmwf_00z` | 09:15 | 18:00 |
   | `weather_prev_day1_morning` | `weather:fetch --only=ecmwf_prev_day1` | 09:30 | 10:00 (vintage fetch cut) |
   | `weather_prev_day1_afternoon` | `weather:fetch --only=ecmwf_prev_day1` | 13:30 | 14:00 (vintage fetch cut) |
   | `eex_futures` | none (the `MorningChain` owns EEX) | 09:00 | 11:30 |
   | `entsoe_outages_morning` | the existing outage fetch | 11:00 | 11:30 |
   | `entsoe_outages_afternoon` | the existing outage fetch | 14:00 | 14:30 |

   Morning-view deadlines stay at the 11:30 cut and the vintage gates at their fetch cuts, because
   the freeze defines those inputs as of those times. The outage gates need one successful fetch
   inside their window; a failed one is repeated (interval 5 minutes, because the window is 30
   minutes). A fetch whose start-to-close would end after the gate deadline is not started (the
   existing `_fetch` budget rule).
4. **Concurrency:** the gates of a view run in parallel inside the workflow (deterministic
   `asyncio` tasks or `workflow.wait`), not as separate workflows. No Temporal signals between
   workflows: the collectors' Schedules also serve the market review and keep running unchanged.
5. **Model run:** the morning gates must all be open by 11:30 and the afternoon gates by their
   deadlines; then `predict` and `score` run exactly as now. The model still gets the same
   `--morning-cut`/`--afternoon-cut` arguments.
6. **One Issue per issue date without a forecast**, as now (`_no_forecast`): reason
   `inputs_missing` (a morning gate or a vintage/outage gate missed its deadline) or
   `deadline_missed` (an afternoon gate missed 18:00). It names every gate that missed its deadline
   and, for each, the facts of its last failed fetch (`staged.failure_facts()`) if it had one. A
   failed fetch that a later loop pass recovers sends no Issue.
7. **Early exit:** when a morning gate misses 11:30, the run ends then (the day cannot get a
   forecast); it does not wait for the afternoon gates.
8. **Workflow versioning:** the change uses `workflow.patched()` so a run that is open during the
   deploy replays its old history. The `stage` query reports the open gates.
9. **Manual starts:** `require_cut_fetches=False` keeps skipping the two outage gates. A manual
   start after a deadline treats that gate as missed at once (as `started_after_morning_cut` now).

### B. Collector fetch retries (`jobs.py`)

10. For staged steps of the `collectors` group, a failed **fetch** activity (`CommandFailed`) is
    retried every 10 minutes until the step's retry deadline; the store still runs once. Default
    retry deadline: 2 hours after the scheduled start, capped at 5 minutes before the job's next
    scheduled start (the hourly `fingrid-forecast-vintages`: 40 minutes). A recovered fetch sends
    no Issue; the Issue of a step that still fails names the last attempt's facts, once.
11. Self-reporting steps (`Step.self_reports`, the PHP command sends its own Issue per run) and the
    other groups keep the current rule (no retry of a failed command), so no attempt sends a second
    Issue. None of the collector steps self-reports today; a test keeps it so.
12. The deploy drain rules stay: an interrupted fetch repeats as now; a retry wait is a workflow
    timer, so a deploy does not lose it.

### C. Docs

13. `orchestration/AGENTS.md`: the gate loop, the table of requirement 3, the collector retry rule
    and why (this spec, "Why"); remove the statements this replaces ("No automatic retry of a
    failed command" keeps its scope for the other groups). Root `AGENTS.md` Temporal paragraph: one
    sentence on gates and collector retries. `tasks/temporal-orchestration/s-workflow-interface.md`
    if it describes the fetch times.

## Done when

- D1. `orchestration` tests (pytest, Temporal time-skipping environment) pass and cover:
  a gate opens after a late input (row appears on the 3rd check); a failed fetch is repeated and a
  later success opens the gate with no Issue; a missed afternoon deadline gives one
  `deadline_missed` Issue naming the gate and its last failure facts; a missed morning gate ends
  the run at 11:30 with one `inputs_missing` Issue; the vintage gates never fetch after their cut;
  the outage gates; the patched path replays a recorded pre-change history
  (`orchestration/tests/histories/`); collector fetch retry recovers with no Issue, and an
  exhausted retry gives one Issue; self-reporting steps are never retried.
- D2. A replay check of the 2026-11-15..19 afternoon failures: the gate loop fetches Fingrid again
  after 14:00, and Fingrid's capacity datasets publish hour H within about 2 hours (measured
  2026-11-20: at 17:57 UTC the latest 267/268 row started 16:00 UTC), so the 13:00 row is ready
  well before 18:00. Recorded with the measurement in `decisions.md`.
- D3. Laravel suite unaffected (no PHP change expected; if one is needed, its tests pass).
- D4. In production, after a separately approved deploy: five issue dates in a row end with
  `forecast_stored`, or with `no_forecast` whose missed gate was really unpublished at its deadline
  (checked against the source). Recorded in `decisions.md`.
