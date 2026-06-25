# Expand bill comparison + compact comparison-page layout

Follow-up to `promote-bill-comparison-in-listings`. Three points raised by the user.

## Point 1 — Consumption basis must be consistent

1a. **Bill-mode contract set must not be filtered by the stale annual slider.**
When a bill is entered, contracts were still pre-filtered by
`isConsumptionInRange($this->consumption)` (the annual slider set *before* the
bill). Pricing already uses the bill's kWh, but the eligibility filter used the
wrong basis, so capped flat-fee tiers (e.g. Helen Helpposähkö) could be wrongly
dropped/kept. Fix: skip that pre-filter in bill mode; the service's
`BillComparisonService::fitsConsumptionLimits()` already enforces caps on the
bill-derived annualized kWh. (DONE in both `ContractsList` and `SeoContractsList`;
needs a regression test.)

1b. **Carry consumption to the contract detail page.**
Clicking a listing card opened the detail page at its flat 5 000 kWh default, so
the price looked different from the listing. Fix: listing cards deep-link the
selected consumption (normal mode) / bill-annualized kWh (bill mode) as
`?kulutus=N`; `ContractDetail` reads + clamps it on mount.
**SEO constraint:** `?kulutus=` URLs must not get indexed. Satisfied because
`ContractDetail::canonicalUrl` already returns the clean param-free URL and
prepared-cache already bypasses on any query string. Only append `?kulutus=`
when it differs from the 5 000 default to keep the common URL clean and limit
crawl-budget waste.

## Point 2 — Roll out in-listing bill comparison to all household comparison pages

Enable on every `SeoContractsList` page (pricing type, housing, energy source,
city, duration, consumption-level) + `CheapestContracts` (inherits), but NOT
`/sahkosopimus/yritykselle` (business: a household bill vs business contracts is
not a meaningful comparison). `SahkosopimusIndex` already on.

## Point 3 — Reduce vertical space; get contracts higher

Comparison pages stack a tall dark hero, a large consumption selector (preset
cards + full calculator panel), the bill form, then filters before any contract.
Make it compact:
- slim the hero,
- compact consumption row (chips) with the full calculator behind a "Tarkenna"
  disclosure (collapsed by default),
- bill entry behind a "Vertaa omaan laskuusi" disclosure (collapsed by default),
- keep filters accessible.

Contracts should sit near the top; power tools one click away.
