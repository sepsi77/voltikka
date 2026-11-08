# laravel-reviewer — temporal-orchestration verification

Range: `12f5e21..f6452f2` · Result: 4 fixed, 1 partly fixed; no new findings.

This is the single verification pass. The earlier range and other local changes were not reviewed again. This was a static review. No tests, Railway commands, database access, live API calls, or production operations were run. The revised collectors-only release scope is accepted.

## Earlier finding → status → evidence

| Earlier finding | Status | Evidence |
|---|---|---|
| LAR-001 — Manual subset suppresses scheduled scope | **Fixed** | `laravel/app/Services/Orchestration/MorningReadiness.php:39-58` reads metadata and exports true only for `ready` with strict `scheduled_complete=true`. `orchestration/src/voltikka_orchestration/jobs.py:294-298` uses this fact, not raw `ready`, to finish a lane. The manual/scheduled distinction in `MorningConsumerExecution.php:110-113` remains. |
| LAR-002 — Old PHP start-time checks defeat the event trigger | **Fixed** | `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:20-23` keeps the same-date check but removes the lower clock bound. Its freshness, noon admission, ownership and midnight checks remain at lines 30-35, 52-73. `laravel/routes/console.php:133-158` retains the old cron windows until the morning cut-over. |
| LAR-003 — Failed and incomplete consumer days can appear successful without an Issue | **Partly fixed** | `orchestration/src/voltikka_orchestration/jobs.py:300-304` keeps a failed claim for its noon tick. Lines 275-283 report incomplete lanes and fail with `ReportedFailure`. Lines 195-198 and 224-230 also propagate producer/completion/evaluation failures. However, a lane still waits for its own command at line 306. A successful late finish can then satisfy the completion check at lines 295-298, without a noon tick or workflow Issue. See the remaining case below. |
| LAR-004 — Deferred completion reports the same failure twice | **Fixed** | `orchestration/src/voltikka_orchestration/jobs.py:171` sets `self_reports=True` for completion. Lines 107-110 and 130-134 suppress the wrapper Issue only for a self-reporting `CommandFailed`, not an exhausted lost/timed-out attempt. This agrees with the PHP reporting boundary in `laravel/app/Console/Commands/CompleteContractImport.php:19-31` and `laravel/app/Services/ContractImport/ContractImportCompletion.php:486-503`. |
| LAR-005 — Retail consumes the forecast deadline window | **Fixed** | `orchestration/src/voltikka_orchestration/jobs.py:269` starts both consumer lanes with `asyncio.gather`. Each lane awaits only its own command at lines 293-312. A slow retail command no longer delays forecast admission. Separate PHP consumer claims remain unchanged. |

### LAR-003 — Remaining case: a live command can pass noon and still produce a green morning

- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S2, S6, S7; retain the independent noon check and make incomplete morning work visible in Temporal and Sentry.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:293-312`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:106-120`; `laravel/app/Services/Orchestration/MorningReadiness.php:50-58`.
- **What remains wrong**: The noon timeout applies to the producer wait, not to an active consumer command. Each lane awaits `run_steps()` before it can reach its next deadline check. PHP permits admitted work to finish after noon. On a late success, PHP stores `deadline_alerted_at` and logs the missed deadline, but also sets `scheduled_complete=true`. Readiness exports only completion and status, not the missed-deadline fact. The lane then returns `complete`, and the parent sends no `morning_incomplete` Issue.
- **Scenario**: Retail starts at 11:55 and finishes successfully at 12:10. Forecast finishes before noon. Retail has no independent 12:00 invocation. Its PHP finish records the deadline only at 12:10. The next workflow read accepts `scheduled_complete=true`; MorningChain succeeds. The operator gets a Laravel error log, not the required workflow Issue. If retail hangs instead, the deadline check waits behind its activity timeout.
- **Fix**: Keep a separate noon task for each incomplete consumer, independent of its active command. Invoke the existing PHP deadline boundary without taking over the claim. Preserve the deadline outcome when the writer later completes; expose a safe deadline fact if needed. For example:
  ```python
  writer = asyncio.create_task(run_consumer_lane())
  deadline = asyncio.create_task(check_incomplete_at_noon())
  await asyncio.gather(writer, deadline)
  # A successful late write does not clear the recorded missed deadline.
  ```
  The affected paths are the workflow lane, readiness JSON and their tests. Do not cancel admitted work, repeat a failed writer, or change calculations. This is a remaining part of LAR-003, not a new finding.

## New findings

New findings: none.

## Files inspected

- Review prompt, reviewer definition, all five first-round reports, and `summary.md`.
- Task `spec.md`, `decisions.md`, `tasks.json`, and revised `phase2-plan.md`.
- Root/Laravel/service context and the Orchestration, MorningFreshness, ContractImport, GasStorage and Python orchestration context files.
- Fix diff; complete `jobs.py`, `activities.py`, `sentry.py`, `MorningReadiness.php`, `MorningConsumerExecution.php`, `OrchestrationReadiness.php` and `CompleteContractImport.php`.
- Consumer command call sites; completion reporting path; gas-storage command change; scheduler, schedule table, worker, Supervisor and entrypoint diffs.
- `OrchestrationMorningReadinessTest.php`, `MorningConsumerExecutionTest.php`, and the changed morning workflow tests in `orchestration/tests/test_jobs.py` (inspection only).
