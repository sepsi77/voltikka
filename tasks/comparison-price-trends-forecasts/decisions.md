# Decisions

- Integrate market trend/forecast context only on contract comparison pages, excluding housing-type and energy-source SEO pages.
- Use the segment matching the comparison page's contract type when available.
- Main `/sahkosopimus` page should show an aggregate 30-day trend summary such as "Sähkösopimukset ovat kallistuneet/halventuneet".
- Forecast copy should be directional only: prices likely to rise, fall, or remain steady.
- Placement should be low-prominence: small cards or pills in/near the hero area, with links to price trend and forecast pages.
- These signals are informational only and must not affect ranking.
- Use last 30 days for trend direction.
- Avoid full charts; no chart or at most a very small sparkline.
- Do not add uncertainty caveats in this comparison-page teaser.
- Safer MVP first: relevant SEO comparison pages plus main comparison page, not every possible surface.

## Implementation notes

- Added `App\Services\ContractMarketInsights\ContractMarketInsightService` to build small cached payloads from precomputed aggregate tables only.
- Aggregate trend uses weighted average of segment median annual costs, weighted by each segment's `contract_count`.
- Trend lookback compares latest available statistic date to the latest statistic date at least 30 days earlier.
- Consumption values are mapped to the nearest statistics levels (2 000, 5 000, 18 000 kWh) so 10 000 kWh UI presets can still show precomputed context without request-time price calculation.
- Forecast pill is fixed-term only and uses the latest 12-month median fixed-term forecast row.
- Public listing cache keys include the market-insight fingerprint so daily statistics/forecast updates can refresh prepared page data.
