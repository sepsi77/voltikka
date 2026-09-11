# Forward-looking canonical Spot estimate

This directory owns the annual wholesale estimate for canonical Spot pricing. Read `../AGENTS.md` first.

The forward window uses `addMonthsNoOverflow(12)`, as the bill calculator does. A February 29 start ends on February 28 the next year, not March 1. The final month weight excludes the end date.

## VAT integration

`SpotEstimate::withVatBasis()` makes an idempotent selected-cost copy for Company pricing. It scales all price and offset fields and monthly prices, but preserves dates, coverage, flags, and confidence. The shared estimator result remains inclusive. Its public copy includes `vat_basis`. The calculator recognizes `zero_intraday_shape_fallback` as flat baseload shape, not historical intraday evidence. See `../DTO/AGENTS.md` for the annual current-rate assumption and exact-period hourly evidence policy.

## Rules

- Use the FI Base forward curve for every calendar month touched by `[window start, window start + 1 year)`. A start date after the first day normally touches 13 months.
- For the in-delivery month, use the latest curve strictly before the first day of that month. For later months, use the latest curve strictly before the window start.
- Use the shared `MarketReferenceCurveProvider` month -> quarter -> year fallback. Do not query the curve for each contract or consumption.
- Preserve the rolling-365 intraday shape when evidence is sufficient: `day offset = rolling day - rolling overall` and `night offset = rolling night - rolling overall`. The evidence window must span 365 Helsinki dates, must not end in the future, and must end no more than the configured 14-day curve-age limit before the comparison date. At least 98% hourly coverage is sufficient for shape offsets. The typed assumptions and estimate payload retain actual hours, expected hours, coverage ratio, and window semantics. Optional fields preserve direct-constructor compatibility.
- Missing, stale, sparse, or legacy shape evidence does not reject a complete futures strip. Use zero day/night offsets instead, with `confidence=lower`, `higher_confidence=false`, and `zero_intraday_shape_fallback` plus the reason flag. Futures remain the market level; no historical level is invented. A covered shape retains `confidence=higher`; accepted partial coverage also carries `partial_shape_coverage`.
- Floor each projected wholesale day or night value at zero before a contract Spot margin is added.
- Reject the complete forward rung only if a required vintage is absent or stale, or one touched month is absent. Do not mix forward and rolling months.
- When the curve fails, use the typed rolling-365 day/night level if available, including partial levels. Its payload states the curve failure and shape coverage. Poor shape coverage is not a new exclusion gate for this rolling level.
- `SpotPriceAverageService` classifies raw stored timestamps as UTC before conversion to Helsinki hours. Rolling production and AsOf raw reconstruction use `[target minus 364 local dates, target plus one local date)` converted to UTC, including DST. The `SpotPriceAverage` model owns the new rolling period types `rolling_365d_local` and `rolling_30d_local`; its latest helpers accept both versions, sort by end date, and prefer local evidence only on a same-date tie. Producer constants remain aliases. Thus a newer legacy row remains a usable fallback; `period_start` remains the target identity, not the evidence start. This distinguishes old UTC-day rows without a migration or historical rewrite.
- AsOf prefers a same-date local row. For a legacy row it first tries raw local reconstruction, then retains the legacy level with `legacy_utc_dates` and `unverified_shape_window`, never claiming exact local coverage. Valid partial raw history can supply a rolling level; the 98% rule controls shape offsets, not level availability. Duplicate, off-hour, and non-finite raw evidence remain invalid.
- Apply the estimate only to resolved phases with `uses_spot=true`. Do not apply it to fixed phases, packages, market-reset shifts, supplier-adjusted shifts, or exact-period pricing.
- The shared curve and shape inputs are VAT-inclusive. Apply the forward strip only to `Household` and `Both` contracts. Company-only canonical components can be VAT-excluded or unknown and are not normalized yet, so they keep the existing rolling path instead of mixing tax bases.
- Actual, normal-price, and structured-only annual passes use the same estimate. Market movement must not become discount saving.

`SpotForwardPriceEstimator` is container-free. It receives the shared curve provider and the existing market-reset age setting. `CanonicalContractPricingService` resolves and memoizes one estimate per normalized window and shape assumption set after parsing proves that the batch has Spot pricing.

The transport record is `calculated_cost.spot_estimate`. It contains the basis, historical shape period and values, both curve vintages, all touched monthly base/day/night values and source kinds, annual equivalents, confidence, and flags.
