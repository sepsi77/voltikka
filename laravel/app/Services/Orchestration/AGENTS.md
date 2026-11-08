# Orchestration readiness (PHP side of the Temporal workflows)

The Temporal workflows (`../../../../orchestration/AGENTS.md`) never query MySQL themselves. They
call `php artisan orchestration:readiness <pipeline> --issue-date=D --view=morning|afternoon`, which
prints one JSON line. Exit 0 = the check ran (ready or not); exit 1 = invalid arguments or an error.

## Files

- `SForecastReadiness.php`: the input facts of the own S forecast (`s_v1`).
- `MorningReadiness.php`: `orchestration:readiness morning --date=D` prints the latest status of
  the `eex_futures`, `contract_import`, `morning_retail` and `morning_forecast` checkpoints of
  Helsinki date D (null = no row), plus `scheduled_complete` per consumer: true only for `ready`
  with metadata `scheduled_complete=true`. A manual subset run is `ready` but not the scheduled
  scope. `deadline_alerted` per consumer is true when the noon alert was reserved
  (`deadline_alerted_at`), also for a scheduled run that completed after noon. The Temporal `MorningChain` uses it only to stop polling (`pending_completion` → keep
  running `contracts:complete-import`; `scheduled_complete` → consumer lane done). The commands
  keep their own gates. Test: `OrchestrationMorningReadinessTest`.
- `../../../config/orchestration.php`: the input list and the cuts. It follows
  `../../../../tasks/own-forecast-model-data/s-model-freeze.md` section 2. A change to the model's
  inputs is a change of both files.
- `../../Console/Commands/OrchestrationReadiness.php`, tests in
  `../../../tests/Feature/OrchestrationReadinessCommandTest.php`.

## Rules

- A fact is exact and checkable. No fallback: a missing input keeps the model from running.
- Only model inputs gate the run. The third-party forecast (scoring only), Fingrid 245/246 and
  the FMI forecasts must never be readiness inputs.
- Append-only vintages (`hourly_forecast_runs`) can be cut by `fetched_at`. Upserted tables
  (`fingrid_hourly_values`, `jao_max_border_flows`) rewrite `fetched_at` on each re-read, so their
  facts check only that the rows exist.
- The ENTSO-E outage fetches before each cut are not database facts: the workflow runs them and
  treats a failed fetch as a missing input.
- `checkpoint_ready` compares `updated_at` with the cut in the app time zone. Do not use
  `recorded_at`: producers pass a Helsinki Carbon value, and Eloquent stores its wall-clock time.
- The weather points come from the collector's own configuration (`points_from`), so the check and
  the collector cannot drift apart.
- `forecast_window` (Previous Runs `ecmwf_prev_day1`, `issued_at` null): every point needs a row
  fetched at or before the view's Fingrid cut (D 10:00 / D 14:00) whose hours cover the 168 hours
  before that cut. `hourly_forecast_runs` is append-only, so this fetch cut is exact. The points
  come from the collector's `point_keys` (`point_keys_from`).
- Read-only: this code never writes.
