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
- `AnnualCostMethodVersion` defines `annual_cost_legacy_v1`, `annual_cost_as_of_v1`, and
  `annual_cost_as_of_v2`; `isAsOf()` accepts both AsOf versions. `AnnualCostCalculationBasis`
  distinguishes observed relational input from a canonical outcome. Public v2 has been active since
  the explicitly approved activation on 2026-09-12. Historical v2 was applied for 233 evidence dates
  from 2026-01-21 through 2026-09-11 (no evidence date on February 12): 213,755 annual rows and
  7,618 aggregates. V1 remains retained at 2026-09-11 with its snapshot joins preserved; historical
  snapshots and price components are unchanged. See
  `../../../../tasks/annual-statistics-v2-rollout/apply-results.md` for proof.
  Current canonical collection writes v2 only. Every public annual reader selects the active method.
  Retained v1 remains dated history for a separately approved rollback.
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
  local period contains exactly 365 Helsinki dates. New `rolling_365d_local` rows use this window;
  old `rolling_365d` rows are UTC-date evidence and remain labelled `legacy_utc_dates`, never exact
  local shape. The model owns both period types and a shared newest-date/local-tie ordering rule.
  AsOf tries raw local reconstruction before accepting a legacy row. Stored `hours_count` must be
  positive and not exceed its window's expected hours. Valid partial stored and raw levels remain
  usable. At least 98% coverage permits historical day/night offsets; lower coverage uses a flat
  shape when a complete futures strip exists. The typed result records expected hours, actual hours,
  coverage ratio, window semantics, and complete/partial flags. Raw timestamps are parsed as UTC;
  duplicates, off-hour values, and non-finite prices remain invalid. Day is local 07:00-21:59 and
  night is 22:00-06:59. Missing hours never pull a later stored average, a future hourly row, or other
  future data. Its memo key is target date plus region.
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
  canonical data first uses covering source observations and one parser-valid interpretation completed
  by the target day. Only exact `canonical_omitted_no_covering_source_observation` opens the dedicated
  historical path. It batch-loads overlapping current-builder episodes and analyses, requires one
  covering episode, exact target snapshot plus sorted component composite membership and normalized
  economic digest in `target_days`, and one validated complete current analysis fingerprint with empty
  errors and a fresh parser pass. A successful retrospective row records its later completion, text grade, episode ID, and
  interpretation ID; stale, pending, failed, mismatched, parser-invalid, or ambiguous states stay closed.
  The resolver never reads `active_contracts`, current contract prose/canonical JSON, publication
  pointers, or currentness pointers.
- `AsOfAnnualCostCalculator::calculate(date, methodVersion = AsOf)` produces typed results for
  2,000, 5,000, and 18,000 kWh. The API keeps its v1 default; callers select v2 explicitly. It
  resolves Spot assumptions and supplier episode candidates once per date. Strict canonical Spot
  uses the as-of forward estimator and typed rolling fallback. An accepted partial stored shape can
  supply the historical day/night offset because the forward curve still supplies the future level.
  Each result carries the Spot source, complete/partial flag, coverage ratio, and expected/actual
  hours in its provenance. Relational Spot receives the
  estimate's annual day/night equivalents. Missing historical shape does not block a complete
  futures strip: both buckets use baseload with lower confidence and explicit zero-shape flags.
  Historical source availability and actual coverage stay unchanged in provenance. Missing both
  historical levels and a usable curve is unavailable. Source
  `PricingModel=Spot` has precedence over an active canonical recurring schedule and always uses the
  Spot forward/fallback path. A proven recurring reset on a non-Spot contract deliberately uses
  exact-date relational components held flat in v1. V1 also retains its supplier seasonal-index hold
  branch. These are diagnostic compatibility paths, not frozen original mathematics after shared code
  changes. V2 uses canonical reset and supplier-adjusted estimates with date-safe seasonal inputs:
  complete months before the target only, date-keyed memoization, and no reference vintage after the
  calculation date, including for genuinely announced future reset periods. Programming exceptions leave the
  calculator and fail the complete command date. Relational open-ended fixed data stays
  conservatively flat unless strict historical canonical data proves supplier-adjusted eligibility.
  Totals must be finite, non-negative, and at most 50,000 euros. Compatibility keys include method,
  calculation basis, estimate method, and estimate basis. Old annual numbers are never numeric inputs.
  V1 retains their per-consumption masks. V2 can recover an old null mask only from the selected
  immutable source payload's proven consumption bounds or explicitly unrestricted bounds. Both
  `MinXKWhPerY` and `MaxXKWhPerY` must exist as non-negative integers or explicit nulls. Missing or
  malformed limits are unknown, not unrestricted. Dedicated retrospective evidence without that
  selected payload cannot recover the mask. Out-of-range and unknown eligibility have separate
  reasons. A selected source with `TargetGroup=Company` produces a household conflict exclusion;
  current contract limits or audience fields never supply this dated proof.
