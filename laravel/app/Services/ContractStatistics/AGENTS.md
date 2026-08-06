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

## Versioned annual-cost persistence foundation

- `contract_price_annual_costs` stores one annual-only row per date, contract, consumption, and
  method. It is separate from `contract_price_snapshots` so a historical method can be rebuilt
  without copying or rewriting observed unit-price facts.
- `AnnualCostMethodVersion` defines `annual_cost_legacy_v1` and the shadow
  `annual_cost_as_of_v1`. `AnnualCostCalculationBasis` distinguishes relational observation input
  from a canonical outcome. The active config remains `annual_cost_legacy_v1`. Public annual readers
  now filter by that configured method, so shadow rows cannot change output before explicit activation.
- Daily aggregate application writes carry a non-null `method_version`, but the database column stays
  nullable so an application rollback can still write the old shape. Existing annual rows are always
  backfilled as legacy and existing unit metrics as `unit_statistics_v1`, including on a migration
  retry. The method-aware unique identity lets annual versions coexist. Because `consumption_kwh` is
  nullable, MySQL and SQLite unique keys still permit duplicate unit identities with NULL consumption;
  a rolled-back application can also write a NULL method. The migration reports existing duplicates
  before replacing the key, and date-scoped application
  writers delete and rebuild their identities on rerun. `basis_counts` and the annual table's
  `provenance` preserve typed JSON evidence summaries for later writers.
- The current Eloquent daily producer gets its unchanged behavior from the model creation default:
  annual rows use the legacy method and all other metrics use the unit method. Future versioned
  writers must set their method and compatibility fields explicitly.
- `AsOfSpotAssumptionsProvider` resolves one explicit Helsinki target date without look-ahead. It
  accepts only the FI rolling row whose `period_end` equals the target and whose identity
  `period_start` equals that end date, and whose three VAT-inclusive values are finite. It never
  carries an older stored level forward. The derived
  period always contains exactly 365 Helsinki dates. Stored `hours_count` must be positive, must not
  exceed the DST-aware UTC-hour span, and must give at least 98% coverage. This tolerance matches the
  public rolling estimator and permits small known historical source gaps; materially incomplete
  stored windows still fail closed. The typed result records expected hours, actual hours, coverage
  ratio, and `complete` or `partial_above_threshold`. If no stored row is valid, reconstruction stays
  stricter: the exact target-through-target-minus-364 window needs one unique row for every expected
  UTC hour and never averages partial raw rows. Day is local 07:00-21:59 and night is 22:00-06:59.
  Missing hours never pull a later stored average, a future hourly row, or other future data. Its memo
  key is target date plus region.
- `HistoricalPriceEpisodeResolver` is the strict as-of counterpart to the current-source resolver.
  It makes one batch query only to `contract_price_snapshots` through the explicit target date. A
  matching observed target row wins. Another basis is eligible only when the caller passes that
  basis explicitly, either once or per candidate. The matching run has a proven start only when the
  immediately preceding local calendar date exists in the same basis and has a different
  representative rate or fee. Gaps and dataset boundaries return a missing `PriceEpisodeAnchor`
  with left-censor flags. The resolver never reads current pointers, interpretations, or later
  snapshots.
- `AsOfAnnualCostEvidenceResolver` has a batched date boundary. Its contract universe is the union of
  exact-date `contract_price_snapshots` and exact-date `price_components`. Snapshots provide the safe
  historical context, pricing basis, segment, and old annual value availability masks. Exact-date
  components are normalized in one query. A component-only identity never reads current contract
  fields: it produces three unavailable `unclassified` results with
  `missing_historical_snapshot_identity` provenance and is never aggregated or persisted. Optional
  canonical data requires covering source-observation rows to identify exactly one source snapshot,
  then one deterministic latest parser-valid published or superseded interpretation completed by
  the target day's end. Failed/in-flight output, tied completion chronology, multiple covering
  snapshots, and date-scoped output for another episode omit canonical data with explicit flags.
  The resolver never reads `active_contracts`, current canonical JSON, or current pointers.
