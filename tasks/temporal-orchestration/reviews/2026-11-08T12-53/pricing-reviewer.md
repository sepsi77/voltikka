# pricing-reviewer — temporal-orchestration phase 2
Range: 5b1646a..5d18ed7 (+ no local changes) · Result: 3 findings

Read-only review. Other working-tree changes are out of scope. No tests, live APIs, Railway commands, or database commands were run. Numeric examples below are illustrative inputs, not production measurements.

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | Deploy-safe pipelines | ⚠️ Partial | Separate services are defined. Shared-cache safety during the release sequence needs correction; see PRC-001. |
| S2 | Event chaining and noon deadline | ❌ Fail | The PHP commands still enforce their old earliest start times. Serial consumer execution can also prevent a ready forecast from starting before noon. |
| S3 | Short, repeatable activities | ⚠️ Partial | The recorded exception for the full EEX command is accepted. No change to pricing mathematics was found. Full activity-repeat safety is outside this pricing review. |
| S4 | Shared API rate limits | ➖ N/A | Phase 3. |
| S5 | Backfill workflows | ➖ N/A | Phase 3. |
| S6 | Existing guarantees | ⚠️ Partial | Pricing evidence and writer fences remain. Cache visibility and independent consumer progress have gaps; see PRC-001 and PRC-002. |
| S7 | Failure visibility | ⚠️ Partial | Explicit reporting is present. This review does not certify all platform failure paths. |
| S8 | Simplest working setup | ✅ Pass | Existing PHP calculations and checkpoints are reused. Reasons for Redis and the separate queue are recorded. |
| S9 | One release group at a time | ⚠️ Partial | The runbook specifies groups. Its cache prerequisite order is unsafe. Release packaging needs platform review. |
| S10 | No unapproved production changes | ✅ Pass | This range supplies local code and a runbook, not an automatic production apply. |
| S11 | Shared Redis cache | ❌ Fail | The final layout uses Redis, but R1 moves the queue before R2 establishes the shared store. The stated Redis rollback also restores isolated stores. |
| S12 | Separate queue, same job envelope | ✅ Pass | The queue command retains its queue list, timeout, tries, and maximum time. |
| S13 | Move jobs except volume jobs | ✅ Pass | Cache maintenance and morning pricing producers are in the Temporal job table. Import uses `--skip-logos`. |
| S14 | Matching application variables | ✅ Pass | The runbook requires references for every application variable, including pricing flags. Actual production values were not inspected. |
| S15 | Retry only lost attempts | ✅ Pass | Pricing job policies reject `CommandFailed`; morning consumers use one attempt. |
| S16 | Retain times except replaced chains | ⚠️ Partial | Helsinki schedule times are explicit. The replaced consumer times still exist in PHP; see PRC-003. |
| S17 | No calculation-method changes | ✅ Pass | No changed annual-cost formula, VAT rule, annual method, forecast model, or historical writer was found. No schema bump or history rebuild is required for this scheduling change itself. |

## Findings

### PRC-001 — The release sequence separates cache writers before it establishes a shared cache
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S6, S11; phase2-plan.md decision 1 and Target layout; decisions.md, 2026-11-08 phase 2, states that a private worker cache cannot update the web cache.
- **Evidence**: `tasks/temporal-orchestration/phase2-plan.md:80-87` moves the queue in R1, then switches the services to Redis in R2. `tasks/temporal-orchestration/phase2-plan.md:96-98` permits a return to the file store without returning cache-writing jobs to the web service. `docker-worker-entrypoint.sh:12-17` starts the separate queue. `laravel/app/Services/ContractInterpretation/ContractInterpretationPublisher.php:124` records price-cache demand from that queue. `laravel/app/Services/Caching/ContractPriceCacheLifecycle.php:61-84` stores and reads demand in the configured cache. `laravel/app/Console/Commands/WarmContractListCache.php:17-18` skips a warm when that store has no demand or missing payload. `laravel/app/Services/ContractListCacheService.php:355-376` permits retained prices when availability and classification still match.
- **What is wrong**: R1 starts a cache-writing queue service while `CACHE_STORE=file` still refers to separate service filesystems. A successful interpretation can update MySQL but put its refresh demand only in the queue container. The web warmer then sees its own complete old generation and no demand. For example, an unchanged-classification contract at 5,000 kWh changes from 10 to 12 c/kWh, with no fee. A new calculation is EUR 600/year; the web can keep EUR 500/year because the demand that must trigger replacement is in another filesystem. This is not the approved five-minute delay: the web producer cannot see the demand at all. R2 rollback creates the same fault; after R3/R4, the worker also builds whole generations in a private file store.
- **User-impact scenario**: Contract listings, contract detail, company prices, and rankings can retain an understated annual total after a valid interpretation publishes. After a later Redis rollback, a missing web payload can instead remain unavailable while worker warm jobs report success in their own store.
- **Fix**: Establish and verify the shared store before any cache-writing queue job moves. Split the image changes if necessary so the Redis extension is available before the queue leaves the web service. Make the rollback restore both shared cache visibility and producer ownership; do not offer `CACHE_STORE=file` alone as a rollback after writers move. Required ordering:
  ```text
  install Redis support -> seed and verify shared Redis on all services
  -> move queue and cache-writing jobs
  rollback to file -> first restore cache-writing jobs to the web service
  ```
  Blast radius: release and rollback instructions, image/release boundaries, and shared cached generations. No pricing formula or stored statistics change is needed. Production steps still need approval.

