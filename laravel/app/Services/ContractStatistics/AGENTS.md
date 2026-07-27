# AGENTS.md

Context for contract-price statistics services.

## Purpose

This subtree calculates historical market statistics from actual imported contract prices, with spot contracts enriched by stored spot-price history.

Primary files:
- `ContractPriceStatisticsService.php` — creates daily per-contract snapshots and aggregate daily statistics.
- `../../Models/ContractPriceSnapshot.php` — immutable-ish per-contract daily observations.
- `../../Models/ContractPriceDailyStatistic.php` — daily aggregate min/p20/average/p80/max metrics.
- `../../Console/Commands/CalculateContractPriceStatistics.php` — current/future daily calculation, usually after `contracts:fetch`.
- `../../Console/Commands/BackfillContractPriceStatistics.php` — historical backfill from `price_components.price_date`.

## Important decisions

- Daily contract availability for historical backfills is inferred from `price_components.price_date`: if a contract has price rows for a date, include it for that date.
- Do **not** carry prices forward for missing dates/contracts. Voltikka fetches all contracts daily; missing rows should simply be missing data.
- **A contract with no relational components is still priced when canonical pricing is on.** `calculateForDate()` used to `continue` on empty components, which silently dropped every contract the interpretation publication gate had withheld — and those are exactly the contracts whose raw structured price was found untrustworthy (promo-only rows, an omitted later price). Canonical pricing reads the validated phase structure instead and is the more reliable figure for them: on 2026-07-27 this recovered 14 active contracts, two of which the relational path had previously recorded at their **promo** price as if it were the year's cost (Kokkolan Tyyni 279 €/v against a canonical 555; Aalto Tyyni Vakiohinta 310 against 748). Such a snapshot carries `annual_cost_*` only — the per-component c/kWh fields stay null because nothing relational exists, and `cleanValues()` drops nulls from the aggregates. A contract canonical also refuses to total (Vimpelin Voima: undisclosed pre-discount list, so an empty continuation phase) is still skipped rather than stored as an all-null row. The legacy non-canonical path still requires components, because it has nothing else to read, and historical backfills always take it.
- Because of the "no carry-forward" rule above, **a whole segment can silently vanish from this page when something upstream stops writing `price_components`** — the aggregation has nothing to notice. This happened once: the contract-interpretation publication gate closed on every Hybrid contract on 2026-07-24, and the `hybrid`/Joustosähkö line on `/sahkosopimus/tilastot` simply ended while every other segment continued. When a segment's line stops, check `price_components` coverage per `pricing_model` first (`contract_price_snapshots` will be empty for that segment too), not this service. See `../ContractInterpretation/AGENTS.md` and `tasks/hybrid-relational-pricing-gate/`.
- After `contracts:republish-gated-pricing` backfills lost price-component days, the daily statistics still hold the gap; rerun `contracts:calculate-price-statistics --date=… --overwrite` for each filled day.
- Future daily calculation uses `active_contracts`, but still reads price components for the requested date so snapshots match that fetch day.
- `contracts:fetch` must run daily statistics before optional percentile badge recalculation; otherwise a percentile memory failure can leave imported price rows without `/sahkosopimus/tilastot` aggregate rows.
- Spot contracts track both margin and realistic total energy price (`stored spot average + margin`).
- Spot `annual_cost` uses trailing-365-day spot average plus margin; use this annual-cost metric, not current/day-period `spot_total_energy_price`, when making contract-type cost comparisons against spot.
- On `/sahkosopimus/tilastot`, the contract-type energy-price table, deep-dive spot chart, and top spot callout show spot as trailing-12-month realized daily spot average + latest typical margin, with p20–p80 calculated from daily spot prices over the same window. Do not switch those figures back to latest-day spot, because they are compared against longer-term contract prices.
- Weekly/monthly UI aggregates should average daily statistics, not recompute from all contract-day rows, so trend lines are market-day weighted.
- `/sahkosopimus/tilastot` caches its prepared Livewire view data per period + consumption until the next day, with cache keys versioned by cheap source-table fingerprints. This prevents repeated request-time grouping of the full daily-statistics table while preserving Livewire controls.
- After `contracts:calculate-price-statistics` recalculates daily statistics (including when called by `contracts:fetch`), it queues `contracts:warm-price-statistics-cache` for the default weekly/5 000 kWh page state so the next low-traffic visitor does not pay the cold-cache aggregation cost. `spot:fetch` queues the same warmer after spot averages update because spot fingerprints also bust this page cache.
- The warmer builds many segment/date summaries in one job. Keep `ContractPriceStatistics` request/job-scoped batching intact: one `dailyStats` collection, one daily spot-average load sliced in memory for rolling windows, and no per-segment latest-row SQL lookups.

## Canonical pricing (forward-only, behind `CANONICAL_PRICING_ENABLED`)

`calculateForDate()` takes `?bool $useCanonical` (defaults to the config flag). When true, the daily
snapshot's `annual_cost` fields come from `CanonicalContractPricingService` (excluded contracts get a
null annual cost, mirroring spot-missing handling); the per-component c/kWh fields stay relational for
chart continuity. **`BackfillContractPriceStatistics` always passes `useCanonical: false`** — today's
canonical interpretation must never be applied retroactively to a historical date.

## Segment classification

`ContractPriceStatisticsService::segmentKey()` is **public and static**, and
`SEGMENT_LABELS` beside it is the one label map (`ContractPriceStatistics::$segments`
reads it). The contract detail page's price-development chart overlays a segment
median and must name the same segment the daily aggregation wrote, so do not add
a second classifier or a second label map anywhere.

Segment keys are intentionally mutually exclusive and order-dependent:
1. `spot` for `pricing_model = Spot`
2. `hybrid` for `pricing_model = Hybrid`
3. `quarterly` for names/texts containing quarterly indicators
4. `fixed_term_*` for `contract_type = FixedTerm`, split by `fixed_time_range`
5. `open_ended` for `contract_type = OpenEnded`
6. `other`

Keep quarterly pattern matching aligned with `ContractsList` / `SeoContractsList` until it is centralized.
