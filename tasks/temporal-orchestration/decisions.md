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

## 2026-11-08 – Sentry interceptor and stdout logs

- `orchestration/src/voltikka_orchestration/sentry.py`: an exception from workflow code fails the
  workflow and sends one Issue (`unexpected_failure`). Chosen over endless workflow-task retries:
  the pilot has no public output, and one Issue plus a fresh run the next day is clearer than a
  stuck run that sends an event on each retry. A deploy can then fix the bug; the failed run is not
  resumed.
- Found: the default Sentry `LoggingIntegration` makes every `logger.error` an event, so
  `report_issue` (log at ERROR + `capture_message`) would have sent two events per outcome. Now
  `event_level=None`.
- An activity failure that the workflow does not handle (for example a crash of the readiness
  command) gives both the Laravel Issue of the command and this workflow Issue: the second one says
  that the day's run ended, which is a separate fact.
- Worker logs go to stdout.
- Worker deployed with `deploy-000022-696b65fa70d7` (commit 696b65f, built from a clean
  worktree because another session had staged changes in the main checkout) at 08:56 Helsinki,
  deployment `997f0dde-…` SUCCESS. The deploy waited until the own-model session's `seed-misses`
  and `upload-archive` were done (seed rows present, `archive/s/pins.json` in the bucket, no
  model process for two checks): the worker has no volume, so a restart deletes `/tmp` files. Log
  lines now have level "info". No active model version yet: today's run gives `no_active_version`
  unless a version is activated before 14:30; the first refit is 2026-11-09 10:45.

## 2026-11-08 – Phase 2 decisions and local implementation

- User request: move all data fetch pipelines and their orchestration to Temporal, so fetching does
  not depend on the main application and the no-deploy window goes away.
- User decisions (all four recommended options): Railway Redis as the shared cache store; a separate
  Railway service `voltikka-queue` for `queue:work`; all scheduled jobs to Temporal except jobs that
  need the web volume (weekly file backup, logo download); direct cut-over one group at a time
  instead of a shadow phase. Plan and runbook: `phase2-plan.md`.
- Why Redis is necessary: fetch commands write the Laravel cache (price-cache demand and
  generations, spot header, statistics warm, forecasts). The file store is on the web volume.
  A worker without the shared store would write a private cache and the web would never see it.
- Found: the worker and queue services must have the same pricing flags as the web service
  (`CANONICAL_PRICING_ENABLED`, `RESET_FORWARD_SHIFT_ENABLED`, annual method, interpretation
  settings). The current worker references only a subset; with the shared cache a different value
  would write wrong prices. Rule: reference every application variable of `voltikka`.
- Found: the Laravel scheduler failure listener alerted for every job without its own Issue. Outside
  the scheduler this listener does not run, so `ArtisanJob` sends one Issue per failed step, except
  for the three self-reporting imports (`spot:fetch`, `futures:fetch-eex`, `contracts:fetch`).
- Retry rule: a non-zero exit (`CommandFailed`) is non-retryable in every job, as with the
  scheduler; only lost attempts are retried. This keeps "no automatic retry" for imports, social
  posting and morning writers, and still makes a worker deploy safe. The worker now shuts down on
  SIGTERM (activities cancelled, child killed, retry about 30 s later on the new worker) instead of
  waiting for the 2-minute heartbeat timeout. Both cases verified on a local dev server.
- Morning chain: one `MorningChain` per date with two children (EEX at 04:00, import at 06:00). The
  consumers start when both children have ended; the `--scheduled` commands keep their gates and
  claims, so the workflow only replaces the cron ticks. Evaluation runs after import completion
  (was 07:45). Signals were not used: the parent waits on its children, which is simpler.
- `contracts:warm-cache --pending` + `contracts:cleanup-price-cache`: one Temporal job every five
  minutes (was per instance every minute). Interpretation demand can wait up to five minutes;
  imports and EEX still refresh synchronously. A one-minute schedule would cost about four times
  more Temporal Actions for no user-visible gain.
- Not done: splitting `futures:fetch-eex` into units. A worker deploy during the run repeats the
  whole run (about 1.7 h) but loses no data; split only if this becomes a real problem.
- Verified locally: PHP readiness tests (9) and the scheduler-related test files (101 tests
  pass), Python tests (23), schedule times on a dev server (UTC future actions match Helsinki
  times), an `ArtisanJob` run with real `php artisan` (success, failure with skip/always, one
  Issue), and lost-attempt retries after SIGKILL and SIGTERM. Nothing deployed, nothing committed.

