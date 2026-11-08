# laravel-reviewer — temporal-orchestration
Range: 5b1646a..5d18ed7 (+ no local changes) · Result: 5 findings

Scope: phase 2, with the S1–S17 mapping in the review prompt. This was a static review. No tests, Railway commands, database access, live API calls, or production operations were run. The shared checkout advanced to `12f5e21` during review. That later commit changes external-review configuration and root context only. It is excluded. The source files cited below still match `5d18ed7`.

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | Deploy-safe pipelines | ⚠️ Partial | The final layout separates data work and the queue from the web service. Production cut-over is pending. The approved whole-EEX retry exception remains; runtime deploy safety was not tested here. |
| S2 | Event chaining and noon deadline | ❌ Fail | The PHP commands still enforce the former start times. The consumers also run in sequence, and a failed checkpoint can remove its noon tick. See LAR-002, LAR-003 and LAR-005. |
| S3 | Short, repeat-safe activities | ⚠️ Partial | Existing writer claims and output fences remain. The long EEX activity is an explicit phase 2 exception. Social and consumer lost-attempt retries remain disabled as documented. |
| S4 | Shared API rate limits | ➖ N/A | Phase 3, outside this range. |
| S5 | Backfill workflows | ➖ N/A | Phase 3, outside this range. |
| S6 | Existing freshness, checkpoint and failure guarantees | ❌ Fail | Freshness checks and write fences remain, but raw `ready` does not prove scheduled completion. Independent consumer execution and failed-day deadline checks are not preserved. See LAR-001, LAR-003 and LAR-005. |
| S7 | Visible failures; one Sentry Issue per failure | ❌ Fail | Failed or incomplete consumers can produce a successful Temporal run without an Issue. Completion failures produce two Issues. See LAR-003 and LAR-004. |
| S8 | Simplest setup with recorded reasons | ✅ Pass | Redis, the separate queue service, subprocess activities and retained PHP checkpoints have recorded reasons. |
| S9 | One cut-over group at a time | ⚠️ Partial | Group selectors and the runbook exist. This commit contains the final scheduler state, not the separate release states described in the runbook. Staged cut-over needs platform review before release. |
| S10 | No unapproved production mutation | ✅ Pass | This range supplies local code and a runbook. No production operation was performed in this review. No new migration or deployment-time history rebuild was found. |
| S11 | Shared Redis cache | ⚠️ Partial | The image enables phpredis. Shared cache configuration remains an approved rollout operation, not a verified production state. |
| S12 | Separate queue service; same queue envelope | ✅ Pass | Queue mode retains the queue names, timeout 1020, tries 3 and max-time 3600. The web Supervisor queue program is removed. |
| S13 | Move schedules; retain volume jobs | ✅ Pass | The final Laravel scheduler retains file backup and logo download. Worker contract import passes `--skip-logos`. |
| S14 | Reference all application variables | ⚠️ Partial | This is explicit in the rollout plan. Live service references were not inspected and remain pending. |
| S15 | No automatic retry after command failure | ✅ Pass | Job steps exclude `CommandFailed` from retry. Social and consumer steps allow one attempt. Readiness checks have a separate read-only retry policy. |
| S16 | Retain Helsinki times unless replaced by a chain | ⚠️ Partial | The moved job table retains the former times or uses a documented chain. The old consumer start-time checks were not removed when their clocks were replaced; see LAR-002. |
| S17 | Do not change pipeline calculations | ✅ Pass | No pricing, statistics or forecast formula changes were found in this range. The execution omissions below still need correction. |

## Findings

