# Shared retained-cache read boundary

## Result

Shared service implementation and the manager-assigned cache/service fixture integration are complete. The six owned test classes pass after the demand-metadata follow-up: 74 tests, 517 assertions. The earlier combined PHP gate failed before these fixture updates; it was not repeated while other agents edit their assigned classes. Page/action and other consumer integration remain separate. No production operation, network request, commit, or push was made.

## Changes

- `ContractListCacheService` uses `PublicPriceCalculationPolicy::allowsCalculation()` at the cold build boundary. Missing preset HTTP reads request idempotent repair and throw `ContractPriceCacheUnavailable`. Unsupported custom misses return null without calculation or demand.
- Retained read guards use scalar active availability/classification, not current publication/source identity or immutable source JSON. Existing active rows retain their complete financial payload, integrity, phases, offers, source evidence, and stored calculation date. Removed/inactive IDs are excluded in canonical mode and removed in legacy mode. Newly active IDs have no calculable retained facts. Cached exclusions stay excluded.
- `guardBuildEvidence()` preserves the old strict current ownership/publication/identity invariants. Producer source fingerprint comparison and the producer lease/candidate/promotion methods are unchanged. New builds attach additive optional `source_classification` metadata. Changed contract type, pricing model, metering, audience, fixed term, company, or consumption bounds cannot reuse incompatible transport. Earlier verified same-schema payloads without this metadata retain the availability-only compatibility rule. No immutable source JSON is read to recover missing metadata.
- Company wrapper schema moves from 2 to 3; the shared calculated-cost schema does not change. Company stored keys no longer include live publication evidence. Per-row `_availability` is one 64-character SHA-256 fingerprint string, computed once per company build from a bounded scalar availability/classification query. The complete scalar universe is not copied into company rows. The follow-up inspection confirmed this was already a string; new tests now assert its type, length, and exact fingerprint. Empty stored company collections have no row marker, so they now cheaply aggregate shared metrics again under a new request memo identity. This fixes a real stale-empty result after a priced contract is made inactive and then active again, without annual calculation. A missing wrapper or changed availability cheaply aggregates guarded shared metrics. Both modes skip absent, excluded, and non-finite price totals. Candidate company builds still consume the supplied exact candidate metrics.
- `ContractPriceCacheUnavailable` has a controlled message. The bootstrap renderer gives this expected class the same plain Finnish 503, Retry-After 30, and private/no-store headers as typed conflicts. Only these two expected classes skip reporting. Malformed payloads remain ordinary errors.

## Demand metadata loss follow-up

The manager found that losing only `DEMAND_KEY` could reset the next invalidation below the active descriptor's satisfied revision and hide real refresh demand. `ContractPriceCacheLifecycle::demandRevision()` now returns the maximum of the raw demand revision and the stored active satisfied revision. It reads `ACTIVE_KEY` directly, so it does not recursively bootstrap inside the short transition lock. No new metadata layer or pointer write is added. Raw import completion proof is unchanged.

Three lifecycle tests prove: demand loss alone is not pending and a populated `--pending` warm is a no-op; the next real invalidation advances above the satisfied floor and `--pending` completes a full replacement; repair demand also advances above that floor and coalesces; and losing a captured higher unmet demand during candidate build still rejects promotion without changing the active pointer. Other services and consumers were not changed in this follow-up.

Verification: the six owned cache classes, including the producer warmer tests, pass with 74 tests and 517 assertions in 2.50 seconds. Scoped Pint and diff checks pass. No full suite, production operation, network request, commit, or push was made.

## Exact custom API

`ContractListCacheService::prepareComparisonForConsumption(int $consumption): ContractMetricSet` is available now.

The caller must first grant `PublicPriceCalculationPolicy::allowUserAction()` for an explicit non-GET action. The method requires permission even for an existing cached set. It reuses exact cached data or builds exact consumption through the strict current guard, pre/post evidence and availability checks, two total conflict attempts, and existing manifest-owned lifecycle writes. A promotion race rejects the old write and retries from the new descriptor. No rounding, interpolation, or separate handoff cache is used. Nonpositive input is rejected; existing caller normalization owns other domain bounds.

`getCachedMetrics()` can read an explicitly prepared custom set under the active generation/schema/mode. A missing custom set stays null, including after replacement; custom profiles are not automatically carried forward. `supportsConsumption()` and preset ranking semantics are unchanged. The standalone calculator must call the new method before its existing redirect; that caller is outside this unit.