## 2026-11-08 – Spec review round 2026-11-08T12-53 (Pi reviewers in T3)

- Five Pi reviewers (platform, test, laravel, pricing, refactor) ran as T3 child tasks on the Pi
  provider (`openai-codex/gpt-6.1-sol`, thinking high) instead of `pi-run.sh`. A T3 child has no
  tool limit, so `git status` was checked after the round: only reports were written.
- 21 first-round findings; the fixes (f6452f2 and later local changes) changed the plan:
  - **Staged cut-over in code** (PLT-001, PRC-001): this branch moves only the collectors group.
    The queue worker and the maintenance, spot and morning schedules stay in the web container
    until the shared Redis store is live (R2), because they write the cache. Each later group is
    its own PR (R3 queue+maintenance+spot, R4 morning). This replaces "one release removes all".
  - **Consumer lanes**: one lane per consumer; done only on `scheduled_complete` (a manual subset
    run is not the daily scope); a separate noon check ticks every incomplete consumer at 12:00
    even while producers or its own command run; a late completion (`deadline_alerted`) counts as
    incomplete; an incomplete morning sends one Issue and fails. The PHP 07:15/07:30 lower clock
    bound is removed (the Laravel cron windows keep the start until R4).
  - **Deploy drain** (PLT-006, PLT-V01): interruptible commands stop and repeat on the new worker;
    claim-based commands (completion, consumers, social posts, price-cache warm) finish within a
    65-minute drain; every claim-based timeout is at most 60 minutes (test); Railway draining
    seconds 4000 on the worker (check the maximum before R3).
  - Completion self-reports; lost/timed-out attempts of self-reporting steps are reported by the
    workflow; `gas-storage:fetch --if-configured` keeps the optional-key skip.
- Found while testing: the Temporal time-skipping test server does not move a workflow task from a
  stopped worker's sticky queue (Temporal Cloud does after a short timeout). Tests that stop a worker
  use `max_cached_workflows=0`.
- Deferred (agent decision): a real heartbeat-loss test and a real PHP + Temporal + database morning
  test; the second is a required acceptance check before the R4 PR. Verdict: ACCEPT for R1.
  Reports: `reviews/2026-11-08T12-53/` (`summary.md`, `actions.md`).

## 2026-11-08 – Pi PR review 2026-11-08T17-53

- `pi-pr-review` over the branch (base local `main`): no critical findings, four important ones.
  Fixed: `schedules --apply` needs `--group` or `--only` (a bare apply would start groups that
  still run in `console.php`); `MorningChain` refuses a past date and waits until 04:00 of a later
  date (the commands write the current Helsinki date); `ContractImport` reads the checkpoint after
  exit 0 (`incomplete` from a partial acquisition fails without a second Issue; another non-`ready`
  state fails with one `import_not_ready` Issue). Deploy-script effect texts corrected.
- Deferred: process-level recovery test (same as the spec-review deferral) and a consumer activity
  across noon (R4 acceptance test). Lessons codified in `.pi/agents/platform-reviewer.md` items 1,
  3, 4 and 7. Details: `reviews/2026-11-08T17-53/actions.md`.

## 2026-11-08 – PR #9 external review, round 1

Head cb578b6. CodeRabbit review 5818590357 (3 actionable, 1 nitpick). Greptile review 5818601216
(Plus tier, confidence 3/5, 4 findings; 2 duplicate CodeRabbit).

| Finding | Reviewer | Verdict | Action | Reaction |
|---|---|---|---|---|
| `contracts:fetch` is interruptible but holds the 30-minute import execution lock (`ContractImportCompletion::required()`, `block(10)`); a deploy kill leaves it and the retry fails | Greptile 4506869539 | Valid | `CONTRACTS_STEP` `interruptible=False`, timeout 90 → 60 min (inside the 65-min drain); drain test includes it; R4 acceptance measures the real import time | 👍 |
| A worker outage across midnight lets a morning step start on the next date (timers do not bound wall time); the Pi-round start-only check assumed otherwise | Greptile 4506869545 | Valid | `CommandRequest.date`; `run_command` refuses a passed date with `DatePassed` (non-retryable, one Issue) before PHP; every morning step carries the date; real-activity test | 👍 |
| `greptile.sh show` does not paginate reviews | CodeRabbit 4506859231, Greptile 4506869550 | Valid | `--paginate` + `tail -1` | 👍 👍 |
| `coderabbit.sh show` hides a failed `gh api` (walkthrough and review body) | CodeRabbit 4506859221, Greptile 4506869552 | Valid | API reads checked before the text filter; failure exits 1 with "result unknown" | 👍 👍 |
| spec-review launch grants `write` to read-only reviewers | CodeRabbit 4506859237 | Partly valid | `write` stays for the reviewer's own report (bash can write anyway); note added next to the command, with a `git status` check | 👍 + reply |
| `run.py` sets `PI_PR_REVIEW_MODEL` in its own environment (nitpick) | CodeRabbit body | Declined | Short-lived process; harmless; ported code kept equal to ecom-buddy | — |
| Docstring coverage pre-merge warning | CodeRabbit | Declined | Not a repository rule | — |

