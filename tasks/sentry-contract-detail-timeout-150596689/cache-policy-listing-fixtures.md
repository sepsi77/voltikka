# Listing test fixture integration

## Result

Done in the eight assigned test classes. The final local test run has **122 passed, 638 assertions**. The 113 failures assigned from the earlier full-suite checkpoint are resolved. No production code or shared test/context file was changed. No assertion or test was removed.

## Changes

Each class now has a small private `refreshPriceFixtures()` helper. It calls `ContractListCacheService::refresh(CompanyListCacheService)` explicitly after fixture creation and before the initial GET or Livewire mount. Empty-market display tests prepare a verified empty generation; they are display tests, not intentional missing-generation tests. Model-only tests do not prepare prices.

- `ActiveContractFilterTest.php`: prepare active membership before listing, detail and home-page mounts. Active/inactive membership and banner checks remain.
- `CanonicalPricingListingTest.php`: prepare canonical eligible and excluded facts before the initial mount. Assert the initial 5,000 kWh profile without granting an action permission. Known prices, incomplete promotions, malformed packages and price-increase copy keep their checks.
- `ContractListingEligibilityTest.php`: prepare national and regional fixtures after postcode links exist. All postcode validation, restoration, membership and browser persistence checks remain.
- `ContractsFilterTest.php`: prepare complete filter fixtures before mounts. The consumption-change case calls `setConsumption`, not an internal property mutation. All classification, metering, energy and postcode checks remain.
- `ContractsListPaginationTest.php`: prepare complete pagination fixtures before mounts and GETs. Ordering, counts, URL parameters, metadata, page reset, 404 and accessibility checks remain.
- `FixedDurationContractsListingTest.php`: prepare duration fixtures before mounts and GETs. Prepare again after the test's deliberate cache flush and after it creates a second contract. The selected-consumption case calls `setConsumption`. All financial, typed category, statistics, forecast and guide checks remain.
- `PricingBucketFilterTest.php`: prepare fixtures before mounts and GETs. Reuse one generation across read loops when fixtures do not change. Bucket, bill ranking, legacy mapping, pill and accordion checks remain.
- `SahkosopimusBillModeTest.php`: prepare annual fixtures before the initial mount, including the existing private bill-component builder. Period costs, savings, ranks, consumption caps, Spot omission, household and business checks remain.

No global warming layer, GET calculation exception, lazy-initialization permission, query-budget increase or service change was added. These classes have no custom-consumption GET that requires explicit profile preparation and no query-count budget to alter. Existing bill form actions remain unchanged.

## Verification

Commands ran in `laravel/` unless stated otherwise:

```text
php artisan test --filter='ActiveContractFilterTest|CanonicalPricingListingTest|ContractListingEligibilityTest|ContractsFilterTest|ContractsListPaginationTest|FixedDurationContractsListingTest|PricingBucketFilterTest|SahkosopimusBillModeTest'
Initial integration: 1 failed, 121 passed, 634 assertions.
Failure: FixedDurationContractsListingTest deliberately flushed the newly prepared generation before its second mount.
Final run: 122 passed, 638 assertions, 5.47 seconds.
```

The fixture was explicitly prepared again after that flush. No production defect was found.

```text
vendor/bin/pint tests/Feature/{ActiveContractFilterTest,CanonicalPricingListingTest,ContractListingEligibilityTest,ContractsFilterTest,ContractsListPaginationTest,FixedDurationContractsListingTest,PricingBucketFilterTest,SahkosopimusBillModeTest}.php
Completed; formatted the eight owned files.

vendor/bin/pint --test tests/Feature/{ActiveContractFilterTest,CanonicalPricingListingTest,ContractListingEligibilityTest,ContractsFilterTest,ContractsListPaginationTest,FixedDurationContractsListingTest,PricingBucketFilterTest,SahkosopimusBillModeTest}.php
Passed.

git diff --check  # repository root
Passed.
```

Final test output: `/tmp/listing-fixtures-final.log`. Initial output: `/tmp/listing-fixtures-first.log`. The owned diffs and worktree status were reviewed. Unrelated dirty work was retained. No full-suite run was made while other agents changed files. No CSS or JS changed, so no asset build was needed.

## Known unowned checkpoint failures

The earlier 218-failure checkpoint in `cache-policy-other-get.md` had 105 failures outside this unit:

- CanonicalOfferSurfacesTest: 8.
- ContractDetailPriceDevelopmentTest: 18.
- ContractRankingTypedMetricsTest: 1.
- CurrentPromotionTermsTest, CurrentResetPremiumIntegrationTest, CurrentSupplierPremiumIntegrationTest: 1 each.
- EnergyRulePublicOutputTest: 2.
- MarketResetEstimateSurfacesTest: 4.
- SeoCityRoutesTest: 30; SeoEnergyRoutesTest: 23; SeoHousingRoutesTest: 16.

These are historical checkpoint counts, not a claim about their current status. Other agents own those files. The manager must run the release gate after all units are complete.

## Limits

No production action, network request, commit or push was made. The manager owns aggregate task files and shared context updates. This file records only the assigned test work.
