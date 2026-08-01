# Decisions

## Initial decisions

- Do not replace canonical calculation DTOs.
- The new type is a read model for consumers, not a new pricing engine.
- Keep arrays at serialization boundaries only.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- Canonical and legacy calculations are typed until their DTOs are serialized. Type loss starts at the shared contract metric/cache arrays.
- `ContractListCacheService` stores broad nested arrays. Listing, ranking, company, detail, card, weekly-offer, and API consumers then read required pricing facts through silent null, zero, or infinity fallbacks.
- A missing ranking `total_cost` can currently become zero in cheaper-contract output. Package, Hybrid, reset, short-term, and excluded states are represented by loosely related array keys.
- The focused presentation/cache/API baseline ran 203 tests: 202 passed. The only failure is the existing strict-float detail assertion.

## Staged design

- Add a consumer read model under `App\Services\ContractPricing`; do not replace canonical or legacy calculation DTOs.
- Start at the `ContractListCacheService` read boundary. Keep arrays in the stored cache, then hydrate once into a typed `ContractMetricSet` before returning data to its direct consumers.
- Model pricing facts separately from list metadata: `ContractPricingViewData`, `ContractMetric`, and `ContractMetricSet` with strict `fromArray()` validation and stable `toArray()` serialization.
- Require finite totals and sort keys for listed outcomes. Reject public rates on excluded outcomes and malformed term, package, Hybrid, estimate, reset, or phase facts.
- Update the four direct cache consumers together. They can serialize intentionally at existing presentation boundaries so cache and HTTP shapes remain unchanged in the first stage.
- Do not bump cache schemas when `toArray()` remains byte/key compatible. If a calculated-cost shape changes, bump only `CalculatedCostPayloadSchema` so dependent cache markers invalidate together.
- Shared contract fixtures now exist and should be used for exact, excluded, package, Hybrid, reset, short-term, canonical-only, and legacy characterization tests.

## First implemented boundary

- Added `App\Services\ContractPricing` as a consumer read-model directory. `ContractPricingViewData`, `ContractMetric`, and `ContractMetricSet` hydrate the existing arrays. One small `PricingFact` value object wraps validated optional records while preserving harmless auxiliary keys.
- `ContractListCacheService` still writes the same array payload to Laravel cache. `getCachedMetrics()` now hydrates the retrieved array once and memoizes one `ContractMetricSet` per consumption. Cache warming clears each hydrated object after that preset. No cache schema marker changed because `toArray()` keeps the existing payload exactly, including the historical excluded-row top-level maximum-float sentinel; typed consumers use the nullable pricing total instead.
- Strict hydration requires all current legacy wrapper keys and all canonical additions. It validates finite numeric facts, listability and sort-key invariants, canonical excluded rate/package/offer absence, package, term, Hybrid, estimate, reset, phase, and offer-term records. Legacy payloads remain valid without `pricing_basis`.
- Updated the direct consumers only: listing pipeline, ranking service, company list cache, and the three ContractDetail reads. Existing Eloquent presentation attributes receive `pricing()->toArray()` intentionally. The unsafe cheaper-contract `?? 0` path is removed. An excluded viewed contract returns no ranking summary; malformed listed pricing fails hydration.
- Kept `CanonicalContractPricingService::metricsForContracts()`, ContractCardPresenter internals, WeeklyOffersVideoService, and API controllers unchanged for the later slice.
- Added direct round-trip and malformed-data tests plus a ranking boundary test that proves a cached missing total throws before it can become a zero-euro recommendation.

## Verification for this boundary

- Typed read-model, ranking guard, and request-memoization tests: 26 passed, 47 assertions.
- Focused listing, canonical cache, company, and reset tests: 147 passed, 388 assertions.
- Focused detail tests: 104 passed and the two documented unrelated failures remained: duplicate monthly Spot fixture setup and strict `30.00000000000003 === 30.0` equality.
- Targeted Pint check passed for all 12 changed PHP paths. The repository-wide Pint check still reports 110 style issues in unrelated existing files; these were not changed.
- `git diff --check` passed.

## Final producer and presentation boundary

