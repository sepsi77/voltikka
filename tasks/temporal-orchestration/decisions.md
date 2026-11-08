# Decisions: Temporal orchestration

## 2026-11-02 – Task created

- User request: move the scheduled tasks and their orchestration to Temporal. Reason (user): the
  current pipelines are fragile and cause "deployment arithmetic"; workflows should signal other
  workflows when they complete, or trigger the next workflow at their end.
- Trigger: the own-forecast-model deploy (image deploy-000015-b155d208ea65) had to be timed
  between the 04:00 EEX run, the 06:00 contract import and its pending completion, the 07:15/07:30
  morning consumers and a 12:30 manual backfill, after about 10 hours of production backfills
  that blocked every deploy (tasks/own-forecast-model-data/decisions.md, 2026-11-01/02).
- Observed root cause to verify in phase 0: `supervisord.conf` runs web, scheduler and queue
  worker in one container, so every deploy stops all data jobs.
- Nothing about hosting, SDK or process layout is decided. Phase 0 must also compare with the
  minimal alternative (a separate scheduler/queue service), because the repository rule is the
  simplest solution that fully solves the problem.

## 2026-11-06 – Forecast workflows added to the scope

- User request: add the forecast information to this task. Spec section "Forecast workflows".
- The own models (S, M) are not in production. Plan them as workflows from the start, so they
  never get a clock-based scheduler version that must be moved later.
- S should start on an event (the FI day-ahead result for D+1 is stored), not on a clock. The
  user asked for a run right after the result; experiment E32 tests it (D 14:30 issue, D+2..D+8)
  against the 11:30 issue. Final issue time follows the E32 result.
- E30 (2026-11-06) showed that a weekly refit is better than monthly or quarterly. So production
  needs a weekly training workflow with a validation gate and an atomic model switch, apart from
  the daily forecast run.
- The models are Python. A Python Temporal worker on its own task queue is an option for the
  open own-model runtime decision; decide both in phase 0. Nothing is decided yet.

## 2026-11-06 – Forecasts run only with all inputs ready

- User decision: "We should not run the forecast with inputs missing. This has to be handled in
  the orchestration layer, we will only run the model once all of the inputs are ready."
- So: no run with a missing input, no empty values, no older vintage as a fallback. The workflow
  waits for (or starts) each input fetch and starts the model only when all inputs are ready.
- Consequence: if an input is not ready at the deadline, that issue has no forecast; a Sentry
  Issue names the input. The model code keeps no fallback logic for missing inputs.
- Still open: the deadline per run, and the exact readiness fact for each input (spec,
  "Forecast workflows"). Applies to S and M.

## 2026-11-06 – The own S forecast is the pilot workflow

- User decision: the S forecast becomes the first Temporal workflow, and the work is done as a
  part of this task, not in `tasks/own-forecast-model-data/`.
- It replaces the morning chain as the phase 1 pilot. Reasons: S is a new pipeline, so no
  scheduler entry must move; its first weeks are a live test with no public output; it needs the
  parts that this task needs anyway (event trigger, input fan-in with a deadline, a weekly
  workflow, a Python worker).
- E32 fixed the S run design (`tasks/own-forecast-model-data/decisions.md`): one run after the
  D+1 prices, D+2..D+7, both input views ready before the run.
- Division of work: the own-model task delivers the frozen model, the forecast and training code
  that the workflow calls, the append-only vintage storage and the score calculation. This task
  owns the workflows, the readiness facts, the deadlines, the runtime and the worker.
- The morning chain moves to phase 2 (shadow first, then cut over).

## 2026-11-07 – Phase 0 decision record drafted

- Draft: `phase0-decision-record.md`. It waits for user approval; nothing changed in production.
- New fact: the price cache is the file store on the web service volume, and a Railway volume
  belongs to one service. So cache-writing jobs (contract import, warm-cache) cannot move to a
  separate service without a shared cache store. This affects the minimal alternative and
  Temporal in the same way; the decision is deferred to the start of phase 2, because the S
  pilot writes only to MySQL.
- Proposals: Temporal over the minimal alternative; Temporal Cloud Developer plan (no base fee,
  $50 per million Actions, estimate under $5 per month) in AWS eu-central-1; Python as the only
  Temporal SDK, with PHP work as `php artisan` subprocess activities and readiness facts as one
  read-only artisan command; one image and a new `voltikka-worker` service; patching plus replay
  tests instead of Worker Deployment versioning; keep `data_freshness_checkpoints` as the truth.
- S pilot draft: a 13:30 Schedule opens the wait, five-minute readiness polls, no model run
  before 14:30, deadline proposal D 18:00, weekly refit Monday 03:00 with a validation gate.

## 2026-11-07 – Phase 0 approved

