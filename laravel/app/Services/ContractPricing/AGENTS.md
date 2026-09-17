# Contract pricing consumer read model

This directory owns the typed calculated-pricing boundary from canonical or legacy calculation through presentation preparation.

## Purpose

- `ContractPricingViewData` validates one existing `calculated_cost` array and gives typed access to totals, monthly values, rates, Spot state, discount state, pricing basis, comparability, and estimate facts, including separate market-reset, supplier-adjusted, and forward-Spot payloads.
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
- `base_only_hybrid` can compose with supplier-adjusted or recurring-reset estimate methods,
  including each existing forward-premium method. The method identifies the financial estimator;
  base-only comparability and effect exclusion remain separate. No payload fields or schema
  version changed for this bounded current Hybrid extension. Do not require `hybrid_base_only` when the calculator reports hold-current, forward-shift, or seasonal-index reset pricing. A consumption-effect record can be absent for source-enum Hybrid fallback; when supplied, it must state `present=true`.
- Sequential base-only Hybrid timelines also accept `forward_curve_spot` and `rolling_365_spot`, but only with the matching validated Spot estimate basis. Resolved inclusive phase windows must not overlap. Both fixed-base and Spot usage must exist in distinct phases; a Spot phase cannot also expose fixed energy, and a fixed phase cannot expose a Spot margin. Packages are not admitted by this bounded extension. Existing estimate and consumption-effect requirements remain. This does not admit ambiguous same-phase Hybrid energy plus Spot margin. The reader validates transport proof only; financial exclusions stay in the calculator. `HybridSpotTransportTest` uses Current calculator results for both methods and tests strict rejection/round-trip behavior.
- Current reset `forward_premium` uses method `recurring_forward_premium` and requires policy `recurring_forward_premium_v1`. It shares strict premium source/count/bucket/date validation with the supplier payload, but cannot use the supplier policy identity. A current curve date is mandatory. The exact typed payload round-trips without repairs. Historical reset methods remain unchanged; current calculated-cost schema is 19.
- Premium transport is a closed public shape, unlike harmless auxiliary pricing records. The shared Supplier/Reset validator requires the exact `PremiumEstimate` public keys, known flags, dated reference records and enum-derived family/proxy/VAT provenance. It rejects private or unknown fields and free text instead of sanitizing stored arrays. This also applies to nested normal/actual projections and to any supplied premium even if its enclosing basis is changed. Valid arrays retain their exact financial values and metadata.
- Reset `beta=0` and empty phase labels are valid calculator output. The coefficient is non-negative, and labels are not costing facts.
- Unknown annual periods carry typed `hold_last_known_price` / continuation assumptions, not a fabricated exact total. Short terms can contain estimated in-term coverage; Hybrid base-only outcomes retain all disclosed phases. Consumers must not infer a full-year signup price or extended promo saving.
- Forward Spot payloads accept `higher` or `lower` confidence. Lower-confidence baseload estimates can have no historical shape dates; full curve vintages and monthly evidence are still required. `higher_confidence` must agree with confidence. Coverage, local/legacy provenance, zero-offset flags, and unknown harmless fields survive exact round-trip hydration.
- Supplier-adjusted estimates require their own basis, price-episode evidence basis, market vintages, current rate, annual equivalent, monthly-fee assumption (`held_flat` or `disclosed_phases`), and flags. The second value preserves known fee-only phases in current unchanged-energy forecasts; receipt copy must not call those fees flat. They must not be read as a recurring reset. Current `forward_premium` also requires policy `supplier_adjusted_forward_premium_v1` and valid premium source, positive evidence counts, confidence, finite named energy buckets, and reference dates. This distinct financial method round-trips in the existing supplier payload. Shared calculated-cost schema 19 invalidates older current semantics; retained annual methods are not rewritten.
- Legacy payloads are explicit: `pricing_basis` can be absent. Do not infer canonical facts from legacy payloads or merge the calculators.
- Keep unknown harmless keys in optional records so old cache rows round-trip without data loss.

## Source-backed energy-rule transport

`energy_rule_comparison` is optional for retained legacy canonical records. When present it has an
exact method/field shape, strict boolean certainty facts, finite signed monthly/net differences,
complete normal tariff buckets, and typed Supplier/Reset projection evidence. Actual, normal and
real-term totals must reconcile. Missing normal totals and savings remain null; signed losses are
not clipped. Only a genuine typed offer with positive signed net benefit sets `includes_discounts`.
`benefitIsEstimate()` is independent of `isEstimate()`: exact actual prices can have uncertain savings.
Only genuine energy-offer components inherit energy-estimate uncertainty. Fee-only benefits remain
exact when shared actual/normal energy forecasts cancel, even when both totals are estimated.
Projection current scalars must match the normal map: General directly; supplier Time at 15/9 and
Season at 5/7. Reset multi-rate scalars are usage-weighted, so they must lie within the bucket range.
Optional phase `energy_price_guaranteed` is a strict boolean. It means the displayed actual rate is
guaranteed throughout that resolved span, not that the source merely disclosed a later normal rate.
`actual_projection` is separate from normal `projection` and does not establish a current normal tariff.
Actual-only estimated continuations remain listable without benefit facts. Existing non-rule term
records still require complete normal facts and nonnegative savings. The shared schema bump belongs
to combined integration, not this boundary alone.

## Cache compatibility

The supplier-adjusted payload was added in calculated-cost schema v12. Schema v13 expands its eligible metering and phase-start semantics. Schema v14 adds `spot_estimate` and changes canonical Spot annual sort values to the forward strip, so persistent list, company, ranking, and prepared-page caches cannot retain v13 rolling values. Schema v15 corrects reset energy boundaries. Schema v16 invalidates annual timeline, consumption, VAT, and Spot-evidence pricing semantics; typed `vat_basis` separates Household/Both/null inclusive from Company excluded display. Reset/supplier equivalents reconcile with billed energy and costed kWh. Unknown VAT assumes the target basis in the calculator, never in this read model. Shared list/company/ranking keys no longer refresh at midnight. Active list/company generation payloads persist until successful staged replacement or immediate explicit invalidation. Ranking calculations reuse the shared annual metrics. `ContractListCacheService::calculatedAt($consumption)` returns the payload's original calculation date; a retained price must not be labelled as calculated today. See `../Caching/AGENTS.md`. No stored historical statistics or interpretations are rewritten. Outer cache versions did not move because their wrapper shape did not change. A future field-shape or pricing-semantics change must follow the existing schema-version rules in `../CanonicalPricing/AGENTS.md`.

## Presentation consumers

The listing and ranking services, cards, ContractDetail, CompanyDetail, SEO offers, weekly offers, and contract/calculation API preparation use this typed boundary. ContractDetail keeps its calculated-cost array only for card, SEO presenter, price-development, prepared-page, and public compatibility transport. CompanyDetail uses its typed map for both canonical and feature-off promotion savings. Arrays remain only in Laravel cache payloads, existing Eloquent presentation attributes, and HTTP response payloads. `CanonicalOfferFacts` accepts `ContractPricingViewData`; its `fromArray()` factory is only for strict fixture or transport hydration.
