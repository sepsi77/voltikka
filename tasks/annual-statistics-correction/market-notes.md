# Market input unit — complete

## API and rules

- Required API: `public function spotSeasonalIndex(CarbonImmutable $asOfDate): ?array`.
- Both reset and supplier estimators pass the request target, not the process clock.
- Seasonal evidence uses raw DATE/datetime bounds: start before the Helsinki target month and declared end before the Helsinki target date. The latest eligible completed month anchors the existing four-year window. Finite positive prices, yearly ratios, minimum years per month, and 12-month coverage stay unchanged. Cache entries, including null, use the Helsinki date key.
- Reset and supplier reference lookup dates are `min(period/episode start, target)`. Delivery anchors stay unchanged. A future anchor adds `reference_vintage_bounded_by_as_of`. The existing reset missing-history fallback uses the target date and keeps `reference_vintage_fallback_today` for compatibility.
- Two forward vintages remain intentional. Beta, VAT conversion, exact known periods, negative floors, fallback confidence, and absurdity guards are unchanged.
- No calculator, writer, method enum, stored evidence, or production data was changed by this unit. The other unit must select corrected historical paths and preserve v1 fallback behavior.

## Files

Implementation: `MarketReset/{MarketReferenceCurveProvider,EexMarketReferenceCurveProvider,MarketResetPriceEstimator}.php`, `SupplierAdjusted/SupplierAdjustedPriceEstimator.php`, and both closest `AGENTS.md` files (CLAUDE symlinks remain valid).

New coverage: `tests/Feature/HistoricalMarketInputBoundaryTest.php` (7 tests). Existing EEX tests now pass explicit dates. Seven fake-containing files have signature updates: SupplierAdjustedPricingTest, HoldFlatCanonicalCalculator, MarketResetForwardShiftTest (two fakes), SpotForwardPriceEstimatorTest, ContractApiCanonicalPricingTest, AsOfAnnualCostCalculatorTest, CurrentAsOfAnnualCostParityTest. Only signatures changed in the latter two shared files. Two MarketResetForwardShiftTest expectations now assert the bounded September target while retaining October delivery anchors.

## Verification

Initial focused run: 68 passed, 3 failed. Two old expectations required the now-forbidden future reference vintage; one new test used the wrong hold-flat enum value. These assertions were corrected.

Final command (SQLite `:memory:` from forced phpunit configuration):

```sh
cd laravel
php artisan test --filter='HistoricalMarketInputBoundaryTest|EexMarketReferenceCurveProviderTest|MarketResetForwardShiftTest|SupplierAdjustedPricingTest|SpotForwardPriceEstimatorTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|ContractApiCanonicalPricingTest|VatBasisFoundationTest|MarketResetEstimateSurfacesTest'
```

Result: **118 passed, 1110 assertions**. This includes all seven fake signature consumers and reset/supplier/Spot regressions. New cases cover future extremes, a stored full in-progress month, Helsinki rollover, both cache lookup orders, null memoization, hold-flat fallback, future announced delivery periods, same-target results under different clocks, and missing historical reference fallback.

`git diff --check`: passed. No CSS/JS change, so no asset build. Existing uncommitted annual-pricing changes were preserved.
