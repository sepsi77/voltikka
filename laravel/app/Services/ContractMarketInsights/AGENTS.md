# Contract market insights

Small comparison-page teasers that reuse precomputed market data.

Primary file:
- `ContractMarketInsightService.php` — builds compact trend and forecast payloads for contract comparison heroes.

## Purpose

These insights are low-prominence brand/context signals on comparison pages. They should help users see that Voltikka has market intelligence without pushing contract results down or changing ranking.

## Performance guardrails

- Do not calculate contract prices during page requests.
- Read only precomputed aggregate tables:
  - `contract_price_daily_statistics` for 30-day annual-cost trends
  - `fixed_contract_price_forecasts` for fixed-term directional forecast teasers
- Cache insight payloads until tomorrow and include a cheap source-data fingerprint in the key. The fingerprint and payload key use the canonical state and expected basis from request-scoped `PricingMode`; the statistics fingerprint reads dates and `updated_at` only for the relevant bases. The payload key also includes the configured forecast model version; bump the cache schema after changing public eligibility. Current schema is v5.
- Public listing prepared-data cache keys include the same fingerprint, so cached page payloads can refresh after daily statistics/forecast updates without raw recalculation.
- The fingerprint itself is cached briefly and uses only `max(...)` aggregate queries.

## Product behavior

- Show insights on `/sahkosopimus`, SEO pricing/duration comparison pages, and `/sahkosopimus/halvin-sahkosopimus`.
- Exclude business, housing-type, energy-source, and consumption-level pages.
- Use a matching segment when available:
  - pörssisähkö -> `spot`
  - joustosähkö -> `hybrid`
  - kvartaalisähkö -> `quarterly`
  - määräaikainen -> `fixed_term_12`
  - toistaiseksi -> `open_ended`
- Use aggregate weighted-by-contract-count trend for the main and cheapest pages, and as fallback for pricing pages without their own segment.
- The latest trend point must use the basis expected by the canonical flag. Canonical mode compares it with an older dated observed seller point and exposes both bases plus visible provenance copy. Feature-off uses observed basis for both. A newer or same-date wrong-basis row never becomes the current point.
- Forecast teaser is fixed-term only, based on the latest eligible 12-month median forecast row, and is directional only. It uses the same public eligibility scope as the full forecast page: configured model version plus canonical current-input provenance in canonical mode, or observed current-input provenance in feature-off mode. Old and missing-provenance rows cannot become a current teaser.
- Hide the component entirely when there is no relevant precomputed data.
- Insights are informational only; never feed them into ranking, sorting, or filtering.
