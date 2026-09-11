# Core implementation progress

## Integrity/fixture follow-up (complete)
- Integrity now keeps a detail-only caveat for detected annual gap estimates, with no invented later rate, rise pill, or EUR impact. In-term gaps no longer qualify for safe short-term continuation suppression. Detected gate, recurring suppression, and known-price materiality thresholds remain.
- Core-policy feature fixtures now use opaque `other` energy when testing exclusion, not usable known prices with unknown continuation. Listing tests explicitly retain the useful unknown-continuation estimate. Fee-only API/detail tests require unavailable pricing, not free energy.
- Final focused command `php artisan test --filter='ContractPricingIntegrityServiceTest|CanonicalPricingListingTest|CanonicalOfferSurfacesTest|ContractApiCanonicalPricingTest|ContractDetailPresenterTest|ContractTypeComparisonTest'`: **62 passed, 365 assertions**, log `/tmp/core-integrity-final-tests.log`.
- Final `php artisan test --filter=BillComparisonCanonicalPricingTest`: **7 passed, 28 assertions**, log `/tmp/core-integrity-bill-tests.log`. VAT owner fixed the temporary `$spot` variable failure; no calculator edit was made by this follow-up.
- Isolated `ContractListingEligibilityTest|PricingBucketFilterTest`: **25 passed, 2 failed**. Both failures are invalid Livewire snapshots after setting public `page` to 3 (ContractListingEligibilityTest:87 and PricingBucketFilterTest:226), not a pricing fixture issue. These two files were not edited.
- Full suite captured during integration: **2173 passed, 49 failed, 9606 assertions**, log `/tmp/core-integrity-full-tests.log`. Most new failures were the then-unfixed VAT period variable. Remaining classes included ArticleSpotElectricityStatisticsQueryTest, CompanyDetailSectionsTest, ContractCardPresenterTest, ContractsListPageTest, HomePageContractTrendTest, MarketResetEstimateSurfacesTest, and the two Livewire snapshot cases. The VAT-related calculator test also still expected the old Company rolling fallback. Those files are outside this follow-up ownership.
- Focused Pint applied successfully to all eight follow-up PHP files; `git diff --check` passed. No constructor/config, calculator, VAT foundation, provider binding, reader, AsOf, card presenter/copy, or Blade file was edited.
- Follow-up changed paths: `ContractPricingIntegrityService.php`, its unit test, and the six feature fixture files listed in the focused command plus BillComparisonCanonicalPricingTest (CanonicalPricingListingTest, CanonicalOfferSurfacesTest, ContractApiCanonicalPricingTest, ContractDetailPresenterTest, ContractTypeComparisonTest, BillComparisonCanonicalPricingTest).
- Integrity details use the canonical data passed by the caller. The service does not normalize VAT; the orchestrator must provide the normalized data, as agreed. No additional raw-rate basis defect was found.
- Parent calculator follow-up verified resolved by its owner: `onlyFuturePricingUnknown()` now includes `structured_matches_description` and `optional_fixing_not_in_base_price`. This follow-up did not edit the reserved calculator.


Core edits are complete and released for the VAT integration. Calculator now uses chronological annual segments for short terms, Hybrid bases, and unknown continuation estimates. Known phase spans do not extend into assumed offer periods. Integration exceptions remain below.

## Public copy integration
- New EstimateMethod: `HoldLastKnownPrice` = `hold_last_known_price`.
- Existing methods stay `term_price_annualized` for short terms and `hybrid_base_only` for Hybrid bases.
- Gap estimates retain `held_current_price_forward` and add `unknown_periods_use_latest_applicable_price_or_disclosed_normal`.
- `term_price_annualized` and `excludes_consumption_effect` assumptions remain.
- A term total can now contain estimated in-term coverage. Do not state that every in-term price is known.
- Hybrid bases keep every disclosed phase. Do not state that the signup base applies to the full year.
- New Spot assumption: `spot_forward_curve_flat_baseload_shape` when the estimate has `flat_baseload_shape_assumption`. Annual costing can use a complete forward estimate without historical shape; the original SpotEstimate provenance stays unchanged.
- Resolved in the integrity follow-up above: detected estimated gaps retain a factual detail caveat without a rise claim or invented EUR impact.

## Arithmetic decisions
- Calendar-month usage fractions conserve each calendar month's annual profile, including February across leap years.
- Display bins use no-overflow contract-month anniversaries. Calendar slice fractions can produce different monthly energy totals. No-op phase insertion must not change them.
- Ordinary fees use contract-month fractions, so N complete contract months cost N monthly fees. Package fee and allowance remain calendar-month scoped.
- Reset shifts have an internal exact `tailStartsOn` date and do not apply before it. Public reset/supplier annual equivalent is derived from costed energy divided by costed kWh. Episode matching representative rates are unchanged.
- One-time fees use the original component object's identity, so inherited charges apply once, but separate disclosed charges remain distinct.

