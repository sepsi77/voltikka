# Decisions

- Local work only. No production calls, commits, pushes or deployments.
- Keep the existing two-attempt cache conflict recovery and publication guards.
- Use only domain-local coordination. Stop and report a precise safety gap if finite execution ownership cannot protect required writes without wider architecture.
- Release requires separate approval for the new scheduled production mutation (`contracts:complete-import` each minute) and a separate fresh full import. Existing failed stage-only production records have no proof manifest and must stay failed.

## Superseded safety stop: finite execution lock alone

At the first stop no application code had changed. The finite lock alone did not prove safety. The manager then resolved this gap with the existing statistics transaction fence described below; process timeouts are not required.

Evidence from the current code:

- `ContractPostImportCoordinator::run()` calls statistics and cache refresh synchronously. Neither call receives a run owner or an execution deadline.
- `ContractPriceStatisticsService::calculateForDate()` deletes and rebuilds the date inside its own transaction. It has no caller ownership/deadline check before its first deletion or commit.
- Laravel scheduler `Event::execute()` constructs its Symfony process with a null timeout. Supervisor sets a queue-worker timeout, but the scheduler is a different process. Local CLI `max_execution_time` is 0.
- Laravel finite cache locks permit a new owner after expiry. They do not stop the previous owner's PHP execution.
- Cache generation promotion guards cache candidates. It does not fence daily statistics writes.

Counterexample to a lock plus boundary checks only:

1. Completion A acquires the finite required-work lock and passes its ownership and deadline checks.
2. A pauses before its statistics transaction starts. The lock expires.
3. Full import B installs a new UUID, imports, obtains the expired lock, writes statistics/cache and marks B ready.
4. A resumes inside the already-authorized statistics call and overwrites B's same-date statistics.
5. A's final CAS correctly fails, but B still has a ready checkpoint above overwritten statistics. A checkpoint CAS cannot roll back these earlier writes.

A check after the call is too late. A larger TTL alone does not prove a bound. Failing an expired pending claim also does not stop the original process. No global publication mutex or new revision checkpoint was added.

This proposed hard-stop step is superseded. No alarm, subprocess timeout, dependency or process-control change was added. The manager directed a checkpoint row fence inside the existing statistics transaction instead.

## Verification of the unchanged application baseline

- `cd laravel && php artisan test --filter='PriceCacheConflictRecoveryTest|ContractPostImportCoordinatorTest'`: 10 passed, 81 assertions.
- `cd laravel && php artisan test`: 2,352 passed, 11,804 assertions, 94.38 seconds. Output: `/tmp/voltikka-deferred-completion-tests.log`.
- `cd laravel && npm run build`: passed, 60 modules, 794 ms. Existing warning: Browserslist data is nine months old. Output: `/tmp/voltikka-deferred-completion-build.log`.
- Scoped Pint/PHP lint: not applicable; no PHP files changed.
- These first-pass checks proved only the unchanged baseline. The implementation and new tests below were added after the manager resolved the fence design.

## Implemented transaction-fence design