- `AsOfAnnualCostCalculator` produces typed shadow results for 2,000, 5,000, and 18,000 kWh. It
  resolves Spot assumptions and supplier episode candidates once per date. Strict canonical Spot
  uses the as-of forward estimator and typed rolling fallback. An accepted partial stored shape can
  supply the historical day/night offset because the forward curve still supplies the future level.
  Each result carries the Spot source, complete/partial flag, coverage ratio, and expected/actual
  hours in its provenance. Relational Spot receives the
  estimate's annual day/night equivalents. Missing Spot evidence is unavailable. A proven recurring
  reset deliberately uses exact-date relational components held flat in v1, because the canonical
  reset estimator's realized seasonal fallback is not as-of safe. A supplier-adjusted outcome that
  selects the shared, non-date-bounded Spot seasonal index is also recalculated from exact-date
  relational prices and held flat with explicit provenance. Programming exceptions leave the
  calculator and fail the complete command date. Relational open-ended fixed data stays
  conservatively flat unless strict historical canonical data proves supplier-adjusted eligibility.
  Totals must be finite, non-negative, and at most 50,000 euros. Compatibility keys include method,
  calculation basis, estimate method, and estimate basis. The old annual numbers are only
  per-consumption masks and never numeric inputs.
- `AnnualCostCompatibilityKey` is the shared current/historical identity factory. It hashes exactly
  method version, calculation basis, estimate method, and estimate basis; pricing basis is not part
  of compatibility.
- `AnnualSeriesCompatibility` is the shared public-series guard. Null keys form one explicit legacy
  regime. A weekly or monthly period is comparable only when every contributing daily row has the
  same normalized key. A mixed period is null. The first daily or aggregated point after a key
  transition is also null, including a transition on an exact period boundary; a transition inside
  a mixed period consumes that gap. Annual deltas use `sameKey()` and cannot cross regimes.
- `AnnualCostStatisticsWriter` accepts one complete date of typed AsOf results. Preview validates and
  summarizes without writes and can accept a partial diagnostic selection. Apply rejects empty sets
  and any contract without exactly the 2,000/5,000/18,000 kWh identity set before one date-scoped
  transaction. It replaces only `annual_cost_as_of_v1` annual-only and aggregate rows, and leaves
  snapshots, unit metrics, legacy annual rows, other methods, and caches unchanged. Unavailable
  results enter a separate reason sub-map but not annual-only rows. Aggregate pricing, calculation,
  estimate-method, and estimate-basis counts include available contributors only. Aggregate
  compatibility hashes the sorted member key set; mixed source, calculation, and estimate evidence
  is explicit.
- `contracts:rebuild-annual-cost-statistics` selects the union of distinct historical snapshot and
  component dates through yesterday by default. It is dry-run unless `--apply` is present. Contract filters and the stable
  contract-ID limit apply to typed results per date. `--apply` rejects either partial selector before
  date lookup, so an empty date range cannot bypass the safety rule. A failed date writes nothing and
  makes the command fail; `--stop-on-error` stops at that date. Applying a date twice is idempotent.
  The command does not warm caches or have a production schedule.
- A canonical `ContractPriceStatisticsService::calculateForDate()` run retains the exact three
  `CanonicalPricingOutcome` slots for every processed current contract, including excluded contracts
  that produce no numeric snapshot. After snapshot IDs exist, `CurrentCanonicalAnnualCostResultFactory`
  loads current contract identity, optional snapshot IDs, and current source pointers in one batch. It
  adapts every contract to exactly three AsOf results without recalculation or a `price_components`
  query; excluded identities are unavailable and let a non-empty full apply remove stale rows safely.
  The writer stays inside the outer date transaction, so adapter, validation, or writer failure
  rolls back snapshots, unit aggregates, legacy annual aggregates, and shadow annual rows together.
  Date-wide replacement removes excluded, out-of-range, and stale rows. Feature-off and historical
  observed calls do not invoke this current adapter. Historical rebuilds continue to use the strict
  `AsOfAnnualCostCalculator`. The public active method remains legacy.
- Exact-date evidence queries normalize SQL date columns with `DATE(...)`. Eloquent stores current
  snapshot and component dates as midnight datetimes, while historical raw fixtures/data can use date
  strings; both forms must enter the same date-bounded AsOf batch.

## Important decisions

