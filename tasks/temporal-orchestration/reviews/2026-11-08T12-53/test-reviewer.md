# test-reviewer — temporal-orchestration, phase 2
Range: 5b1646a..5d18ed7 (+ no scoped local changes) · Result: 4 findings

## Spec checklist
| # | Spec item | Test(s) | Verdict | Notes |
|---|---|---|---|---|
| S1 | Deploy-safe pipelines | `WebSchedulerTest::test_the_web_scheduler_runs_only_the_volume_jobs`; `AnalyzeHistoricalContractEpisodeTest::test_historical_job_and_http_policy_fit_the_worker_envelope` | ⚠️ Partial | Web schedule removal and queue options are checked. No automated lost-attempt or worker-shutdown test. TST-002. Deploy-window removal is phase 4. |
| S2 | Event chaining and noon deadline | `test_morning_chain_starts_consumers_when_both_producers_have_ended`; `test_morning_chain_ticks_once_at_noon_for_the_deadline_alert` | ⚠️ Partial | Temporal timers run, but an internal fake supplies all checkpoint changes. TST-003. |
| S3 | Repeat-safe activities | `test_failed_step_is_not_retried_skips_later_steps_but_runs_always_steps` | ⚠️ Partial | No real interrupted activity test. TST-002. The 2026-11-08 decision permits the unsplit EEX command; this review does not reopen that decision. |
| S4 | Shared API rate limits | — | ➖ N/A | Phase 3. |
| S5 | Backfill workflows | — | ➖ N/A | Phase 3. |
| S6 | Existing guarantees | Changed Laravel command tests; `MorningConsumerExecutionTest`; phase 2 Python tests | ⚠️ Partial | Command-level checks remain. Schedule coverage, command retry behavior, the PHP/workflow checkpoint connection, and Issue counts have gaps. TST-001–004. |
| S7 | Visible failure, one Issue | `test_failed_step_is_not_retried_skips_later_steps_but_runs_always_steps`; `test_a_self_reporting_command_gets_no_second_issue`; `test_a_bug_in_workflow_code_fails_the_run_with_one_issue` | ⚠️ Partial | No test combines the new job failure path with the real Issue activity and production interceptor. TST-004. |
| S8 | Simple setup with recorded reasons | `decisions.md`; `phase2-plan.md` | ➖ N/A | Documentation requirement. Reasons are recorded. |
| S9 | Exclusive cut-over per group | `WebSchedulerTest::test_the_web_scheduler_runs_only_the_volume_jobs`; `test_every_schedule_starts_a_registered_workflow_and_groups_cover_all` | ⚠️ Partial | Final web schedule is checked. The Python test does not prove the expected contents of each rollout group. TST-001. Production cut-over is pending. |
| S10 | No unapproved production changes | — | ➖ N/A | Production work is pending. No production command was run during this review. |
| S11 | Shared Redis cache | Queue-envelope and cache lifecycle tests | ⚠️ Partial | These tests do not prove a shared Redis store. Redis service and variable checks belong to the pending R0/R2 rollout. No production result is claimed. |
| S12 | Separate queue; same queue envelope | `AnalyzeHistoricalContractEpisodeTest::test_historical_job_and_http_policy_fit_the_worker_envelope` | ✅ Pass | The existing policy assertions now read the queue entrypoint. Timeout, retry-after, tries and queues remain checked. Service startup is a rollout check. |
| S13 | Move all non-volume jobs; skip logos | `WebSchedulerTest`; `test_morning_chain_starts_consumers_when_both_producers_have_ended`; schedule inventory test | ⚠️ Partial | `--skip-logos` and the two remaining web jobs are checked. An omitted Temporal job can pass the inventory test. TST-001. |
| S14 | Same application variables on all services | — | ➖ N/A | Recorded R0 rollout requirement; no variable provisioning in this range. |
| S15 | Retry lost attempts, not failed commands | `test_failed_step_is_not_retried_skips_later_steps_but_runs_always_steps`; `test_failed_command_raises_with_only_the_error_key` | ⚠️ Partial | These tests exercise separate halves, not the actual command error through Temporal. No lost-attempt test. TST-002. Social and morning retry exceptions remain accepted. |
| S16 | Preserve Helsinki schedules except chains | Schedule inventory test; existing Helsinki helper tests | ⚠️ Partial | No assertion checks a moved schedule's calendar or overlap policy. TST-001. |
| S17 | No calculation-method changes | — | ➖ N/A | No calculation-method implementation change in the reviewed range. |

## Findings

