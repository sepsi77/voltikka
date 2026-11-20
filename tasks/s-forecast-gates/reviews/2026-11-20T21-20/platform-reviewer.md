# platform-reviewer — s-forecast-gates
Range: b2754eb8d283..64bc535e7ea3 (local changes: none) · Result: 3 findings

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | One gate per readiness key and two outage proofs | ✅ Pass | Keys/views match the PHP configuration. |
| S2 | Check/fetch/repeat until ready or deadline | ❌ Fail | Ordinary HTTP failures can disable further fetches (PLT-001). |
| S3 | Gate table, Helsinki deadlines and outage intervals | ❌ Fail | Table/timezone arithmetic agree; readiness can open a gate after its deadline (PLT-002). |
| S4 | Parallel workflow-local gates, no signals | ✅ Pass | Ordered task creation, workflow timers, no cross-workflow signals. |
| S5 | All inputs ready; unchanged predict/score cuts | ⚠️ Partial | Commands/cuts unchanged; a late read can falsely certify an on-time gate (PLT-002). |
| S6 | One Issue with missed gates/failure facts | ⚠️ Partial | Normal aggregation sends once; acquisition failures are mislabeled as store failures, and late reads can avoid the missed-gate outcome. |
| S7 | Early end at a missed morning cut | ❌ Fail | Collection waits for readiness completion, not the absolute cut (PLT-002). |
| S8 | Patch old histories; stage query exposes gates | ✅ Pass | Old path, replay fixtures/tests and gate query inspected. Tests not executed. |
| S9 | Manual starts and skipped outage gates | ✅ Pass | Matches recorded interpretation: late manual checks may accept already-ready facts but never fetch. |
| S10 | Collector retries, one store, bounded window | ❌ Fail | Real acquisition failures evade retry; next-start cap reserves only one store attempt (PLT-001, PLT-003). |
| S11 | Self-reporting steps and other groups unchanged | ✅ Pass | Collector-only Schedule assignment, self-reporting guard and policy test present. |
| S12 | Deploy drain and durable timer waits | ✅ Pass | Workflow timer waits; stores remain non-interruptible and below the drain. Total next-start budget separately defective (PLT-003). |
| S13 | Documentation updated | ✅ Pass | Root, orchestration context and workflow interface updated; actual deadline guarantees need the corrections below. |
| S14 | D1 listed tests | ⚠️ Partial | Requested scenario tests exist; immediate fake command failures miss the actual PHP acquisition outcome (PLT-001). No tests run, as instructed. |
| S15 | D2 replay check recorded | ✅ Pass | Measurement and inferred recovery timeline recorded; not an actual historical source replay. |
| S16 | D3 Laravel unaffected | ✅ Pass | PHP change is a configuration comment only. Cross-language behavior nevertheless needs PLT-001. |
| S17 | D4 post-deploy production check | ➖ N/A | Explicitly pending separately approved deployment. |

## Findings

### PLT-001 — Normal API failures bypass both new retry loops
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S2, S6, S10; `decisions.md`, “A failed store stops the gate's fetches” and “Collector fetch retries”.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:160-175,209-232`; `orchestration/src/voltikka_orchestration/s_forecast.py:324-327`; `laravel/app/Console/Commands/FetchFingridData.php:134-148,191-195`; `laravel/app/Console/Concerns/StagedImport.php:80-103`. Siblings: `FetchFingridForecastVintages.php:108-117,174-178`, `FetchJaoMaxBorderFlows.php:117-125,168-179`, `FetchWeather.php:235,529-532`.
- **What is wrong**: Collectors retry only a failed `--fetch-only` activity. Fingrid catches HTTP/request failures, records failed units, and returns normally; the trait prints `status=fetched` and exits 0 despite failed acquisition. Only the subsequent store exits 1 with `fetch_incomplete` and JSON `step=fetch`. Python discards that distinction: a failed store activity is always classified `step=store`. Collectors end immediately; S gates set `fetch=None` for the rest of the day. Weather, JAO and Fingrid vintages share this mismatch. This is concrete integration evidence against the decision's assumption that a failed store points to a bug: here it is a recoverable API outage, not a parser/database defect.
- **User-impact scenario**: At 14:00 the afternoon Fingrid gate encounters a temporary HTTP failure for capacity dataset 268. Fetch saves a payload and exits 0; store skips that dataset and exits `fetch_incomplete`. The gate disables fetches. Fingrid recovers at 14:05 and publishes the required 13:00 row later, but there is no later daily Fingrid collector. The gate only reads until 18:00 and loses the forecast with misleading store-failure facts. Likewise, an hourly vintage collector's HTTP failure gets no ten-minute retry, losing that vintage despite recovery inside its retry window.
- **Fix**: Classify acquisition incompleteness at the fetch boundary, before committing the one chosen store. Preserve raw payloads and distinguish transport failures from parse/database failures; keep genuine store failures non-retryable. Target shape:
  ```python
  fetched = await fetch_once()
  if acquisition_failed(fetched):
      # Retain reference/facts; retry FETCH within its window.
      ...
  else:
      await store_once(fetched)
  ```
  Blast radius: shared staged-result handling in `s_forecast.py`/`jobs.py`, plus the PHP fetch outcome contract if necessary. Preserve existing partial-acquisition behavior explicitly. Add a real-command HTTP-failure/recovery case for Fingrid and check sibling collectors; immediate fake `CommandFailed` cannot demonstrate this fix. No frozen model change is needed.

