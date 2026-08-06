# Forward-looking Spot annual pricing

## Problem

The default contract ranking compares different time bases. Spot annual totals use the trailing 365-day realized FI Spot price, while market-reset and supplier-adjusted contracts use a forward-looking 12-month market estimate. When the forward curve is above the trailing year, Spot contracts appear too cheap relative to other pricing types and dominate the first result pages.

## Goal

Rank Spot contracts with an honest forward-looking 12-month expected cost on the same comparison horizon as other market-linked estimates. Keep trailing-365-day Spot data as historical information.

## Requirements

- Use the upcoming 12-month VAT-inclusive FI forward curve for canonical household and `Both` Spot annual totals. Company-only prices keep the existing rolling path until their VAT basis is normalized end to end.
- Apply a documented household load-shape adjustment instead of treating baseload futures as the exact customer capture price.
- Add each contract's exact current Spot margin, monthly fee, and supported discounts.
- Keep Spot totals typed as estimates and explain the forward basis in public copy.
- Keep trailing-365-day Spot values available for historical statistics and clearly labelled historical surfaces.
- Do not apply the forward estimate to exact-period bill comparisons. These must continue to use realized hourly Spot prices for the requested period.
- Fail safely when forward data is missing or stale. Use a typed, explained fallback instead of excluding all Spot contracts or silently returning zero.
- Avoid N+1 queries. Resolve one market curve and load-shape basis per pricing service request or batch.
- Invalidate persistent calculated-cost caches when pricing semantics change.
- Add focused arithmetic, fallback, ranking, API, card, detail, statistics, exact-period, cache, and regression tests.
- Update nearby context documentation and public methodology copy.

## Acceptance examples

- The first-page Spot ranking no longer uses only the trailing-365-day realized price as its wholesale forecast.
- A Spot contract's annual total uses the same future 12-month window as an adjustable open-ended estimate.
- Current margin and fee facts stay unchanged.
- A historical bill period stays based on realized hourly prices.
- Missing forward data produces a visible typed fallback.
