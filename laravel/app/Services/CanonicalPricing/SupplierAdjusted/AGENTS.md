# Supplier-adjusted open-ended annual estimate

This directory annualises a deliberately narrow set of adjustable open-ended `FixedPrice` tariffs. It is separate from `../MarketReset/`: these suppliers disclose no recurring cadence or pass-through rule.

## Eligibility

The first release accepts only relational `OpenEnded` + `FixedPrice` + `General` contracts with an exact and complete canonical calculation, no recurring schedule, no consumption effect, and exactly one `current_structured` phase from `contract_start` or `none` through `ends:none`. That phase must contain one current `energy_general` c/kWh component. It can contain no monthly fee, one monthly fee, or duplicate monthly fees only when all duplicate amounts are identical. Duplicate identical fees resolve with the calculator's conservative maximum rule. Conflicting duplicate fee amounts are excluded. This exception is required for Vimpelin Voima Oy Sulaketariffi, whose canonical phase contains five identical `monthly_fee=4.20` components. Packages, normal amounts, discounts, Spot margins, other tariff rates, future phases, multi-phase timelines, mechanism switches, FixedTerm, Spot, and Hybrid are excluded.

The interpretation schema has no typed open-ended price-guarantee horizon. This release cannot exempt a claimed guarantee until the schema can prove its start and end.

## Estimate

The current calendar-month remainder keeps the exact published rate. Later comparison months use:

`P_m = P_current + beta * (F_m(today) - F_reference)`

`F_reference` is the FI month price for the month in which the observed current-price episode began, at the latest curve vintage before that episode start. The existing month -> quarter -> year forward ladder and reset settings supply beta, curve-age, seasonal-index, negative-floor, and absurdity rules. Fallback order is forward shift, realized Spot seasonal index, then hold the current supplier price. Every rung remains `comparable_estimate` and has a `supplier_adjusted_estimate` payload. The monthly fee stays flat and the payload records that assumption.

## Episode evidence

`CurrentPriceEpisodeResolver` runs only in `CanonicalContractPricingService`, after candidates are parsed. It uses at most one snapshot query and one source-observation fallback query per batch and never runs from the calculator. It prefers the latest contiguous matching current-price run in `contract_price_snapshots`, with `observed_seller_data` rows preferred. This date is a proxy. If snapshots do not resolve it, the current source observation can supply `first_observed_at` only when its source snapshot matches the published interpretation snapshot. Missing evidence produces a typed hold-flat estimate with a missing-anchor flag. The orchestrator memoizes resolved anchors by contract, energy rate, and fee during one service lifetime, so repeated consumption calculations do not repeat the batch queries.

## Guardrails

- Keep the card pricing category fixed.
- Apply the same offsets to total, promotion-free base, and structured-only totals.
- Do not apply supplier-adjusted offsets in `calculatePeriod()`; exact bill periods remain factual.
- Do not reuse `reset_estimate`, recurring cadence values, or `EstimateMethod::None`.
## Public presentation

`SupplierAdjustedEstimateCopy` generates the popover and detail receipt note only from the validated `supplier_adjusted_estimate` record. The copy states that the current price is the seller's published fact, the 12-month equivalent is Voltikka's estimate, the seller can change an open-ended price with notice, future prices and a change schedule are unknown, and the estimate is not a price promise. It explains forward prices as `tukkumarkkinan ennakkohinnat eli sähköfutuurit` before using the technical term. It never reads seller text or an interpretation summary.

All three basis rungs show the shared `Arvio` popover. General-tariff receipts show exactly `Energia nyt`, soft `12 kk keskihinta, arvio`, and `Perusmaksu`. The category remains `Kiinteä hinta` with lock styling, but the band says only that the current energy price is fixed and that the seller can change it with notice. ContractDetail adds one quiet basis note and a short qualifier that keeps the current published fact separate from the annual estimate. No copy claims a recurring cadence or a contractual future price.