- User: "Approved". All proposals of `phase0-decision-record.md` apply: Temporal, Temporal Cloud
  Developer plan, Python as the only SDK with artisan-subprocess activities, one image with a
  `voltikka-worker` service, patching plus replay tests, checkpoints stay, S deadline D 18:00.
- The user creates the Temporal Cloud account and namespace. A Railway storage bucket is
  permitted if phase 2 needs shared storage for the price cache.
- Another agent session finalises the frozen S model (`tasks/own-forecast-model-data/` phase 3).
  This task builds the worker, readiness command and workflows meanwhile, with a stub model
  activity, and does not edit that task's files.
- Open: the refit gate margin.

## 2026-11-07 – Phase 1 local work and the model freeze

- Built locally (not committed, not deployed): `php artisan orchestration:readiness`
  (`laravel/app/Services/Orchestration/`, `laravel/config/orchestration.php`), the Python package
  `orchestration/` (worker, `SDailyForecast`, `SWeeklyRefit`, schedules CLI, tests), a Python layer
  in `Dockerfile` and `docker-worker-entrypoint.sh`.
- The model session froze `s_v1` (`../own-forecast-model-data/s-model-freeze.md`) and sent
  corrections. Applied: removed FMI ECMWF, solar GHI, Fingrid 245 and the third-party vintage from
  the run readiness (not model inputs; the third-party vintage belongs to scoring); added the
  Open-Meteo `ecmwf_00z` run of D at all 38 points (exact `issued_at` = D 00:00 UTC), SE1/SE3/EE/
  DE-LU day-ahead through D+1, JAO capacity through the end of D and D+1 (six borders the model
  uses), Fingrid 267/268 capacity; the ENTSO-E outage fetch before each cut stays a workflow step.
- The refit is train → activate: the frozen method has no validation gate. The earlier open item
  "refit gate margin" is closed.
- Weather readiness has no fetch cut and is checked in the afternoon poll: both views use the same
  00 UTC run, and its fetch time was not part of the training data.
- The workflow starts at 10:55, not 13:30: it must run the morning outage fetch itself before the
  11:30 cut, because a successful fetch is not a database fact (outage rows are versioned, not
  stamped per fetch). A late manual start can skip both outage-fetch proofs only explicitly
  (`require_cut_fetches=false`).
- The Temporal Python sandbox proxies `zoneinfo`; a proxied ZoneInfo is not a tzinfo. The workflow
  uses the EU summer-time rule as pure arithmetic, tested against zoneinfo for every hour of
  2024–2030.
- Checkpoint readiness uses `updated_at`, not `recorded_at`: producers pass a Helsinki Carbon value
  to `recorded_at`, and Eloquent stores its wall-clock time, so the column is Helsinki time in a
  UTC application.
- Verified: PHP tests (6) in both app time zones, Python tests (13), one end-to-end run on a local
  Temporal dev server with the real readiness command (result: no forecast, missing
  `eex_futures`, because the local database has no checkpoint), and the Python layer built on the
  production base image (Python 3.13.18, about +180 MB before the model dependencies).

## 2026-11-07: Coordination note from the own-model session answered

- The note (`../own-forecast-model-data/coordination-temporal.md`, written 2026-11-06) matches the
  readiness list already applied, with one change: the outage fetch now asks only for the outages
  that `s_v1` reads (FI and SE3 A77/A80, A78 SE1_FI/SE3_FI/EE_FI). Before, it fetched every
  configured zone, so a failed SE1/SE2 unit (not a model input) would have stopped the run.