## Verification so far
- `/tmp/voltikka-annual-review.php`: Fixed6 first month only now 1260 annual comparison / 630 estimated term; Hybrid disclosed 5 then 10 now 1150; inherited one-time fee case now 1321.
- `/tmp/voltikka-reset-review-extra.php`: known-through-August-15 cost equals expected 575.268817204; varying disclosed phases reconcile at 11.0 c/kWh.
- Updated old expectations for unknown-future exclusion, calendar-bin misalignment, first-month fee proration, and representative annual equivalents with explicit calculations.

## Final verification
- `cd laravel && php artisan test --filter='CanonicalContractPriceCalculatorTest|CanonicalPeriod|MarketResetForwardShiftTest|SupplierAdjustedPricingTest|ContractPriceStatisticsCanonicalSourceTest|MonthlyUsageProfileConsistencyTest'`: **134 passed, 1062 assertions**. Log: `/tmp/core-final-tests.log`.
- `php artisan test`: **2178 passed, 36 failed, 9589 assertions** during concurrent integration. Log: `/tmp/core-full-tests.log`. This was before the final small exact-period reset regression and cleanup. Do not treat the full suite as passed.
- Focused Pint check over all 11 owned PHP files: passed. A prior `pint --dirty --test` also found unrelated/concurrent formatting differences; no unrelated file was formatted.
- `git diff --check`: passed. Final diff and status reviewed. No CSS/JS edits, no asset build, no commit, no deployment.

## Remaining integration work for parent
- Resolved by the assigned integrity follow-up: `promoLabel()` keeps unknown-gap detail warnings without price-rise or measured-impact claims; in-term gaps no longer use the safe outside-term continuation exemption.
- Resolved by the assigned fixture follow-up: changed-inclusion and fee-only safety tests now preserve useful unknown-continuation estimates and use genuinely opaque energy in exclusion fixtures. All six affected feature classes pass in final targeted runs.
- Other full-suite failures: `CanonicalPricingParserTest` (VAT foundation), `ArticleSpotElectricityStatisticsQueryTest` (4), `CompanyDetailSectionsTest` (16), `ContractCardPresenterTest` (2 copy expectations), `ContractListingEligibilityTest`, `ContractsListPageTest`, `HomePageContractTrendTest`, `MarketResetEstimateSurfacesTest` (dated cache key), and `PricingBucketFilterTest`. See the full log for actual failures; these areas have concurrent owners or are outside this unit.
- Core updated `ContractPriceStatisticsCanonicalSourceTest` deliberately: unknown continuation produces a 250 EUR estimate; a fee-only unidentifiable energy price produces no snapshot.
- Exact-period pricing does not receive annual reset offsets now. Its existing limited hold policy for recurring/Spot/Hybrid periods remains, and held slices select a price that has already started. Ordinary unknown continuation gaps still return no period pricing; annual estimates do not fill them.
- Existing same-mechanism inheritance for disclosed changed-component phases remains. Estimated gaps limit inheritance to already-applicable phases. No parser, tax DTO foundation, episode resolver, orchestrator, cache, API, or shared root/canonical context file was edited by core.

## Owned changed paths
- `laravel/app/Services/CanonicalPricing/CanonicalContractPriceCalculator.php`
- `laravel/app/Services/CanonicalPricing/DTO/WindowSegment.php`
- `laravel/app/Services/CanonicalPricing/Enums/EstimateMethod.php`
- `laravel/app/Services/CanonicalPricing/Support/{PhaseTimelineBuilder,MonthlyUsageProfileBuilder}.php`
- `laravel/app/Services/CanonicalPricing/Support/{AGENTS,CLAUDE}.md` (new context and symlink)
- `laravel/app/Services/CanonicalPricing/MarketReset/DTO/ResetEstimate.php`
- `laravel/app/Services/CanonicalPricing/MarketReset/AGENTS.md` (CLAUDE is already a symlink)
- `laravel/tests/Unit/CanonicalPricing/{CanonicalContractPriceCalculatorTest,MarketResetForwardShiftTest,SupplierAdjustedPricingTest,MonthlyUsageProfileConsistencyTest}.php`
- `laravel/tests/Feature/ContractPriceStatisticsCanonicalSourceTest.php`
- This notes file.
