# Decisions

## Initial decisions

- Do not merge canonical and legacy calculators in this task.
- Keep feature-off behavior as a compatibility path, but make it internally consistent.
- Use one inclusive end-date rule.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- The focused baseline passes: 60 tests and 284 assertions.
- `ContractPriceCalculator` applies component discounts to annual estimates, but its partial `UntilDate` coverage uses reversed signed durations and treats the disclosed final date as exclusive.
- Feature-off `BillComparisonService` calculates General, Time, Season, Spot, and monthly fees itself and ignores discount arithmetic for exact periods.
- Feature-off annual pricing in bill comparison starts discount timing at today instead of the bill period start.

## Implementation design

- Add one typed exact-period result and a `ContractPriceCalculator::calculatePeriod()` entry point. Keep the canonical calculator separate.
- Keep the request facts explicit: normalized relational components, contract facts, inclusive local start/end dates, period kWh, and realized Spot prices including VAT.
- Share component/rate resolution and discount amount/timing helpers inside `ContractPriceCalculator`; preserve the annual calculator API and its rich usage-profile behavior.
- Use half-open intervals internally. An `UntilDate` value is an inclusive local date and resolves to midnight after that date.
- Exact-period energy usage is flat by local day. Time uses the existing 85/15 split. Season uses actual winter/other dates. Spot uses the realized average plus the selected first non-monthly margin. Ordinary fees use inclusive days divided by 30.
- Measure exact-period promotion state from positive calculated savings. Do not infer it from raw metadata.
- Keep consumption-cap policy, database loading, annualization, canonical branching, unavailable-reason translation, and row construction in `BillComparisonService`.
- Remove raw rate extraction, Spot detection/costing, seasonal costing, and component discount inspection from `BillComparisonService`.
- Pass the bill period start into the legacy annual calculation so annual and period offer timing use the same counterfactual acceptance date.

## Implementation result

- `ContractPriceCalculator::calculatePeriod()` now owns feature-off General, Time, Season, Spot, monthly-fee, and component-discount period pricing.
- `ContractPeriodPricingResult` carries typed availability, actual/base totals, measured savings, Spot facts, and resolved rates.
- Annual and period paths now share component resolution, Spot margin selection, payment-unit compatibility, discount amounts, and inclusive calendar-date `UntilDate` semantics.
- Period pricing preserves valid negative Spot totals and reports a promotion only when measured savings are positive.
- `BillComparisonService` now delegates relational period pricing, maps typed unavailable reasons, and anchors its legacy annual estimate at the bill start.
- The final focused suite passes: 71 tests and 322 assertions. Pint passes for all changed PHP implementation and focused test files.
- The full Laravel suite ran 1,740 tests: 1,738 passed and the same 2 unrelated current-tree tests failed as before this task. `ContractDetailPageTest::test_spot_faq_answers_the_variation_question_only_with_real_history` creates duplicate May rows when month subtraction runs on 31 July. `ContractDetailPresenterTest::test_six_month_detail_copy_uses_the_real_term_benefit_not_the_annualized_benefit` has an exact-float assertion mismatch (`30.00000000000003` versus `30.0`). Neither failure uses the feature-off period calculator.
- Detailed feature-off period rules are documented in `laravel/app/Services/BillComparison/AGENTS.md`, with source-of-truth notes in `laravel/app/Services/AGENTS.md` and `laravel/AGENTS.md`.
