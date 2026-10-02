# Retained-cache producer stage

## Result

Producer/lifecycle stage complete locally. The overall cache policy is not complete. Public guards and synchronous HTTP miss calculations are unchanged. Pure GETs, including crawler/custom query-string requests, must become cache-only in the separate consumer stage. Exact custom calculations for explicit user actions remain permitted. No public consumer behavior was changed in this stage.

## Implementation

- `ContractPriceCacheLifecycle::invalidate()` writes a durable monotonically increasing demand revision under the existing short transition lock. It retains the exact active pointer and every payload. Publication/EEX callers keep their existing `bumpVersion()` calls. The returned integer is now the demand revision, not an activated public version.
- `requestRepair()` records one pending demand without revision churn. It is ready for the future HTTP cache-miss boundary; no HTTP caller was added here.
- Each candidate captures demand revision. Promotion checks required payload digests, active descriptor, demand revision and producer ownership. It stores satisfied demand in the active descriptor itself. There is no second demand-clear write that could lose a later request. Old descriptors without the new field mean revision zero.
- All staged refresh entry points share one 1,800-second cache producer lease with a ten-second acquisition wait. Calculations do not hold the short transition lock. The existing two-full-candidate retry budget, calculation-state reset, import candidate guard and required failed-candidate retirement stay in place. Lease loss fails without another candidate retry. The lease owner is checked at promotion, and an expired owner cannot release the replacement owner's lock.
- Required import completion proof checks current demand revision in addition to generation and evidence. Demand before a successful build can be satisfied; demand after activation prevents completion from claiming that the new source update was built. Later morning consumers are not pinned to this cache proof.
- Normal `contracts:warm-cache` and `--pending` check pending demand or missing current schema/mode preset/company keys. They use a full private eight-preset-plus-company refresh when needed, not public read-through accessors. `--refresh` forces replacement. `--pending` is quiet on success/no work; failures return nonzero and retain demand.
- A per-instance minute schedule runs `--pending` in the background with 35-minute overlap protection. It has no `onOneServer`. Other schedules are unchanged.
- Existing non-expiring payloads, digest readback, durable manifests, one-hour reader grace and bounded cleanup remain unchanged.

## Files

Code: lifecycle, list cache service, warmer command, console schedule, and the import completion-write proof.

Tests: lifecycle and conflict tests, import completion tests, ranking/schema tests in MarketResetEstimateSurfacesTest and CompanyListPageTest, and EEX demand assertions in FetchEexFuturesCommandTest. Existing tests now use promotion when they need a changed active pointer. No tests were disabled.

Context: `laravel/app/Services/Caching/AGENTS.md`; its CLAUDE mirror remains a symlink. Unrelated dirty work was retained.

## Verification

- `php artisan test --filter='ContractPriceCacheLifecycleTest|PriceCacheConflictRecoveryTest'`: 27 passed, 164 assertions.
- Seven-class cache/import/consumer regression filter: 80 passed, 817 assertions. A later concurrency assertion addition also passed in the full suite.
- Initial wider/full runs exposed old immediate-invalidation test assumptions. These were corrected to demand or verified-promotion semantics. The completion-write proof was extended to reject unsatisfied demand.
- Final `php artisan test`: 2,887 passed, 23,115 assertions, 132.24 seconds. No known remaining PHP test failures.
- Focused `vendor/bin/pint --test` across all eleven changed PHP files: passed.
- `git diff --check`: passed. Final diff and worktree status reviewed.

No CSS/JS change, build, commit, push, network request or production operation was made.

## Limits

The tests use local test cache stores and local test data. They prove cache lease exclusion during calculation and expired-owner rejection, not cross-host deployment behavior. The cache store is instance-local in production. Public source-evidence guards and current evidence-key cache behavior can still exclude retained prices or trigger cold work until the consumer stage is complete. City-card shared-metric reuse and GET versus explicit-action consumption behavior remain outside this unit.