### PRC-002 — Retail execution can use the forecast's remaining pre-noon window
- **Severity**: High
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S2, S6; phase2-plan.md Workflows starts both consumers after the two producers, without making forecast generation depend on retail completion. The earlier schedules ran the consumers independently.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:42-45` orders retail before forecast. `orchestration/src/voltikka_orchestration/jobs.py:241-247` awaits each consumer serially, with a 120-minute timeout. `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:30-31` admits no new scheduled writer at noon. `laravel/app/Livewire/FixedContractPriceForecast.php:66-71` uses the latest stored eligible forecast date.
- **What is wrong**: Suppose producer completion or freshness recovery makes both consumers ready at 11:55. Retail takes ten minutes. Expected: the independent forecast can start at 11:55 and save today's eligible rows. Actual: forecast is not invoked until 12:05, when PHP records the deadline and produces no rows. Its own inputs were ready before noon; an unrelated consumer consumed its execution window. A stalled retail activity can delay it by two hours. The workflow can still end normally with no new forecast.
- **User-impact scenario**: `/sahkosopimus/sahkon-hintaennuste` and forecast teasers continue to show an older issue's numbers, or remain unavailable, despite fresh forecast inputs before the deadline.
- **Fix**: Start the two eligible consumer activities concurrently, retaining the existing per-consumer durable claims and `max_attempts=1`. Keep deadline checks independent of another consumer's running activity. Target shape:
  ```python
  await asyncio.gather(*(run_steps("MorningChain", [consumer_step(key)])
                         for key in open_consumers))
  ```
  This is an outline, not a request for a new helper abstraction. Blast radius: morning workflow scheduling only; no financial formula, cache schema, historical rows, or automatic retry rule changes. Add one slow-retail, ready-forecast deadline case when tests are permitted.

### PRC-003 — The old PHP start-time gates still delay event-ready forecasts
- **Severity**: Medium
- **Likelihood**: High
- **Class**: normal
- **Spec rule**: S2, S16; decisions.md, 2026-11-08 phase 2, says the consumers start when both producer children have ended and the workflow replaces the former cron ticks.
- **Evidence**: `orchestration/src/voltikka_orchestration/jobs.py:229-243` starts consumer ticks after both children finish. `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:20-23` still returns before 07:15 for retail and 07:30 for forecast. `orchestration/tests/test_jobs.py:55-76` substitutes checkpoints without those PHP gates; `orchestration/tests/test_jobs.py:142-162` therefore accepts consumer completion around 06:02.
- **What is wrong**: With both producers and required evidence ready at 06:02, the approved chain should execute both consumers then. Actual PHP calls return success without execution until the old earliest times. With five-minute ticks starting at 06:02, retail first executes around 07:17 and forecast around 07:32. The new workflow calls the commands earlier but does not remove their clock-based coupling. The integration test's simulated early completion cannot occur through the real PHP boundary.
- **User-impact scenario**: The public forecast and its comparison-page teasers retain the preceding issue for about 90 minutes after today's required evidence is ready. This defeats the intended event-driven publication improvement; it does not change forecast arithmetic.
- **Fix**: Remove the obsolete earliest-time condition for the Temporal consumer path. Preserve the current Helsinki date check, noon admission deadline, freshness checks, ownership, and terminal-state rules. Target shape after the old schedules have been removed:
  ```php
  if ($scheduled && $date->toDateString() !== $now->toDateString()) {
      return 0;
  }
  ```
  Blast radius: scheduled consumer admission only. This implements the recorded event-chaining decision; it does not authorize earlier execution without ready inputs or change manual behavior. Verify through the actual PHP boundary when tests are permitted.

## Files inspected
- `.pi/agents/pricing-reviewer.md`; root project context; task prompt.
- `tasks/temporal-orchestration/{spec.md,decisions.md,tasks.json,phase2-plan.md}`.
- `orchestration/AGENTS.md`; `orchestration/src/voltikka_orchestration/{jobs.py,schedules.py,activities.py,worker.py}`; `orchestration/tests/test_jobs.py`.
- Range diffs for `Dockerfile`, `docker-entrypoint.sh`, `docker-worker-entrypoint.sh`, `supervisord.conf`, `scripts/deploy-image.sh`, `laravel/routes/console.php`, and `laravel/app/Console/Commands/OrchestrationReadiness.php`.
- `laravel/config/{cache.php,database.php}`.
- `laravel/app/Services/{CanonicalPricing,ContractStatistics,Caching,PriceForecasting,RetailPremium,ContractCard,MorningFreshness,ContractImport,Orchestration}/AGENTS.md` (pricing policies and relevant domain sections).
- `laravel/app/Services/Caching/{ContractPriceCacheLifecycle.php,ContractPriceCacheEvidence.php}`.
- `laravel/app/Services/ContractListCacheService.php`.
- `laravel/app/Services/ContractImport/ContractPostImportCoordinator.php`; cache proof and refresh paths in `ContractImportCompletion.php`.
- `laravel/app/Services/ContractInterpretation/ContractInterpretationPublisher.php` (cache demand path).
- `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php`.
- `laravel/app/Services/Orchestration/MorningReadiness.php`.
- `laravel/app/Console/Commands/{WarmContractListCache.php,RunFixedContractPriceForecasts.php}`; cache invalidation path in `FetchEexFutures.php`.
- `laravel/app/Livewire/FixedContractPriceForecast.php` (public forecast selection).
- Laravel vendor `Illuminate/Cache/{RedisLock.php,RedisStore.php}` (Redis lock and serialization behavior).