Codification (`.agents/skills/spec-review/missed-findings.md`):

| Finding | Owner | Cause | Instruction changed |
|---|---|---|---|
| Import lock on an interruptible step | platform-reviewer | (d) the drain rule is written; the checklist did not tell the reviewer to read each PHP command for locks and leases | `.pi/agents/platform-reviewer.md` item 2 (+ copy) |
| Date check only at workflow start | platform-reviewer | (e) the Pi round found it; the manager's partial fix assumed a run cannot cross midnight | item 1 widened: check at the activity boundary |
| Review helper scripts | none | (b) `code-review-workflow` tooling (ported), outside this task's spec-review range | none |

## 2026-11-08 – PR #9 external review, round 2

Head b7d0045. CodeRabbit review 5818634562: no inline finding; one comment outside the diff
(`laravel/routes/console.php:11-15`): the R1 web deploy removes the collector entries before the
Schedules are applied, so a delay misses triggers. Valid (runbook gap): R1 now applies the
Schedules at once after the web deployment is `SUCCESS`, in a 16:00–16:40 window, with recovery
for a missed trigger (`temporal schedule trigger`) and for a failed apply (previous web image).
No code change. Codification: platform-reviewer, cause (d): checklist item 4 covers double runs,
not the gap between the two schedulers; item 4 widened.

## 2026-11-08 – PR #9 external review, round 3

Head 0b451de. CodeRabbit: no inline finding; one comment outside the diff (`jobs.py:253-361`):
`MorningChain` took its default date from `workflow.now()` in the first workflow task, so after a
worker outage across midnight a 04:00 run did the next morning and the lost morning sent no Issue.
Valid (verified with a test that fails without the fix). The default date is now the run's start
date (`workflow.info().workflow_start_time`, also in `ContractImport`); such a run fails with "the
date has passed". Test `test_a_scheduled_morning_keeps_the_date_it_started_on_after_a_worker_outage`
(a `start_delay` stands in for the outage, because the test server does not skip time while a
workflow task waits). Codification: platform-reviewer item 1 widened, cause (d) (third finding of
the same date class; the checklist now names the default-date source).

## 2026-11-08 – PR #9 external review, round 4

Head a593060. CodeRabbit review 5818723528: no actionable comment; one trivial nitpick (the
readiness retry policy looks like a conflict with "never retry a failed command"). Valid as a
documentation gap: the exception was in `orchestration/AGENTS.md` only. Comment added next to
`READINESS_OPTIONS`, and the exception added to the `temporal-retry-and-cutover` rule in
`.greptile/config.json` so the comment does not return. No behavior change. Codification: none,
because it is a missing comment on a documented exception, not a defect.

## 2026-11-09 – PR #9 external review, round 5

Head 2b9c947. CodeRabbit: no inline finding; two comments outside the diff (`jobs.py` noon check
and lanes): an exhausted `orchestration:readiness` read (3 attempts in about one minute) ends the
whole `MorningChain`, so a short database break stops the morning. Valid (R4 code). One fix for
every caller (lanes, noon check, import loop): `READINESS_OPTIONS` retries without an attempt
limit inside a 30-minute `schedule_to_close_timeout` (interval 20 s to 2 min); a longer outage still
fails the run with one Issue (Sentry interceptor). Consumer writes keep `max_attempts=1`. Test
`test_a_short_readiness_outage_does_not_end_the_morning` (8 failed reads in a row); it fails with
the old policy. Found while testing: a stale `.pyc` (same source size and mtime second after a
restore) made one check run old code; run such checks with `PYTHONDONTWRITEBYTECODE=1`.
Codification: platform-reviewer item 2, cause (d).
