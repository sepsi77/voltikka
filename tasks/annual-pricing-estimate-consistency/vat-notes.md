# VAT foundation

## Status
Foundation and calculator VAT integration are complete for the documented current-VAT scope. No production calls, commits, schema-version change, or historical rewrites by the VAT agent.

## Calculator integration (completed)
- Calculator entry points normalize explicit amounts and normal amounts before annual, exact-period, candidate, or direct-rate pricing. Household/Both/null select included; Company selects excluded. Unknown component VAT and package values retain the documented target-basis assumption.
- AppServiceProvider supplies one configured `price_forecasting.fixed_term.vat_multiplier` snapshot. Reset and supplier requests convert both reference and forward market prices by 1/factor for Company. Company 8 c/kWh and 50 -> 90 EUR/MWh gives 583.333333 EUR at 5000 kWh, not 630.083333 EUR. Household equivalent is 732.083333 EUR.
- SpotAssumptions and SpotEstimate expose idempotent selected-cost copies and preserve dates, coverage, counts, flags, and confidence. Shared household market facts remain unchanged. Company forward pricing is enabled. Annual public data and Spot estimate data carry `vat_basis`.
- Exact Company periods prefer optional `HistoricalSpotPrice::centsPerKwhWithoutTax`. Inclusive-only hours from 2024-09-01 onward use the configured current factor. Earlier inclusive-only hours cannot prove an excluded bill and return unavailable. Annual rolling normalization is a current-basis estimate, not a reconstruction of historical VAT.
- SEO and detail VAT text no longer unconditionally says 25.5% included. Company listings explicitly distinguish Company excluded prices from Both included prices. Spot card popovers read public `vat_basis` for VAT wording. Other copy is preserved.
- Fixed the calculator shape flag to use the estimator's actual `zero_intraday_shape_fallback`. Added harmless affirmative issue codes `structured_matches_description` and `optional_fixing_not_in_base_price` to future-gap eligibility. Unknown continuation disclosure now applies to Spot gaps too; it does not claim wholesale prices are held flat.
- Added `DTO/AGENTS.md` and its CLAUDE symlink; updated SpotForward and ContractCard context. No shared root/canonical context or tasks.json edits.

## Late window consistency fix
- At the parent's request, changed SpotForwardPriceEstimator's window end from `addYear()` to `addMonthsNoOverflow(12)`. Added a February 29 regression with a distinct last-month rate to prove the final February has 27 priced days, not 28.
- `php artisan test --filter='SpotForwardPriceEstimatorTest|VatIntegrationTest|CanonicalContractPriceCalculatorTest'`: **77 passed, 569 assertions**. Log `/tmp/vat-leap-tests.log`. Pint passed for both added-scope files; `git diff --check` passed.

## Integration verification
- Final focused command: `cd laravel && php artisan test --filter='VatIntegrationTest|VatBasisFoundationTest|CanonicalPricingParserTest|CanonicalContractPriceCalculatorTest|CanonicalPeriod|MarketResetForwardShiftTest|SupplierAdjustedPricingTest|ContractPriceStatisticsCanonicalSourceTest|MonthlyUsageProfileConsistencyTest|ContractCardPresenterTest|AnnualEstimateCopyConsistencyTest'`: **227 passed, 1562 assertions**. Log `/tmp/vat-core-final.log`.
- Full suite before the final Spot gap regression: `php artisan test`: **2223 passed, 6 failed, 9934 assertions**. Log `/tmp/vat-full-tests.log`. Failures: ContractListingEligibilityTest line 87 and PricingBucketFilterTest line 226 (invalid Livewire snapshots), ContractsListPageTest line 277 (query consumption null), MarketResetEstimateSurfacesTest lines 69/87/163 (obsolete schema keys). New integration-test agent owns these failures.
- Focused Pint check passed for all owned PHP implementation files, new VAT tests, parser/calculator tests, and AnnualEstimateCopyConsistencyTest. Broader earlier Pint check found unrelated import formatting in ContractCardPresenterTest; it was not formatted.
- Initial VAT tests exposed and fixed a local exact-period variable error and a supplier test fixture that lacked eligibility/anchor facts. No final VAT/core test failures remain.
- The two obsolete ContractCardPresenter test cases were updated before the later ownership transfer: three assertions now use the new finite-term/multiple-base-price copy. Do not repeat that work. No further edits after transfer.

## Remaining parent integration
- Completed the final service integration after the parent extended ownership: `BillComparisonService::canonicalPeriodRequest()` now supplies actual `price_without_tax`, and parses raw `utc_datetime` explicitly in UTC. A service regression under the Helsinki application timezone proves a July 2023 Company bill costs 30 EUR and the Household bill costs 37.20 EUR using the recorded 24% hourly VAT, with no missing-hour fill. No changes to household user-total normalization or annualization profiles.
- `php artisan test --filter='BillComparisonCanonicalPricingTest|CanonicalPeriod|VatIntegrationTest|VatBasisFoundationTest'`: **27 passed, 240 assertions**. Log `/tmp/vat-bill-final.log`. Initial service test needed rolling annual evidence because market rows also require an annual estimate; that fixture is now complete.
- Updated BillComparison context and corrected the single outdated Spot estimator flag in canonical context to `zero_intraday_shape_fallback`. No remaining VAT integration exception.
- Final `git diff --check` and service `php -l` passed. Test-file Pint passed. Combined Pint check reported existing service-wide formatting differences (imports, braces, operator spacing); the service diff contains only the UTC parse replacement and ex-VAT argument, so no unrelated formatting was changed.
- Keep the parent-owned public-payload cache schema bump. The VAT agent did not change cache versions.
- Root/canonical project documentation remains parent-owned. Preserve the current-basis annual Spot assumption and unknown/package target-basis assumption.
- No CSS/JS changes, asset build, commit, or deployment.

