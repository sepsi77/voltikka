# laravel-reviewer — staged-api-imports focused check
Range: `743f1c1..a906fac9fe3c0873269e9d19ad3f3f2d3b0e208e` · Result: 2 findings

Static check of the two pricing-verification residuals and the changed locking/failure paths only. No tests, live APIs or production access. `php -l` passed for both changed commands and `StagedImport.php`; range `git diff --check` passed. Source HEAD matches the requested head.

## Residual verdicts

| Residual | Verdict | Evidence |
|---|---|---|
| PRC-001 — concurrent superseded contract store | **Not fixed** completely | Ordinary overlap is repaired: all command modes/scopes acquire the same lock before the freshness read and replay takeover (`laravel/app/Console/Commands/FetchContracts.php:205-229`); the importer transaction commits before release (`:258-282`; `laravel/app/Services/ContractImport/ContractImporter.php:62-89`). However, its 1,800-second lease can expire while the permitted store still runs, reopening the check/write race. See LAR-201. |
| PRC-002 — concurrent stale forecast projection | **Not fixed** completely | Ordinary overlap is repaired: parsing precedes locking, while the recency check, display upsert and archive insert share a transaction started inside the lock (`laravel/app/Console/Commands/FetchSpotForecast.php:92-118,142-174`). `transactional: false` at `:70` correctly avoids committing after release. However, the 300-second lease is shorter than the allowed store lifetime and has no expiry fence. See LAR-201. |

## Checks that pass

- **Release paths:** contracts use `finally` for every acquired-lock return/exception (`FetchContracts.php:224-282`), including superseded refusal, failed takeover, empty acquisition and importer failure. Acquisition timeout does not release another owner's lock. Forecast's callback form releases on success and exception through Laravel's `Lock::block()` finally (`laravel/vendor/laravel/framework/src/Illuminate/Cache/Lock.php:114-138`). Redis release is owner-checked (`RedisLock.php:63-65`). Abrupt process death relies on lease expiry.
- **Lock order:** the new contract lock is distinct from `ContractImportCompletion::LOCK`, and is released before coordinator/completion execution (`FetchContracts.php:281,302`; `ContractImportCompletion.php:33,294-311`). Checkpoint takeover finishes its short transaction before importer entry (`ContractImportCompletion.php:45-57`). No new store-lock/completion-lock cycle was found. Forecast starts its transaction only after acquiring its source/region lock; no other application forecast writer was found by related-writer search.
- **Protected writes:** source observations, pointers, availability and relational import rows remain inside the importer transaction under the new lock. Forecast display and archive writes are both inside its locked transaction. Contract fetch checkpoints, logo updates, interpretation dispatch, statistics/cache completion and final checkpoint writes intentionally remain outside this new store lock; this preserves the existing separate stages rather than enclosing network/post-import work in the store critical section.
- **Busy reporting:** both commands return exit 1 with closed `store_busy`, retain the payload reference and make no import-row write. Contracts aggregate one explicit Issue/log through the existing reporter; forecast emits its existing safe aggregate log (`FetchContracts.php:215-221,126-136,105-106`; `FetchSpotForecast.php:114-118`; `laravel/app/Console/Concerns/StagedImport.php:120-131,160-169`). Same-process default contract mode fails only its owned checkpoint; scoped mode never creates one. Split-mode checkpoint handling has the defect below.

## Findings

### LAR-201 — Store leases expire before the protected writers must stop
- **Severity:** High
- **Likelihood:** Low
- **Class:** blocking
- **Spec rule:** S5/S8; PRC-001/PRC-002 serialization decisions in `decisions.md:110-111`.
- **Evidence:** `laravel/app/Console/Commands/FetchContracts.php:46,225-259,281`; `laravel/app/Console/Commands/FetchSpotForecast.php:39,108-113,145-174`; `laravel/vendor/laravel/framework/src/Illuminate/Cache/RedisLock.php:34-40,49-56`; `orchestration/src/voltikka_orchestration/jobs.py:74,118,145-148,241-242`; `orchestration/src/voltikka_orchestration/schedules.py:106`; `orchestration/src/voltikka_orchestration/staged.py:20`.
- **What is wrong:** the sole serialization proof is an expiring Redis key: 30 minutes for contracts, 5 minutes for forecast. Neither critical section renews ownership, enforces a shorter hard execution bound, or uses a database serialization fence after lease loss. Contract stores may run for 60 minutes; the scheduled forecast store for 30 minutes, and manual Artisan entry points have no corresponding wall-clock bound here. These timeouts are ceilings, not evidence that normal imports take that long; no long-running incident is claimed.
- **Impact scenario:** old A passes its recency check, then stalls before its mutations beyond the lease (for example a suspended/stalled PHP process). New B acquires the expired lock and commits. A resumes before its activity timeout and writes without owning the lock. Contracts can restore older source pointers/prices and membership; forecast can restore `[8,12]` over B's `[11,12]`. Importer row locks cannot re-evaluate the earlier freshness read, and the forecast's nonlocking transaction read does not prevent this. The original published-data consequences therefore remain possible under unusual timing.
- **Fix:** make serialization last through commit independently of an expired lease, or enforce a hard writer lifetime strictly below the lease for **every** entry point, with termination/rollback margin. A transaction-scoped stable database mutex is one valid shape:
  ```php
  DB::transaction(function () use ($payload) {
      $this->lockStableStoreIdentityForUpdate(); // held through commit, not a TTL
      $this->refuseSupersededPayload($payload->fetchedAt());
      // Existing authoritative database writes, excluding post-import/network work.
  });
  ```
  The helper is illustrative, not an existing API. Forecast needs the equivalent source/region boundary around recency + both writes. Merely checking ownership once or refreshing immediately before an unbounded transaction leaves another expiry window. Blast radius: both commands/default and replay callers; contract source/current-price consumers and forecast page/CSV. The added regressions in `FetchContractsCommandTest.php:1317-1417` and `FetchSpotForecastCommandTest.php:333-408` exercise waiting, not holder lease expiry; retain them and add a controlled expiry-before-write case.

