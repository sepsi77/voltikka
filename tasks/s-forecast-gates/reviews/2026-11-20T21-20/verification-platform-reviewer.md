# platform-reviewer — s-forecast-gates verification
Range: `64bc535e7ea3..6369b5f3` · Result: 2 fixed, 1 partly fixed; no additional findings

Static verification only. No tests, production access, Temporal Cloud access or live API calls. Local changes were limited to the review directory.

## Finding verification

### PLT-001 — fixed

The manager-approved `fetch_incomplete` exception is implemented in both loops:

- `orchestration/src/voltikka_orchestration/staged.py:83-92` maps this store outcome to acquisition failure (`fetch`). This matches the actual PHP outcome at `laravel/app/Console/Commands/FetchFingridData.php:191-195` and `laravel/app/Console/Concerns/StagedImport.php:175-186`; `activities.py:151-158` preserves its error key in `CommandFailed` details.
- `orchestration/src/voltikka_orchestration/s_forecast.py:227,340-348` preserves the payload reference and keeps gate fetches enabled for acquisition failures; other store failures still disable fetches.
- `orchestration/src/voltikka_orchestration/jobs.py:204-240` repeats fetch and store only within an enabled collector retry window, resets the payload reference for the next acquisition, and reports only the final failure. Replay-only stores cannot enter this loop, and self-reporting commands have no retry deadline (`jobs.py:162-167`).
- Added recovery/exhaustion cases inspected: `orchestration/tests/test_jobs.py:544-568` and `orchestration/tests/test_s_forecast.py:579-603`. Genuine failed-store cases remain final.

The shared helper's other caller, `SWeeklyRefit`, still receives failure facts rather than acquiring a new retry loop (`s_forecast.py:533`).

### PLT-002 — partly

**Implemented:** checks beginning before the cut receive the remaining schedule-to-close budget; failed deadline-limited reads become missed gates; results crossing the cut are rejected (`orchestration/src/voltikka_orchestration/s_forecast.py:365-379`). The last check and collection-time reporting are implemented (`s_forecast.py:290-297,318-333,352`).

**Still outstanding — a scheduled gate can take the retrospective/manual exception after an ordinary late pass.**

- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: requirements 3, 5 and 7; decisions.md, “Spec review 2026-11-20T21-20 fixes”, PLT-002. The decision permits a retrospective check for a manual start after the deadline, not for a scheduled gate resuming after it.
- **Evidence**: `orchestration/src/voltikka_orchestration/s_forecast.py:322-329,352,361-379`; parent collection at `s_forecast.py:288`; upserted-input check at `laravel/app/Services/Orchestration/SForecastReadiness.php:110-127`.
- **What is wrong**: both the budget restriction and late-result rejection require `started < deadline`. A check beginning at or after the deadline gets the normal 30-minute budget and may open the gate, irrespective of whether this was a manual start. `_gate()` calls `_key_ready()` before checking expiry. The parent still waits for that task rather than independently settling the gate at the cut.
- **Scenario**: the scheduled `fingrid_morning` gate checks false at 11:25 and starts a fetch. That activity exhausts its remaining budget at 11:30. Its failure returns, the sleep target (11:28) is already past, and the next loop starts a readiness call after 11:30. That call now has 30 minutes. A collector stores the previously missing capacity row at 11:31; a queued/retried readiness attempt returns ready afterward. The gate opens and, if the other gates succeed, the run stores a forecast without the required missed-cut Issue, even though the morning input was absent at 11:30. A worker interruption across the cut reaches the same branch.
- **Fix**: distinguish a genuinely retrospective manual run from a delayed iteration of an already-running gate. Refuse a new scheduled read at/after expiry and apply the late-result guard independently of the individual read's start time. For example:
  ```python
  # allow_retrospective is fixed from the run's start/mode, not this pass's clock.
  if workflow.now() >= deadline and not allow_retrospective:
      return None
  # ... bounded readiness call ...
  if workflow.now() > deadline and not allow_retrospective:
      return None
  ```
  Blast radius: `_gate`/`_key_ready` and their caller's manual-mode handling; add a delayed-fetch/worker-resumption case whose next readiness call would begin after the cut. Keep the approved retrospective manual behavior and frozen model unchanged. The added tests at `test_s_forecast.py:606-631` do not exercise this entry-after-deadline path.

### PLT-003 — fixed as decided

The manager replaced the next-start-cap promise with a narrower retry policy; that decision is implemented:

- `orchestration/src/voltikka_orchestration/schedules.py:114-134` assigns 120 minutes only to staged, non-self-reporting collector steps with a shortest start gap of at least six hours.
- Hourly Fingrid vintages, hourly ECMWF 00z and the four-hour-gap Previous Runs job remain at zero; the complete expected table is checked in `orchestration/tests/test_jobs.py:596-617`.
- `orchestration/src/voltikka_orchestration/jobs.py:162-167,231-235` measures the window from workflow start and permits retries only before its deadline. Store drain behavior is retained (`jobs.py:170-174,224`; `staged.py:43-46`).

This verifies the revised requirement 10, not the superseded guarantee that every store completes before the next scheduled start.

## New findings

Findings: none beyond the outstanding portion of PLT-002 above.

## Files inspected

- First-round platform report; task `spec.md`, `decisions.md`, `tasks.json`.
- Root context supplied with the review; orchestration, Laravel orchestration, morning-freshness and import-payload context files; phase 2 plan; spec-review skill.
- Fix diff, including documentation and tests.
- `orchestration/src/voltikka_orchestration/{jobs.py,s_forecast.py,schedules.py,staged.py,activities.py}` (changed paths and materially affected callers).
- `orchestration/tests/{test_jobs.py,test_s_forecast.py}` (static inspection only).
- `laravel/app/Console/Concerns/StagedImport.php`, `laravel/app/Console/Commands/FetchFingridData.php`, `laravel/app/Services/Orchestration/SForecastReadiness.php`.

`git diff --check 64bc535e7ea3..6369b5f3` passed.
