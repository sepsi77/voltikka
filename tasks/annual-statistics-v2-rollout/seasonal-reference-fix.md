# Seasonal reference correction before annual-statistics v2 rollout

## Scope and boundary

This is a local code correction after deployed release `b47eea5` (calculated-cost schema v16).
No production calls, actual-data writes, commit, push, deployment, historical apply, or public
activation were made by this executor. Tests use the forced in-memory SQLite test database.
The parent owns `decisions.md`, `tasks.json`, the full-preview summary, release verification,
and approval requests. Earlier full-preview logs were not changed.

The parent reports that the read-only preview completed all 232 dates and that production still
had zero v2 rows at 2026-09-11 22:49 Helsinki, with public annual method v1. These are supplied
rollout facts, not a new production check by this executor.

The parent also identified June 1 quarterly median changes: at 18,000 kWh, €1,538.04 to
€3,572.5754 with the same 13 members; at 5,000 kWh, €469.70 to €1,034.8487. The candidate results
used the seasonal basis. These figures identify affected output. They do not measure this bug's
contribution, and this correction does not claim to remove the complete increase.

## Rule and implementation

`ResetEstimateRequest::anchorPeriodMonth` is a month inside the last known reset period. It is
not proof that a published quarter price belongs to only that month. The calculator can select
June for a known Q2 price.

`MarketResetPriceEstimator::seasonalIndexShift()` now uses a small private helper:

- `monthly`: retain the exact anchor-month seasonal index.
- `quarterly`, `seasonal`, and `other`: use the calendar-day-weighted mean of the three indices
  in the containing quarter. Day counts come from the anchor year, including leap February.
- A missing, non-finite, or nonpositive required reference or tail index rejects the seasonal
  attempt and reaches the existing hold-flat fallback.

The existing multiplicative offset remains `beta * (anchor * monthIndex / referenceIndex - anchor)`.
The global beta, dated input lookup, exact known period, forward-curve priority, two vintage rules,
future reference bound, negative floor, and absolute plausibility guards do not change.
Supplier-adjusted monthly references do not change. There are no new DTO fields, dependencies,
settings, or fixed-term-market caps. Earlier market-reset economic conclusions and their
retractions were read; this is not a beta calibration or a new economic prior.

`CalculatedCostPayloadSchema::VERSION` changes from 16 to 17. Existing schema-dependent tests use
the shared constant or marker, so no current hard-coded v16 assertion required a change. Historical
v16 documentation remains intact. New rule/reason notes are in the MarketReset and CanonicalPricing
`AGENTS.md` files; their `CLAUDE.md` symlinks remain unchanged.

## Synthetic proof and regression coverage

For Q2 indices April .9, May .6, June .3:

`(30 * .9 + 31 * .6 + 30 * .3) / 91 = .6`.

At a published 10 c/kWh and July index .6, July stays 10 c/kWh instead of 20. The current June
price stays exact, even though June's own index is .3.

`tests/Unit/CanonicalPricing/MarketResetForwardShiftTest.php` adds six tests:

1. All three nonmonthly cadences produce the same quarter reference for April, May, and June anchors.
2. Unequal Q1 indices use the anchor year's February day count in 2024 and 2025, independently of
   the fixed request year; this also checks a nonzero offset.
3. Each required Q2 reference month and July tail month rejects missing, NaN, infinity, zero,
   and negative indices for all three nonmonthly cadences.
4. Monthly June .3 to July .6 keeps the old formula and global beta scaling without requiring
   April or May indices.
5. A usable quarter forward reference keeps priority and its original two vintages for all
   three nonmonthly cadences.
6. The shared calculator starts on June 1, keeps June exact, prices July at 10 c/kWh, and
   reconciles the annual total for all three nonmonthly cadences. Next April costs 15 c/kWh;
   the annual total at 5,000 kWh is `5000 / 12 * (11 * 10 + 15) / 100`.

`tests/Feature/AsOfAnnualCostCalculatorTest.php` adds the same quarter-reference annual proof
through strict dated canonical evidence for AsOfV2, all three cadences, and all three consumption
levels (nine available canonical results). No statistics writer is called by the new test.

## Verification

Commands run from `laravel/`:

- `php artisan test --filter=MarketResetForwardShiftTest`: **36 passed, 668 assertions**.
- `php artisan test --filter='AsOfAnnualCostCalculatorTest|MarketResetEstimateSurfacesTest|EexMarketReferenceCurveProviderTest|SupplierAdjustedPriceEstimatorTest'`:
  **52 passed, 391 assertions**. The supplier filter name had no matching test class. The final
  command below uses the correct `SupplierAdjustedPricingTest` name.
- `php artisan test --filter='MarketResetForwardShiftTest|AsOfAnnualCostCalculatorTest|MarketResetEstimateSurfacesTest|EexMarketReferenceCurveProviderTest|SupplierAdjustedPricingTest|HistoricalMarketInputBoundaryTest|ContractRequestMemoizationTest|ContractRankingTypedMetricsTest'`:
  **111 passed, 1,213 assertions, 1.19 seconds**. This includes dated seasonal availability,
  future vintage bounds, unchanged supplier behavior, and schema-dependent cache keys.
- `vendor/bin/pint --test app/Services/CalculatedCostPayloadSchema.php app/Services/CanonicalPricing/MarketReset/MarketResetPriceEstimator.php tests/Unit/CanonicalPricing/MarketResetForwardShiftTest.php tests/Feature/AsOfAnnualCostCalculatorTest.php`:
  **failed** on the estimator's formatting (`new_with_parentheses`, `unary_operator_spaces`,
  `not_operator_with_successor_space`, `single_line_empty_body`). The other three files passed.
  Running Pint on an unchanged HEAD copy at `/tmp/MarketResetPriceEstimator-before-seasonal-fix.php`
  reported the same four formatter rules. Existing surrounding style was retained; no unrelated
  formatting repair was made.
- `git diff --check`: **passed**. Final diff and status reviewed. Existing untracked rollout
  folder remains; only this note was added there by the executor.

## Parent's local read-only preview

The parent supplied these quarterly 5,000-kWh median results from
`/tmp/annual-v2-quarter-local-preview.log`:

| Date | Stored v1, EUR | Corrected local v2, EUR |
|---|---:|---:|
| 2026-01-21 | 704.34 | 594.5981792 |
| 2026-04-08 | 476.30 | 719.7948 |
| 2026-06-01 | 469.70 | 724.7427003 |

The earlier production preview's June 1 candidate was EUR 1034.8487358. These local results
are not a substitute for a new complete production preview and do not establish the complete
production effect of this correction. The parent has started the full release test/build job;
its result is not yet recorded here.

The current shared-schema references in `laravel/app/Services/AGENTS.md` and
`laravel/app/Services/ContractStatistics/AGENTS.md` now say v17. The no-historical-rewrite rules
and historical v15/v16 release notes remain unchanged. Both CLAUDE symlinks remain intact.

## Release work still required

The parent must run the full release test suite and asset build, then request new deployment
approval. After an approved deployment, run a new complete read-only historical preview before
write or activation approval. Do not limit the new preview to old seasonal flags: old hold-flat
plausibility fallbacks can hide affected seasonal attempts. Stored v1 evidence, historical apply,
current v2 coverage, and the public method switch keep their separate rollout approval boundaries.

## Out-of-scope finding

`MarketReset/Enums/ResetEstimateBasis.php` still has an old comment that says the forward formula
uses one vintage. Actual code and canonical/MarketReset context correctly specify two vintages.
That unrelated comment was not changed.