### LAR-202 — A busy normal split contract store leaves its fetch checkpoint running
- **Severity:** Medium
- **Likelihood:** Low
- **Class:** normal
- **Spec rule:** S11 (failed store records `failed` with payload reference); S8. Preserving an unrelated manual replay's checkpoint is correct and is not disputed.
- **Evidence:** `laravel/app/Console/Commands/FetchContracts.php:87-88,134-135,154-158,215-229`; `laravel/app/Services/ContractImport/ContractImportCompletion.php:45-57,117-123`; `orchestration/src/voltikka_orchestration/jobs.py:145-148`; `laravel/app/Console/Concerns/StagedImport.php:79-81`.
- **What is wrong:** the normal Temporal fetch and store are separate processes. Fetch creates `running`; store starts with null `runUuid`/`checkpointDate` and only acquires checkpoint ownership **after** obtaining the store lock. On `store_busy`, reporting therefore cannot finalize the fetch's checkpoint. This is not just a hand-started replay: the ordinary scheduled store also uses `--from-payload`. The new tests explicitly preserve the fetch marker for this full-payload busy case (`laravel/tests/Feature/FetchContractsCommandTest.php:1322-1348`), but test failed-checkpoint behavior only in same-process default mode (`:1353-1362`).
- **Impact scenario:** a long scoped store holds the lock while the morning full fetch succeeds. Its split store times out after five minutes. Temporal reports terminal `store_busy`, but the global checkpoint still says `running`, stage `started`, without the failed payload reference. `tick()` selects only pending completion, so it cannot settle this orphan. Operators see contradictory workflow/checkpoint states and lack the required checkpoint replay reference until a separately approved replay or next full run.
- **Fix:** bind the fetch's checkpoint UUID into the saved payload, then on pre-takeover busy failure CAS-finalize **only that still-owned fetch marker**, never a newer/unrelated replay owner:
  ```php
  // Fetch: record its checkpoint owner in the payload facts.
  // Busy store: no takeover; existing record() rejects a changed UUID.
  $completion->record($date, $payloadFetchUuid, STATUS_FAILED, [
      'stage' => 'store_busy', 'payload' => $reference,
  ]);
  ```
  Preserve no import-row writes and the single Issue. Blast radius: full split `ContractImport` stores and checkpoint diagnostics; scoped stores and unrelated manual replays must remain untouched. Add a separate-process-equivalent fetch/store busy regression and a newer-owner preservation case to `FetchContractsCommandTest`.

## Files inspected

- Requested range diff; task `spec.md`, `tasks.json`, review-round `decisions.md`, and `verification-pricing-reviewer.md`.
- `FetchContracts.php`, `FetchSpotForecast.php`, `StagedImport.php`; `ContractImporter.php`, `ContractPostImportCoordinator.php`, `ContractImportCompletion.php`; `NordpoolPredictFiService.php`; `DataFetchFailureReporter.php`; `config/cache.php`.
- Changed contract/forecast lock regression sections (read only); related application reader/writer searches.
- Laravel vendor `Cache/Lock.php` and `Cache/RedisLock.php`; orchestration store timeout/caller sections in `jobs.py`, `staged.py`, `activities.py`, `schedules.py`; shared-cache declaration in `config/deploy.yml`.
- Root, Laravel, services, contract-import, spot-forecast, import-payload, support, tasks and orchestration context instructions. `/AGENTS.md` was absent.