- `ContractImportCompletion` is one domain-local helper for required work and existing checkpoint JSON. It is not a generic workflow engine. Separate initial/tick entry points avoid another acquisition, interpretation dispatch, queue job, schema, revision checkpoint or publisher mutex.
- Full fetch installs a new run UUID before acquisition. All later full-run writes compare ownership under the checkpoint row lock. Initial, scoped and deferred statistics take that same row lock inside `calculateForDate()`'s existing transaction before its first deletion. Deferred checks also validate the exact claim token, pending status, lease, current date, manifest and current active IDs. General statistics callers use the default null fence.
- If B has already become ready, paused A rejects B's new UUID before deleting any statistics. If A already holds the row lock, B cannot install its new start until A's statistics commit. Thus B's later statistics win. A's final checkpoint write still cannot replace B. Claim tokens provide the same protection against duplicate execution within one UUID. No hard timeout is part of the correctness proof.
- After manager review, scoped statistics never insert or replace a global checkpoint. They lock the existing same-date row or retain its absence. The missing-key locking read inside the statistics transaction uses InnoDB's default REPEATABLE READ gap locking to serialize a concurrent full-start insertion. They reload and verify current active IDs, so an old scoped set is rejected if a full run finishes before the fence executes. See the review correction below.
- Waiting exact targets skip the initial cache build after successful initial statistics and sitemap invalidation. Only those proven full runs can defer. Transport-failed targets without validation errors wait; deterministic rejection can settle an inactive target but cannot prove publication. Empty active coverage fails closed. Relational publication permission is not a canonical gate.
- Pending checks are bounded to 60 claims within two hours on the same Helsinki calendar date. A 30-minute durable claim blocks duplicates; an expired claim fails terminally instead of repeating uncertain writes. The 30-minute required-work cache lock and 35-minute scheduler overlap expiry reduce overlap but do not fence statistics. Ready/failed rows do not resume.
- Exact dispatcher-returned IDs stay bound to observations and snapshots, including date-scoped variants. Identity inspection uses bounded batches and does not load source payloads or full interpretation output. MySQL JSON key order is deliberately ignored for object identity; ordered evidence lists remain strict.
- Statistics and the private cache build check the same active/publication identity. The optional refresh candidate guard retains two total candidates and mandatory retirement. Final readiness binds the verified generation/fingerprint and exact statistics times. The completion write guard rejects changed proof before finalization. After manager review, subsequent morning readers use the same existing scoped checks for new and legacy metadata. A relevant fixed-term publication in the last read/write window produces the recoverable publication-order failure, not an extra global error.
- Optional messages are controlled text. Normal waiting sends no Issue. A genuine/exhausted terminal tick emits one safe aggregate after its checkpoint transaction, without exception messages, source bodies or tokens.

## Concurrency evidence and limit

The portable tests use real SQLite rows, statistics transactions, real publication/activation and real eight-preset/company cache builds. They test stale UUID/token rejection before deletion, rollback integrity, scoped fence placement, duplicate claims, a newer full run becoming ready before an old statistics fence, publication during statistics/build/finalization, and JSON key normalization. A MySQL grammar test proves that the fence query requests `FOR UPDATE` before date writes. SQLite cannot prove InnoDB blocking; no local MySQL fixture or production database was used. The blocking guarantee rests on the existing database row-lock/transaction semantics described above.

## Release plan — approval still required

No production calls or mutations occurred. No commit or push was made. This is not a deployed fix.

After review and explicit release approval, follow the repository Git release flow. The approval must cover enabling the new `contracts:complete-import` scheduled writer every minute on project `6d8cae01-1006-409f-8108-1d51f1abc676`, environment `9245cef8-41d0-486e-862f-193726511dba`, app service `700d0624-fa96-4266-876c-e37640d220ea`. The production command is `php artisan contracts:complete-import`; it can claim pending checkpoint facts, replace current-date statistics, invalidate sitemap, and build/promote the full price generation. `git push origin main` would deploy that schedule and therefore requires confirmation before execution.

After that deployment is verified, a **separate** approval is needed for `php artisan contracts:fetch --skip-logos` in the same exact production context. It performs a fresh full acquisition/import and creates the required manifest. Do not change or retry the old stage-only failed checkpoint, and do not automatically repeat the previous full import. `php artisan contracts:complete-import --dry-run` is the read-only diagnostic. No historical backfill, retained v1 September 11 overwrite, migration, flag, variable or dependency is part of this release.

## Verification before manager review

