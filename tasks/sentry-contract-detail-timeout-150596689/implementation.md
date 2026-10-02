# Bounded implementation and verification

## Change

- `Support/PhaseTimelineBuilder.php`: compute the 13 exact start-relative no-overflow anniversaries once per build. Reuse them for annual end, anniversary boundaries and normalization month selection. Compute each of the 12 billing-month `diffInDays` values once. Keep phase resolution and the calendar cursor unchanged.
- `DTO/WindowSegment.php`: compute one signed duration per construction and retain private immutable calendar and billing fractions. Methods reuse those values. Preserve constructor signature/defaults, calendar clamping, signed/unclamped billing fractions, null fallback and annual scaling.
- `tests/Unit/CanonicalPricing/TimelineFractionReuseTest.php`: exact reference arithmetic across January 31, leap-day and Helsinki DST windows; dated and relative phase splits; equal-start ties; empty/uncovered coverage; signed, zero and fractional-day durations; null and negative billing denominators; split conservation; deterministic date-call counts.
- Nearest Support and DTO contexts document the reuse boundaries. Existing CLAUDE.md files are symlinks to those contexts.

## Commands and results

All commands ran in local `laravel/`. No production or network database use.

- `php artisan test --filter=TimelineFractionReuseTest`: initial 4 tests / 1,067 assertions passed before the builder-count and dated-split additions.
- `php artisan test --filter='TimelineFractionReuseTest|CanonicalContractPriceCalculatorTest|CanonicalPeriodPricingTest|BillComparisonCanonicalPricingTest|BillComparisonTest|ContractDetailBillComparisonTest|SahkosopimusBillModeTest'`: 130 tests / 1,889 assertions passed before the dated-split addition.
- `php artisan test --filter='Tests\\Unit\\CanonicalPricing\\'`: 273 tests / 6,263 assertions passed before the dated-split addition.
- Final `php artisan test --filter='Tests\\Unit\\CanonicalPricing\\|BillComparisonCanonicalPricingTest|BillComparisonTest|ContractDetailBillComparisonTest|SahkosopimusBillModeTest'`: 316 tests / 6,574 assertions passed in 4.05 s. Log: `/tmp/voltikka-sentry-150596689-pricing-tests.log`.
- `vendor/bin/pint --test app/Services/CanonicalPricing/Support/PhaseTimelineBuilder.php app/Services/CanonicalPricing/DTO/WindowSegment.php tests/Unit/CanonicalPricing/TimelineFractionReuseTest.php`: passed.
- Final `vendor/bin/pint app/Services/CanonicalPricing/Support/PhaseTimelineBuilder.php app/Services/CanonicalPricing/DTO/WindowSegment.php tests/Unit/CanonicalPricing/TimelineFractionReuseTest.php`: passed.

The manager separately reports the cache safety gate: 27 tests / 147 assertions passed. Log: `/tmp/voltikka-sentry-150596689-cache-tests.log`.

## Limits

No timing threshold test is added. Call-count tests prove removal of repeated date work, not production latency. The manager completed full-market exact metric equality and three paired cold timing runs at both consumptions; see verification.md. Local median wall time falls 38–48%, with identical metric outputs. The production 30-second timeout and cache-loss writer are not reproduced or identified by this implementation. No pricing schema, method, estimator, lineage, invalidation or shared cache changes are made. No deployment, commit or push occurs. Unrelated dirty context and annual-history task files are not edited.
