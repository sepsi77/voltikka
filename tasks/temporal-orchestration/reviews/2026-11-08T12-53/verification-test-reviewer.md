# test-reviewer — temporal-orchestration, phase 2 verification

Range: `12f5e21..f6452f2` · Assessed HEAD: `f6452f23dda99dd5747dc846826041147900eadb`

Result: **1 earlier finding fixed; 3 partly fixed; 0 not fixed; 0 new findings.**

This is the single verification pass. Only the fix range was checked for new defects. The earlier reports, summary and manager triage were read. The partly fixed findings below retain their earlier classification; a proposed deferral is not proof that the missing test passed.

## Earlier finding → status → evidence

| Earlier finding | Status | Evidence |
|---|---|---|
| **TST-001 — Removed schedule guarantees have no equivalent Python checks** | **Fixed** | `orchestration/tests/test_jobs.py:278-336` now has an independent expected schedule table. It checks the job inventory, command arguments, groups, Helsinki calendars, SKIP policy and actual production workflow registry. This catches the earlier deletion, timing and overlap examples. `laravel/tests/Feature/WebSchedulerTest.php:29-45` checks that collectors are absent and uncut groups remain. Restored Laravel schedule tests check the retained Spot watchdog, overlap, consumer windows and calibration schedules. The suggested CLI group-selection test is still absent, but the central schedule-guarantee defect is fixed. |
| **TST-002 — Retry tests do not join real command failures to Temporal, or test lost attempts** | **Partly fixed** | `orchestration/tests/test_jobs.py:404-412` runs the real command activity and `ArtisanJob` against a harmless local subprocess. It checks one attempt after a non-zero exit. Lines `415-431` check child termination and successful execution on a replacement worker after graceful shutdown. Lines `434-449` check that a non-interruptible command finishes once during drain. **Remaining:** no real heartbeat-loss or abrupt worker-loss test. The test at lines `181-193` raises a manufactured `ApplicationError(type="ActivityTimeout")`; it does not cause a Temporal heartbeat timeout. The shutdown tests also construct their own Worker and use a 20-second drain (`376-381`, `419`, `438`), not the production worker factory. Thus they do not prove production drain wiring or the crash path. |
| **TST-003 — Morning tests replace the checkpoint and command behavior they must prove** | **Partly fixed** | `orchestration/tests/test_jobs.py:199-273` now checks concurrent lanes, manual-subset readiness, failed-claim noon ticks, failed producers and a deadline before import starts. `laravel/tests/Feature/OrchestrationMorningReadinessTest.php:28-51` checks real database rows through the readiness command and distinguishes scheduled completion. `laravel/tests/Feature/MorningConsumerExecutionTest.php:138-188` checks early admission, freshness refusal and independent noon alert behavior at the PHP service. **Remaining:** the workflow still gets every checkpoint and consumer result from `FakeArtisan` (`orchestration/tests/test_jobs.py:42-114`). The PHP admission test supplies its own preflight and execution callbacks. No test joins Temporal, real PHP command entrypoints, real freshness gates and the isolated database. The manager explicitly proposes this real-chain test as deferred; it remains unproved, not fixed. |
| **TST-004 — One-Issue tests omit the production reporting path** | **Partly fixed** | `orchestration/tests/test_jobs.py:473-489` now runs `ArtisanJob`, the real command and Issue activities, the real Sentry interceptor and an in-memory external transport. It checks one event for an ordinary failed command and no extra event for a self-reporting failure or success. Removing the interceptor's `ReportedFailure` exclusion would now add an event and fail the test. **Remaining:** the fixture directly installs `LoggingIntegration(event_level=None)` at lines `464-470`; it does not call production `voltikka_orchestration.sentry.init` (`orchestration/src/voltikka_orchestration/sentry.py:24-34`). A regression that restores default ERROR-log events in production initialization still passes this test. The event-count path is now tested, but its production logging setup is not. |

### Remaining checks for the partly fixed findings

- **TST-002:** add a real heartbeat-timeout/replacement-worker case. Check that the old child cannot overlap the replacement. Check shutdown through the production worker factory so loss of its drain setting fails a test.
- **TST-003:** retain the proposed isolated PHP + Temporal + database test as an explicit open acceptance check before the morning cut-over. It must cover pending completion, missing/stale EEX proof, an existing running claim and one durable deadline alert. Separate PHP and Python tests do not prove this connection.
- **TST-004:** initialize Sentry through the production initializer with only its external transport replaced. Keep the real reporting activity and interceptor, then assert one event. This must fail if production ERROR logs become events again.

## New findings

New findings: none.

No new over-specified test finding was confirmed. Schedule times, command arguments, retry outcomes and claim flags express the approved requirements. No additional test regression was confirmed in the fix diff. The remaining gaps above belong to the earlier findings, not new finding IDs.

## Tests run

- `cd orchestration && UV_OFFLINE=1 SENTRY_DSN= SENTRY_LARAVEL_DSN= uv run pytest -q tests/test_jobs.py tests/test_activities.py tests/test_sentry.py tests/test_s_forecast.py` — **35 passed**, 8 Sentry sandbox import warnings. Tests used the local time-skipping server, local subprocesses and an in-memory Sentry transport.
- `cd laravel && php artisan test --filter='OrchestrationMorningReadinessTest|WebSchedulerTest|AnalyzeHistoricalContractEpisodeTest|CheckSpotPriceFreshnessCommandTest|ContractPriceCacheLifecycleTest|ExpiredFileCacheCleanupTest|FetchGasStorageCommandTest|FetchSpotCommandTest|MorningConsumerExecutionTest|PublishDailySpotCommandTest|RetailPremiumCalibrationTest'` — **121 passed**, 720 assertions.
- `git diff --check 12f5e21..f6452f2` — passed.

No Railway commands, production database access, live API calls, source edits or Git mutations were used. The only report written is this file. Other local changes were excluded.

## Files inspected

- Supplied verification prompt; `.pi/agents/test-reviewer.md`; root project instructions; `tasks/AGENTS.md`; Laravel test-isolation rules; `orchestration/AGENTS.md`; service and local Orchestration/MorningFreshness context.
- Task `spec.md`, `decisions.md`, `tasks.json`, `phase2-plan.md`; all five first-round reports and `summary.md` in this review folder.
- `orchestration/tests/test_jobs.py`, `tests/conftest.py`; relevant existing activity and Sentry tests; `src/voltikka_orchestration/{activities,jobs,schedules,sentry,worker}.py`.
- Complete Laravel morning readiness and execution tests; scoped changes in the other Laravel test files listed in the test command; scoped readiness, admission, gas-command, scheduler and process-layout diffs.
