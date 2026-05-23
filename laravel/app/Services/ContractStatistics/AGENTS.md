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
- Future daily calculation uses `active_contracts`, but still reads price components for the requested date so snapshots match that fetch day.
- `contracts:fetch` must run daily statistics before optional percentile badge recalculation; otherwise a percentile memory failure can leave imported price rows without `/sahkosopimus/tilastot` aggregate rows.
- Spot contracts track both margin and realistic total energy price (`stored spot average + margin`).
- Spot `annual_cost` uses trailing-365-day spot average plus margin; use this annual-cost metric, not current/day-period `spot_total_energy_price`, when making contract-type cost comparisons against spot.
- On `/sahkosopimus/tilastot`, the contract-type energy-price table, deep-dive spot chart, and top spot callout show spot as trailing-12-month realized daily spot average + latest typical margin, with p20–p80 calculated from daily spot prices over the same window. Do not switch those figures back to latest-day spot, because they are compared against longer-term contract prices.
- Weekly/monthly UI aggregates should average daily statistics, not recompute from all contract-day rows, so trend lines are market-day weighted.
- `/sahkosopimus/tilastot` caches its prepared Livewire view data per period + consumption until the next day, with cache keys versioned by cheap source-table fingerprints. This prevents repeated request-time grouping of the full daily-statistics table while preserving Livewire controls.
- After `contracts:calculate-price-statistics` recalculates daily statistics (including when called by `contracts:fetch`), it queues `contracts:warm-price-statistics-cache` for the default weekly/5 000 kWh page state so the next low-traffic visitor does not pay the cold-cache aggregation cost. `spot:fetch` queues the same warmer after spot averages update because spot fingerprints also bust this page cache.

## Segment classification

Segment keys are intentionally mutually exclusive and order-dependent:
1. `spot` for `pricing_model = Spot`
2. `hybrid` for `pricing_model = Hybrid`
3. `quarterly` for names/texts containing quarterly indicators
4. `fixed_term_*` for `contract_type = FixedTerm`, split by `fixed_time_range`
5. `open_ended` for `contract_type = OpenEnded`
6. `other`

Keep quarterly pattern matching aligned with `ContractsList` / `SeoContractsList` until it is centralized.