First pass: no confirmed over-specified assertion was found in the changed tests. Queue options, command order, poll intervals and checkpoint JSON fields express documented requirements. No assertion needs to be loosened merely because a job moved to Temporal.

### TST-001 — Removed schedule guarantees have no equivalent Python checks
- **Severity**: High
- **Likelihood**: High
- **Class**: blocking
- **Spec rule**: S6, S9, S13, S16; phase2-plan.md decisions 3–4 and “Groups and order of cut-over”.
- **Evidence**: `orchestration/tests/test_jobs.py:181-187`; `orchestration/src/voltikka_orchestration/schedules.py:56-128`; `laravel/tests/Feature/WebSchedulerTest.php:15-26`. The range removes schedule assertions from `CheckSpotPriceFreshnessCommandTest`, `FetchSpotCommandTest`, `ContractPriceCacheLifecycleTest`, `MorningConsumerExecutionTest`, `PublishDailySpotCommandTest`, and `RetailPremiumCalibrationTest`.
- **What is wrong**: The inventory test compares groups, schedules and counts that all come from the same `JOBS` table. It checks neither an independent required-job list nor calendars, policies, arguments, or production workflow registration. Delete the calibration entry from `JOBS`, change the freshness check from :10 to :00, or change SKIP to ALLOW_ALL: the current assertions still pass. The test name also claims registration coverage without reading the production `WORKFLOWS` registry.
- **User-impact scenario**: A moved collector or calibration job silently stops running, or an overlap starts two writers. The web schedule test still passes because the old job is correctly absent there.
- **Fix**: Add `test_phase2_schedule_inventory_and_times_match_the_approved_plan`, with an independent table of required jobs, groups, command arguments and Helsinki calendars. Check action targets against the production worker registry and check SKIP. Include the independent :10 watchdog, five-minute cache job, monthly calibration, and the approved chains. Add a group-selection test at the schedules CLI boundary. Fake only the external Temporal connection if needed. Blast radius: tests only; retain the approved timing changes rather than restoring old cron times.

### TST-002 — Retry tests do not join real command failures to Temporal, or test lost attempts
- **Severity**: High
- **Likelihood**: High
- **Class**: blocking
- **Spec rule**: S1, S3, S6, S15; decisions.md 2026-11-08 “Phase 2 decisions and local implementation”, retry rule and local SIGTERM/SIGKILL checks.
- **Evidence**: `orchestration/tests/test_jobs.py:48-56,84-89,111-125`; `orchestration/tests/test_activities.py:47-52`; `orchestration/src/voltikka_orchestration/activities.py:94-98,114-119`; `orchestration/src/voltikka_orchestration/worker.py:59-64`; `orchestration/src/voltikka_orchestration/jobs.py:81-90`.
- **What is wrong**: The workflow fake manufactures `CommandFailed`. The real activity test checks only error text, not its Temporal error type or retry behavior. Remove `type="CommandFailed"` from the real activity: both tests still pass, but a failed import becomes retryable. No test interrupts an actual activity and starts a replacement worker. The recorded manual shutdown experiments do not prevent a later regression.
- **User-impact scenario**: A failed import runs several times without inspection, or a worker release leaves an activity unable to resume. A child process that survives shutdown can overlap its replacement.
- **Fix**: Add `test_real_nonzero_command_is_attempted_once` through `ArtisanJob` and the actual `Activities.run_command`. Add `test_lost_command_attempt_resumes_on_replacement_worker`, using a local harmless child process, a temporary result store, and the Temporal test server. Check child termination, replacement execution and one durable result. Cover shutdown and heartbeat-loss paths. Keep social and morning `max_attempts=1` exceptions; do not reverse the recorded policy. Blast radius: local test fixtures and tests only; no EEX splitting or production restart.