- Answers to its three questions are in `s-workflow-interface.md` ("Answers to the own-model
  session"): model processes use MySQL directly with the Laravel credentials and write only their
  own tables (Laravel migrations); fitted versions in the private archive bucket with a
  `model_versions` row; a separate package `forecast/` (`voltikka_forecast.s`), started as a child
  process and pinned through a uv path dependency in `orchestration/uv.lock`.
- Python tests: 13 pass.
- The own-model session accepted the answers (2026-11-07, section 5 of its note) and confirmed the
  late D+1 price rule with conditions (all five zones; `afternoon_cut` always 14:30; the vintage
  records its real start; no prices by 18:00 = no vintage). The workflow already meets them: no
  code change. The `ecmwf_00z` morning availability time is not measured yet (first live tick
  2026-11-07 09:15; research assumption about 09:00 Helsinki); the workflow checks the weather only
  in the afternoon poll, so the 18:00 deadline does not depend on it today.

## 2026-11-07: Model package integrated; refit moved to Monday 10:45

- From the own-model note (sections 7–9): the refit starts on Monday at 10:45 and runs
  `fingrid:fetch` itself before `train`, because Sunday's Fingrid hours are training targets and a
  03:00 refit is not the backtest's refit. Train and activate must finish before 14:15, so the
  active version never changes under the 14:30 predict run; after that the run fails with
  `refit_deadline_passed` and the old version stays.
- New readiness type `forecast_window` for `ecmwf_prev_day1` (`temp_south_7`): each of the five
  southern points needs a row fetched before the view's Fingrid cut that covers the 168 hours
  before it. Its collector is not deployed yet, so the check fails in production until it is.
- Model failures no longer fail the workflow: `predict` → "no forecast" + one Issue with the closed
  error key (for example `artifact_mismatch` after a model-code deploy); `score` → one Issue, the
  forecast stays. `run_command` passes the key in the failure details.
- `forecast/` is a non-editable uv path dependency of the worker; `orchestration/uv.lock` has the
  same version of every package that `forecast/uv.lock` has (checked). The image copies it and sets
  `S_MODEL_MODULE=voltikka_forecast.s`. Condition of the agreement met: the model session reports
  E32 identical on the package code (its note, section 9).
- Found and fixed: `.dockerignore` is an allowlist and did not contain `orchestration`,
  `forecast` or `docker-worker-entrypoint.sh`, so the committed Dockerfile (8047898) could not
  build. A worker-layer build on the production base image (linux/amd64) with the real context now
  passes; the image grows by about 570 MB (600 → 1,174 MB) with the model libraries.
- Told the own-model session: without `DB_HOST` the model command ends with a traceback and no JSON
  line, so the activity sees only `no_json_error`. Fixed by the model session in 9eef5e5: a missing
  `DB_*` variable or a failed connection now gives `{"status":"failed",...,"error":"internal_error"}`
  and exit 1 (checked from the worker environment). No activity change and no refit needed.

## 2026-11-08 – Temporal Cloud namespace settings (proposal, docs read)

- Docs read (docs.temporal.io/cloud: namespaces, regions, api-keys, service-accounts,
  permissions-reference, billing). Proposal to the user: namespace `voltikka-prod` in AWS
  eu-central-1, retention 7 days, authentication **API keys** (chosen at creation; a change needs
  Support), no CA certificate, no codec server.
- Key: a namespace-scoped service account (account role Read, namespace permission **Write**), not
  the namespace page's "Generate API Key" (that gives Admin). Write covers worker polling, starting
  workflows and Schedule create/update. Expiry at most 2 years; rotate by overlap.
- Worker values: `TEMPORAL_ADDRESS=<ns>.<account>.tmprl.cloud:7233` (namespace endpoint),
  `TEMPORAL_NAMESPACE=<ns>.<account>` (full ID). Temporal Cloud has no spending limit; at our
  volume no alert is built. Add a payment method before the trial credit ends.
- Checked read-only (names only): the web service has `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`, so the worker can reference them for the model.

## 2026-11-08 – Production rollout (user: "You can handle the rest")

- User created the namespace (`voltikka-prod.lw6f1`, values in `laravel/.env`, not printed) and
  approved the rest: commit, push, image, Railway service, variables, worker deploy, schedules.
- Commit `220c32c` pushed. Image `deploy-000021-220c32cd0a2c` (PHP 8.4.27; worker, model and
  scikit-learn 1.9.1 import in the image). The web service was not deployed: this release has no
  migration and the web code path is unchanged; it gets the image at its next normal release.
- Railway service `voltikka-worker` (`73a37ca9-6280-4695-856a-7674e548e74c`, europe-west4,
  restart ALWAYS, no volume, no domain): one `railway environment edit` JSON patch with the image,
  the start command `/usr/local/bin/docker-worker-entrypoint.sh` (replaces the image ENTRYPOINT),
  the registry credential copied from the web service config through a pipe, the three
  `TEMPORAL_*` values from `laravel/.env`, and references for everything else (`DB_*` to MySQL,
  Laravel/API keys to `voltikka`, `FORECAST_BUCKET*` to `voltikka-forecast`). No value printed.
- Deployment `b3e32a5f-…` SUCCESS; log: "Worker started: namespace voltikka-prod.lw6f1, task
  queue voltikka-main". `orchestration:readiness` runs in the worker against production MySQL.
  Python logs go to stderr, so Railway shows them with level "error" (cosmetic).
- Schedules created: `s-forecast-daily` (next 2026-11-08 10:55 Helsinki) and `s-refit-weekly`
  (next 2026-11-09 10:45).
- Open: the own-model session's one-time `seed-misses` and `upload-archive` in the worker (they
  need its local research files). Until then `predict` gives `no_active_version` (one Issue per
  day) and the refit gives `refit_invalid`. The Sentry interceptor for unexpected workflow
  failures is not built.
