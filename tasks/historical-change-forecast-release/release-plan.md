# Release plan — not authorization

1. Review the exact diff and local verification. Do not include huge raw research exports in the release. Keep intended app code, tests, context and small task evidence only.
2. Ask for explicit branch/commit/push and normal deployment approval. One normal Git deployment includes `2026_09_14_000001_make_forecast_gap_diagnostics_nullable.php`; the Docker entrypoint runs migrations automatically. Confirm the nullable migration as part of approval.
3. Target: Voltikka project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment `9245cef8-41d0-486e-862f-193726511dba`, app service `700d0624-fa96-4266-876c-e37640d220ea`.
4. Manager's read-only preflight found no model/minimum/threshold/horizon pins. Thus code defaults should suffice. Recheck metadata if the release is delayed. Any required configuration mutation needs separate explicit approval; v1/v2/v3 generation pins fail closed.
5. The model switch hides old public forecasts until new eligible rows exist. State this release gap; do not fabricate initial rows or add deployment-time generation. Existing 07:30 generation and 07:45 evaluation schedules stay unchanged.
6. Before a manual first generation, separately inspect the exact current context with a read-only dry run: `php artisan forecasting:run-fixed-contracts --as-of=YYYY-MM-DD --horizon=30 --require-freshness --dry-run`. Production may contain newer evidence than the export.
7. Request separate approval for the exact first write command: `php artisan forecasting:run-fixed-contracts --as-of=YYYY-MM-DD --horizon=30 --require-freshness`. Replace the date with the inspected Helsinki date. This command can recover statistics with overwrite when publication order is the only failure, then recheck readiness. It is not a forecast-only write. Do not run it automatically or add a schedule.
8. Read back model/basis/mean/pair counts and null diagnostics after approved generation. Monitor compatible stored median outcomes with the existing read-only report. Old completed forecasts stay intact.

No production command, migration, configuration write, deployment, commit or push was executed by this implementation agent.