### LAR-001 — A successful manual subset can suppress the full scheduled consumer
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S6; `MorningFreshness/AGENTS.md`, successful manual work does not certify scheduled default scope.
- **Evidence**: `laravel/app/Services/Orchestration/MorningReadiness.php:34-41`; `orchestration/src/voltikka_orchestration/jobs.py:40,237-240`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:33-35,110-113`; `laravel/app/Console/Commands/CollectRetailPremiumObservations.php:20-31,67-83,104-115`.
- **What is wrong**: The new readiness result exports only `status`. `MorningChain` treats every `ready` consumer as complete. The PHP execution protocol intentionally distinguishes manual success (`ready`, `scheduled_complete=false`) from full scheduled success (`ready`, `scheduled_complete=true`). A manual subset therefore becomes a false proof that the full daily scope is complete. Both consumer keys use this lossy read path.
- **User-impact scenario**: An operator successfully runs `retail-premiums:collect --contract=<id>` before the morning chain reaches its consumers. The chain sees `morning_retail=ready` and never invokes the full scheduled collection. The remaining contracts receive no daily collection, while the chain ends successfully. A manual partial forecast can also be skipped without the inspection that the scheduled forecast command would otherwise require.
- **Fix**: Export a safe completion fact from checkpoint metadata and use it in the consumer stop rule. For example:
  ```python
  done = status == "ready" and scheduled_complete is True
  ```
  A failed status can stop new writes, but it must not be treated as successful completion. Keep raw producer statuses separate. Blast radius: `MorningReadiness`, its JSON consumer in `jobs.py`, readiness tests, and morning-chain tests. Retain both PHP commands' existing ownership and output checks; do not infer completion from stored output alone.

### LAR-002 — The old PHP start-time checks defeat the new event trigger
- **Severity**: Medium
- **Likelihood**: High
- **Class**: normal
- **Spec rule**: S2, S16; `tasks/temporal-orchestration/phase2-plan.md:40-44` explicitly replaces the 07:15/07:30 start times.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:229-243`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:20-24`; both commands call this execution service (`CollectRetailPremiumObservations.php:67`, `RunFixedContractPriceForecasts.php:100`).
- **What is wrong**: Temporal starts consumer ticks after the two producers end, but the common PHP service returns before claiming or checking freshness until 07:15 for retail and 07:30 for forecasts. The implementation therefore starts no consumer work early, even when all evidence is ready. Removing the cron entries did not remove the second clock gate.
- **User-impact scenario**: EEX and import completion are ready at 06:20. Every early tick is a successful no-op. Retail and forecast output still wait for their former clock times, contrary to the approved event-chain behavior. The Python fake-command test does not exercise this PHP guard.
- **Fix**: Remove the lower time-of-day bound from the scheduled execution gate. Keep the same-Helsinki-date check, freshness checks, claims, noon admission limit and midnight fences:
  ```php
  if ($scheduled && $date->toDateString() !== $now->toDateString()) {
      return 0;
  }
  ```
  Blast radius: the shared execution service and both consumers' timing tests. No calculation or producer retry policy needs to change. This implements the recorded decision; it does not require reversing it.

### LAR-003 — Failed and incomplete consumer days can appear successful without an Issue
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S2, S6, S7; `phase2-plan.md:40-44` requires the deadline tick. The existing execution protocol keeps deadline reporting independent of failed writers.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:99-120,237-250`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:76-86,110-119,123-137`; `laravel/config/logging.php:76-78`; `orchestration/src/voltikka_orchestration/sentry.py:57-67`.
- **What is wrong**: A durable scheduled execution failure deliberately returns exit 0 after storing `failed` and writing a Laravel error log. Waiting at noon also returns 0 after its deadline log. `run_steps()` sees only successful command exits, and the parent returns a normal result. No exception reaches the Sentry interceptor. The configured `sentry_logs` driver sends logs, not Issues. In addition, `TERMINAL` removes failed consumers from all later ticks. Thus a consumer that fails before noon never receives its independent noon check; when both statuses are terminal, the workflow ends before noon.
- **User-impact scenario**: Forecast generation encounters incompatible existing rows at 08:00. PHP safely stores `failed` and sends no exception Issue. Retail succeeds. Temporal shows a successful morning chain, no Sentry Issue is created for the failed forecast, and no noon deadline fact is recorded. If dependencies instead stay unavailable all morning, the final waiting/deadline outcome also returns a successful workflow without an Issue.
- **Fix**: Read safe consumer outcome facts, not just process exits. Report a stored failure or missed deadline once and finish the affected run with `ReportedFailure`, without retrying the writer. Keep a noon status-check tick for incomplete consumers, including failed ones; a tick on a failed claim must not execute output work. Target shape after required checks:
  ```python
  if failed_or_deadline_missed:
      await report(safe_issue)
      raise ApplicationError("Morning work incomplete", type=REPORTED_FAILURE)
  ```
  Blast radius: readiness output, consumer outcome handling, terminal/noon scheduling, and chain/reporting tests. Keep Laravel error logs and existing durable alert deduplication. Do not change the intentional exit-0 tick protocol into automatic retries.

### LAR-004 — Deferred import completion reports the same failure twice
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S7; `tasks/temporal-orchestration/decisions.md:237-239` intends one Issue per failed step, but its list of self-reporting commands omits completion.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:112-120,152,202`; `laravel/app/Console/Commands/CompleteContractImport.php:19-31`; `laravel/app/Services/ContractImport/ContractImportCompletion.php:486-503`.
- **What is wrong**: `COMPLETION_STEP` uses the default `self_reports=false`. A terminal completion outcome already sends the explicit contracts import Issue before returning failure. The command also sends that Issue when an unexpected completion exception is caught. The new wrapper then sends another `ContractImport/command_failed` Issue for the same non-zero exit.
- **User-impact scenario**: Pending completion reaches `checks_exhausted`, or its checkpoint write fails. The operator receives both the contracts failure Issue and a Temporal command failure Issue for one completion failure. The duplicate uses a different fingerprint, so normal grouping does not remove it.
- **Fix**: Mark the completion step as self-reporting for ordinary reported command failures:
  ```python
  COMPLETION_STEP = Step(["contracts:complete-import"], timeout_minutes=35, self_reports=True)
  ```
  Keep an orchestration alert for failures where the process never reaches its reporting boundary, such as an exhausted activity timeout; do not use the flag as proof that a killed process reported. Blast radius: completion step configuration, the shared reporting boundary and tests for terminal completion, caught exceptions and lost attempts. No PHP completion proof or write behavior needs to change. This corrects the incomplete command inventory in the decision; it does not change its one-Issue rule.

