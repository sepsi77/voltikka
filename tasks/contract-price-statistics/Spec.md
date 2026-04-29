# Contract price statistics page

Goal: create a statistics page that shows how active electricity contract prices develop over time, based primarily on actual contract prices imported from the Azure consumer API. Spot contracts may additionally use stored spot-price history so their total energy price can be compared realistically against other contract types.

Requested metrics:
- Spot contract margins.
- Spot total energy price = stored spot market energy price + contract margin.
- Energy price and fixed monthly fee (`perusmaksu`) by contract group:
  - fixed-term (`FixedTerm`) subdivided by `fixed_time_range`
  - hybrid / joustosähkö (`pricing_model = Hybrid`)
  - quarterly / kvartaalisähkö (same pattern matching as SEO/list filters)
  - open-ended / toistaiseksi voimassa oleva (`contract_type = OpenEnded`)
- Calculated annual prices at consumption levels 2000, 5000, and 18000 kWh/year.
- For calculated prices, expose range statistics such as cheapest, 20th percentile, average, and 80th percentile.

Storage/cadence idea:
- Calculate daily immediately after `contracts:fetch`, using only contracts in `active_contracts` at that time.
- For historical backfill, infer active contracts for date D from contracts that have `price_components.price_date = D`.
- Store immutable daily per-contract observations and/or daily aggregate statistics so later weekly/monthly views do not depend on current active contracts.
- Weekly and monthly UI views aggregate from the daily stored data.
