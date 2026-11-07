# Orchestration readiness (PHP side of the Temporal workflows)

The Temporal workflows (`../../../../orchestration/AGENTS.md`) never query MySQL themselves. They
call `php artisan orchestration:readiness <pipeline> --issue-date=D --view=morning|afternoon`, which
prints one JSON line. Exit 0 = the check ran (ready or not); exit 1 = invalid arguments or an error.

## Files

- `SForecastReadiness.php`: the input facts of the own S forecast (`s_v1`).
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
- Read-only: this code never writes.
