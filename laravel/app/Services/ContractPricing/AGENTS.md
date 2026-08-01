# Contract pricing consumer read model

This directory owns the typed calculated-pricing boundary from canonical or legacy calculation through presentation preparation.

## Purpose

- `ContractPricingViewData` validates one existing `calculated_cost` array and gives typed access to totals, monthly values, rates, Spot state, discount state, pricing basis, comparability, and estimate facts.
- `PricingFact` wraps validated optional package, contract-term, consumption-effect, reset, phase, and offer-term records. It keeps unknown harmless auxiliary keys.
- `CanonicalContractMetric` combines one canonical pricing view with typed `ContractPricingIntegrity`, comparability, listability, and the finite nullable sort key returned by the canonical batch producer.
- `ContractMetric` combines one cached contract ID with pricing, emissions, consumption-limit state, comparability, listability, sort key, and typed integrity.
- `ContractMetricSet` owns the cached contract map, sorted IDs, excluded IDs, and consumption.

This is a consumer read model. It is not a pricing calculator and must not duplicate canonical or legacy pricing rules.

## Boundary rules

- `CanonicalContractPricingService::metricsForContracts()` returns `array<string, CanonicalContractMetric>`. Every caller uses typed access. `CanonicalContractMetric::toArray()` exists only for stable transport compatibility.
- `ContractPricingViewData::fromCanonicalOutcome()` and `fromLegacyResult()` are the explicit calculator adapters. Consumers do not manually inspect calculator serialization.
- `ContractListCacheService` stores arrays in Laravel cache and hydrates them once after each cache read. Its public `getCachedMetrics()` method returns `ContractMetricSet|null`.
- `ContractDetail::pricingViewDataFor()` memoizes one typed object per requested consumption. A supported cache hit returns `ContractMetricSet::metric(...)->pricing()` directly; canonical and legacy fallbacks use the explicit outcome/result adapters. Generated detail-page pricing policy must not read the compatibility array.
- `toArray()` must reproduce the stored payload exactly. Do not rename, add, remove, normalize, or cast stored values here.
- Serialize `pricing()->toArray()` only where an existing Eloquent presentation attribute or public payload still requires the old calculated-cost array shape.
- Missing required keys and malformed required facts throw `InvalidArgumentException`. Do not add zero, null, maximum-float, or infinity repair values for listed totals or sort keys.
- Listed metrics require a finite non-null pricing total and sort key. Excluded metrics require a null sort key.
- Canonical excluded pricing has no public current rates, package, or offer terms. Legitimate non-public metadata can remain.
- `base_only_hybrid` can compose with a recurring-reset estimate method. Do not require `hybrid_base_only` when the calculator reports hold-current, forward-shift, or seasonal-index reset pricing. A consumption-effect record can be absent for source-enum Hybrid fallback; when supplied, it must state `present=true`.
- Reset `beta=0` and empty phase labels are valid calculator output. The coefficient is non-negative, and labels are not costing facts.
- Legacy payloads are explicit: `pricing_basis` can be absent. Do not infer canonical facts from legacy payloads or merge the calculators.
- Keep unknown harmless keys in optional records so old cache rows round-trip without data loss.

## Cache compatibility

This boundary does not change the calculated-cost fields or the outer cache payload. It therefore does not change `CalculatedCostPayloadSchema::VERSION` or any outer cache version. A future field-shape change must follow the existing schema-version rules in `../CanonicalPricing/AGENTS.md`.

## Presentation consumers

The listing and ranking services, cards, ContractDetail, CompanyDetail, SEO offers, weekly offers, and contract/calculation API preparation use this typed boundary. ContractDetail keeps its calculated-cost array only for card, SEO presenter, price-development, prepared-page, and public compatibility transport. CompanyDetail uses its typed map for both canonical and feature-off promotion savings. Arrays remain only in Laravel cache payloads, existing Eloquent presentation attributes, and HTTP response payloads. `CanonicalOfferFacts` accepts `ContractPricingViewData`; its `fromArray()` factory is only for strict fixture or transport hydration.