### PLT-002 — Readiness retries can open morning gates after the frozen cut
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S3, S5, S7; frozen input cuts; `decisions.md`, “Collection and early end”, and manager's 30-minute readiness retry decision.
- **Evidence**: `orchestration/src/voltikka_orchestration/s_forecast.py:94-99,278-286,311-317,334-342`; `laravel/app/Services/Orchestration/SForecastReadiness.php:110-127`; `forecast/src/voltikka_forecast/s/data.py:137-141`.
- **What is wrong**: Readiness has an independent 30-minute schedule-to-close, not the gate's remaining budget. `_gate()` accepts a ready response **before** testing its deadline. The parent waits for gate tasks without an absolute-cut watchdog. Upserted-input readiness checks row existence, not whether the row existed by 11:30. A read retried or queued across the cut can therefore certify data first stored after the cut. The documented last check and early end are not hard boundaries. Longer read retries do not justify accepting post-cut input.
- **User-impact scenario**: The 11:25 `fingrid_morning` readiness activity fails during a database outage and retries. At 11:30 the workflow still waits. At 11:36 the database recovers and a collector stores the previously missing 09:00 capacity row. Readiness returns ready at 11:37; the gate opens, and predict later stores a forecast using that row. The input was unavailable at its frozen deadline, but the run reports success rather than no forecast. A false result instead delays morning failure past the cut.
- **Fix**: Make scheduled gate expiry independent of readiness completion; an unproven post-deadline observation must not open it. Bound retries by the remaining gate budget and settle an unresolved gate at its absolute deadline:
  ```python
  remaining = deadline - workflow.now()
  options = dict(READINESS_OPTIONS,
                 schedule_to_close_timeout=min(remaining, READINESS_BOUND))
  # An absolute deadline closes an unresolved scheduled gate.
  # A late result cannot reopen it.
  ```
  Keep the documented retrospective manual-check behavior separate. Blast radius: `_gate`, `_key_ready`/`_readiness`, collection logic, and tests with delayed/retried readiness results. Patch changes where necessary for histories created by the current gate implementation; leave frozen model files unchanged.

### PLT-003 — Collector cap does not bound the complete store before the next start
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S10; `decisions.md`, “Deadline computation” (last store ends five minutes before the next start).
- **Evidence**: `orchestration/src/voltikka_orchestration/schedules.py:112-135,144`; `orchestration/src/voltikka_orchestration/jobs.py:126-146,180-184,223`; `orchestration/src/voltikka_orchestration/staged.py:43-46`; `laravel/app/Console/Concerns/StagedImport.php:35-37,137-144`.
- **What is wrong**: The cap subtracts only `min(step.timeout_minutes, 60)`: one store attempt's start-to-close. Store actually permits three lost-attempt attempts and up to one day of schedule-to-close, including queue delay. Nothing ties total store duration to the next-start boundary. The promised guarantee fails during a crash or activity backlog, while Schedule overlap is SKIP. This concerns already-permitted lost attempts, not retrying a failed store command.
- **User-impact scenario**: The 10:45 Fingrid-vintage collector retries fetch at 11:25 and succeeds at 11:29:50, before its 11:30 fetch deadline. Store reaches its ten-minute local timeout at 11:39:50 and loses its lock-owning process. Its permitted retry starts about 30 seconds later and can wait five minutes for the orphaned store lock, until 11:45:20. At 11:45 the prior workflow remains open, so the Schedule skips that hour's new vintage. Even without a lost process, store queueing is not covered by the cap. A later snapshot cannot reconstruct the missed forecast vintage.
- **Fix**: Budget the entire fetch/store run against an absolute next-start-minus-margin deadline, not one store attempt. Reserve the full permitted store retry budget in the fetch cap and/or propagate a total store budget including queueing and retries:
  ```python
  store_options["schedule_to_close_timeout"] = min(
      STORE_SCHEDULE_TO_CLOSE, run_deadline - workflow.now()
  )
  ```
  Reject exhausted budgets before scheduling; retain per-attempt drain/lease limits and report the final failure once. Blast radius: collector Schedule input/budget calculation, `fetch_step` → `run_step` store handoff, and tests with a delayed or lost last store. Other groups and the frozen model need not change.

## Files inspected
- `AGENTS.md`; `orchestration/AGENTS.md`; `forecast/AGENTS.md`; Laravel orchestration, morning-freshness and import-payload context files.
- `tasks/s-forecast-gates/{spec.md,decisions.md,tasks.json}`; current review prompt/directory (no earlier round present).
- `tasks/temporal-orchestration/{phase2-plan.md,s-workflow-interface.md}`; `tasks/own-forecast-model-data/s-model-freeze.md`.
- `orchestration/src/voltikka_orchestration/{s_forecast.py,jobs.py,schedules.py,staged.py,activities.py,sentry.py,worker.py}`.
- `orchestration/tests/{test_s_forecast.py,test_jobs.py}` and replay-fixture changes; installed SDK activity option serialization inspected for context only.
- `laravel/config/orchestration.php`; `laravel/app/Services/Orchestration/SForecastReadiness.php`; `laravel/app/Services/ImportPayload/ImportPayloadWriter.php`.
- `laravel/app/Console/Concerns/StagedImport.php`; commands `OrchestrationReadiness.php`, `FetchFingridData.php`, `FetchFingridForecastVintages.php`, `FetchJaoMaxBorderFlows.php`, `FetchEntsoeTransparencyData.php`, `FetchWeather.php`.
- `forecast/src/voltikka_forecast/s/data.py` (unchanged reader, to verify the cut boundary).

Static review only. No tests, live APIs, production access, deployment or Schedule mutation performed.