- Daily contract availability for historical backfills is inferred from `price_components.price_date`: if a contract has price rows for a date, include it for that date.
- Do **not** carry prices forward for missing dates/contracts. Voltikka fetches all contracts daily; missing rows should simply be missing data.
- **Forward canonical snapshot and current AsOf collection never read `price_components`.** They parse each chunk's current canonical JSON once per contract, calculate the three reference consumptions, and write every available current fact from those typed outcomes: annual totals, general/time/season representative rate, monthly fee, Spot margin/total, and measured offer status. The same outcome objects then become current AsOf annual rows. This recovers canonical-only contracts, prevents a conflicting relational promo rate from returning, and guarantees reset, supplier-adjusted, Spot, package, fixed, short-term, and Hybrid totals equal the public canonical ranking outcomes. An unavailable unit stays null. A package keeps its annual total and package fee, but its excess rate is not stored as an all-in `energy_price`. An excluded/all-null outcome is not stored. The feature-off and strict historical rebuild paths still require relational components where their rules specify them.
- **Every snapshot and aggregate has `pricing_basis`.** `canonical_calculation` identifies forward current calculations; `observed_seller_data` identifies feature-off and historical rows. Request-scoped `PricingMode::expectedContractPriceBasis()` is the shared public-current rule: canonical flag on means canonical basis, and feature-off means observed basis, with no cross-basis fallback. The two small columns are necessary because the old tables could not distinguish canonical annual values from observed unit values. Existing rows default to observed. CSV exports the field and page copy explains it.
- Before the canonical unit migration, a whole segment could vanish when upstream stopped writing `price_components`; this happened to Hybrid on 2026-07-24. Forward canonical snapshot and legacy aggregate collection no longer has that dependency. Missing exact-date components can make only a shadow AsOf fallback unavailable; it cannot remove the canonical compatibility snapshot. If a current segment now stops, inspect canonical publication/comparability first. Historical backfill still depends on component-date coverage by design. See `../ContractInterpretation/AGENTS.md` and `tasks/hybrid-relational-pricing-gate/`.
- After `contracts:republish-gated-pricing` backfills lost price-component days, the daily statistics still hold the gap; rerun `contracts:backfill-price-statistics --from=… --to=… --overwrite` over the affected historical dates.
- Future daily calculation uses `active_contracts`. Canonical mode reads only typed canonical outcomes for its compatibility snapshots and adapts those same outcomes to shadow annual rows; feature-off reads observed components for the requested date and writes no shadow rows. `contracts:calculate-price-statistics --date=` rejects every date other than today, including future dates, and directs past-date operators to the historical annual rebuild command; an omitted date and today's date keep the current behavior.
- `ContractPostImportCoordinator` captures exact timestamps immediately before and after it calls `calculateForDate()` with active IDs and `overwrite=true`, then calls the optional `ContractPercentileService`; a percentile failure cannot leave imported price rows without `/sahkosopimus/tilastot` aggregate rows. The start timestamp is the freshness boundary because an interpretation can publish while statistics are being calculated.
- Spot contracts track both margin and realistic total energy price (`stored spot average + margin`).
- Current canonical Spot `annual_cost` uses the same forward 12-month curve, historical intraday shape, exact margin, fee, and offers as the public ranking. Historical observed rows keep the trailing-365 Spot level that was known for that date. Use `annual_cost`, not current/day-period `spot_total_energy_price`, for contract-type annual-cost comparisons.
- On `/sahkosopimus/tilastot`, the contract-type **c/kWh** table, deep-dive Spot chart, and top Spot callout remain historical views: trailing-12-month realized daily Spot average + latest typical margin, with p20–p80 calculated from daily prices over the same window. Do not switch those historical unit-price figures to the forward estimate or latest-day Spot. The annual-cost chart and current canonical snapshot are the forward-looking surfaces.
- Weekly/monthly UI aggregates should average daily statistics, not recompute from all contract-day rows, so trend lines are market-day weighted.
- `/sahkosopimus/tilastot` caches its prepared Livewire view data per period + consumption until the next day, with cache keys versioned by the expected current basis, active annual method, and cheap source-table fingerprints. Current cache schema v13 includes method isolation and the shared annual-series cutover guard. The source fingerprint reads only unit rows and the active annual method, so writing shadow annual rows does not invalidate public prepared data.
- After `contracts:calculate-price-statistics` recalculates daily statistics, it queues `contracts:warm-price-statistics-cache` for the default weekly/5 000 kWh page state. The contract post-import coordinator does not call that command; after successful direct statistics it dispatches `WarmContractPriceStatisticsCache` directly for the same state. `spot:fetch` queues the same warmer after spot averages update because spot fingerprints also bust this page cache.
- The warmer builds many segment/date summaries in one job. Keep `ContractPriceStatistics` request/job-scoped batching intact: one `dailyStats` collection, one daily spot-average load sliced in memory for rolling windows, and no per-segment latest-row SQL lookups.
- One pricing basis owns each newly calculated date. Inside the calculation transaction, a run deletes opposite-basis snapshots for only its target date and replaces snapshots for its own contract set before aggregate calculation. This removes stale snapshots when a later canonical run excludes a contract. It never deletes another date. Its daily-statistic cleanup is method-scoped to `unit_statistics_v1` and `annual_cost_legacy_v1`; it never removes shadow AsOf rows or the annual-only table. A feature-off/backfill run takes the same target-date ownership with observed basis.
- Public statistics view data ends on the latest `unit_statistics_v1` date for `PricingMode::expectedContractPriceBasis()`. On that endpoint date, unit rows still require the expected basis. An active annual row is accepted only when it has that expected basis or aggregate `pricing_basis=mixed_evidence`; this prevents feature-off mode from exposing a stale canonical AsOf row left for audit. Earlier rows keep their dated basis. The mixed-metric model scope selects only unit rows or annual rows at the configured active method.
- Every public annual-cost trend uses `AnnualSeriesCompatibility`: mixed weekly/monthly periods and the first point after a method cutover are null, while deltas require the same normalized key. Unit c/kWh aggregation is unchanged.
- The two statistics widgets on `/sahkosopimus/kannattaako-porssisahko` follow the same endpoint rule. They read only the trailing year and only the plotted columns, then cache prepared arrays. Do not restore their former unbounded all-column Eloquent reads: together with the other eager article widgets, those reads exhausted the 128 MB production request limit.

