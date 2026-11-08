# platform-reviewer — temporal-orchestration, phase 2
Range: `5b1646a..5d18ed7` (+ no local changes) · Result: 8 findings

Review method: static inspection only. No tests, production commands, live API calls, or database access. Other checkout changes are out of scope. Production rollout is still pending; the scenarios below are not claims that production changed.

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | Deploy-safe pipelines | ⚠️ Partial | Web and queue separation is present. Cancelling a claimed completion step cannot safely resume that step; see PLT-006. The approved whole-EEX retry remains a recorded limit. |
| S2 | Event chaining and noon deadline | ❌ Fail | The PHP consumers still enforce the old start times. Producer waits and serial consumer execution can delay the noon check; see PLT-002 and PLT-005. |
| S3 | Short, repeatable activities | ⚠️ Partial | Whole-EEX execution is explicitly deferred. Deferred import completion is not restartable after its durable claim; see PLT-006. |
| S4 | Shared API rate limits | ➖ N/A | Phase 3. No new finding about the approved deferral. |
| S5 | Backfill workflows | ➖ N/A | Phase 3. |
| S6 | Existing safety and failure guarantees | ❌ Fail | Durable gates remain. The noon alert and optional gas-key filter are not preserved; see PLT-002 and PLT-008. |
| S7 | Visible failures, one Issue per failure | ❌ Fail | False-success workflow results, duplicate completion Issues, and missing lost-attempt Issues; see PLT-003, PLT-004, and PLT-007. |
| S8 | Simple setup with recorded reasons | ✅ Pass | Redis, separate queue service, generic jobs, and child workflows have recorded reasons. |
| S9 | One cut-over group at a time | ❌ Fail | The committed web scheduler removes all groups, but R1 applies only collectors; see PLT-001. |
| S10 | No unapproved production mutation | ✅ Pass | No production mutation was found in the reviewed implementation. Apply commands require an explicit operator invocation. |
| S11 | Shared Redis cache | ⚠️ Partial | The image installs phpredis. Shared connection settings and Redis persistence are rollout requirements, not verified production facts. |
| S12 | Separate queue service and unchanged envelope | ✅ Pass | The queue entrypoint retains the command options. The database retry-after minimum remains 1,050 seconds. The new service is pending. |
| S13 | All schedules except web-volume jobs move | ✅ Pass | The final schedule table covers the removed jobs. The import uses `--skip-logos`; web retains file backup and logo download. Cut-over safety is separate under S9. |
| S14 | All application variables referenced | ⚠️ Partial | The plan and context file require the full variable set. Production reference parity cannot be confirmed in this read-only source review. |
| S15 | No automatic retry after command failure | ⚠️ Partial | Writer Step policies exclude `CommandFailed`; social and morning consumers have one attempt. The new read-only readiness policy still retries `CommandFailed`, contrary to the literal “every policy” rule; no writer retry was found through that path. |
| S16 | Helsinki times retained, except chains | ✅ Pass | Collector times match the old scheduler. Backup and spot timing changes are chain replacements. |
| S17 | No change to pipeline calculations | ✅ Pass | No model, pricing, forecast formula, or vintage-selection implementation changes are in this range. |

## Findings

### PLT-001 — The R1 image removes groups that R1 does not start
- **Severity**: High
- **Likelihood**: High
- **Class**: blocking
- **Spec rule**: S9; phase2-plan.md decision 4 and R1–R4.
- **Evidence**: `laravel/routes/console.php:11-32`; `tasks/temporal-orchestration/phase2-plan.md:70-71`, `:80-92`; the range contains one implementation commit, `5d18ed7`, not the three release snapshots described by the runbook.
- **What is wrong**: The committed scheduler already contains only the two volume jobs. The runbook says that R1 removes only collectors, then applies only the collectors Schedules. There is no committed R1 scheduler state that retains maintenance, spot, and morning jobs.
- **User-impact scenario**: The operator builds this revision and follows R1. The web deploy stops all old data schedules. Only collectors start in Temporal. Hourly Spot imports, nightly database backups, price-cache maintenance, and the next morning chain then have no scheduler until later rollout steps.
- **Fix**: Keep the uncut groups in the next deployable revision and remove each group in its own approved release. The R1 shape must be “Laravel B/C/D remain; Temporal A starts.” Provide tested release commits/images for the actual sequence. Do not deploy this final-state image as R1. Blast radius: release artifacts and scheduler registration; no calculation changes.