- `cd laravel && php artisan test --filter='DeferredContractImportCompletionTest|ContractPostImportCoordinatorTest|FetchContractsCommandTest|PriceCacheConflictRecoveryTest|MorningJobFreshnessGateTest'`: **87 passed, 607 assertions**, 15.21 seconds. Output: `/tmp/deferred-focused.log`.
- `cd laravel && php artisan test`: **2,374 passed, 12,056 assertions**, 95.99 seconds. Output: `/tmp/voltikka-deferred-completion-final-tests.log`. This includes the unchanged two-attempt/HTTP 503 guard and legacy freshness tests.
- Scoped `vendor/bin/pint --test` over the 13 changed/new PHP files: **passed**. A prior scoped Pint run applied formatting only in those files.
- `php -l` over those same 13 files: **all passed**.
- `cd laravel && npm run build`: **passed**, 60 modules, 869 ms. Existing warning: Browserslist data is nine months old. No dependency update was made.
- New coverage includes real publication/new activation, fresh date statistics, eight presets plus company payload, no Azure/redispatch during ticks, durable claims/bounds, dry-run SQL and cache zero-write checks, safe terminal failures, pointer/date-scoped target mismatch, active drift, scoped serialization, stale-run and same-run token fences, SQL placement/rollback, MySQL lock compilation/JSON ordering, and publication/generation changes during statistics/build/finalization.
- Initial development test failures (readonly `reset()` use, fixture expectations and HTTP count) were corrected before the final focused and full passes. No failing check is being omitted from the completion claim.
- Final `git diff --check`, task JSON parsing, new-file whitespace checks and all six affected `AGENTS.md`/`CLAUDE.md` symlink mirror comparisons: **passed**. The working tree contains only the intended application, test, context and task changes. No commit, push or production action was run.

## Manager review correction

The initial implementation incorrectly applied `readyCurrent()` to every later morning read. A legitimate EEX/cache invalidation or unrelated publication then added `contract_completion`, while the ready checkpoint could not resume. A relevant fixed publication also gained this extra failure, which disabled existing publication-only forecast statistics recovery. The manager explicitly corrected this earlier requirement.

Removed the new check and completion-service dependency from `MorningJobFreshnessService`; that application file is again unchanged from its existing scoped implementation. `readyCurrent()` remains at completion write/final-CAS guards. Completion proof certifies the successful work at that time; it does not freeze the all-market cache or publications forever. Pending status still blocks. Fixed forecast readers retain household fixed 6/12/24 scope, exact pointed-observation checks, independent EEX proof and existing non-dry statistics-only recovery. Retail retains existing all-checkpoint-active interpretation checks. No forecast model, recovery command, schedule or ready-row retry was added or changed.

Removed scoped checkpoint insertion from `statisticsFence()`. If a row exists, the transaction locks it. If absent, no global fact is created. The exact-key `FOR UPDATE` read occurs inside the existing statistics transaction: under MySQL/InnoDB default REPEATABLE READ it locks the missing key's index gap, so a concurrent full-start insertion waits for commit. If full start wins first, the row exists when the fence runs; changed active IDs reject an older scoped input before deletion. Existing date writes still stay transactional. SQLite proves callback placement, no inserted checkpoint and logical stale-input rejection, not InnoDB blocking. No production isolation setting was inspected; verify the existing MySQL isolation assumption during read-only release review if it has been customized. No new lock table, isolation setting, dependency or fake readiness row was added.

New real completed-manifest fixtures cover cache invalidation, unrelated Spot/business publication and new inactive observations, relevant publication as the only failure, actual statistics replacement through existing forecast recovery, wrong pointed episodes, pending status, scoped no-checkpoint creation, and full start between scoped fence preparation and execution. The fixture uses the real `Fixed12` source duration, so the forecast scope is actually exercised.

## Final verification after review corrections

- `cd laravel && php artisan test --filter='DeferredContractImportCompletionTest|MorningJobFreshnessGateTest|FetchContractsCommandTest|ContractPostImportCoordinatorTest|PriceCacheConflictRecoveryTest'`: **93 passed, 663 assertions**, 16.02 seconds. Log: `/tmp/deferred-review-focused.log`.
- `cd laravel && php artisan test`: **2,380 passed, 12,112 assertions**, 99.37 seconds. Log: `/tmp/voltikka-deferred-completion-review-tests.log`.
- Scoped `vendor/bin/pint --test` and `php -l` on all 12 changed/new PHP files: **passed**. `MorningJobFreshnessService.php` now has no diff from the existing implementation.
- `cd laravel && npm run build`: **passed**, 60 modules, 802 ms. The existing nine-month-old Browserslist warning remains; no dependency update was made.
- The initial recovery test counted a second warm job while the first job's `ShouldBeUnique` lock was still held. The fixture now models completion of the first job by releasing its real unique lock, then verifies the recovery enqueue. No application queue behavior changed. The corrected focused and full suites pass.
- Final `git diff --check`, task JSON, new-file whitespace, six context symlink mirrors, and byte comparison of the restored morning reader with `HEAD`: **passed**. Final status contains only the intended task changes.
- No production calls, commits, pushes, deployment, forecast-model changes or additional schedule changes occurred during review correction.

