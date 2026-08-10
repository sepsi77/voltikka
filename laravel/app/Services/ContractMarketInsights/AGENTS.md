# Contract market insights

Small comparison-page teasers that reuse precomputed market data.

Primary file:
- `ContractMarketInsightService.php` — builds compact trend and forecast payloads for contract comparison heroes.

## Purpose

These insights are low-prominence brand/context signals on comparison pages. They should help users see that Voltikka has market intelligence without pushing contract results down or changing ranking.

## Performance guardrails

- Do not calculate contract prices during page requests.
- Read only precomputed aggregate tables:
  - `contract_price_daily_statistics` for broad 30-day annual-cost trends and exact-duration offered-energy-price trends
  - `fixed_contract_price_forecasts` for fixed-term directional forecast teasers
- Cache insight payloads until tomorrow and include a cheap source-data fingerprint in the key. The fingerprint and payload key use the configured active annual method, canonical state, and expected basis from request-scoped `PricingMode`; the statistics fingerprint includes max dates and `updated_at` values for both active annual rows and null-consumption `energy_price` unit rows. The payload key also includes the configured forecast model version and exact duration. Current payload schema is v10 and fingerprint schema is v5. v10 invalidates cached v9 Finnish forecast labels after the neutral copy changed from `Vakaata` to `Vakaa`.
- Public listing prepared-data cache keys include the same fingerprint, so cached page payloads can refresh after daily statistics/forecast updates without raw recalculation. Their outer schemas are `contracts-list:view-data:v3` and `seo-contracts-list:view-data:v4`; bump them when a code-only insight membership change can leave the fingerprint unchanged.
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
- Use aggregate weighted-by-contract-count trend for the main and cheapest pages, and as fallback for pricing pages without their own segment. The aggregate includes both current `market_reset` and persisted historical `quarterly` keys; each date still follows its stored basis and key. Aggregate endpoints build a deterministic map by segment and compare only the intersection whose segment-specific key is unchanged. Different segments do not need to share one global key. Both weighted endpoints use the same included segment set.
- The latest trend point must use the basis expected by the canonical flag and the configured active annual method. A 30-day comparison requires the same non-null or null `compatibility_key`; it never crosses keys. Canonical mode normally compares it with an older dated observed seller point and exposes both bases plus visible provenance copy. `market_reset` has no matching observed rows by design, so it waits for a 30-day-old canonical point and compares canonical with canonical instead of relabelling old quarterly/open-ended evidence. Quarterly cadence keeps the `quarterly` key and can use its own dated observed history when compatibility also matches. Feature-off uses observed basis for both. A newer or same-date wrong-basis row never becomes the current point.
- Exact 6/12/24 month pages read `unit_statistics_v1` `energy_price` rows with null consumption, positive contract count, the exact duration segment, and the current basis from `PricingMode`. The newest eligible median is compared with the newest matching point at least 30 days older. Canonical mode uses dated observed seller history for that older point; feature-off stays observed. No other duration is a fallback. Payloads keep both medians, both dates, count, bases, segment, and duration.
- Forecast teaser is fixed-term only and directional. Broad fixed-term callers keep the old 12-month default. Exact pages query the latest eligible median forecast for their own 6, 12, or 24 month duration and label it with that duration. It uses the same public eligibility scope as the full forecast page: configured model version plus canonical current-input provenance in canonical mode, or observed current-input provenance in feature-off mode. Old and missing-provenance rows cannot become a current teaser.
- Trend and forecast unavailable states are independent. Hide the component entirely only when both are unavailable.
- The hero component dates each trend and forecast cell separately. Do not replace them with one shared newest date, because one source can be older. The contract-count footer is explicitly scoped to hintakehitys and must not imply that the same sample count produces the forecast.
- Forecast direction labels use idiomatic Finnish: `Nousussa`, `Laskussa`, and `Vakaa`. The neutral headline is `Ennuste: vakaa hintataso`; do not use the fragment `Vakaata` as a standalone label.
- Insights are informational only; never feed them into ranking, sorting, or filtering.