- V2 relational fallback uses a separate `ContractPriceCalculator(uniformSeasonalConsumption: true)`
  instance. The default legacy and v1 profiles remain unchanged. Results retain
  `ObservedRelationalComponents` and `observed_relational_uniform_monthly_consumption`; they never
  fabricate canonical evidence. Fee-only or unidentified energy is unavailable. The energy guard
  follows the engine's actual General, Time, then Season positive-rate precedence, not snapshot
  metering labels. A selected Time/Season tariff requires both finite buckets; both winter aliases
  resolve to the same slot. Fully disclosed zero energy remains valid. For Spot, the actual first
  non-monthly margin row must have a finite recognized energy/margin type; a later valid General
  row cannot hide an unidentified first surcharge.
- `AnnualCostCompatibilityKey` is the shared current/historical identity factory. It hashes exactly
  method version, calculation basis, estimate method, and estimate basis; pricing basis is not part
  of compatibility.
- `AnnualSeriesCompatibility` is the shared public-series guard. Stored aggregate compatibility stays
  strict for audit evidence. For AsOf median charts only, a valid aggregate `basis_counts.estimate_method`
  map creates a display regime from the actual annual method version and dominant estimate method,
  including sorted ties. V1 and v2 cannot share a display regime. Minority-method
  changes do not split the market median, but a dominant-method change does. Invalid counts, legacy rows,
  `sameKey()`, and `evaluatePeriod()` keep the stored conservative behavior. Null keys form one explicit
  legacy regime. A mixed period is null, and the first point after a regime transition is also null.
  Weekly and monthly statistics-page series append the latest non-null daily median at its exact date
  after grouped periods; it connects only when the display regime permits it. Daily output is not appended.
- `AnnualCostStatisticsWriter::preview/write(date, results, methodVersion = AsOf)` accepts typed
  results from exactly the selected AsOf method. Mixed methods and a Legacy target are invalid. Preview validates and
  summarizes without writes and can accept a partial diagnostic selection. Apply rejects empty sets
  and any contract without exactly the 2,000/5,000/18,000 kWh identity set before one date-scoped
  transaction. It replaces only the selected method's annual-only and aggregate rows, and leaves
  snapshots, unit metrics, legacy annual rows, other methods, and caches unchanged. V2 writes preserve
  complete stored v1 annual and aggregate financial records byte for byte. Unavailable
  results enter a separate reason sub-map but not annual-only rows. Dedicated historical results write
  `historical_episode_id`, `historical_interpretation_id`, and `historical_evidence_grade` while normal
  source IDs stay null; source and current-adapter rows keep all three dedicated fields null. The full
  identity and flags remain in provenance JSON. Aggregate pricing, calculation, estimate-method, and
  estimate-basis counts include available contributors only. Aggregate compatibility hashes the sorted
  member key set; mixed source, calculation, and estimate evidence is explicit.
