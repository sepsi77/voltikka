# Supplier-adjusted open-ended annual estimate

This directory annualises a deliberately narrow set of adjustable open-ended `FixedPrice` tariffs. It is separate from `../MarketReset/`: these suppliers disclose no recurring cadence or pass-through rule.

## Eligibility

Eligibility accepts relational `OpenEnded` + `FixedPrice` contracts with `General`, `Time`, or `Season` metering, an exact and complete canonical calculation, no recurring schedule, no consumption effect, and exactly one `current_structured` phase through `ends:none`. The phase can start at `contract_start`, `none`, `unknown`, or a date. An unknown or dated start is not a 12-month price guarantee, so the ordinary adjustable seller price stays an estimate.

The exact energy component set follows metering: `General` needs `energy_general`; `Time` needs `energy_day` and `energy_night`; `Season` needs `energy_seasonal_winter` and `energy_seasonal_other`. Missing or unrelated components are excluded. Identical duplicate expected energy values are accepted, but conflicts are excluded. The phase can contain no monthly fee, one monthly fee, or multiple current fee variants. Fee variants resolve with the calculator's existing conservative maximum rule. This covers Vimpelin Voima Oy Sulaketariffi, whose canonical phase contains five identical `monthly_fee=4.20` components, and fuse-size fee variants without making their ordinary energy price ineligible. Packages, normal amounts, discounts, Spot margins, other tariff rates, future phases, multi-phase timelines, mechanism switches, FixedTerm, Spot, and Hybrid are excluded.

The candidate uses one stable representative rate for seller-price episode matching and market estimation. It is the General rate, `(day × 15 + night × 9) / 24` for Time, or `(winter × 5 + other × 7) / 12` for Season. These are the same weights as `ContractPriceStatisticsService` snapshots. They do not replace the exact tariff rates in calculation output.

The interpretation schema has no typed open-ended price-guarantee horizon. This release cannot exempt a claimed guarantee until the schema can prove its start and end.

## Estimate

The current calendar-month remainder keeps the exact published rate. Later comparison months use:

`P_m = P_current + beta * (F_m(today) - F_reference)`

`F_reference` is the FI month price for the month in which the observed current-price episode began, at the latest curve vintage before that episode start. The existing month -> quarter -> year forward ladder and reset settings supply beta, curve-age, seasonal-index, negative-floor, and absurdity rules. Fallback order is forward shift, realized Spot seasonal index, then hold the current supplier price. Every rung remains `comparable_estimate` and has a `supplier_adjusted_estimate` payload. The monthly fee stays flat and the payload records that assumption.

## Episode evidence

`CurrentPriceEpisodeResolver` runs only in `CanonicalContractPricingService`, after candidates are parsed. It uses at most one snapshot query and one source-observation fallback query per batch and never runs from the calculator. It prefers the latest contiguous matching current-price run in `contract_price_snapshots`, with `observed_seller_data` rows preferred. This date is a proxy. If snapshots do not resolve it, the current source observation can supply `first_observed_at` only when its source snapshot matches the published interpretation snapshot. Missing evidence produces a typed hold-flat estimate with a missing-anchor flag. The orchestrator memoizes resolved anchors by contract, energy rate, and fee during one service lifetime, so repeated consumption calculations do not repeat the batch queries.

## Guardrails

- Keep the card pricing category fixed.
- Apply one additive monthly market offset to every Time or Season per-kWh bucket. Apply the same offsets to total, promotion-free base, and structured-only totals.
- Do not apply supplier-adjusted offsets in `calculatePeriod()`; exact bill periods remain factual.
- Do not reuse `reset_estimate`, recurring cadence values, or `EstimateMethod::None`.
## Public presentation

`SupplierAdjustedEstimateCopy` generates the popover and detail receipt note only from the validated `supplier_adjusted_estimate` record. The copy states that current energy rates are seller-published facts, the 12-month equivalent is Voltikka's estimate, the seller can change an open-ended price with notice, future prices and a change schedule are unknown, and the estimate is not a price promise. It explains forward prices as `tukkumarkkinan ennakkohinnat eli sähköfutuurit` before it uses the technical term. It never reads seller text or an interpretation summary.

All three basis rungs show the shared `Arvio` popover. General-tariff receipts show `Energia nyt`, soft `12 kk keskihinta, arvio`, and `Perusmaksu`. Time and Season cards keep their two exact current tariff rows plus the monthly fee, within the three-row cap. ContractDetail uses detailed mode and adds the soft 12-month equivalent before the fee. The category remains `Kiinteä hinta` with lock styling, but the band says only that the current energy price is fixed and that the seller can change it with notice. Copy calls current day/night or seasonal rates seller-published facts and separately calls the equivalent Voltikka's estimate. It does not call the weighted representative a published single energy price. No copy claims a recurring cadence or a contractual future price.