## Owned fixture integration

- `ContractPriceCacheLifecycleTest` and `PriceCacheConflictRecoveryTest` remove the default test request HTTP method to model direct CLI producer calls. Actual HTTP conflict rendering is exercised by an explicit permitted POST action, not a forbidden GET build. Cold company conflicts keep their immutable generation key while still proving fresh price recovery and the two-attempt budget.
- `ContractRequestMemoizationTest` mocks the scalar `currentAvailability()` query and availability fingerprint. Cache read counts and same-instance memo assertions remain. Its company wrapper fixture uses schema 3 and the string marker.
- `AnnualConsumerConsistencyTest` builds verified presets before shared reads/HTTP calls. Source-only pointer changes retain byte-identical prices/rank/company membership until replacement. The later current build still excludes unsafe old publication and incomplete current outcomes. Midnight, API exactness, statistics, and financial assertions remain.
- `CompanyListPageTest` explicitly calls its local `preparePrices()` helper after each data fixture and before HTTP/Livewire initialization. There is no global test warming and no test-mode GET permission. Existing pricing, membership, ranking, emission, filter, SEO, and no-relational-query assertions remain. Only the intentional company wrapper schema expectation changes to 3.
- No other agents' page/local/company-detail, API/week/widget, standalone calculator, or bill tests were edited.

## Verification

### Final owned gate

- `php artisan test --filter='ContractPriceCacheLifecycleTest|PriceCacheConflictRecoveryTest|ContractRequestMemoizationTest|AnnualConsumerConsistencyTest|CompanyListPageTest|SharedPriceCacheReadBoundaryTest'`: 71 passed, 474 assertions, 1.78 seconds.
- Scoped `vendor/bin/pint --test` across ten PHP files: passed.
- `git diff --check`: passed. Final owned diff and worktree status reviewed. No full suite was repeated during concurrent integration.
- One intermediate follow-up run had 70 passed and one memo-fixture failure after the empty-wrapper fix. The fixture now uses a nonempty cached row marker and preserves the original single cache-read assertion; the final gate above passes.

### Earlier read-stage gates (before owned fixture integration)

- Initial focused runs found fixture errors and a partial mock with uninitialized mode. These were corrected in the new test file only.
- `php artisan test --filter=SharedPriceCacheReadBoundaryTest`: final result 10 passed, 111 assertions, 0.51 seconds.
- Tests cover cold GET/HEAD/automatic POST repair without revision churn, retained canonical price/provenance byte equality after changed pointers and demand, stable stored timestamp and company key, cheap company wrapper aggregation in both modes, new/inactive IDs, malformed payloads, custom missing/prepared GETs and HEADs, denied GET preparation, explicit POST exact custom preparation, custom loss after promotion, strict current-build exclusions, retained exclusions, changed price class, and a bounded custom promotion-race retry with manifest ownership.
- Seven-class targeted regression command (`SharedPriceCacheReadBoundaryTest|ContractPriceCacheLifecycleTest|PriceCacheConflictRecoveryTest|ContractRequestMemoizationTest|CompanyListPageTest|AnnualConsumerConsistencyTest|PublicPagePriceCachePolicyTest`): 37 failed, 37 passed, 332 assertions. This run preceded the tenth new test.
- `php artisan test`: 613 failed, 2,287 passed, 20,768 assertions, 124.83 seconds. This run preceded the tenth new test. Full gate is not successful.
- `vendor/bin/pint --test` for the five scoped PHP files: passed.
- `git diff --check`: passed. Final scoped diff and worktree status reviewed. The cache CLAUDE mirror remains a symlink.

## Integration exceptions

The earlier broad failures are not a passing full gate. The six assigned cache/service classes now have explicit CLI or verified-producer fixtures and pass without changing the GET policy. The policy's default GET-shaped test request remains denied, as required. The scalar memo-query fixture and intentional retained-source/company-key expectations are now fixed in the owned classes.

Other consumer classes were not rerun here during concurrent edits. Their exact current remaining failure set is not known. The earlier full-suite counts remain historical evidence only; the manager must run the combined gate after the other agents finish. Root and Livewire context remain the manager's responsibility.

No lifecycle, warmer, import, schedule, calculator mathematics, canonical publication, history, or statistics code was changed. Root and Livewire context remain the manager's responsibility.