- `contracts:rebuild-annual-cost-statistics` defaults to `--method=annual_cost_as_of_v2` and a stored
  `--baseline` equal to the active public method. It selects historical snapshot/component dates plus
  retained baseline-only dates through yesterday. Unknown methods, a Legacy target, and partial apply
  options fail before date queries. Recalculated v1 is preview-only diagnostic output; historical
  correction `--apply` accepts v2 only. The stored baseline is captured before writing, including when
  baseline equals target. AsOf contract totals come only from exact-method annual rows; Legacy totals
  come from snapshot annual columns. Full-date aggregate deltas use stored baseline medians. Partial
  previews use one sorted baseline/candidate contract-ID union, including baseline-only IDs, and
  rebuild baseline medians within that same selection. Output reports matched/new/lost aggregates
  and contract-consumption coverage, unavailable reasons, method/basis flags, and missing baselines.
  Unstored AsOf unavailable identities are unknown, not zero. Output and totals are bounded.
  `--contract` and `--limit` are preview-only. Empty apply fails; complete-date replacement remains
  transactional and idempotent. A failed date makes the command fail; `--stop-on-error` stops the
  range. No cache warming, schedule, automatic deployment rebuild, or production execution is added.
  Follow `../../../../tasks/annual-statistics-correction/rollout.md`: obtain an approved full database
  backup before mutations and separate approval for the public active-method switch.
- A canonical `ContractPriceStatisticsService::calculateForDate()` run retains the exact three
  `CanonicalPricingOutcome` slots for every processed current contract, including excluded contracts
  that produce no numeric snapshot. After snapshot IDs exist, `CurrentCanonicalAnnualCostResultFactory`
  loads current contract identity, optional snapshot IDs, and current source pointers in one batch. It
  adapts every contract to exactly three v2 AsOf results without recalculation or a `price_components`
  query; excluded identities are unavailable and let a non-empty full apply remove stale rows safely.
  The writer stays inside the outer date transaction, so adapter, validation, or writer failure
  rolls back snapshots, unit aggregates, legacy annual aggregates, and v2 annual rows together.
  Date-wide replacement removes excluded, out-of-range, and stale rows. Feature-off and historical
  observed calls do not invoke this current adapter. Historical rebuilds continue to use the strict
  `AsOfAnnualCostCalculator`. Production has used public v2 since 2026-09-12, with v1 retained
  at 2026-09-11 for dated rollback. The annual-only table has no compatibility-snapshot foreign key. Current same-day
  replacement preserves v1 financial rows, but old snapshot IDs in provenance can stop resolving,
  and company date/contract joins can lose newly excluded identities. Avoid replacing the last
  retained v1 date; stored annual rows alone do not replace a full backup.
- Exact-date evidence queries normalize SQL date columns with `DATE(...)`. Eloquent stores current
  snapshot and component dates as midnight datetimes, while historical raw fixtures/data can use date
  strings; both forms must enter the same date-bounded AsOf batch.

## Seller-set energy-price index

