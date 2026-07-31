# Decisions

## Initial decisions

- Use a request-scoped immutable value, not repeated config reads.
- Keep feature flags available, but resolve them once at the boundary.
- Do not combine unrelated cache wrapper versions.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- Baseline focused suite: 144 tests passed with 665 assertions.
- `CanonicalContractPricingService` and cache builders read the two flags separately.
- Plain `new CanonicalContractPriceCalculator()` has no reset estimator and silently holds reset prices flat, while container construction injects the configured estimator.
- Expected statistics basis is reconstructed from config in several consumers.
- Calculated-cost schema version 11 is duplicated by list and prepared-page caches; company and ranking wrappers do not name that shared payload dependency.

## Implementation design

- Add one request-scoped immutable `PricingMode` snapshot with canonical state, reset-shift state, expected statistics basis, and one cache marker.
- Require `MarketResetPriceEstimator` in `CanonicalContractPriceCalculator`; hold-flat tests and explicit comparisons must pass an estimator with disabled settings instead of relying on null.
- Require the calculator and `PricingMode` in `CanonicalContractPricingService` so direct and container construction cannot diverge silently.
- Add one shared calculated-cost schema constant. Keep company, ranking, and prepared-view wrapper versions separate, but include the shared version in every dependent cache key.
- Replace separate canonical-state and basis reads in pricing/statistics consumers with the same request-scoped mode.

## Implemented

- Added immutable request-scoped `CanonicalPricing\PricingMode` for both flags, expected statistics basis, and one `c{0,1}r{0,1}` marker.
- Added `CalculatedCostPayloadSchema::VERSION` as the single calculated-cost schema version. List, company, ranking, and prepared-page caches now name this dependency while retaining their own outer versions.
- Made `MarketResetPriceEstimator` mandatory in `CanonicalContractPriceCalculator`. Unit hold-flat calculations pass disabled settings and an explicit no-data provider.
- Made calculator and mode mandatory in `CanonicalContractPricingService`. Its constructor rejects any reset-state mismatch between the mode and estimator.
- Scoped the immutable mode, settings, estimator, and calculator in `AppServiceProvider`. Kept `CanonicalContractPricingService` transient because `withSpotAssumptions()` stores caller-specific state.
- Removed direct canonical/reset config reads and `ContractPriceBasis::expectedCurrent()` use from application consumers. Framework-created Livewire/resources resolve the scoped mode at their boundary; normal services receive constructor injection.
- Made the fixed-price forecast public scope accept an explicit expected basis.
- Updated flag-flip tests to start a new scoped boundary. An already-resolved mode remains unchanged after config mutation by design.

## Validation

- Baseline before change: 144 tests passed with 665 assertions.
- Final focused pricing-mode suite: 147 tests passed with 686 assertions before the final mismatch guard; the final reset/basis checks passed 36 tests with 192 assertions, including isolated and randomized-order checks.
- Broad pricing consumers passed after scoped-boundary and constructor updates. The affected run reached 191 tests; its two failures were fixed and rechecked as 19 tests with 108 assertions.
- Final full Laravel suite: 1667 tests passed and 2 unrelated tests failed with 5926 assertions. `ContractDetailPresenterTest` has the known strict floating-point identity failure (`30.00000000000003` versus `30.0`) in isolation. `ContractDetailPageTest` creates the same monthly Spot average twice and fails its unique key in isolation. No pricing-mode test failed.
- Pint passed for all 44 touched PHP files. `git diff --check` passed.
- Independent review found no blocking issue after the scoped test boundary and direct mode/estimator mismatch guard were added.
