# Calculator caller review repair

## Result

Done locally. No network, production action, commit, push, or full test suite ran.

## Changes and decisions

- `ConsumptionCalculator::$calculationResult` has `#[Locked]`. The server can still calculate and replace the array. Livewire rejects whole-array and nested client updates before price calculation or cache writes.
- Compare validates the raw computed total as a required integer from 1 through `ContractListCacheService::MAX_COMPARISON_CONSUMPTION` before it grants permission, prepares a cache, sends analytics, or redirects. The shared limit is 150,000 kWh. No separate caller limit was added. Exact supported totals stay exact; there is no rounding or clamping.
- The Blade view shows `comparisonConsumption` errors with `role="alert"` immediately before Compare. Finnish copy states the supported range and asks the visitor to check the calculator inputs.
- Compare catches only `ContractPriceCacheUnavailable` from exact preparation. This expected capacity/no-availability outcome shows a Finnish try-later notice and sends no analytics or redirect. Unexpected errors still escape. Financial calculations, source guards, historical evidence, and shared services are unchanged.
- Existing real server calculation tests still produce exactly 7,312 kWh in both pricing modes. Preparation and two destination GET reads retain the exact price. GET has no annual calculation or donor/forward/source-observation work.
- New tests cover whole-array and nested result attacks, missing/null/zero/negative/fractional/non-numeric/over-limit server state, the inclusive upper bound, accessible range and unavailable notices, and an unexpected storage error. The attack test forbids cache writes and annual preparation. Existing consumption and blur-policy tests need no fixture change.

## Verification

Final commands:

- `cd laravel && php artisan test tests/Feature/CalculatorExplicitPriceActionTest.php tests/Feature/ConsumptionCalculatorTest.php tests/Unit/FormInputBlurPolicyTest.php`: 45 passed, 286 assertions.
- `cd laravel && vendor/bin/pint --test app/Livewire/ConsumptionCalculator.php tests/Feature/CalculatorExplicitPriceActionTest.php`: passed.
- `git diff --check`: passed.

An initial gate had 44 passes and one test-fixture failure: Mockery tried to create a final `ContractMetricSet` return value for the upper-bound spy. The test now returns a real empty typed set. An initial Pint check required import fixes; focused Pint applied them. Both final gates pass.

## Integration notes

The shared maximum constant was present before verification. Capacity policy implementation belongs to the service owner; this caller test supplies the expected typed unavailable outcome, not a second capacity policy. No shared contexts were edited, as instructed. The manager must add the locked-result, shared bound, and expected unavailable handling decisions to the nearest Livewire context. Existing unrelated dirty work was kept.
