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
