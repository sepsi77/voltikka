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

## 2026-08-11 investigation: one-contract aggregate trend

- The production payload is arithmetically consistent with the service, but it is not a valid broad market signal. It compares only the `fixed_term_over24` segment: one current contributor at €518.4914 versus one observed contributor at €478.05, which produces the shown +8.5% and `contract_count = 1`.
- Current production has hundreds of annual-cost contributors. The aggregate collapses because `compatibleAggregateRows()` keeps only segment keys present on both endpoint dates with an identical strict stored `compatibility_key`. Most 12 July observed aggregates contain mixed relational/canonical estimate methods, while 11 August canonical aggregates use current canonical methods. Their strict keys differ. `fixed_term_over24` is the sole common segment whose key stayed the same.
- This is not stale HTML or a bad database count. The service intentionally exposes the surviving compatible count, and the Blade component has no minimum sample guard.
- Do not relax strict compatibility to manufacture a trend across different pricing methods.
- **Retracted follow-up:** a minimum compatible-contract count on the old weighted-segment-median method is not sufficient. It would still measure a changing mix of products instead of like-for-like price movement.

## Replacement method decision

- Replace the broad aggregate with a precomputed, bilateral matched-lineage household offer price index at 5,000 kWh. It measures the same trusted product lineages at the latest complete date and exactly 30 Helsinki calendar days earlier.
- Use canonical phase-aware annual cost: energy plus recurring fees, VAT included, distribution excluded. Require positive finite values, the active AsOf method, one strict compatibility regime, the same provider, and the same stable economic family at both endpoints. Current offers must be household/Both and proven nationally available.
- Stable families are Spot, market-reset, fixed terms of at least 12 months, and open-ended fixed/supplier-adjusted offers. Exclude Hybrid because its annual total omits unknown consumption effect. Exclude fixed terms below 12 months because annualizing them does not create a purchasable 12-month offer.
- Match exact IDs first and then trusted persisted replacement links. Never run fuzzy matching in the index. Ambiguous branching lineages fail closed.
- Calculate each lineage log price relative. Collapse duplicate offers with a median per provider/family, then a median across providers in each family. Combine family changes with fixed, versioned family weights derived once from distinct nationally available provider coverage on a declared basket date. Do not use changing daily contract counts or renormalize around a missing family.
- Require all fixed-weight families, at least 5 matched providers and 10 lineages per family, at least 60% provider coverage and 50% lineage coverage at both endpoints, and at least 15 providers and 50 lineages overall. Persist an unavailable typed result when a gate fails. Never carry forward, use an older comparison date, or fall back to the old aggregate.
- Persist available and unavailable index rows in a dedicated versioned table during canonical daily statistics calculation. The comparison page reads only this precomputed row. Visible copy must call it a national household **offer-price** trend and state the 5,000 kWh annual-cost basis, provider count, lineage count, and family count.
- A production-data experiment confirms that matching can produce a broad signal: the simpler provider-balanced calculation over currently compatible national lineages gave about +10.4% across Spot, fixed, and Hybrid evidence, with 136 matched lineages. This is diagnostic only, not the final index: the final method excludes Hybrid and must stay unavailable until all fixed families pass their coverage gates.

## 2026-08-11 direction change: seller-set energy price index

- **Retracted design:** do not implement the matched-lineage annual-cost index above. The product direction is now a direct c/kWh index for prices that sellers set, with Spot excluded. This removes annual-consumption and annual-cost estimates from the headline signal.
- A strict estimate-free overall index can use only canonical direct General rates. Existing `energy_price` aggregates mix direct General rates with synthetic Time and Season blends, so they cannot by themselves produce the strict index.
- Include direct General rates from fixed-term contracts of all durations, ordinary open-ended contracts, supplier-adjusted open-ended contracts, and current-period market-reset contracts. Their annual estimates are irrelevant to this metric.
- Exclude Spot. Exclude packages because they have no all-in direct c/kWh rate. Exclude Time and Season from the overall value because combining their two factual rates requires a usage assumption. Exclude Hybrid from the overall value because its seller-set base rate is not the complete customer energy price; it can have a separately labelled base-price series.
- Build and persist the index during daily canonical statistics collection from contract snapshots and current typed contract facts. Do not scan raw contracts during page requests. Historical levels can start on 2026-01-21 because production now has exact-manifest LLM-validated historical canonical interpretations; raw observed component rates are still not eligible.
- Add the persisted overall index and transparent family sub-indices to `/sahkosopimus/tilastot`. The main `/sahkosopimus` teaser reads the same persisted overall series and its exact 30-day change. The index is independent of the visitor's annual-consumption selector.
- Final weighting uses one supplier contribution per family and a fixed family-weight map. A supplier contribution is the median of its eligible direct General offers. Each family is the arithmetic mean of supplier medians. The launch basket is 2026-08-11: fixed-term 22/44 = 0.500000, open-ended 13/44 = 0.295455, and market-reset 9/44 = 0.204545. These weights are frozen for v1 and are not renormalized around a missing family.

## Implementation

- `SellerSetEnergyPriceIndexService` persists versioned overall, fixed-term, open-ended, market-reset, and separate Hybrid-base rows in `contract_price_daily_statistics` during current canonical collection. It uses `avg_value`, keeps counts and weights in `basis_counts`, rejects rates below 0.005 or above 50 c/kWh, and replaces its date rows on rerun.
- An observed/feature-off recalculation removes a same-date canonical index instead of leaving detached evidence. Public index readers are canonical-only.
- The broad comparison-page teaser now requires the exact index row 30 calendar days earlier. It has no annual-cost, observed-basis, nearby-date, or consumption fallback.
- `/sahkosopimus/tilastot` leads with the same overall and family levels, follows the selected daily/weekly/monthly period, shows missing collection periods as chart gaps, and keeps the Hybrid base fact outside the overall index. Existing annual-cost analysis remains below it.
- Historical backfill resolves exact date-bounded evidence through `AsOfAnnualCostEvidenceResolver` and extracts only the direct signup General rate with the canonical phase timeline and inheritance rules. It does not use annual-cost values or raw observed rates. Current contract facts supply the same explicit historical household/national-scope assumption as the AsOf rebuild.
- A family level requires at least three suppliers. This removes the 2026-07-23 rollout-boundary artifact, where only one fixed-term supplier had a date-valid interpretation, and creates an honest chart gap instead of a one-supplier overall index.
- `contracts:backfill-seller-set-energy-price-index` is dry-run by default and writes only with `--apply`. The synced production-data smoke test reconstructed 202 dates from 2026-01-21 through 2026-08-11. It wrote 1,007 local rows; the overall series has 201 dates because 2026-07-23 fails the supplier gate.