### PLT-002 — The noon check waits behind producers and consumer work
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S2 and S6; the retained 12:00 deadline and independent deadline alert in MorningConsumerExecution.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:231-247`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:29-31`, `:123-137`.
- **What is wrong**: There is no independent noon timer. Both producer children must end before the loop starts. The loop then awaits each consumer in sequence. It also excludes `failed` consumers from every later tick, including noon. The existing PHP deadline alert is reached only when its command is invoked.
- **User-impact scenario**: EEX retries after a lost attempt and remains active at noon: neither consumer gets its noon check. Alternatively, retail starts at 11:55 and runs for ten minutes: forecast cannot start before its deadline, although it could have run independently. If a consumer fails before noon and the other finishes, the workflow exits early and never reserves the failed consumer's deadline alert.
- **Fix**: Run consumer waiting/execution independently and add a durable noon timer that is not blocked by producer or consumer activities. At noon, invoke the existing alert boundary for every incomplete consumer, including failed or running claims, without reclaiming ownership. Example shape: `producer_wait`, independent consumer lanes, and `sleep_until(noon) -> deadline_checks`. Blast radius: morning workflow control flow and a read-only/status or alert boundary; retain PHP write fences and no-retry rules.

### PLT-003 — Failed morning work is shown as a completed workflow
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S7; phase 2 decision that failed work must remain visible in Temporal and Sentry.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:173-174`, `:183-206`, `:237-250`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:76-86`, `:110-119`.
- **What is wrong**: `EexFutures` returns the string `failed`, which completes the child successfully. `ContractImport` does not change its initial success status after a completion-step failure and discards evaluation outcomes. Morning consumers deliberately return exit 0 after storing a failed checkpoint; the workflow accepts that checkpoint as terminal and returns success. Their Laravel error log is not an explicit Sentry Issue.
- **User-impact scenario**: A forecast writer fails, stores `morning_forecast=failed`, and exits 0 as designed. MorningChain becomes green/completed and sends no workflow Issue. A completion failure can also be returned as `contracts=succeeded`. An operator who uses failed workflows or Sentry Issues misses the failed publication.
- **Fix**: Carry terminal checkpoint and step failures into the workflow outcome. Continue required alert/cleanup work, then fail the affected child or parent with `ReportedFailure`. For consumer failures that have only a Laravel log, send one controlled Issue before that marker. Example shape: `if failed_outcomes: raise ApplicationError(..., type=REPORTED_FAILURE)`. Do not repeat the writer. Blast radius: morning outcome mapping and reporting only.

### PLT-004 — A failed completion step sends two Issues
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S7; one Issue per failed step, no duplicates.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:108-120`, `:148-149`, `:202`; `laravel/app/Console/Commands/CompleteContractImport.php:22-31`; `laravel/app/Services/ContractImport/ContractImportCompletion.php:486-503`.
- **What is wrong**: `COMPLETION_STEP` leaves `self_reports=False`. The command already sends a `DataFetchFailureReporter('contracts')` Issue for a terminal completion failure, then returns failure. `run_steps` sends another Issue for the same failed tick.
- **User-impact scenario**: A pending import reaches its check/deadline bound. PHP records the terminal failure and sends the contracts Issue. Temporal then sends a second `command_failed` Issue for that same event.
- **Fix**: Mark completion as self-reporting for a confirmed `CommandFailed`, with separate handling for failures where the command could not report (PLT-007). Example: `COMPLETION_STEP = Step(..., self_reports=True)`. Blast radius: completion-step reporting metadata; no checkpoint or retry changes.

### PLT-005 — The old consumer start-time gates still override event chaining
- **Severity**: Medium
- **Likelihood**: High
- **Class**: normal
- **Spec rule**: S2; decisions.md “Phase 2 decisions and local implementation”; phase2-plan.md MorningChain replaces the 07:15/07:30 start times.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:231-243`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:20-23`; `orchestration/tests/test_jobs.py:139-162`.
- **What is wrong**: The workflow calls the consumers when both producers end, but the real PHP boundary still returns without work before 07:15/07:30. The test fake does not implement these guards, so its 06:02 execution is not the real command behavior.
- **User-impact scenario**: Import and EEX finish at 06:20. Temporal calls both consumers, but both silently do nothing. The workflow polls until the old clock windows open. The promised event-triggered publication does not occur.
- **Fix**: Remove only the obsolete lower-time limits for the approved Temporal path, or add an explicit chain invocation mode that bypasses those limits. Keep same-date validation, freshness, ownership, and noon admission checks. Blast radius: consumer admission timing and end-to-end tests; no calculation change. This follows the recorded phase 2 decision; it does not require a new timing policy.

### PLT-006 — Worker shutdown makes a live completion claim terminal
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S1, S3, and S6; phase 2 lost-attempt retry decision, together with the retained rule that an interrupted completion claim cannot be taken over.
- **Evidence**: `orchestration/src/voltikka_orchestration/worker.py:59-64`; `orchestration/src/voltikka_orchestration/activities.py:99-104`; `orchestration/src/voltikka_orchestration/jobs.py:83-91`, `:148`, `:202`; `laravel/app/Services/ContractImport/ContractImportCompletion.php:127-139`, `:149-151`, `:486-496`; `scripts/deploy-image.sh:73-74`, `:106`.
- **What is wrong**: SIGTERM immediately cancels activities and kills PHP. Completion persists a 30-minute claim before statistics/cache work. A retry cannot resume that claim: it returns `claimed`, then a later tick makes the expired claim terminal as `interrupted_execution`. The worker deploy path has no guard for this unsafe interval. Repeating the subprocess is not sufficient to preserve this pipeline.
- **User-impact scenario**: A worker deploy occurs while completion builds the private price generation. The old process dies with its claim intact. The retry appears successful because `claimed` exits 0. About 30 minutes later, completion fails permanently. The contract checkpoint never becomes ready, so that morning's dependent publications do not run without inspection and repair.
- **Fix**: Until completion has an approved resumable protocol, prevent worker deploy/shutdown during its live claimed section and document this exception to the general deploy-safe claim. A complete fix needs restart-safe bounded units or an approved recovery protocol. Do not simply clear or take over the claim: that reverses the retained interrupted-write safety decision and needs user authorization. Blast radius: worker deploy/drain handling and completion activity boundaries; financial logic must remain unchanged.

### PLT-007 — Self-report suppression also hides failures where PHP could not report
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S7; failed and stuck work must be visible in Sentry, with one Issue per failure.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:108-120`, `:131-134`, `:146-147`, `:173-174`; `orchestration/src/voltikka_orchestration/sentry.py:62-65`.
- **What is wrong**: `run_steps` suppresses reporting for every `ActivityError` when `self_reports=True`, not just a non-zero command result. Exhausted heartbeat/start-to-close timeouts and process-start failures can occur before the import's reporter runs. ArtisanJob then raises `ReportedFailure`, which also suppresses the interceptor; EexFutures instead returns `failed` successfully.
- **User-impact scenario**: An import hangs while heartbeats continue until its timeout, or repeated worker loss exhausts its attempts. PHP never reaches its outcome reporter. Temporal sends no Issue because the step is marked self-reporting. The EEX case can leave both child and parent completed, with only a failed result string and a stale running checkpoint.
- **Fix**: Suppress only a confirmed self-reported `CommandFailed`; report exhausted infrastructure failures once. Example shape: `already_reported = step.self_reports and isinstance(error.cause, ApplicationError) and error.cause.type == 'CommandFailed'`. Keep `ReportedFailure` suppression only after a report is known to be owned by PHP or the workflow. Blast radius: generic failure classification for the three imports and completion; no extra retries.

