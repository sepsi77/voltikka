# refactor-reviewer — temporal-orchestration
Range: 5b1646a..5d18ed7 (+ no local changes) · Result: 1 finding

## Findings

### REF-001 — Old schedule rules conflict with the new schedule owner
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S8; root `AGENTS.md`, Documentation Maintenance: keep context files correct after a behavior change. S9 requires one schedule owner. The 2026-11-08 phase 2 decision moves jobs to Temporal; `phase2-plan.md`, Workflows, explicitly puts social publication after the hourly import.
- **Evidence**: `laravel/AGENTS.md:498-501` says that the following schedules use Temporal, but then describes a 60-minute Laravel overlap lock and independent social publication at minute 15. `AGENTS.md:431` repeats the independent minute-15 rule. `laravel/AGENTS.md:412-417` still directs agents to `routes/console.php` for backup scheduling and gives separate 03:30/03:45 cleanup and monitor times. The implemented owners are `orchestration/src/voltikka_orchestration/schedules.py:79-83` (backup, then cleanup and monitor) and `:95-98` (hourly import, then social publication). `laravel/routes/console.php:10-14` explicitly excludes these jobs.
- **What is wrong**: The change adds a new schedule description but leaves the old descriptions beside it. There are now conflicting instructions for the same jobs. The old descriptions can document pre-cutover production, but they need that label and a separate pointer to the local phase 2 behavior. In particular, the new sentence at Laravel line 498 makes the next three sentences read as descriptions of the new Temporal schedules. This is documentation residue, not a request to reverse the approved chaining decision.
- **User-impact scenario**: During a social-publication delay, an agent follows the root minute-15 rule and looks for a missing independent schedule. It can propose a second schedule instead of inspecting the `spot-hourly` workflow. During a backup incident, an operator can expect a monitor run at 03:45 even though the local implementation runs it after the backup finishes. Both errors make incident diagnosis slower and can lead to a second schedule owner.
- **Fix**: Remove the repeated old schedule details, or label them explicitly as pre-cutover production. Keep one short phase 2 description and point to the schedule table. For example:
  ```text
  Production before phase 2: independent social publication at minute 15.
  Phase 2, local only: spot-hourly starts at minute 0; publication is its
  always step after spot:fetch. Schedule owner: orchestration/.../schedules.py.
  ```
  State that backup cleanup and monitoring are steps after the 03:00 backup in phase 2. **Blast radius**: `AGENTS.md:429-434`, `laravel/AGENTS.md:410-420,498-501`; their `CLAUDE.md` symlinks need no separate edit. No runtime, caller, or test change is needed. Do not restore the removed schedules.

## Review notes

- The shared `ArtisanJob` and existing PHP commands avoid a second domain implementation. The new readiness service only reads checkpoint status. No speculative configuration or parallel pricing system was found.
- The checked `CLAUDE.md` files are symlinks to their sibling `AGENTS.md` files.
- Platform overlap: retry/cancellation correctness, alert delivery, and production cutover sequencing belong to the platform review and are not repeated here.
- Tests were not run, as instructed. No Railway command, live API call, database access, source edit, or Git mutation was made. Other local changes were excluded. Evidence line numbers refer to commit `5d18ed7`.

## Files inspected

- `.pi/agents/refactor-reviewer.md`; root `AGENTS.md` (relevant rules and scoped diff); `tasks/AGENTS.md`.
- `tasks/temporal-orchestration/spec.md`, `decisions.md`, `tasks.json`, `phase2-plan.md`, and this review's prompt.
- `orchestration/AGENTS.md`; `src/voltikka_orchestration/{jobs,schedules,activities,s_forecast,sentry,worker}.py`; `orchestration/tests/test_jobs.py`.
- `laravel/AGENTS.md`; `laravel/app/Services/{AGENTS.md,Orchestration/AGENTS.md,MorningFreshness/AGENTS.md,Caching/AGENTS.md,SpotSocial/AGENTS.md}`.
- `laravel/app/Console/Commands/OrchestrationReadiness.php`; `laravel/app/Services/Orchestration/MorningReadiness.php`; checkpoint-key references in `laravel/app/Models/DataFreshnessCheckpoint.php` and the existing morning consumer.
- `laravel/routes/console.php`; the scoped test changes in `AnalyzeHistoricalContractEpisodeTest`, `CheckSpotPriceFreshnessCommandTest`, `ContractPriceCacheLifecycleTest`, `ExpiredFileCacheCleanupTest`, `FetchSpotCommandTest`, `MorningConsumerExecutionTest`, `OrchestrationMorningReadinessTest`, `PublishDailySpotCommandTest`, `RetailPremiumCalibrationTest`, and `WebSchedulerTest`.
- Scoped diffs in `Dockerfile`, `docker-entrypoint.sh`, `docker-worker-entrypoint.sh`, `supervisord.conf`, and `scripts/deploy-image.sh`; `scripts/AGENTS.md`.
