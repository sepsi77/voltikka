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
- **`ContractPriceStatisticsService::segmentKey()` is the one classifier** and
  `SEGMENT_LABELS` the one label map. Do not re-derive a segment here; the
  overlay must describe the same market `/sahkosopimus/tilastot` aggregates.
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
- **Point markers are dropped past 12 change points.** Near-daily repricers exist
  (Lumme Vuosisähkö 6 kk moved 45 times in 3 months across its replacement
  chain) and 45 dots turn the line into a smear.

## Known input caveat

`priceHistory` is merged across the **backward replacement chain**, so the series
can span several contract IDs of one lineage. That is deliberate: sellers
republish a fixed-term offer as a new contract row on every reprice, and the
lineage is the product. It also means the change count describes the lineage,
not one database row.