### PLT-008 — The optional gas-key condition was removed
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S6; preserve deliberate scheduler filters unless a change is decided.
- **Evidence**: `orchestration/src/voltikka_orchestration/schedules.py:59`; `laravel/app/Console/Commands/FetchGasStorage.php:39-42`; base `5b1646a:laravel/routes/console.php` registers this command with `when(fn () => filled(config('gas_storage.api_key')))`, with an explicit comment that a missing key must not create a daily error.
- **What is wrong**: The new Schedule always invokes the command. The command returns failure when the optional key is absent. The old intentional skip has become a daily failed workflow and Issue. No decision in the phase 2 record changes this optional-service rule.
- **User-impact scenario**: A service has no GIE key because collection has not been enabled, or the operator removes that optional key. Each 10:35 run now raises an operational error instead of remaining inactive.
- **Fix**: Preserve the configuration-aware skip in the activity/PHP boundary, or explicitly omit/pause this Schedule when the optional integration is disabled. Do not pass the key through Temporal. Blast radius: gas collector admission only; no source-data or calculation changes.

## Files inspected

- Task context: `tasks/temporal-orchestration/spec.md`, `decisions.md`, `tasks.json`, `phase2-plan.md`, and the supplied review prompt.
- Reviewer/context rules: `.pi/agents/platform-reviewer.md`, root project context, `orchestration/AGENTS.md`, `laravel/AGENTS.md`, `laravel/app/Services/Orchestration/AGENTS.md`, `laravel/app/Services/MorningFreshness/AGENTS.md`, `laravel/app/Services/ContractImport/AGENTS.md`, and `scripts/AGENTS.md`.
- Runtime: `Dockerfile`, `docker-entrypoint.sh`, `docker-worker-entrypoint.sh`, `supervisord.conf`, `scripts/deploy-image.sh`, `scripts/build-and-push-image.sh`.
- Temporal: `orchestration/src/voltikka_orchestration/{jobs,schedules,activities,worker,sentry,s_forecast,settings}.py` and `orchestration/tests/test_jobs.py`.
- Laravel boundaries: current and base `laravel/routes/console.php`, `laravel/config/{queue,cache}.php`, `laravel/app/Services/Orchestration/MorningReadiness.php`, `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php`, relevant sections of `laravel/app/Services/ContractImport/ContractImportCompletion.php`, and `laravel/app/Console/Commands/{CompleteContractImport,CheckSpotPriceFreshness,FetchGasStorage}.php`.
- Range file list, commit history, relevant diffs, and `git diff --check 5b1646a..5d18ed7` (no whitespace errors).