### LAR-005 — Retail execution can consume the forecast's remaining deadline window
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S2, S6; the two existing consumers have separate claims and the same noon deadline. The phase 2 decision replaces their triggers, not their independence.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:41-44,241-247`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:30-31,52-60`; removed independent background schedules in `laravel/routes/console.php` in this range.
- **What is wrong**: The consumer loop awaits the entire retail activity before it starts the forecast activity. Each activity can run for 120 minutes. The former schedules ran independently in the background. A slow retail run now prevents an otherwise ready forecast from claiming its work before noon. Separate checkpoint ownership cannot help because the second command has not started.
- **User-impact scenario**: The producers finish at 11:50. Retail starts and takes 15 minutes. Forecast evidence was ready at 11:50, but its command starts only at 12:05. The PHP noon gate correctly refuses new work, so that day's public forecast is absent solely because the unrelated retail collector ran first.
- **Fix**: Run the two bounded consumer loops independently after the producer join, with their own deadline checks. For example, use two concurrent workflow tasks, each awaiting only its own command and poll timer. Keep each consumer's one-attempt activity policy and durable PHP claim. A failed consumer must not cancel or delay the other consumer's admitted work. Blast radius: `MorningChain` consumer scheduling and tests with late readiness and a slow retail activity. No change to command calculations or freshness requirements is needed.

## Files inspected
- Review prompt; `.pi/agents/laravel-reviewer.md`; root project instructions supplied in context; `laravel/AGENTS.md`; `tasks/AGENTS.md`.
- `tasks/temporal-orchestration/{spec.md,decisions.md,tasks.json,phase2-plan.md}`.
- `orchestration/AGENTS.md`; `orchestration/src/voltikka_orchestration/{jobs.py,schedules.py,activities.py,sentry.py}`; relevant helper in `s_forecast.py`; `orchestration/tests/test_jobs.py`.
- `laravel/app/Services/{AGENTS.md,Orchestration/AGENTS.md,MorningFreshness/AGENTS.md,ContractImport/AGENTS.md,Caching/AGENTS.md,RetailPremium/AGENTS.md,PriceForecasting/AGENTS.md}`; `laravel/app/Support/AGENTS.md`.
- `laravel/app/Services/Orchestration/MorningReadiness.php`; `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php`; relevant completion paths in `laravel/app/Services/ContractImport/ContractImportCompletion.php`.
- `laravel/app/Models/DataFreshnessCheckpoint.php`; `laravel/config/{cache.php,logging.php}`.
- `laravel/app/Console/Commands/{OrchestrationReadiness.php,CompleteContractImport.php,RunFixedContractPriceForecasts.php,EvaluateFixedContractPriceForecasts.php,CheckSpotPriceFreshness.php,DownloadLogos.php}`; relevant caller sections in `CollectRetailPremiumObservations.php` and `FetchContracts.php`.
- Reporting references in `laravel/app/Console/Commands/{FetchWeather.php,FetchGasStorage.php,PublishDailySpot.php}`, `laravel/bootstrap/app.php` and `laravel/app/Providers/AppServiceProvider.php`.
- `laravel/routes/console.php`, including its base-commit version; `Dockerfile`; diffs of `docker-entrypoint.sh`, `docker-worker-entrypoint.sh`, `supervisord.conf`.
- Laravel test diffs: `AnalyzeHistoricalContractEpisodeTest`, `CheckSpotPriceFreshnessCommandTest`, `ContractPriceCacheLifecycleTest`, `ExpiredFileCacheCleanupTest`, `FetchSpotCommandTest`, `MorningConsumerExecutionTest`, `OrchestrationMorningReadinessTest`, `PublishDailySpotCommandTest`, `RetailPremiumCalibrationTest`, `WebSchedulerTest`. Test source was inspected only; tests were not run.
