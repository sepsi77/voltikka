# Annual pricing test integration

## Result
Done with a pre-existing formatting exception. Only five existing feature test files and this note were changed. No app code, config, other documentation, VAT/core tests, or new regression files were changed. No commit, deployment, or production command ran.

## Changes
- `MarketResetEstimateSurfacesTest.php`: replace hard-coded calculated schema 15 with `CalculatedCostPayloadSchema`. Ranking key checks include the current Helsinki date. Both reset flag keys, cache entry growth, and import-version checks remain.
- `ContractCardPresenterTest.php`: another agent had already changed the three old copy strings before this unit's first test run. Preserve those changes. Add checks for calculated real-term cost multiplied by 12 / 6, known and estimated term parts, and no post-term offer. Rename the plain Hybrid test and check known base prices, explicit unknown-price assumptions, and exclusion of the consumption effect. Reject the old single flat-rate statement.
- `ContractListingEligibilityTest.php`: add 25 national contracts to the existing national contract. Page 2 now exists before invalid input resets it or postcode restoration preserves it. Keep postcode errors and browser events. Check the national-only total, page size, and absence of the regional contract.
- `PricingBucketFilterTest.php`: add 22 fixed contracts to the four existing bucket fixtures. Verify page 2 renders before the bucket action resets it. Keep toggle order, first-click analytics, and final Spot membership checks.
- `ContractsListPageTest.php`: create 26 priced, active national Hybrid contracts with explicit classifier defaults. Keep consumption 10000, the row-house preset, null direct input, the consumption-effect filter, and page 2. Also assert a successful response and the filter/page properties.

## Existing failures versus annual-pricing changes
- Baseline HEAD is `59e0c1f`. `git show HEAD:...` and SHA-1 comparisons prove that `ContractsList`, `SeoContractsList`, and `SahkosopimusIndex` are byte-identical to HEAD. The three failing pagination tests were also unchanged from HEAD before this unit.
- HEAD already aborts with 404 when the requested page exceeds the last page. Eligibility had one national row but set page 3. Bucket toggling had four rows but set page 3. The query-consumption test had no contracts but requested filtered page 2. Thus, these are existing invalid fixtures, not annual-pricing regressions. The invalid Livewire snapshot and null consumption follow the 404 response. No public input handler change was necessary.
- Schema/date assertions and term/Hybrid copy expectations became obsolete through the intended annual-pricing changes.
- An intermediate query fixture with null canonical classifier fields was still filtered out. Use the existing `CanonicalPricingFixture::fixedAttributes()` defaults to provide explicit absent reset facts, then set the Hybrid model. The unchanged SQL resolver has SQL-null behavior for absent classifier fields; this unit did not change that behavior. Review null-classifier handling separately if real unclassified Hybrid contracts must enter this filter.

## Verification
Commands ran from `laravel` unless stated otherwise. No explicit AsOf process override was used. The documentation agent's forced legacy annual-method default was present.

1. `php artisan test --filter='MarketResetEstimateSurfacesTest|ContractCardPresenterTest|ContractListingEligibilityTest|PricingBucketFilterTest|ContractsListPageTest'`
   - Before edits: **6 failed, 179 passed, 646 assertions**. Three schema/date tests and the three pagination fixtures failed. Presenter tests already passed after the other agent's string edits. Log: `/tmp/annual-integration-before.log`.
   - Intermediate: **1 failed, 184 passed, 683 assertions**. Query fixture still returned 404 because it lacked classifier defaults.
   - Final: **185 passed, 688 assertions**, 3.20 seconds. Log: `/tmp/annual-integration-after.log`.
2. `vendor/bin/pint --test tests/Feature/{MarketResetEstimateSurfacesTest,ContractCardPresenterTest,ContractListingEligibilityTest,PricingBucketFilterTest,ContractsListPageTest}.php`
   - Failed on pre-existing `fully_qualified_strict_types` and `ordered_imports` in Presenter, Eligibility, and ListPage tests. The other two files passed. To verify origin, exported those three HEAD files to `/tmp/annual-test-style-head.h1Kop4` and ran `vendor/bin/pint --test --config=pint.json /tmp/annual-test-style-head.h1Kop4`. All three HEAD copies fail with the same two fixers. Left unrelated formatting unchanged.
3. `php artisan test`
   - **2235 passed, 10013 assertions**, 92.35 seconds. Log: `/tmp/annual-integration-full.log`. This is the integrated working-tree result at this run, not a promise about later concurrent edits.
4. Root `git diff --check`: passed. Reviewed the five-file diff and final `git status --short`; concurrent files were preserved. Status snapshot: `/tmp/annual-integration-status.log`.

No CSS/JS changed, so no asset build ran in this unit. No annual-pricing app failure remains from these tests. The parent owns any further null-classifier review, shared formatting cleanup, and final release checks.