- `SellerSetEnergyPriceIndexService` writes `seller_set_energy_price_index_v1` rows to `contract_price_daily_statistics`. Current canonical collection writes inside the daily transaction. A current feature-off run can remove today's row, but an observed historical recalculation does not remove a separately reconstructed row.
- The validated historical series starts on 2026-01-21. The frozen basket date stays 2026-08-11. Historical reconstruction keeps the same metric, calculation basis, estimate basis, compatibility key, weights, range gate, supplier median, and all-three-family gate as current rows.
- Historical membership does not require or read an annual cost. `AsOfAnnualCostEvidenceResolver` verifies the exact snapshot/component manifest and supplies parsed validated canonical data plus historical company identity. The direct-rate calculator boundary resolves the signup phase with the normal timeline and inheritance logic without consumption or annual-cost calculation. The command reports matching 5,000 kWh annual rows only as a diagnostic. `previewHistoricalForDate(date, methodVersion = null)` uses an explicit method or the active public method; it never combines inactive annual versions or changes index mathematics.
- The index's historical household and national scope retains its own explicit assumption: the current `ElectricityContract` relationship supplies only target-group and proven-national eligibility. This does not supply dated consumption or audience proof to the v2 annual calculator. Historical company identity, contract family facts, and price come from date-bounded evidence. Fixed term wins family classification, then an active canonical recurring reset. Persisted `quarterly`/`market_reset` evidence is the fallback when an older valid interpretation did not carry the cadence. Hybrid stays a separate base-price series.
- The overall index uses only positive canonical direct General rates up to 50 c/kWh from proven national household/Both/legacy-null offers. Spot, Time, Season, packages, and unknown shapes are absent. Hybrid has a separate base-rate row and never enters the overall value.
- Fixed-term, open-ended, and market-reset families each give one observation per supplier: the supplier median of eligible offers. A family value is the arithmetic mean of supplier medians and requires at least three suppliers. The overall value uses the frozen basket supplier-family weights 0.500000 / 0.295455 / 0.204545. It is absent unless all three sufficient families exist; weights are never renormalized around a missing family.
- Index rows use `avg_value`, not `median_value`, and keep supplier counts, offer counts, family counts, weights, evidence mode, and bounded historical provenance counts in `basis_counts`. Reconstructed evidence does not create another compatibility regime because the economic method is unchanged. Page readers must use the versioned metric, calculation basis, estimate basis, and compatibility key together.
- `contracts:backfill-seller-set-energy-price-index` accepts exact `--date` or inclusive `--from` and `--to` inside 2026-01-21 through 2026-08-11. It selects dates with stored snapshot evidence, previews by default, writes only with `--apply`, reports per-date diagnostics, supports `--stop-on-error`, and replaces one date idempotently. It uses only the configured database and has no production or Railway integration.
- The statistics-page chart averages daily index levels for weekly/monthly views and inserts null periods for collection gaps. The headline 30-day change requires the exact prior calendar date.

## Important decisions

- Daily contract availability for historical backfills is inferred from `price_components.price_date`: if a contract has price rows for a date, include it for that date.
- Do **not** carry prices forward for missing historical observation dates/contracts. Voltikka fetches all contracts daily; missing rows should simply be missing data. This differs from an annual estimate's future segments: those can use an explicit latest applicable price or disclosed normal continuation. Known phases win, no gap is free, and assumed coverage cannot extend promo savings.
- **Forward canonical snapshot and current AsOf collection never read `price_components`.** They parse each chunk's current canonical JSON once per contract, calculate the three reference consumptions, and write every available current fact from those typed outcomes: annual totals, general/time/season representative rate, monthly fee, Spot margin/total, and measured offer status. The same outcome objects then become current AsOf annual rows. This recovers canonical-only contracts, prevents a conflicting relational promo rate from returning, and guarantees reset, supplier-adjusted, Spot, package, fixed, short-term, and Hybrid totals equal the public canonical ranking outcomes. An unavailable unit stays null. A package keeps its annual total and package fee, but its excess rate is not stored as an all-in `energy_price`. An excluded/all-null outcome is not stored. The feature-off and strict historical rebuild paths still require relational components where their rules specify them.
- **Every snapshot and aggregate has `pricing_basis`.** `canonical_calculation` identifies forward current calculations; `observed_seller_data` identifies feature-off and historical rows. Request-scoped `PricingMode::expectedContractPriceBasis()` is the shared public-current rule: canonical flag on means canonical basis, and feature-off means observed basis, with no cross-basis fallback. The two small columns are necessary because the old tables could not distinguish canonical annual values from observed unit values. Existing rows default to observed. CSV exports the field and page copy explains it.
- Before the canonical unit migration, a whole segment could vanish when upstream stopped writing `price_components`; this happened to Hybrid on 2026-07-24. Forward canonical snapshot and legacy aggregate collection no longer has that dependency. Missing exact-date components can make an AsOf historical fallback unavailable; it cannot remove the canonical compatibility snapshot. If a current segment now stops, inspect canonical publication/comparability first. Historical backfill still depends on component-date coverage by design. See `../ContractInterpretation/AGENTS.md` and `tasks/hybrid-relational-pricing-gate/`.
- After `contracts:republish-gated-pricing` backfills lost price-component days, the daily statistics still hold the gap; rerun `contracts:backfill-price-statistics --from=… --to=… --overwrite` over the affected historical dates.
- Future daily calculation uses `active_contracts`. Canonical mode reads only typed canonical outcomes for its compatibility snapshots and adapts those same outcomes to v2 annual rows; feature-off reads observed components for the requested date and writes no AsOf rows. `contracts:calculate-price-statistics --date=` rejects every date other than today, including future dates, and directs past-date operators to the historical annual rebuild command; an omitted date and today's date keep the current behavior.
- `ContractPostImportCoordinator` captures exact timestamps immediately before and after it calls `calculateForDate()` with active IDs and `overwrite=true`, then calls the optional `ContractPercentileService`; a percentile failure cannot leave imported price rows without `/sahkosopimus/tilastot` aggregate rows. The start timestamp is the freshness boundary because an interpretation can publish while statistics are being calculated.
- Spot contracts track both margin and realistic total energy price (`stored spot average + margin`).
- Current/legacy statistics rolling readers accept both legacy and local period types, select by
  `period_end <= target`, and prefer local evidence on a same-date tie. Their raw fallback uses the
  same half-open 365-Helsinki-date UTC window and explicit raw UTC classification. Coverage metadata
  must survive into canonical estimates; sparse levels must not become high-confidence shape.
