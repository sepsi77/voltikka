# AGENTS.md

Context for `laravel/app/Services/CompanyStatistics`.

See also:
- `../../Livewire/AGENTS.md` (`CompanyDetail`) for the page that consumes this
- `../ContractStatistics/AGENTS.md` for the tables this reads

## `CompanyMarketComparisonService`

Purpose: place one seller's own contract prices against the whole market, per
contract-type segment, for the `[yhtiö] hinta` / `sähkön hinta` query cluster on
`/sahkosopimus/sahkoyhtiot/{slug}`.

It reads two tables that already exist and calculates no contract prices of its
own:

- `contract_price_daily_statistics` — the market p20 / median / p80 band
- `contract_price_snapshots` — the same fields per contract, with `company_name`

Both are written by `ContractPriceStatisticsService` on the same date by the same
method. The service selects the latest **usable joined date** for
`ContractPriceBasis::expectedCurrent()`: market and seller rows must share date,
segment, basis, consumption, and the minimum market count. Thus a newer
wrong-basis or market-only date cannot replace a usable canonical pair. **Do not
swap either side for a live `ContractPriceCalculator` call.**

When canonical mode has no usable canonical joined date, the service can select
the latest usable `observed_seller_data` joined date. This is an explicitly
historical page fallback, not a current-price fallback: the payload sets
`comparison_state=historical_observed_fallback` and
`is_historical_fallback=true`, and the Blade states its date and that it is not
today's comparison. Current canonical rows always win when usable. The FAQ does
not answer a current-price question from the historical fallback.

A non-historical payload can also carry the small typed `spot_benchmarks`
payload. It reads only the `spot` segment's `spot_margin` and `monthly_fee`
medians from the exact `stat_date` and `pricing_basis` selected above. Each
metric independently requires a numeric median and at least
`MIN_MARKET_CONTRACTS`; unusable metrics are absent. A historical observed
fallback always sets this payload to null. This prevents current canonical
contract charges from being compared with dated observed market rows. In
feature-off mode, current observed contract charges use current observed rows on
the same date.

### The metric is `annual_cost`, not `energy_price`

`energy_price` prices a spot contract at **that day's** spot average plus the
seller's margin. On 2026-07-24 that put spot at 2,16 c/kWh beside a 12-month
fixed contract at 10,47 c/kWh, which is not a comparison. `annual_cost` uses the
trailing-365-day spot average for spot, which is what
`../ContractStatistics/AGENTS.md` names as the metric for cross-type comparison.

Cost of that choice: `annual_cost` exists only for 2 000 / 5 000 / 18 000 kWh
(`REFERENCE_CONSUMPTIONS`), and the company page also offers 10 000 kWh. So a
selected consumption is **snapped** to the nearest reference by
`snapConsumption()` and the page states which figure it used. Same reasoning as
`ContractDetail::rankConsumption()`: a market-wide reference for a free value
would mean recomputing the whole market per visitor.

### Two guardrails that exist because of real bad data

- **`MIN_MARKET_CONTRACTS = 10`.** A p20-p80 band over a handful of contracts is
  noise. `/sahkosopimus/tilastot` hides such rows and this page agrees.
- **The energy-rate guard applies only to `observed_seller_data`.** In old
  relational snapshots, a missing energy rate could leave only the standing
  charge in `annual_cost_*`, so the contract read as a 59 EUR/year product. The
  guard still requires `energy_price_cents_per_kwh > 0` for those rows.
  A `canonical_calculation` annual total is complete on its own and can validly
  have a null unit rate, for example a canonical-only contract or an energy
  package. The seller comparison must accept it and match snapshots to the
  market row's `pricing_basis`; otherwise a relational guard drops a valid
  canonical annual comparison.
- **An observed seller snapshot needs `energy_price_cents_per_kwh <= 50`**
  (`MAX_PLAUSIBLE_ENERGY_PRICE_CENTS`). This is not redundant with the shared
  cleaner: `ContractPriceStatisticsService::cleanValues()` applies the 50 c/kWh
  ceiling to the **`energy_price`** metric, while this service reads
  **`annual_cost`**, whose ceiling is 50 000 EUR. A broken import therefore
  passes straight through. Vaasan Sähkö's "Kiinteä 12 kk (yösähkö)" was ingested
  at 585,46 c/kWh on 13 days in February 2026, giving a 39 724 EUR/year
  snapshot; with only a few contracts in that segment it drew a spike on the
  trend chart 60 times the real price.

  **This is a filter, not a repair, and it must stay that way.** The real price
  is not recoverable: 932 c/kWh is the only `DayTime` value that contract ever
  had, its last price row is 2026-02-18 so it is long off the market, and it has
  no `contract_source_snapshots` row to re-parse. 9,32 c/kWh is a plausible
  guess and a guess must never be written back. Do not "correct" such rows.

  Those rows are also inside the market `annual_cost` aggregates for those
  dates, and that is **fine by design**. p20 / median / p80 absorb one bad row
  out of 49, which is precisely why the statistics publish percentiles instead
  of min and max (`../ContractStatistics/AGENTS.md` records the same reasoning
  for the consumption table). Nothing on any page reads `min_value` or
  `max_value`, so no display is affected.

  **The seller side gets no such protection, and that is why this filter
  exists.** Percentiles are robust because the market segment holds tens of
  contracts. A seller holds far fewer: on 2026-07-24, 87 of 136
  company-and-segment pairs held 2 contracts or fewer, and 55 held exactly one.
  At n = 1 the median *is* the bad row, and at n = 2 it is its average with one
  good row. No robust statistic can save a sample that small, so an impossible
  value has to be dropped before it is used.

