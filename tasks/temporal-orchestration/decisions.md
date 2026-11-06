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
