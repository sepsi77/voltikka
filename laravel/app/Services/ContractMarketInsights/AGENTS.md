# Contract market insights

Small comparison-page teasers that reuse precomputed market data.

Primary file:
- `ContractMarketInsightService.php` — builds compact trend and forecast payloads for contract comparison heroes, the fixed-term SEO guide, and the aggregate fixed-term decision article.

## Purpose

These insights are low-prominence brand/context signals on comparison pages. They should help users see that Voltikka has market intelligence without pushing contract results down or changing ranking.

## Performance guardrails

- Do not calculate contract prices during page requests.
- Read only precomputed aggregate tables:
  - `contract_price_daily_statistics` seller-set index rows for the broad 30-day trend
  - `contract_price_daily_statistics` offered-energy-price rows for exact-duration trends
  - `fixed_contract_price_forecasts` for fixed-term directional forecast teasers
- Cache insight payloads until tomorrow and include a cheap source-data fingerprint in the key. The fingerprint and payload key use the configured active annual method, canonical state, and expected basis from request-scoped `PricingMode`; the fingerprint also includes the seller-set index endpoint. The payload key includes the configured forecast model version and exact duration. Current hero payload schema is v13 and fingerprint schema is v7. v13 adds stored numeric forecast facts. Fingerprint v7 also tracks active-method `mixed_evidence` annual rows used by the article's price-of-certainty comparison.
- `fixedTermComparison()` has its own `fixed-term-offered-price-comparison-v2` payload version. Its cache key also includes the shared fingerprint and expected basis, and it expires tomorrow. v2 checks candidate common dates in newest-first order, so malformed newest rows do not hide an older eligible common date.
- `fixedTermArticle()` is the one prepared payload for `/sahkosopimus/kannattaako-maaraaikainen`. Article schema v4 adds decision summaries, shared clean-number chart ticks, and history/forecast direction fields. It keeps the v3 removal of the invalid cross-segment annual compatibility gate. It expires tomorrow and includes the same fingerprint, expected basis, and configured forecast model in its cache key. It reads only `contract_price_daily_statistics` and `fixed_contract_price_forecasts`; it never loads contracts or calculates prices.
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
- The fixed-term decision article has a separate canonical-only current comparison. It requires `open_ended`, `fixed_term_6`, `fixed_term_12`, and `fixed_term_24` on one newest eligible date under the same unit-statistics range and count rules. Canonical `open_ended` has a published current fixed rate because the classifier has removed Spot, reset, and Hybrid products, but the seller can change that rate later under its terms. Feature-off mode returns no article current comparison rather than relabel observed mixed history. The three-duration `fixedTermComparison()` payload stays unchanged for the SEO guide.
- The article's 5,000 kWh annual comparison reads only active-method `annual_cost` rows for `open_ended` and `fixed_term_12` on one common eligible date. Rows need ordered finite p20/median/p80 values and at least 10 contracts. Expected-basis rows are accepted. `mixed_evidence` is accepted only on the latest expected-basis unit endpoint, matching statistics-page semantics. Different same-date segment estimator methods do not block the result: compatibility keys guard time-series continuity, not a current cross-segment comparison. Feature-off mode fails closed. The payload includes yearly and monthly median differences, cheaper/equal direction, and a small-difference flag. Small means at most 5 €/month or 5% of the lower median so later data cannot keep close-price copy when the gap becomes material.
- The current comparison also returns a median-price ranking plus lowest/highest fixed durations. Blade interpretation uses those fields instead of assuming that 24 months stays lowest or 6 months stays highest.
- Article history reads at most the trailing 12 months of fixed 6/12/24 unit rows only and averages valid daily p20/median/p80/count points by week. In canonical mode, canonical evidence wins over observed evidence before validation when both exist on one date. Feature-off mode reads observed evidence only, so shadow canonical rows cannot leak into a public legacy-basis page. Open-ended stays out because old observed classification is not comparable. The service expands all three series to one nice-number scale with 4–6 shared ticks. Each tick includes its vertical percentage for HTML-axis/SVG-grid alignment. Each series includes first/last median facts and a rose/fell/stable direction; changes within 0.05 c/kWh are stable. Disclosure tables expose every plotted value.
- Article forecasts use only the latest 30-day date accepted by `eligibleForPublicDisplay()` for the request's expected basis and configured model. A duration is unavailable unless p20/median/p80 rows are all present, finite, ordered for both current and forecast values, positive in sample size, and on one target date. Old models and wrong-basis rows never supply an article forecast. The payload summarizes available median changes as down when all are negative, up when all are positive, stable when all are effectively zero, and mixed otherwise.
- Forecast teaser is fixed-term only and directional. Broad fixed-term callers keep the old 12-month default. Exact pages query the latest eligible median forecast for their own 6, 12, or 24 month duration and label it with that duration. It uses the same public eligibility scope as the full forecast page: configured model version plus canonical current-input provenance in canonical mode, or observed current-input provenance in feature-off mode. Old and missing-provenance rows cannot become a current teaser. The payload includes the stored current median, forecast median, expected c/kWh change, horizon days, and contract count; consumers do not recalculate these model facts.
- Trend and forecast unavailable states are independent. Hide the component entirely only when both are unavailable.
- The hero component dates each trend and forecast cell separately. Do not replace them with one shared newest date, because one source can be older. The contract-count footer is explicitly scoped to hintakehitys and must not imply that the same sample count produces the forecast.
- Forecast direction labels use idiomatic Finnish: `Nousussa`, `Laskussa`, and `Vakaa`. The neutral headline is `Ennuste: vakaa hintataso`; do not use the fragment `Vakaata` as a standalone label.
- Insights are informational only; never feed them into ranking, sorting, or filtering.