### Track geometry is calculated here, not in Blade

`trackGeometry()` returns `band_left_percent`, `band_width_percent`,
`median_percent` and `marker_percent`, so
`resources/views/partials/company-market-comparison.blade.php` stays
presentation-only. Same rule as the signed spot bars on `/spot-price`.

The domain comes from the **p20-p80 spread**, not from the market min and max.
Those two columns carry exactly the broken rows the guard above removes on the
seller side (hybrid min 49 EUR, open-ended max 1 340 EUR on 2026-07-24), so a
min-max track would draw a dishonest scale. Padding by 0,6 of the spread leaves
the band about 45 % of the track; the first version padded by 8 % and the band
covered 86 % of the track, which read as a plain line rather than a range. The
domain is always widened to contain the seller's own value, so a contract
cheaper than p20 or dearer than p80 still has a visible marker.

### The trend chart

`buildChart()` returns the payload shape that
`resources/js/contract-price-statistics.js` already renders, **band included**,
so this feature added no chart renderer. Weekly aggregation over a trailing 365
days, matching `ArticleContractPriceComparisonChart`.

The fallback chart uses only observed points through its dated endpoint. A
canonical chart still combines older observed history with canonical points from
the first canonical date. In both cases:

- **The segment is chosen by `CHART_SEGMENT_PREFERENCE`, and
  `fixed_term_12` leads it.** Määräaikainen 12 kk is the type a visitor
  comparing sellers shops for: a known price for a known term. The ladder then
  falls to `fixed_term_24` and `fixed_term_6`, the only other fixed terms with a
  market wide enough to reference (49 and 20 contracts; 13-23 kk and yli 24 kk
  have 2 and 1 and never clear `MIN_MARKET_CONTRACTS`). A seller with no
  fixed-term product falls through to the largest market segment it does sell.

  Live split on 2026-07-24: 21 sellers on 12 kk, 2 on 24 kk, 8 on
  Toistaiseksi voimassa oleva, 4 on Pörssisähkö.

  **Two earlier rules were wrong and must not come back.** Choosing the seller's
  *own* largest segment put the seller inside its own reference: Vaasan Sähkö
  holds 5 of 13 Kvartaalisähkö contracts, so the market median was largely its
  own line and the chart drew one line where two were expected. Choosing the
  largest *market* segment alone gave every one of the 35 sellers either
  `open_ended` (62 contracts) or `spot` (59), so no seller ever got a fixed-term
  chart, and `open_ended` is the most dispersed segment on the market
  (p20 448 EUR, p80 754 EUR at 5 000 kWh), which is where a median says least.
  The preference ladder keeps the overlap risk small on its own: no seller holds
  more than a few of the 49 / 49 / 20 contracts in the preferred segments.
- **`leadStroke` is set to coral on purpose.** The shared renderer draws the lead
  series in slate-800 and the first non-lead series in slate-800 too, so
  "this seller" and "market median" rendered as one navy line. `leadStroke` is an
  additive opt-in in that JS; a payload that omits it keeps the previous
  behaviour exactly, so `/sahkosopimus/tilastot` and the article chart are
  unchanged.
- A seller-only week is dropped: its line would sit over an empty band.

### Caching

Cached for 6 hours under key schema v5: canonical flag + expected basis + company
+ snapped reference consumption + a fingerprint of the two source tables'
newest dates and maximum `updated_at`. In canonical mode the fingerprint includes
both canonical and observed bases because either can own the payload. A same-day
rewrite therefore creates a new key, and a flag flip cannot serve an
opposite-basis payload.
Skipped under `runningUnitTests()`, like the page-level caches, to avoid
array-driver pollution across tests.

**`selected_consumption` and `is_snapped` are added after the cache read, never
stored.** The cache key carries only the snapped reference, so storing them let a
10 000 kWh visitor be served a payload claiming 5 000 kWh was the selection and
that nothing had been snapped. That bug was live during development.

The trailing chart keeps observed seller dates before canonical forward
collection starts, then accepts only canonical points from the transition date.
The visible chart copy states this provenance, and an observed row on the same or
a later date cannot replace the canonical current point.

Tests: `tests/Feature/CompanyDetailSectionsTest.php`.