## Local deterministic-rejection correction

The manager supplied read-only production facts for the September 14 full import: all 405 contracts processed, but required statistics stopped with `publication_missing`. The single active offender was business Spot contract `i1badk-porvoon-energia-oy-spot-porssisahko-yrityksille`: exact target 1654 failed with validation errors and output; published pointer 1183 remained older. No further production inspection or mutation was needed or performed.

Settlement now accepts an exact failed target with nonempty validation errors for active as well as inactive contracts. It does not certify publication or change any source, classification, canonical JSON, publication pointer, validation result, relational price, or activation. Pending/processing and transport failures still wait within existing bounds. Other active unpublished cases remain closed. Ownership, manifests, fences, claims, cache candidate limits and HTTP 503 behavior are unchanged. Proof retains the exact failed target in the manifest and actual older published ID plus failed status in ready evidence.

Inspection found a precise related safety gap: `ContractPriceStatisticsService` called the canonical batch calculator directly on stored canonical JSON without the cache's current-publication check. Business-only contracts were already outside statistics, but an active rejected household fixed contract could contribute older prices. One existing `ContractPriceCacheEvidence` batch now filters unsafe active calculation inputs. Old same-date numeric snapshots are removed and annual rows are unavailable (`canonical_outcome_missing`), not fabricated prices. No relational fallback or general calculator change was added. Two existing query-budget assertions gain one batch; query growth is not per contract.

Tests use real old publications, new pointed snapshots, failed exact targets, initial required statistics, all eight preset cache payloads and ready proof. They assert null unsafe prices, retained normal prices, removal of old same-date fixed statistics, no numeric unsafe annual totals, and byte-identical source/model/interpretation/activation/component rows. Existing forecast scope rejects the failed household fixed target but ignores business Spot; retail remains strict for both. Active pending/processing/transport, bounded expiry, unknown/missing publication, and inactive rejection are covered.

`ContractImportCompletionStopped` now normalizes constructor reasons through a fixed allowlist. The aggregate reporter can expose its controlled reason instead of `unexpected`, with unknown/secret-bearing input still mapped to `unexpected`. Neither exception messages nor raw constructor input reach logs or Sentry.

Earlier checks exposed one test field typo (`general_rate` instead of `general_kwh_price`) and the expected extra evidence batch in both source/interpretation query budgets. These fixture expectations were corrected; no safety rule was relaxed.

### Final local correction verification

- `cd laravel && php artisan test --filter='DeferredContractImportCompletionTest|DataFetchFailureReporterTest|ContractPostImportCoordinatorTest|FetchContractsCommandTest|PriceCacheConflictRecoveryTest|ContractPriceCacheLifecycleTest|MorningJobFreshnessGateTest|ContractPriceStatisticsCanonicalSourceTest|CurrentCanonical'`: **134 passed, 1,016 assertions**, 16.74 seconds. Log: `/tmp/rejection-focused-final.log`.
- `cd laravel && php artisan test`: **2,384 passed, 12,289 assertions**, 96.99 seconds. Log: `/tmp/rejection-full-final.log`.
- Scoped `vendor/bin/pint --test` on all seven changed PHP files: **passed**. Initial scoped Pint applied formatting only to the statistics service.
- `php -l` on those seven files: **all passed**.
- `cd laravel && npm run build`: **passed**, 60 modules, 904 ms. Existing Browserslist age warning remains. Log: `/tmp/rejection-build.log`.
- Final diff review, `git diff --check`, task JSON parsing and all five changed context/CLAUDE mirrors: **passed**. No dependency, migration, flag, production call, import retry, commit or push was made. This correction remains local.
