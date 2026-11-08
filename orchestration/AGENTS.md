# Temporal orchestration (Python worker)

Temporal workflows and the worker for Voltikka data pipelines. Task, decisions and status:
`../tasks/temporal-orchestration/` (read `phase0-decision-record.md` first). Status 2026-11-08:
phase 1. Production: Temporal Cloud namespace `voltikka-prod` (AWS eu-central-1) and the Railway
service `voltikka-worker`; see "Image and service" below.

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
`orchestration`, `forecast` and `docker-worker-entrypoint.sh`. The worker service starts `../docker-worker-entrypoint.sh`: no
migrations, no volume, no web server, no scheduler. The web service owns migrations, so deploy the
web service first when a release has a migration.

Production service (created 2026-11-08): Railway `voltikka-worker`
(`73a37ca9-6280-4695-856a-7674e548e74c`), same GHCR image and registry credential as the web
service, start command `/usr/local/bin/docker-worker-entrypoint.sh` (it replaces the image
ENTRYPOINT, so the web start script and its migrations never run here), restart policy ALWAYS, no
volume, no domain. Deploy: `../scripts/deploy-image.sh --service worker <TAG>` (production
mutation; the 04:00–09:00 window does not apply, because the web container keeps running).
Variables: `TEMPORAL_ADDRESS` (namespace endpoint `<ns>.<account>.tmprl.cloud:7233`),
`TEMPORAL_NAMESPACE` (full `<ns>.<account>`), `TEMPORAL_API_KEY` (service-account key, namespace
permission Write); everything else is a Railway reference, never a copied value: `DB_*` to
`MySQL`, the Laravel and API-key variables the artisan activities need to `voltikka`
(`APP_*`, `CACHE_STORE`, `LOG_*`, `ENTSOE_API_KEY`, `FINGRID_API_KEY`, `SENTRY_*`), and
`FORECAST_BUCKET*` to `voltikka-forecast`.
