# Decisions

## 2026-08-06 — Task created

- Spot can reasonably have the lowest expected cost because the customer carries wholesale-price risk. The present degree of dominance is not reliable because the ranking mixes a backward-looking Spot baseline with forward-looking adjustable-contract estimates.
- Production review at 5,000 kWh showed that 24 of the first 25 results were Spot contracts. Canonical Spot used the trailing-365-day household-profile price, while supplier-adjusted examples used upcoming-year forward equivalents near 11.6–12.2 c/kWh.
- Use one comparable future horizon before considering any presentation-level diversification. Do not artificially interleave contract categories in the cost ranking.
- Keep exact-period bill comparison factual and separate from the annual forecast.

## 2026-08-06 — Model implemented

- `CanonicalPricing/SpotForward/` owns the model. Spot is not a supplier reset: futures supply the wholesale level directly, and the contract contributes its exact margin, fees, phases, and supported discounts.
- The window can touch 13 calendar months. The in-delivery month uses the latest FI Base curve strictly before delivery began. Every later month uses the latest curve strictly before the comparison date. The shared provider supplies month, then quarter, then year fallback prices in VAT-inclusive c/kWh.
- Futures already contain monthly seasonality. Historical Spot data supplies only an additive intraday shape: `day - overall` and `night - overall` from the trailing 365-day evidence. Applying historical monthly seasonality as well would count seasonal shape twice.
- Projected wholesale day/night values are floored at zero before the exact contract margin is added.
- Missing, stale, or incomplete curve or shape evidence rejects the full forward strip. Shape evidence must span 365 dates and end no more than 14 days before the comparison date. The one fallback is the typed rolling-365 day/night estimate. Forward and historical months are never mixed.
- `CanonicalContractPricingService` memoizes one strip by window and shape evidence after parsed data proves that the batch contains Spot pricing. Multiple contracts and consumptions do not repeat the curve work.
- The forward strip is VAT-inclusive and therefore applies to `Household` and `Both` contracts. Company-only canonical components can be VAT-excluded or unknown, and the current parser does not normalize that basis. They keep the prior rolling path instead of combining a VAT-inclusive curve with business charges. A future business-forward model must preserve component VAT status through the typed calculator.
- `calculatePeriod()` receives no forward estimate. It continues to use realized hourly Spot rows and the exact phase margin.
- Current canonical annual-cost statistics use the forward outcome. Historical observed statistics, the c/kWh Spot table, deep-dive charts, and price-history charts remain realized evidence.
- `calculated_cost.spot_estimate` records the basis, shape window, curve vintages, all touched monthly prices and source kinds, annual equivalents, confidence, and flags. `CalculatedCostPayloadSchema` moved from v13 to v14 so persistent ranking and page caches cannot retain rolling Spot totals.
- A successful non-dry EEX fetch that writes prior-date FI Base data bumps the shared contract-list pricing version. All calculated-cost-dependent caches include that version directly or indirectly, so a new curve invalidates old Spot and reset totals instead of waiting for cache expiry.
- Local production-data review at 5,000 kWh moved the cheapest Sähkötytöt Spot offer from about €420.60 to €506.58 per year for the 2026-08-06 window. In the audited national household set it moved from the top of a Spot-dominated block to about position 20, between fixed, supplier-adjusted, Hybrid base-only, and Spot products. This is an observed consequence, not a category quota.
- Final verification passed 1,942 Laravel tests with 7,236 assertions. The production asset build and `git diff --check` also passed.