- Added `CanonicalContractMetric`. `CanonicalContractPricingService::metricsForContracts()` now returns this typed metric map. It owns `ContractPricingViewData`, typed `ContractPricingIntegrity`, comparability, listability, and the nullable finite sort key. Its exact `toArray()` exists only for stable transport compatibility.
- Added explicit canonical-outcome and legacy-result adapters on `ContractPricingViewData`. Canonical and legacy calculator DTOs remain unchanged.
- Updated every batch caller together. List cache arrays remain the stable Laravel cache payload, while cold listings, ranking, SEO offers, weekly offers, and contract API preparation use typed metric access without canonical array-key fallback.
- Card presentation strictly hydrates one calculated pricing read model and typed integrity. Receipt, footer, copy, package, phase, Hybrid, reset, term, discount, estimate, rate, and total decisions no longer inspect a broad calculated-cost array. Existing Blade signatures and `ContractCardView` output did not change.
- `CanonicalOfferFacts` now consumes `ContractPricingViewData`; `fromArray()` is only the strict fixture/transport compatibility factory. Company and SEO offer decisions call the typed method.
- CompanyDetail, ContractDetail fallbacks, CalculationController, and feature-off service paths adapt calculator DTOs before decisions and serialize only at existing Eloquent or HTTP boundaries. Company sorting and statistics use finite typed totals; excluded totals stay missing.
- Weekly and API output preparation use typed metrics and validated pricing facts, then serialize the unchanged response keys. Feature-off branches and all cache/API payload versions remain unchanged.

## ContractDetail acceptance cleanup

- `ContractDetail::pricingViewDataFor()` is the one request-local typed accessor per consumption. It returns `ContractMetricSet` pricing directly on a supported cache hit, uses `fromCanonicalOutcome()` or `fromLegacyResult()` only at calculator fallbacks, and memoizes the `ContractPricingViewData` object.
- Detail-page qualifier, receipt-note, term, FAQ, current-display, package, cost-table, and counterfactual decisions now use typed accessors and `PricingFact`. Existing calculated-cost arrays remain only as compatibility transport for the public property/helper, card, SEO presenter input, price development, and prepared page payload. Cache payload v18 and public copy did not change.
- CompanyDetail's feature-off promotion branch now reads measured savings from its existing typed pricing map instead of the attached Eloquent transport array.

## Final verification

- `ContractDetailPresenterTest`: 15 passed, 1 known strict-float failure; 70 assertions.
- `ContractDetailPageTest`: 89 passed, 1 known duplicate monthly Spot fixture failure; 283 assertions.
- `CompanyDetailPageTest`: 36 passed, 109 assertions.
- `ContractCardPresenterTest`: 57 passed, 169 assertions.
- Typed read-model, typed ranking, and request memoization tests: 28 passed, 52 assertions.
- Targeted Pint passed for the touched PHP files. Final acceptance grep and `git diff --check` are recorded in the executor report.

## Independent review fixes

- A separate read-only review found three valid calculator outputs that the first strict validator rejected. The validator now accepts composed reset + BaseOnlyHybrid outcomes, a supported reset coefficient of zero, and schema-valid empty phase labels.
- BaseOnlyHybrid still requires a Hybrid or recurring-reset estimate method. Any supplied consumption-effect record must state that the effect is present.
- Standalone weekly-offer tests now clear scoped pricing services after changing pricing configuration, matching the immutable `PricingMode` test boundary used elsewhere.
- Typed read-model, canonical-offer, and reset regression: 44 tests and 141 assertions pass. Weekly canonical/legacy output: 4 tests and 69 assertions pass.
- All 423 active contracts in the local production snapshot successfully produce and hydrate typed canonical metrics at 5,000 kWh with reset shifting both enabled and disabled, including the live reset + Hybrid composed outcome.
- No calculated-cost, cache wrapper, prepared-page, or HTTP response shape changed, so `CalculatedCostPayloadSchema` and outer cache versions remain unchanged.
- Final Laravel regression: 1,842 tests pass with 6,506 assertions. The only two failures are the previously documented duplicate monthly Spot fixture and strict `30.00000000000003 === 30.0` assertion; neither exercises the typed read-model boundary.
