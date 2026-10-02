# Core cache-policy review fixes

## Result

Done. The bounded mechanism guard, custom preparation limits, and custom company-read follow-up are implemented. No network request, production operation, commit, or push occurred. Other agents' files were not changed.

## Changes

- `ContractListCacheService.php`: current availability selects the existing nine scalar fields plus four SQL JSON scalar projections (reset present/cadence, effect present/applies_to). It stores effective reset, cadence, and base-effect flags. Valid SQLite integer and MySQL unquoted boolean values normalize consistently. Date and price changes alone keep retained prices. The retained guard compares only stored classification fields, so older partial metadata stays readable. No metadata keeps the existing availability-only compatibility. Strict new-build evidence remains unchanged.
- The public preparation domain is 1..`MAX_COMPARISON_CONSUMPTION` (150000), checked before calculation permission, cache bootstrap, pricing, or writes. Exact 7312 remains exact. Unknown custom GET stays null.
- `ContractPriceCacheLifecycle.php`: new nonpreset custom payload writes have a 1800-second TTL and a 64-live-profile limit per active generation. The existing manifest has a bounded `custom_expirations` map beside its owned key list. Preflight checks capacity before pricing; the write rechecks under the existing transition lock. Live profiles are not evicted. Capacity throws `ContractPriceCacheUnavailable`.
- Expired custom keys are physically forgotten before admission, then removed from the manifest. Failure leaves cleanup ownership intact. A false forget can mean an absent file/array entry; a one-second null marker on the exact expired owned key and checked deletion proves removal without restoring a price. Persistent deletion failure or manifest-write failure blocks admission and preserves retry state. Retired cleanup ignores the metadata map and keeps its existing owned-key/grace/active-generation rules. Preset/company payloads remain forever values.
- `CompanyListCacheService.php`: nonpreset reads ignore old persisted wrappers, require exact shared custom metrics, and aggregate only in a request memo. They preserve availability and generation race checks. Missing/expired custom metrics return typed unavailable. They perform no persistent wrapper write and no annual calculation. Preset behavior is unchanged.
- Added focused regression cases in `SharedPriceCacheReadBoundaryTest.php` and `ContractPriceCacheLifecycleTest.php`. Updated `Caching/AGENTS.md`; its existing `CLAUDE.md -> AGENTS.md` symlink remains intact.

## Verification

- Initial two-class run: 37 passed, two test failures. Corrected a mock reset call and a SQL assertion that incorrectly included the active-ID EXISTS subquery. Next two-class run: 39 passed, 429 assertions.
- Initial six-class run after company follow-up: 84 passed, one mocked-preset detection failure. Company reads now use the public preset constant directly; no fixture change was needed.
- Final command: `cd laravel && php artisan test --filter='ContractPriceCacheLifecycleTest|PriceCacheConflictRecoveryTest|ContractRequestMemoizationTest|AnnualConsumerConsistencyTest|CompanyListPageTest|SharedPriceCacheReadBoundaryTest'` — **85 passed, 693 assertions, 2.24 seconds**. This retains the prior 74-test gate, including demand-metadata-loss floor cases.
- Scoped `vendor/bin/pint` formatted the five owned PHP files. Final scoped `vendor/bin/pint --test` passed.
- Scoped `git diff --check` passed. Reviewed the owned code/diff and final `git status --short`; unrelated work remains intact.
- SQLite executes the four-scalar availability query. MySQL grammar compilation proves four JSON_UNQUOTE/JSON_EXTRACT projections without a connection. No live MySQL execution was done.
- No full suite or frontend build was run, as instructed.

## Limits

- Partial old metadata cannot prove the new mechanism flags until verified refresh. This compatibility is deliberate; no cache schema bump discards user-approved retained prices.
- Expiry pruning is action-driven before custom preparation/write, not a new scheduled active-generation sweep. At most 64 new custom ownership entries remain when no more actions occur; generation retirement still owns their later cleanup.
- Pre-change custom entries without expiry metadata are not retroactively migrated. The handoff feature is still local and not deployed; a verified replacement removes these entries from active reads. Old persistent custom company wrappers are ignored, not restored or extended.
- Request memos do not extend persistent TTL. Finite-TTL ranking/prepared-page caches stay outside this forever-manifest scope. Caller UI validation and client-result locking belong to the caller agent.
