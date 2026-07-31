# AGENTS.md

Context for the contract detail page's "Näin hinta on kehittynyt" module.

See `../AGENTS.md` for the service-subtree rules and `../../Livewire/AGENTS.md`
for how `ContractDetail` renders this payload.

## Purpose

`PriceDevelopmentPresenter` builds one server-rendered SVG chart, a small
seller-behaviour fact record, and the copy that scopes both. It is consumed by
`ContractDetail::getPriceDevelopmentProperty()` and rendered by the
`#hintakehitys` section of `resources/views/livewire/contract-detail.blade.php`
plus `resources/views/partials/contract-version-timeline-item.blade.php`.

Everything it returns is **consumption-independent** by construction: it
describes the contract's own observed c/kWh prices and the market around them.
That is why it is safe inside the detail page's prepared view-data cache, and
why the section needs no "5 000 kWh:lla" scope sentence.

## Two variants, because the honest question differs

| `pricing_model` | Ink line | Dashed reference |
|---|---|---|
| anything but `Spot` | the contract's own observed energy price, **stepped** | median of its `contract_price_daily_statistics` segment (`metric_key = energy_price`, `consumption_kwh IS NULL`) |
| `Spot` | realized **monthly** market average (`spot_price_averages`, `period_type = monthly`) plus this contract's margin | trailing-12-month average (`rolling_365d`) plus the same margin |

A spot contract's own price history is just its margin, which is almost always
flat and teaches nothing. Volatility is the fact a spot buyer needs, so the spot
variant plots the market instead. This follows the approved
`tasks/contract-detail-overhaul/mockups/rank1-spot.html`.

## Decisions that must not be undone casually

- **The ink line is `#0f172a` (slate-900) and the reference is a dashed
  `#64748b` (slate-500) with a direct end label. Coral is never a data series** —
  it is reserved for actions and warnings. This was a review outcome, not taste.
- **Every delta is c/kWh or €/kk, never a percentage.** Two consecutive `-8 %`
  rows read as a copy-paste bug in the mockup review.
- **The contract line is stepped.** A published price holds until the next
  observation; sloping between two known prices draws prices the seller never
  charged.
- **The contract series uses the same representative-energy weighting as
  `ContractPriceStatisticsService::representativeEnergyPrice()`** (General, else
  day/night 15:9, else seasonal 5:7). A time-metered contract charted on
  `DayTime` alone would sit above a median that blends day and night, and the
  overlay would be a lie about the gap.
- **`ContractStatisticsSegmentClassifier` is the one classifier** and owns the one
  `SEGMENT_LABELS` map. The presenter classifies with
  `PricingMode::expectedContractPriceBasis()`: a current canonical reset overlays persisted
  `market_reset` rows, while feature-off uses the observed text rule. Do not re-derive a
  segment here.
- **The median query uses the public statistics endpoint rule.** Older dates can keep their
  stored historical basis. On the latest expected-basis date only that basis is accepted,
  and newer opposite-basis rows are excluded. Segment keys are never translated: older
  observed `quarterly` or `open_ended` rows do not become canonical `market_reset`; an
  overlay for that segment starts with rows actually persisted under `market_reset`.
- **A window shorter than `MIN_TRACKING_DAYS` (21) renders a message, not a
  chart**, and produces no behaviour tags at all. A flat line through two
  observations claims stability that has not been observed.
- **The spot variant needs `MIN_SPOT_MONTHS` (3) completed months** and excludes
  the running month, whose average is partial.
- **A null `spot_price_margin` is left out, never guessed.** On a promo-then-spot
  contract (Cheap Markkinahintasähkö) the tracked `General` component is a flat
  intro price, not a margin; adding it would invent a price. When the margin is
  unknown the chart plots the bare market average and the note says so, and the
  "Marginaali ennallaan" tag is suppressed because the tracked component is not
  the margin.
- **Change points and the observation window are separate.** `changeSeries()`
  returns the real changes plus `last_date`; `plateauPoints()` adds the terminal
  point only for drawing. Counting the terminal plateau as a change made the
  behaviour record claim a price move that never happened.
- **A 0,00 c/kWh energy observation is dropped when the same component type is
  priced above zero on another observed date** (`withoutCollidedZeroEnergyPrices()`).
  This is a display guard against a known ingestion artifact, not a rule that
  zero cannot be a price. The upstream payload can send two `General` components
  with the null UUID and the same fuse size, one real and one zero; they collapse
  to one relational key, and before `ContractInterpretation\CanonicalPriceComponentWriter`
  learned to select the positive row (2026-07-25) the zero could win the day's
  upsert. Nine active contracts (eight Vaasan Sähkö Vaikuttaja variants plus
  Herrfors Vakaa+) still carry false zeros on 23.–24.7.2026, and the chart drew
  them as a vertical drop to zero while the version timeline underneath kept
  showing the real price, because every other surface already prefers a positive
  row. **The stored rows are still wrong**; repairing them from the immutable
  source snapshots is a production mutation and needs explicit confirmation.
  Two exclusions are deliberate: **spot contracts are skipped entirely** (their
  tracked component is the margin, and a 0 c/kWh margin is a real commercial
  position), and **`Monthly` is skipped** (dropping a base fee to 0 €/kk is an
  ordinary seller move). A genuinely zero-priced package component survives
  because it is zero on *every* observed date: Helen Helpposähkö, Väre
  Kuukausisähkö and Vattenfall Ilmasto Vakio charge nothing per kWh and still
  chart a flat 0,00 line.
- **Point markers are dropped past 12 change points.** Near-daily repricers exist
  (Lumme Vuosisähkö 6 kk moved 45 times in 3 months across its replacement
  chain) and 45 dots turn the line into a smear.

## Known input caveat

`priceHistory` is merged across the **backward replacement chain**, so the series
can span several contract IDs of one lineage. That is deliberate: sellers
republish a fixed-term offer as a new contract row on every reprice, and the
lineage is the product. It also means the change count describes the lineage,
not one database row.

### Repairing the collided rows

The display guard above hides the artifact; it does not fix the stored data.
`contracts:repair-price-component-collisions` does that, rebuilding each poisoned
row from the `contract_source_snapshots` payload that was in observation on its
date, through `ContractInterpretation\CanonicalPriceComponentWriter::resolveRows()`
so a repaired row is byte-identical to what a correct import would have written.

- **Dry run by default**; `--apply` is the only thing that writes, inside one
  transaction. `--contract=` and `--date=` narrow the scope.
- **Evidence, never inference.** A row with no covering snapshot, no positive
  candidate in the payload, or a resolved type that disagrees with the stored row
  is reported and skipped. Nothing is ever filled in from a neighbouring day.
- A storage key that is non-positive on *every* observed date is a real
  zero-priced component and is never a candidate.
- Page caches hold the old series, so run `php artisan cache:clear` afterwards.

**Keep the display guard after the data is repaired.** It costs nothing on clean
data, and it is the only thing standing between a future ingestion regression and
a published price chart that crashes to zero.