- The statistics-page source fingerprint includes daily and both versions of rolling 30/365 rows.
  Coverage and price sums detect a same-second refresh even when row count and date do not change.
- Current canonical annual outcomes use the shared flat default monthly consumption profile with explicit heating/cooling shape, no-overflow anniversary fee/bin rules, calendar package rules, and once-only inherited charges. Short-term real costs remain annualized; Hybrid totals exclude consumption effects. Reset/supplier annual equivalents use billed energy divided by costed kWh, not snapshot representative weights. Audience VAT is Household/Both/null inclusive and Company excluded; explicit source components and inclusive market curves normalize once before costing. Current collection reuses these outcomes, not a second statistics calculation.
- Shared calculated-cost schema v17 invalidates semantic caches only. It does not run a historical rebuild, rewrite snapshots/annual statistics, or replace stored method evidence. Dated annual metric keys advance at Helsinki midnight. Existing historical method rules remain dated evidence, not current annual fallbacks.
- `phpunit.xml` forces the configured legacy annual-method default for test isolation from local `.env`; AsOf tests opt in through `config()->set()`. Test isolation does not change production method configuration.
- Current canonical Spot `annual_cost` uses the same forward 12-month curve, historical intraday shape (or explicit lower-confidence zero-offset baseload), exact margin, fee, and offers as the public ranking. Historical observed rows keep the trailing-365 Spot level that was known for that date. Use `annual_cost`, not current/day-period `spot_total_energy_price`, for contract-type annual-cost comparisons.
- On `/sahkosopimus/tilastot`, the contract-type **c/kWh** table, deep-dive Spot chart, and top Spot callout remain historical views: trailing-12-month realized daily Spot average + latest typical margin, with p20–p80 calculated from daily prices over the same window. Do not switch those historical unit-price figures to the forward estimate or latest-day Spot. The annual-cost chart and current canonical snapshot are the forward-looking surfaces.
- Weekly/monthly UI aggregates should average daily statistics, not recompute from all contract-day rows, so trend lines are market-day weighted.
- `/sahkosopimus/tilastot` caches its prepared Livewire view data per period + consumption until the next day, with cache keys versioned by the expected current basis, active annual method, and cheap source-table fingerprints. Current cache schema v21 adds the separate dated annual endpoint, retains the coral overall seller-set line, and includes the seller-set index, dominant-method display regime, the exact latest-day endpoint, point-marker modes, the reset-category sufficiency gate, and dated Quarterly rows in the deep dive and both summary tables. The source fingerprint reads only unit rows and the active annual method, so writing inactive annual rows does not invalidate public prepared data.
- After `contracts:calculate-price-statistics` recalculates daily statistics, it queues `contracts:warm-price-statistics-cache` for the default weekly/5 000 kWh page state. The contract post-import coordinator does not call that command; after successful direct statistics it dispatches `WarmContractPriceStatisticsCache` directly for the same state. `spot:fetch` queues the same warmer after spot averages update because spot fingerprints also bust this page cache.
- The warmer builds many segment/date summaries in one job. Keep `ContractPriceStatistics` request/job-scoped batching intact: one `dailyStats` collection, one one-pass segment + metric + consumption index over those rows, memoized period series, one daily spot-average load sliced with native ordered-array loops for rolling windows, and no per-segment latest-row SQL lookups. The daily-statistics query hydrates all `unit_statistics_v1` rows. It hydrates active-method `annual_cost` rows only for the component's selected consumption because the page does not use the other annual consumption rows. The one-pass index and series memoization reduced the 2026-08-07 local production-snapshot cold warm from about 12 seconds / 144 MB RSS to about 3 seconds / 123 MB RSS after production exhausted its 300-second queue timeout.
- One pricing basis owns each newly calculated date. Inside the calculation transaction, a run deletes opposite-basis snapshots for only its target date and replaces snapshots for its own contract set before aggregate calculation. This removes stale snapshots when a later canonical run excludes a contract. It never deletes another date. Its base daily-statistic cleanup is method-scoped to `unit_statistics_v1` and `annual_cost_legacy_v1`. The current canonical adapter separately replaces only v2 annual rows within that transaction; v1 remains unchanged. A feature-off/backfill run takes the same target-date ownership with observed basis.
- Unit panels end on the latest `unit_statistics_v1` date for `PricingMode::expectedContractPriceBasis()`. Annual panels have their own latest eligible active-method date, no later than the unit endpoint, requiring the expected basis or `mixed_evidence`. Retained annual history behind unit collection gets dated historical copy, not today's-price copy. This is an endpoint comparison, not a clock-age rule for ordinary yesterday data. Earlier points retain their dated basis; sample floors and compatibility rules remain. No expected-basis annual endpoint means no fallback to an inactive method or wrong-basis annual data. The one-pass daily index also caches the annual endpoint. Switching back to v1 selects retained dated v1; it does not resume old current computation.
- Every public annual-cost trend uses `AnnualSeriesCompatibility`: mixed weekly/monthly periods and the first point after a method cutover are null, while deltas require the same normalized key. Unit c/kWh aggregation is unchanged.
- The two statistics widgets on `/sahkosopimus/kannattaako-porssisahko` follow the same endpoint rule. They read only the trailing year and only the plotted columns, then cache prepared arrays. Do not restore their former unbounded all-column Eloquent reads: together with the other eager article widgets, those reads exhausted the 128 MB production request limit.

The v2 relational energy guard accepts the raw `Spot` margin type only for an explicit Spot
contract. It still validates the actual first non-monthly row used by the legacy engine; a later
recognized row cannot repair an unknown first surcharge. Non-Spot tariffs do not gain a `Spot`
energy slot. Focused tests: `php artisan test --filter='AsOfAnnualCostCalculatorTest'`.

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
quarterly reset schedules use `quarterly`; the public label is `Kvartaalisähkö`. Monthly,
seasonal, and other reset schedules use `market_reset`; the public label is `Jaksoittain vaihtuva
hinta`. The latter segment's stable membership starts on 2026-08-10. The public statistics page
hides it until its unit or annual surface has at least 30 non-null daily observations and a point on
the current public endpoint. Historical Quarterly rows stay in their own category. Its deep dive and both summary tables can
show at least 30 dated observations without a current row; each dated row states its latest date.
Current quarterly collection continues the same key and a missing full display period
inserts a chart gap.

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
