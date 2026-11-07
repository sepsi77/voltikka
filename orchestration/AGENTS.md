# Temporal orchestration (Python worker)

Temporal workflows and the worker for Voltikka data pipelines. Task, decisions and status:
`../tasks/temporal-orchestration/` (read `phase0-decision-record.md` first). Status 2026-11-07:
phase 1, local only. No production service, schedule or Temporal namespace uses this code yet.

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
  cancellation) and `report_issue` (one Sentry Issue per outcome that needs a person, fingerprint
  `temporal-workflow` + workflow + reason).
- `s_forecast.py`: `SDailyForecast` and `SWeeklyRefit` (the S pilot). Timeline and model interface:
  `../tasks/temporal-orchestration/s-workflow-interface.md` and
  `../tasks/own-forecast-model-data/s-model-freeze.md`.
- `worker.py`: worker entrypoint (`python -m voltikka_orchestration.worker`).
- `schedules.py`: creates or updates the Temporal Schedules; prints the plan without `--apply`.
  Applying a production schedule is a production change and needs user approval.

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

## S model command

`S_MODEL_MODULE` names the Python module that the model task delivers (`python -m <module>
predict|score|train|activate …`, one JSON line on stdout). It must be installed in the same
virtual environment. Without it, model activities return `{"status": "stub"}`, so the readiness
and deadline parts can run live before the model exists.

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
`/opt/orchestration/.venv`. The worker service starts `../docker-worker-entrypoint.sh`: no
migrations, no volume, no web server, no scheduler. The web service owns migrations, so deploy the
web service first when a release has a migration.