## Canonical pricing (forward-only, behind `CANONICAL_PRICING_ENABLED`)

`calculateForDate()` takes `?bool $useCanonical` (defaults to the config flag). When true, all numeric
snapshot price fields and `has_discount` come from `CanonicalPricingOutcome`; no relational component
query is allowed. `outcomesForContractsAtConsumptions()` is the batch boundary and parses canonical
JSON once per contract. **`BackfillContractPriceStatistics` always passes `useCanonical: false`**:
today's interpretation must never be applied retroactively to a historical seller observation.

## Segment classification

`ContractStatisticsSegmentClassifier` is the one basis-aware classifier and owns the one
`SEGMENT_LABELS` map. `ContractPriceStatisticsService`, the detail-page overlay, the public
statistics page, and company comparisons all use it. Do not add another label map or reset
cadence list.

For `canonical_calculation`, the classifier resolves the shared card facts through
`PricingCategoryResolver` and `PricingBucket::fromFacts()`. It maps Spot to `spot`,
MarketReset to the generic `market_reset`, ConsumptionEffect to `hybrid`, and Fixed to the
contract-term segment without any text-quarterly fallback. Thus Spot wins over a reset
schedule, and a reset wins over Hybrid, exactly as on cards and pricing filters. Monthly,
quarterly, seasonal, and other reset schedules all use `market_reset`; the public label is
`Päivittyvä hinta`.

For `observed_seller_data`, the exact historical order stays unchanged:
1. `spot` for `pricing_model = Spot`
2. `hybrid` for `pricing_model = Hybrid`
3. `quarterly` for names/texts containing quarterly indicators
4. `fixed_term_*` for `contract_type = FixedTerm`, split by `fixed_time_range`
5. `open_ended` for `contract_type = OpenEnded`
6. `other`

Quarterly text matching uses `../ContractListing/ContractListingPipeline::matchesQuarterly()`.
Statistics can inspect `name`, `extra_information_fi`, `short_description`, and
`long_description`, while listing SQL inspects `name` and `extra_information_fi`.
The shared map retains `quarterly => Kvartaalisähkö` for persisted history and CSV. Never
project today's canonical reset fact onto an observed row, and do not rewrite old rows.
