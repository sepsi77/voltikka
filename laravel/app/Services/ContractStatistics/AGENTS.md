# AGENTS.md

Context for contract-price statistics services.

## Purpose

This subtree calculates historical market statistics from actual imported contract prices, with spot contracts enriched by stored spot-price history.

Primary files:
- `ContractPriceStatisticsService.php` — creates daily per-contract snapshots and aggregate daily statistics.
- `ContractPercentileService.php` — calculates and stores card percentile thresholds; the Artisan command is only an output adapter.
- `../../Models/ContractPriceSnapshot.php` — immutable-ish per-contract daily observations.
- `../../Models/ContractPriceDailyStatistic.php` — daily aggregate min/p20/average/p80/max metrics.
- `../../Console/Commands/CalculateContractPriceStatistics.php` — current/future daily calculation, usually after `contracts:fetch`.
- `../../Console/Commands/BackfillContractPriceStatistics.php` — historical backfill from `price_components.price_date`.

## Important decisions

- Daily contract availability for historical backfills is inferred from `price_components.price_date`: if a contract has price rows for a date, include it for that date.
- Do **not** carry prices forward for missing dates/contracts. Voltikka fetches all contracts daily; missing rows should simply be missing data.
- **Forward canonical collection never reads `price_components`.** It parses each chunk's canonical JSON once per contract, calculates the three reference consumptions, and writes every available current fact from those typed outcomes: annual totals, general/time/season representative rate, monthly fee, Spot margin/total, and measured offer status. This recovers canonical-only contracts and prevents a conflicting relational promo rate from returning through statistics. An unavailable unit stays null. A package keeps its annual total and package fee, but its excess rate is not stored as an all-in `energy_price`. An excluded/all-null outcome is not stored. The feature-off path still requires relational components.
- **Every snapshot and aggregate has `pricing_basis`.** `canonical_calculation` identifies forward current calculations; `observed_seller_data` identifies feature-off and historical rows. Request-scoped `PricingMode::expectedContractPriceBasis()` is the shared public-current rule: canonical flag on means canonical basis, and feature-off means observed basis, with no cross-basis fallback. The two small columns are necessary because the old tables could not distinguish canonical annual values from observed unit values. Existing rows default to observed. CSV exports the field and page copy explains it.
- Before the canonical unit migration, a whole segment could vanish when upstream stopped writing `price_components`; this happened to Hybrid on 2026-07-24. Forward canonical collection no longer has that dependency. If a segment now stops, inspect canonical publication/comparability first. Historical backfill still depends on component-date coverage by design. See `../ContractInterpretation/AGENTS.md` and `tasks/hybrid-relational-pricing-gate/`.
- After `contracts:republish-gated-pricing` backfills lost price-component days, the daily statistics still hold the gap; rerun `contracts:calculate-price-statistics --date=… --overwrite` for each filled day.
- Future daily calculation uses `active_contracts`. Canonical mode reads only typed canonical outcomes; feature-off reads observed components for the requested date.
- `ContractPostImportCoordinator` captures exact timestamps immediately before and after it calls `calculateForDate()` with active IDs and `overwrite=true`, then calls the optional `ContractPercentileService`; a percentile failure cannot leave imported price rows without `/sahkosopimus/tilastot` aggregate rows. The start timestamp is the freshness boundary because an interpretation can publish while statistics are being calculated.
- Spot contracts track both margin and realistic total energy price (`stored spot average + margin`).
- Spot `annual_cost` uses trailing-365-day spot average plus margin; use this annual-cost metric, not current/day-period `spot_total_energy_price`, when making contract-type cost comparisons against spot.
- On `/sahkosopimus/tilastot`, the contract-type energy-price table, deep-dive spot chart, and top spot callout show spot as trailing-12-month realized daily spot average + latest typical margin, with p20–p80 calculated from daily spot prices over the same window. Do not switch those figures back to latest-day spot, because they are compared against longer-term contract prices.
- Weekly/monthly UI aggregates should average daily statistics, not recompute from all contract-day rows, so trend lines are market-day weighted.
- `/sahkosopimus/tilastot` caches its prepared Livewire view data per period + consumption until the next day, with cache keys versioned by the expected current basis and cheap source-table fingerprints. Current cache schema v10 keeps canonical and feature-off payloads separate. This prevents repeated request-time grouping of the full daily-statistics table while preserving Livewire controls.
- After `contracts:calculate-price-statistics` recalculates daily statistics, it queues `contracts:warm-price-statistics-cache` for the default weekly/5 000 kWh page state. The contract post-import coordinator does not call that command; after successful direct statistics it dispatches `WarmContractPriceStatisticsCache` directly for the same state. `spot:fetch` queues the same warmer after spot averages update because spot fingerprints also bust this page cache.
- The warmer builds many segment/date summaries in one job. Keep `ContractPriceStatistics` request/job-scoped batching intact: one `dailyStats` collection, one daily spot-average load sliced in memory for rolling windows, and no per-segment latest-row SQL lookups.
- One pricing basis owns each newly calculated date. Inside the calculation transaction, a run deletes opposite-basis snapshots for only its target date and replaces snapshots for its own contract set before aggregate calculation. This removes stale snapshots when a later canonical run excludes a contract. It never deletes another date. A feature-off/backfill run takes the same target-date ownership with observed basis.
- Public statistics view data ends on the latest date for `PricingMode::expectedContractPriceBasis()`. Earlier rows keep their stored historical basis, but a newer opposite-mode row cannot become the current endpoint. If the expected basis has no rows yet, the page uses its unavailable state instead of crossing the source boundary.
- The two statistics widgets on `/sahkosopimus/kannattaako-porssisahko` follow the same endpoint rule. They read only the trailing year and only the plotted columns, then cache prepared arrays. Do not restore their former unbounded all-column Eloquent reads: together with the other eager article widgets, those reads exhausted the 128 MB production request limit.

## Canonical pricing (forward-only, behind `CANONICAL_PRICING_ENABLED`)

`calculateForDate()` takes `?bool $useCanonical` (defaults to the config flag). When true, all numeric
snapshot price fields and `has_discount` come from `CanonicalPricingOutcome`; no relational component
query is allowed. `outcomesForContractsAtConsumptions()` is the batch boundary and parses canonical
JSON once per contract. **`BackfillContractPriceStatistics` always passes `useCanonical: false`**:
today's interpretation must never be applied retroactively to a historical seller observation.

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

Quarterly text matching uses `../ContractListing/ContractListingPipeline::matchesQuarterly()`.
Statistics can inspect `name`, `extra_information_fi`, `short_description`, and
`long_description`, while listing SQL inspects `name` and `extra_information_fi`.
Do not add a private phrase copy or apply current canonical classifications to historical rows.
