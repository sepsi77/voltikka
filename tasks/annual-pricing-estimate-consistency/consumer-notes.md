# Consumption API and daily cache work

## Result

Implementation is complete. Focused consumer tests pass. The wider current-statistics test run has two failures under the concurrent core pricing changes; see Verification. No commit, deployment, production call, schema bump, shared root context edit, or tasks.json edit was made.

## Changes and policy

- `laravel/app/Http/Controllers/Api/CalculationController.php`: detailed usage always requires `total`, even with a separate `consumption` and empty usage array. Component sums below the total add their remainder to `basic_living`. Sums above the total return 422 with `The consumption breakdown must not exceed energy_usage.total.` Only validated snake-case fields reach the alias-aware DTO factory.
- Monthly heating is an optional array of exactly calendar keys 0..11. It requires explicit `room_heating`. All values must be finite, numeric, and non-negative. Treat them as weights and normalize to room-heating consumption. Positive room heating needs a finite positive weight sum. Zero room heating normalizes to zero. Without the array, normal calculator heating distribution applies. Partial component breakdowns remain valid.
- `laravel/app/Services/DTO/EnergyUsage.php`: `basicLiving` accepts `int|float`. This is needed to preserve the remainder from fractional cooling. No global DTO normalization was added.
- `ContractListCacheService.php` and `CompanyListCacheService.php`: append the explicit Helsinki calculation date to persistent keys. Instance memo keys use the full persistent key, including data version and date. List warming still clears retained metric objects. Existing bounded 48-hour TTL remains.
- `ContractRankingService.php`: append the Helsinki date to the one-hour ranking cache. Clear rank, eligible-list, and bucket-summary memos when the Helsinki date or list data version changes.
- Import/futures versions, pricing flag markers, and payload schema markers remain intact. Parent must bump the shared calculated-cost schema once at integration. Existing key tests now use the schema constant rather than a hard-coded number.
- `laravel/app/Http/AGENTS.md`: documents API input policy and daily annual-cache behavior. `CLAUDE.md` is already its symlink. Parent should also reconcile the shared Services/Caching/root context with the date-key policy; those shared files were left unchanged.

## Tests

- New `tests/Feature/AnnualConsumerConsistencyTest.php` verifies both pricing modes: fixed 12 months, 10 c/kWh, 5 EUR/month, 5000 kWh gives 560 EUR for total-only, partial basic living, exact breakdowns, fractional cooling, normalized heating weights, and zero heating. Exact monthly kWh and equivalent weights give identical complete responses.
- It rejects missing totals, component sums above the total, wrong calendar keys, negative/non-numeric/infinite monthly values, zero weights with positive heating, and a monthly array without room heating.
- A dated promotion expires at Helsinki midnight while UTC is still on the previous date. The same service instances refresh list totals, company totals, default ranks, consumption-specific ranks, and bucket summaries. A custom 5001-kWh API calculation and current daily statistics also update. Unsupported custom usage still bypasses the preset list cache.
- Existing key assertions updated in `tests/Unit/ContractRequestMemoizationTest.php`, `tests/Unit/ContractRankingTypedMetricsTest.php`, and `tests/Feature/CompanyListPageTest.php`.

## Verification

- `cd laravel && vendor/bin/pint app/Http/Controllers/Api/CalculationController.php app/Services/DTO/EnergyUsage.php app/Services/ContractListCacheService.php app/Services/CompanyListCacheService.php app/Services/ContractRankingService.php tests/Feature/AnnualConsumerConsistencyTest.php tests/Feature/CompanyListPageTest.php tests/Unit/ContractRequestMemoizationTest.php tests/Unit/ContractRankingTypedMetricsTest.php`: completed; formatting applied. The final new-test formatting run passed.
- Final focused command: `cd laravel && php artisan test --filter='AnnualConsumerConsistencyTest|CalculationApiTest|ContractRequestMemoizationTest|ContractRankingTypedMetricsTest|CompanyListPageTest'`: **54 passed, 237 assertions**.
- Wider command with `|ContractPriceStatisticsCanonicalSourceTest` added to that filter: **71 passed, 2 failed, 298 assertions** at that run. Both failures are outside owned files:
  - `test_a_contract_canonical_pricing_refuses_to_total_is_still_skipped`, line 99: expected 0 snapshots, got 1. Fixture has an undisclosed continuation, now estimated by the core work.
  - `test_a_missing_canonical_unit_rate_stays_null_even_when_a_relational_rate_exists`, line 148: no snapshot exists for a fee-only canonical fixture; test expected a 48-EUR annual total.
- Earlier new-test failures were test assertions only (dotted validation-error key, missing false canonical classifier fields, and wrong snapshot date column); corrected and covered by the final passing focused run.
- `git diff --check`: passed. Final owned diff and working-tree status reviewed. Concurrent agent changes were not reverted.
- No CSS/JS changes. No asset build needed for this unit. Full-suite and shared semantic test reconciliation remain with the parent.