## Implementation
- `CanonicalPricingParser` preserves component `vat_status`. Mixed included/excluded component types no longer cause a parser exclusion.
- `CanonicalComponent` has an optional `vatStatus = 'unknown'` constructor field. `withVatBasis(bool $includeVat, float $vatMultiplier)` returns a calculation copy. It converts monetary `amount` and `normalAmount` and records the resulting basis. Unknown status assumes the target basis. Repeated normalization to that basis does not convert twice. Percent and opaque units stay unchanged.
- `CanonicalContractData::withVatBasis()` copies phases and their components. It preserves labels, kind, boundaries, schedules, issue data, and source objects. Packages have no source VAT field; their amounts and allowances stay unchanged under the target-basis assumption. Top-level consumption-effect data stays unchanged. Monetary consumption-effect components can convert, but remain disclosure-only and never become billed.
- Both reset and supplier-adjusted requests accept optional `marketPriceMultiplier = 1.0`. Both estimators multiply reference and forward values before the additive shift and report the reference in that same basis. Beta, curve vintages, full-curve fallback, and dimensionless seasonal ratios stay unchanged.
- No fixed VAT constant was added to runtime code. The caller must use the existing `price_forecasting.fixed_term.vat_multiplier` setting, which the EEX provider already uses.

## Exact follow-up integration
1. In the calculator, obtain the configured VAT factor once through the existing construction/config boundary. Select `$includeVat = $context->targetGroup !== 'Company'`. Household, Both, and null stay included. Compute `$marketPriceMultiplier = $includeVat ? 1.0 : 1.0 / $vatMultiplier`.
2. At `calculate()`, `supplierAdjustedCandidate()`, and `directGeneralRate()`, before phase/rate/eligibility work, use `$data = $data->withVatBasis($includeVat, $vatMultiplier)`. These boundaries can safely normalize the same copy again. Do not replace stored parser/source data.
3. At `resolveResetEstimate()` pass `marketPriceMultiplier` to `ResetEstimateRequest`. Pass the same context factor through `resolveSupplierAdjustedEstimate()` to `SupplierAdjustedEstimateRequest`. Current and anchor contract rates must already be in the selected basis. Do not scale estimator offsets again.
4. Normalize annual Spot assumptions and Spot-estimate monthly price values, shape offsets, and price metadata to the selected basis exactly once. Leave dates, coverage, counts, and dimensionless evidence unchanged. Do not mutate a shared household Spot estimate when calculating Company contracts. Remove the Company-forward restriction only after this full-bill path is consistent. Coordinate the exact helper with the service agent.
5. Review `calculatePeriod()` as a separate factual boundary: components need the same normalization; realized Spot uses the selected basis, with care for historical VAT changes. Do not apply annual market shifts there. Historical persisted statistics must not be rewritten.
6. Replace `CanonicalPricingParserTest::test_conflicting_vat_basis_for_same_component_throws` with preservation/normalization assertions. It is intentionally obsolete under the new policy. It was outside this executor's allowed test-file ownership.
7. Add end-to-end Household/Both/null/Company tests for explicit/unknown/mixed components, promotion normal amounts, Spot, reset, supplier-adjusted, and direct rate/episode matching. A 50 -> 90 EUR/MWh market change must add 4 c/kWh for Company, not 5.02; household stays 5.02. Update root/canonical context and the single shared schema version after integration. The manager owns those files.

## Verification
- `cd laravel && php artisan test --filter=VatBasisFoundationTest`: PASS, 4 tests, 78 assertions. Covers mixed source VAT, normal amounts, immutable phases/packages, idempotence, fee/margin/one-time conversion, non-billed effect semantics, estimator defaults, both market shifts, reference metadata/vintages, and seasonal ratios.
- `php artisan test --filter='VatBasisFoundationTest|MarketResetForwardShiftTest|SupplierAdjusted'`: at that point 36 passed, 4 failed, 287 assertions. The VAT tests passed. Concurrent core timeline/equivalent work causes failures in `fee_only_promotions_keep_the_september_energy_reference_and_monthly...` (line 170), `time_and_season_fee_transitions_use_inherited_energy_buckets` (220), `a_real_energy_promotion_keeps_its_finite_boundary` (271), and supplier `time_and_season_offsets_are_additive_and_exact_rates_stay_unchanged` (234). Recheck these after the core agent finishes; these files were not edited here.
- `php artisan test --filter='VatBasisFoundationTest|CanonicalPricingParserTest'`: at that point 14 passed, 1 failed, 91 assertions. Only the obsolete VAT-conflict exception test failed.
- `git diff --check`: PASS. Reviewed the owned diff and final status. Unrelated concurrent changes remain untouched. No CSS/JS changes; no asset build needed for this unit.

## Semantic limits
Packages and top-level effect data have no VAT source facts. Their target-basis assumption is deliberate, not evidence of the seller's tax status. Always start another audience calculation from source data, especially for packages. The normalization multiplier must be a valid positive configured factor. This patch alone is not an end-to-end VAT fix and must not be released without the calculator/Spot integration.
