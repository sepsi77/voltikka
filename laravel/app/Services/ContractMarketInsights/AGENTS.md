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
- Cache insight payloads until tomorrow and include a cheap source-data fingerprint in the key.
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
- Forecast teaser is fixed-term only, based on latest 12-month median forecast row, and is directional only.
- Hide the component entirely when there is no relevant precomputed data.
- Insights are informational only; never feed them into ranking, sorting, or filtering.
