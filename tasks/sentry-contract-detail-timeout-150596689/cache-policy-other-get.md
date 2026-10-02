# Remaining public annual GET consumers

## Result

Implementation and fixture integration are complete in the assigned consumers and their four old test classes. The combined owned regression gate passes. The old 34 failures in those classes are resolved. No test was disabled and no global test warming or GET calculation permission was added.
No production operation, network call, commit, or push was made.

## Changes

- `Api/ContractController`: list/show use shared typed cached metrics at the requested consumption, or the 5,000 kWh reference when consumption is absent. The previous 1 kWh batch is removed. No-consumption responses still omit calculated cost. Canonical unit/package/estimate/offer/integrity fields keep their transport. Missing cached pricing is explicit unavailable pricing, not a fabricated calculator outcome. Null totals sort last. Feature-off annual costs also read cached metrics. The explicit calculation POST controller is unchanged.
- `WeeklyOffersVideoService`: both modes read the shared 2,000/5,000/10,000 kWh sets. Generation identity is checked before and after each read. One promotion retries all profiles, with two total attempts. Two promotions fail with `ContractPriceCacheUnavailable`; no mixed-generation prices are returned. Missing contract profiles are ineligible. Canonical benefit ranking, one-company selection, terms and public shape remain. Legacy costs/savings read cached values. The duplicate annual calculation path and its unused Spot-average helper are removed.
- `Api/VideoController`: the weekly unavailable exception returns JSON 503 with Retry-After and no-store.
- `ContractTypeComparison`: public GET/HEAD and automatic POST initialization use cached `ContractPricingViewData` for candidate selection, monthly series, annual values, rates, packages and benefit/estimate copy. The series is not reconstructed. Cached month labels use the retained calculation date. Missing sides stop the comparison. Only actual mode, consumption and contract-selection action hooks grant the shared `PublicPriceCalculationPolicy` permission. Canonical explicit actions still evaluate exact outcomes; legacy explicit actions keep their old seasonal monthly math and integer-kWh truncation.
- `Http/AGENTS.md` records these rules. Its existing `CLAUDE.md` symlink supplies the mirror. No WeeklyOffers-specific context exists. Livewire/cache/root contexts remain with their other owners.
- `OtherPublicGetPriceCacheTest` seeds verified generations before pricing spies. It covers both pricing modes, API GET/HEAD with absent/all-eight-preset/custom consumption, positive prepared-custom GET reads, new and inactive IDs, missing generations, weekly profiles and missing membership, CLI weekly cache reuse, promotion retry/exhaustion, initial widget selection/chart/display, automatic POST initialization and exact explicit actions.
- `ContractApiCanonicalPricingTest`, `ContractApiTest`, `ContractTypeComparisonTest`, and `WeeklyOffersCanonicalPricingTest` now explicitly run private verified refresh after fixture creation and before their first priced GET/initial mount or pricing spies. Legacy API priced fixtures now have explicit active membership. Financial values, offer terms, membership, one-company selection and transport assertions remain. Cached GET query budgets are five for the contract API and six for weekly profiles, with zero annual evaluations/market reads.
- A true widget transport defect was corrected: the cached accessor discarded excluded metric pricing and thus lost typed exclusion comparability. It now retains null-total exclusion facts for unavailable chart/display copy, but never exposes a priced excluded side. The existing missing-canonical test checks `excluded_incomplete` in both surfaces. The widget spy test proves initial zero evaluations, exactly one evaluation per side after explicit selection, and exact cached/action chart parity for equivalent inputs. The Hybrid test rebuilds its fixture generation after changing classification, rather than reusing incompatible cached facts.

## Verification

Final commands in `laravel/`:

```text
php artisan test --filter='ContractApiCanonicalPricingTest|ContractApiTest|ContractTypeComparisonTest|WeeklyOffersCanonicalPricingTest|OtherPublicGetPriceCacheTest|CalculationApiTest'
85 passed; 738 assertions

vendor/bin/pint --test app/Http/Controllers/Api/ContractController.php app/Http/Controllers/Api/VideoController.php app/Services/WeeklyOffersVideoService.php app/Livewire/ContractTypeComparison.php tests/Feature/ContractApiCanonicalPricingTest.php tests/Feature/ContractApiTest.php tests/Feature/ContractTypeComparisonTest.php tests/Feature/WeeklyOffersCanonicalPricingTest.php tests/Feature/OtherPublicGetPriceCacheTest.php
passed
```

Scoped Pint formatting was run before the final test. `git diff --check` passes. The owned diffs and final worktree status were reviewed. Unrelated worktree changes were retained.

## Remaining unowned full-suite failures

A full local `php artisan test` checkpoint ran while other agents still edited their fixtures. It completed with **218 failed, 2,708 passed, 22,922 assertions**, in 133.84 seconds. No failure belongs to the four assigned old test classes or `OtherPublicGetPriceCacheTest`. This checkpoint began before the last four new profile/CLI cases and exclusion-transport assertions; the final owned 85-test gate above ran afterwards. The temporary failure detail is `/tmp/other-get-full-suite.log`.

Remaining class failure counts at that checkpoint:

- ActiveContractFilterTest 6; CanonicalOfferSurfacesTest 8; CanonicalPricingListingTest 1.
- ContractDetailPriceDevelopmentTest 18; ContractListingEligibilityTest 4; ContractRankingTypedMetricsTest 1.
- ContractsFilterTest 23; ContractsListPaginationTest 31.
- CurrentPromotionTermsTest 1; CurrentResetPremiumIntegrationTest 1; CurrentSupplierPremiumIntegrationTest 1.
- EnergyRulePublicOutputTest 2; FixedDurationContractsListingTest 20; MarketResetEstimateSurfacesTest 4.
- PricingBucketFilterTest 22; SahkosopimusBillModeTest 6.
- SeoCityRoutesTest 30; SeoEnergyRoutesTest 23; SeoHousingRoutesTest 16.

The three Current* integration failures above are API cold-GET fixtures returning the intended 503 and need explicit warming before GET. `EnergyRulePublicOutputTest` uses reflection to pass old `CanonicalContractMetric` values to the now cached-`ContractMetric` API/weekly private adapters; its two fixtures need typed cache metric conversion. `ContractRankingTypedMetricsTest` has no `electricity_contracts` table for the new scalar availability read. The remaining listing/detail/SEO tests mostly encounter the public unavailable boundary. These files belong to other units and were not edited. The complete release gate is not yet green.

## Ownership and limits

Shared cache reads, lifecycle, producer, policy, provider and other Livewire consumers were not edited. The aggregate task status/spec/decision files remain with the manager; this file records this assigned unit. No financial calculator mathematics, schema, historical evidence, migration, dependency, environment variable or feature flag changed. Full-suite reconciliation and production latency/release proof remain separate manager work.
