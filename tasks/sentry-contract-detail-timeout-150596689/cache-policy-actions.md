# Explicit calculator actions

## Changes

- `ConsumptionCalculator::compareContracts()` validates the computed positive integer total, grants request-local explicit-action permission, and prepares that exact consumption with `ContractListCacheService::prepareComparisonForConsumption()` before the existing redirect. The analytics event and URL stay unchanged. Mount, statistics reads, consumption mathematics, heating rules, and input bindings stay unchanged.
- Standalone `BillComparison` keeps the sample inputs and default dates, but mount no longer compares them. `calculate()` grants explicit-action permission, including calls from the existing input hooks. Render rebuilds protected results only when the current request has calculation permission. GET, HEAD, lazy initialization, and unrelated Livewire refreshes do not compare a sample bill.
- The existing bill view has no empty-result prompt. A small Finnish prompt now asks the visitor to edit the bill inputs. Results, calculation status, and errors remain protected and outside the snapshot. No public bill-result cache is added.
- New `CalculatorExplicitPriceActionTest` covers a real computed non-preset total of 7,312 kWh (20 m², two people, 1,238 EV km/month). It keeps the existing doubled-EV consumption rule unchanged. Preparation stores the exact 438.72 euro annual price at 6 c/kWh. Two destination GETs reuse the same payload in both legacy and canonical pricing modes, with annual calculation forbidden and no donor/forward/source-observation queries. Calculator GET and bare custom listing GET do not prepare prices. The missing custom listing profile returns 503. Bill GET/HEAD/refresh do not compare. Explicit bill calculation and an input update keep exact period costs, VAT normalization, and the protected snapshot.

## Verification

All commands ran locally. No network request, production action, commit, or push ran.

- `cd laravel && php artisan test tests/Feature/CalculatorExplicitPriceActionTest.php`: 5 passed, 116 assertions.
- `cd laravel && php artisan test tests/Feature/CalculatorExplicitPriceActionTest.php tests/Feature/ConsumptionCalculatorTest.php tests/Feature/BillComparisonTest.php tests/Feature/SharedPriceCacheReadBoundaryTest.php`: 62 passed, 413 assertions.
- `cd laravel && vendor/bin/pint --test app/Livewire/ConsumptionCalculator.php app/Livewire/BillComparison.php tests/Feature/CalculatorExplicitPriceActionTest.php`: passed.
- `git diff --check`: passed.
- `cd laravel && php artisan test tests/Feature/BillComparisonCanonicalPricingTest.php`: 6 passed, 2 failed, 29 assertions. Failures are `all_three_surfaces_use_the_same_corrected_canonical_period_cost` and `partial_spot_history_remains_available_in_the_service_and_contract_detail_ui`. Both hit a cold annual-cache read from the contract-detail view and raise `ContractPriceCacheUnavailable` at `ContractListCacheService.php:152`. These other surfaces and fixture setup are outside this unit's allowed files.
- An earlier broad filter (`ConsumptionCalculatorTest|BillComparisonTest`) also selected `ContractDetailBillComparisonTest`: 47 passed, 13 failed, 186 assertions. All 13 failures are cold-cache contract-detail view reads, not standalone calculator failures. A filter with extra regex anchors selected no tests; subsequent verification used exact file paths.

## Integration notes

This unit uses the new shared cache-preparation API and the scoped public-calculation policy supplied by the other units. Root, Laravel, Livewire, and cache contexts are owned by the manager and were not edited here. The manager must add the implementation decisions above to the nearest Livewire context and update task completion state after integration. Existing unrelated dirty work was kept.