### TST-003 — Morning tests replace the checkpoint and command behavior they must prove
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S2, S6; test-reviewer real-chain rule; phase2-plan.md “Workflows”.
- **Evidence**: `orchestration/tests/test_jobs.py:31-73,84-89,142-178`; `laravel/tests/Feature/OrchestrationMorningReadinessTest.php:14-30`; `laravel/tests/Feature/MorningConsumerExecutionTest.php:24-43,63-80,136-155`.
- **What is wrong**: Every morning workflow test replaces the command activity and the checkpoint store with `FakeArtisan`. The fake makes EEX ready immediately and makes the consumers ready without running freshness gates or durable claims. The Laravel readiness test only prints pre-seeded statuses. The execution-service tests prove useful local rules, but do not connect those rules to Temporal. Thus no test proves that deferred completion, an interrupted consumer, and the noon tick work through the new command connection. Removing the wait for a slow EEX child would also evade the existing happy-path scenario, where EEX finishes immediately.
- **User-impact scenario**: The workflow can read the wrong checkpoint, stop polling before real completion, or reach noon without a durable deadline alert. Operators see missing morning output even though the fake-based tests are green.
- **Fix**: Add `test_morning_workflow_uses_real_checkpoint_commands_and_deadline`, with an isolated test database and real Laravel console entrypoints/services. Fake external HTTP and time only. Exercise a producer that ends after the other producer, real pending-completion state, stale or missing EEX proof, and a running consumer claim. Assert no output from unproved evidence, no takeover of the claim, completion only after the real proof passes, and one stored deadline alert. Keep the small fake-based tests as workflow unit tests. Blast radius: test fixtures and integration tests only; no freshness-policy change.

### TST-004 — One-Issue tests omit the production reporting path
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S6, S7; decisions.md 2026-11-08 phase 2 one-Issue rule.
- **Evidence**: `orchestration/tests/test_jobs.py:76-89,111-139`; `orchestration/tests/test_sentry.py:43-71`; `orchestration/src/voltikka_orchestration/sentry.py:24-34,55-65`; `orchestration/src/voltikka_orchestration/activities.py:122-136`.
- **What is wrong**: Job tests replace `report_issue` with a list append and omit `SentryInterceptor`. Sentry tests run only `Buggy` and `Fine`, with logging integrations disabled. Neither test reaches `ReportedFailure` through the real reporting activity. Remove the new `already_reported` exclusion: the current suite still passes, but a failed job sends another Issue. The suite also cannot detect duplicate events from error logging plus explicit capture.
- **User-impact scenario**: Each ordinary failed maintenance step creates duplicate Issues, or a report activity stops delivering Issues while list-based assertions remain green.
- **Fix**: Add `test_failed_artisan_job_emits_one_sentry_event` with the real Issue activity, production Sentry interceptor and logging configuration, and an in-memory Sentry transport at the external boundary. Check exactly one event and failed Temporal status. Add self-reporting-import and successful-job cases to check no extra workflow event. Blast radius: tests only; no live Sentry traffic or reporting-policy change.

## Tests run
- `cd orchestration && UV_OFFLINE=1 uv run pytest -q tests/test_jobs.py tests/test_activities.py tests/test_sentry.py tests/test_s_forecast.py` — **23 passed**, 8 Sentry sandbox import warnings. The cached local time-skipping server was available.
- `cd laravel && php artisan test --filter='OrchestrationMorningReadinessTest|WebSchedulerTest|AnalyzeHistoricalContractEpisodeTest|CheckSpotPriceFreshnessCommandTest|ContractPriceCacheLifecycleTest|ExpiredFileCacheCleanupTest|FetchSpotCommandTest|MorningConsumerExecutionTest|PublishDailySpotCommandTest|RetailPremiumCalibrationTest'` — **104 passed**, 586 assertions.
- `git diff --check 5b1646a..5d18ed7` — passed.

No Railway commands, production database access, live API requests, source edits or Git mutations were used. Other sessions changed the shared checkout during the review; the reviewed source and test paths had no changes relative to the specified head when checked.

## Files inspected
- Root `AGENTS.md` context; `.pi/agents/test-reviewer.md`; `tasks/AGENTS.md`.
- `tasks/temporal-orchestration/{spec.md,decisions.md,tasks.json,phase2-plan.md}` and the supplied review prompt.
- `laravel/AGENTS.md`, especially Test isolation; `laravel/app/Services/{AGENTS.md,Orchestration/AGENTS.md,MorningFreshness/AGENTS.md}`.
- `orchestration/AGENTS.md`.
- `orchestration/src/voltikka_orchestration/{activities.py,jobs.py,schedules.py,sentry.py,worker.py}`.
- All five files in `orchestration/tests/`: `conftest.py`, `test_activities.py`, `test_jobs.py`, `test_s_forecast.py`, `test_sentry.py`.
- `laravel/app/Console/Commands/OrchestrationReadiness.php`; `laravel/app/Services/Orchestration/MorningReadiness.php`.
- All ten changed Laravel test files listed in the test command above; complete `MorningConsumerExecutionTest.php` and `OrchestrationMorningReadinessTest.php`, plus changed assertions and retained test entry points in the other files.
- Scoped diffs for `laravel/routes/console.php`, `Dockerfile`, `docker-entrypoint.sh`, `docker-worker-entrypoint.sh`, and `supervisord.conf`.
