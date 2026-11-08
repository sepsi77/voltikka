# pricing-reviewer — temporal-orchestration phase 2 verification

Range: `12f5e21..f6452f2` · Result: 3 earlier findings fixed; 0 new findings.

This is the single verification pass. Evidence refers to `f6452f2`. The earlier reports, the manager's triage, and the S1–S17 mapping in `summary.md` were read. Other local changes are excluded. This was a static review only. No tests, Railway commands, database access, live API calls, or production operations were run. Only this report was written.

## Earlier finding → status → evidence

| Earlier finding | Status | Evidence |
|---|---|---|
| PRC-001 — Cache writers move before the shared cache exists | **Fixed** | `supervisord.conf:25-33` restores the queue worker in the web container. `laravel/routes/console.php:17-51` retains import, completion, cache warm and cache cleanup there; `:125-168` retains EEX and both morning consumers. `tasks/temporal-orchestration/phase2-plan.md:75-95` now limits R1 to collectors, puts shared Redis in R2, and moves queue/cache-writing schedules only in R3 or later. `:105-108` requires cache writers to return to the web container before a return to `CACHE_STORE=file`. Thus the earlier EUR 500 retained-price / EUR 600 replacement example no longer has a planned split-store interval. Actual production store visibility remains a release check, not a result of this review. |
| PRC-002 — Retail work consumes the forecast's pre-noon window | **Fixed** | `orchestration/src/voltikka_orchestration/jobs.py:267-270` starts both consumer lanes with `asyncio.gather`. Each lane owns its command and wait loop at `:285-313`; it does not await the other consumer. Both keep one activity attempt at `:293`. With both inputs ready at 11:55, a ten-minute retail run no longer prevents the forecast command from starting at 11:55. Source assertions for concurrent activity are at `orchestration/tests/test_jobs.py:217-222`; these tests were inspected, not run. |
| PRC-003 — Old PHP start-time gates delay event-ready forecasts | **Fixed** | `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:20-24` removes only the lower clock bound. Same-date admission remains there; noon admission, freshness and owner/date fences remain at `:30-35`, `:52-64` and `:69-74`. The pre-cut-over Laravel windows remain at `laravel/routes/console.php:133-138` and `:153-158`. After the future morning cut-over, the Temporal lanes can therefore invoke both consumers when producers finish, without waiting for 07:15/07:30. `laravel/tests/Feature/MorningConsumerExecutionTest.php:139-168` checks early waiting and early execution through the PHP service; tests were not run. |

## New findings

New findings: none.

## Verification notes

- The new readiness output requires both `ready` and strict `scheduled_complete=true` (`laravel/app/Services/Orchestration/MorningReadiness.php:50-58`). The lanes use that fact (`orchestration/src/voltikka_orchestration/jobs.py:294-298`), not raw manual-subset success. This does not weaken the PHP freshness or output checks.
- No changed annual-cost formula, VAT conversion, annual method, forecast model, price-cache schema, or historical financial writer was found in the fix range. No pricing-method history rebuild is required for these fixes (S17).
- This report verifies the three earlier pricing findings and checks the fix for new pricing defects. It does not certify all platform failure, shutdown, or deadline-reporting paths. The real PHP + Temporal + database chain remains unproved here; the manager records its test as deferred.

## Files inspected

- Review prompt, `.pi/agents/pricing-reviewer.md`, all five first-round reports, and `summary.md`.
- Task `spec.md`, `decisions.md`, `tasks.json`, and `phase2-plan.md`.
- Root and relevant Laravel, orchestration, canonical pricing, statistics, caching, forecasting, retail-premium, card, morning freshness, and readiness context.
- Fix-range file list and diffs; full `orchestration/src/voltikka_orchestration/jobs.py`, `MorningConsumerExecution.php`, and `MorningReadiness.php`.
- Scoped changes in `activities.py`, `worker.py`, `schedules.py`, `docker-entrypoint.sh`, `supervisord.conf`, and `laravel/routes/console.php`; comparison of the restored web schedule with `5b1646a`.
- Consumer/readiness assertions in `orchestration/tests/test_jobs.py`, `laravel/tests/Feature/MorningConsumerExecutionTest.php`, and `laravel/tests/Feature/OrchestrationMorningReadinessTest.php`.
