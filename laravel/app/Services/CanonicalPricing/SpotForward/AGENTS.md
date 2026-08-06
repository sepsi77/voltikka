# Forward-looking canonical Spot estimate

This directory owns the annual wholesale estimate for canonical Spot pricing. Read `../AGENTS.md` first.

## Rules

- Use the FI Base forward curve for every calendar month touched by `[window start, window start + 1 year)`. A start date after the first day normally touches 13 months.
- For the in-delivery month, use the latest curve strictly before the first day of that month. For later months, use the latest curve strictly before the window start.
- Use the shared `MarketReferenceCurveProvider` month -> quarter -> year fallback. Do not query the curve for each contract or consumption.
- Preserve the rolling-365 intraday shape: `day offset = rolling day - rolling overall` and `night offset = rolling night - rolling overall`. The evidence window must span 365 dates, must not end in the future, and must end no more than the configured 14-day curve-age limit before the comparison date. Historical AsOf pricing can pass an accepted stored shape with at least 98% hourly coverage because the shape supplies only these offsets; the curve still supplies the future level. The AsOf result keeps complete/partial coverage provenance, while this estimator keeps its typed forward or fallback basis.
- Floor each projected wholesale day or night value at zero before a contract Spot margin is added.
- Reject the complete forward rung if shape evidence is invalid, a required vintage is absent or stale, or one touched month is absent. Do not mix forward and rolling months.
- The only fallback is the typed rolling-365 day/night estimate. It remains `comparable_estimate` and its payload states the fallback reason.
- Apply the estimate only to resolved phases with `uses_spot=true`. Do not apply it to fixed phases, packages, market-reset shifts, supplier-adjusted shifts, or exact-period pricing.
- The shared curve and shape inputs are VAT-inclusive. Apply the forward strip only to `Household` and `Both` contracts. Company-only canonical components can be VAT-excluded or unknown and are not normalized yet, so they keep the existing rolling path instead of mixing tax bases.
- Actual, normal-price, and structured-only annual passes use the same estimate. Market movement must not become discount saving.

`SpotForwardPriceEstimator` is container-free. It receives the shared curve provider and the existing market-reset age setting. `CanonicalContractPricingService` resolves and memoizes one estimate per normalized window and shape assumption set after parsing proves that the batch has Spot pricing.

The transport record is `calculated_cost.spot_estimate`. It contains the basis, historical shape period and values, both curve vintages, all touched monthly base/day/night values and source kinds, annual equivalents, confidence, and flags.
