# Contract market insights

Small comparison-page teasers that reuse precomputed market data.

Primary file:
- `ContractMarketInsightService.php` — builds compact trend and forecast payloads for contract comparison heroes and the fixed-term SEO guide.

## Purpose

These insights are low-prominence brand/context signals on comparison pages. They should help users see that Voltikka has market intelligence without pushing contract results down or changing ranking.

## Performance guardrails

- Do not calculate contract prices during page requests.
- Read only precomputed aggregate tables:
  - `contract_price_daily_statistics` seller-set index rows for the broad 30-day trend
  - `contract_price_daily_statistics` offered-energy-price rows for exact-duration trends
  - `fixed_contract_price_forecasts` for fixed-term directional forecast teasers
- Cache insight payloads until tomorrow and include a cheap source-data fingerprint in the key. The fingerprint and payload key use the configured active annual method, canonical state, and expected basis from request-scoped `PricingMode`; the fingerprint also includes the seller-set index endpoint. The payload key includes the configured forecast model version and exact duration. Current hero payload schema is v13 and fingerprint schema is v6. v13 adds stored numeric forecast facts.
- `fixedTermComparison()` has its own `fixed-term-offered-price-comparison-v1` payload version. Its cache key also includes the shared fingerprint and expected basis, and it expires tomorrow.
- Public listing prepared-data cache keys include the same fingerprint, so cached page payloads can refresh after daily statistics/forecast updates without raw recalculation. Their outer schemas are `contracts-list:view-data:v5` and `seo-contracts-list:view-data:v9`; bump them when a code-only insight membership or copy change can leave the fingerprint unchanged.
- The fingerprint itself is cached briefly and uses only `max(...)` aggregate queries.

## Product behavior

- Show insights on `/sahkosopimus`, SEO pricing/duration comparison pages, and `/sahkosopimus/halvin-sahkosopimus`.
- Exclude business, housing-type, energy-source, and consumption-level pages.
- Use a matching segment when available:
  - pörssisähkö -> `spot`
  - joustosähkö -> `hybrid`
  - kvartaalisähkö -> `quarterly` in both modes
  - broad määräaikainen -> `fixed_term_12`
  - exact 6/12/24 month pages -> `fixed_term_6`, `fixed_term_12`, or `fixed_term_24`
  - toistaiseksi -> `open_ended`
- The main and cheapest pages, and pricing-page aggregate fallbacks, use `seller_set_energy_price_index_v1`. This is a canonical-only, consumption-independent index of direct General c/kWh rates. It excludes Spot, Time, Season, packages, and Hybrid from the overall value. It requires fixed-term, open-ended, and market-reset family values under one fixed weight map. `SellerSetEnergyPriceIndexService` owns the population and persistence rules.
- The broad teaser uses the newest persisted overall row and only the row on the exact Helsinki calendar date 30 days earlier. Both rows must have the same method, calculation basis, estimate basis, and compatibility key. It never searches for a nearby date, reads annual cost, uses observed rows, or appears in feature-off mode. Its footer states both eligible offer and supplier counts.
- Segment-specific annual-cost trends keep their strict compatibility behavior. `market_reset` waits for an older canonical point instead of relabelling old quarterly/open-ended evidence. Quarterly cadence keeps the `quarterly` key and can use its own dated observed history when compatibility also matches.
- Exact 6/12/24 month pages read `unit_statistics_v1` `energy_price` rows with null consumption, positive contract count, the exact duration segment, and the current basis from `PricingMode`. The newest eligible median is compared with the newest matching point at least 30 days older. Canonical mode uses dated observed seller history for that older point; feature-off stays observed. No other duration is a fallback. Payloads keep both medians, both dates, count, bases, segment, and duration.
- The fixed-term SEO figure reads only `unit_statistics_v1` `energy_price` rows for `fixed_term_6`, `fixed_term_12`, and `fixed_term_24`. These rows contain fully fixed products. The shared statistics classifier puts Hybrid and recurring market-reset products in separate segments. The figure uses the newest date where all three fixed segments have the expected basis, null consumption, non-null p20/median/p80, ordered finite values, and at least 10 contracts. It returns no figure rows when this common dataset does not exist. Its percentages use one scale from the lowest p20 to the highest p80. It does not query price components or calculate contract prices.
- Forecast teaser is fixed-term only and directional. Broad fixed-term callers keep the old 12-month default. Exact pages query the latest eligible median forecast for their own 6, 12, or 24 month duration and label it with that duration. It uses the same public eligibility scope as the full forecast page: configured model version plus canonical current-input provenance in canonical mode, or observed current-input provenance in feature-off mode. Old and missing-provenance rows cannot become a current teaser. The payload includes the stored current median, forecast median, expected c/kWh change, horizon days, and contract count; consumers do not recalculate these model facts.
- Trend and forecast unavailable states are independent. Hide the component entirely only when both are unavailable.
- The hero component dates each trend and forecast cell separately. Do not replace them with one shared newest date, because one source can be older. The contract-count footer is explicitly scoped to hintakehitys and must not imply that the same sample count produces the forecast.
- Forecast direction labels use idiomatic Finnish: `Nousussa`, `Laskussa`, and `Vakaa`. The neutral headline is `Ennuste: vakaa hintataso`; do not use the fragment `Vakaata` as a standalone label.
- Insights are informational only; never feed them into ranking, sorting, or filtering.
