# SEO, detail, offer and current-pricing test fixtures

## Result

Done. All 11 assigned classes pass together: **282 tests, 3,993 assertions**.
Only the assigned test files and this record were changed. No production code, shared TestCase,
context file, global test warming, or GET calculation exception was added. Existing dirty work
was retained. No full-suite run, production operation, commit, or push was made.

## Changes

- `tests/Feature/SeoCityRoutesTest.php`, `SeoEnergyRoutesTest.php`, and
  `SeoHousingRoutesTest.php`: explicitly refresh the verified eight presets and company cache
  after fixture creation and before HTTP reads or initial listing mounts. The city SQL counter
  starts after the producer. All route, SEO, 404, national/postcode availability and lazy solar
  assertions remain.
- `tests/Feature/ContractDetailPriceDevelopmentTest.php`: refresh before initial detail mounts.
  The helper also checks that all observed price-component attributes and daily-statistic
  attributes are unchanged by annual warming. Historical values, source dates, chronology,
  replacement timelines and original observed-date assertions remain unchanged.
- `tests/Feature/CanonicalOfferSurfacesTest.php`: refresh before company and SEO mounts.
  SQL query logs start after producer setup. Financial, membership, offer and JSON-LD assertions
  remain unchanged.
- `tests/Feature/CurrentPromotionTermsTest.php`: give public fixture contracts explicit active
  membership and refresh before API GET. The real calculation POST and promotion exclusions
  remain unchanged.
- `tests/Feature/CurrentResetPremiumIntegrationTest.php` and
  `CurrentSupplierPremiumIntegrationTest.php`: refresh before API GET. Rebind the existing
  test curve after scoped-instance reset so the producer uses the same financial evidence as
  the original fixture. Both GETs request the supported 5,000 kWh preset. No custom profile is
  requested, so no temporary POST preparation is necessary.
- `tests/Feature/MarketResetEstimateSurfacesTest.php`: seed verified producers before cache
  and ranking reads. Preserve the earlier explicit lifecycle promotion. Its version test now
  reads version 2 after the first verified refresh, then version 4 after the existing promotion
  and second verified refresh. Check company wrapper schema 3, as required by the implemented
  retained-cache reader. Estimator and financial gates are unchanged.
- `tests/Unit/EnergyRulePublicOutputTest.php`: convert the existing real typed canonical
  fixture outcomes through `CanonicalContractMetric` and strict `ContractMetric::fromArray`.
  Keep finite sort keys, listability, comparability, integrity, emissions and consumption-limit
  fields. API/weekly numeric and public-shape assertions remain unchanged.
- `tests/Unit/ContractRankingTypedMetricsTest.php`: mock only `currentAvailability()` on a
  partial real cache-service instance. The real fingerprint, cache hydration and malformed-total
  exception still run. No JSON query mock or global database bypass was added.

## Verification

Commands ran in `laravel/`:

```sh
php artisan test --filter='SeoCityRoutesTest|SeoEnergyRoutesTest|SeoHousingRoutesTest|ContractDetailPriceDevelopmentTest|CanonicalOfferSurfacesTest|MarketResetEstimateSurfacesTest|CurrentPromotionTermsTest|CurrentResetPremiumIntegrationTest|CurrentSupplierPremiumIntegrationTest|EnergyRulePublicOutputTest|ContractRankingTypedMetricsTest'
```

- First integration run: 15 failed, 267 passed, 3,892 assertions. Remaining causes were missing
  company warming, old typed reflection fixtures, missing scalar availability fixture, lost scoped
  test curves, missing active promotion membership and cold market-reset cache-key fixtures.
- Second run: 1 failed, 281 passed, 3,956 assertions. The remaining fixture expected old company
  wrapper schema 2. It now checks schema 3.
- Final run after formatting and history-preservation checks: **282 passed, 3,993 assertions**,
  23.55 seconds. Log: `/tmp/seo-test-final.log`.

```sh
vendor/bin/pint tests/Feature/SeoCityRoutesTest.php tests/Feature/SeoEnergyRoutesTest.php tests/Feature/SeoHousingRoutesTest.php tests/Feature/ContractDetailPriceDevelopmentTest.php tests/Feature/CanonicalOfferSurfacesTest.php tests/Feature/MarketResetEstimateSurfacesTest.php tests/Feature/CurrentPromotionTermsTest.php tests/Feature/CurrentResetPremiumIntegrationTest.php tests/Feature/CurrentSupplierPremiumIntegrationTest.php tests/Unit/EnergyRulePublicOutputTest.php tests/Unit/ContractRankingTypedMetricsTest.php
vendor/bin/pint --test tests/Feature/SeoCityRoutesTest.php tests/Feature/SeoEnergyRoutesTest.php tests/Feature/SeoHousingRoutesTest.php tests/Feature/ContractDetailPriceDevelopmentTest.php tests/Feature/CanonicalOfferSurfacesTest.php tests/Feature/MarketResetEstimateSurfacesTest.php tests/Feature/CurrentPromotionTermsTest.php tests/Feature/CurrentResetPremiumIntegrationTest.php tests/Feature/CurrentSupplierPremiumIntegrationTest.php tests/Unit/EnergyRulePublicOutputTest.php tests/Unit/ContractRankingTypedMetricsTest.php
```

Scoped formatting completed; the final formatting check passed. `git diff --check` passed.
The owned diffs and final `git status --short` were reviewed. No query bounds were raised,
coverage was removed, or financial expectations were changed. No production bug was found.
The full release gate remains with the manager and other test owners.
