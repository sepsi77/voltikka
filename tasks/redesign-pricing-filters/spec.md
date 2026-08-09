# Redesign pricing filters + rewrite hybrid band detail copy

## Background

The canonical/market-reset pricing update made the first page of the comparison list
spot-heavy. This is correct ranking, but it can deter users who want price certainty.
The filters exist but are collapsed inside the "Rajaa hakua" accordion
(`resources/views/partials/contract-filters.blade.php`), so users do not find them.

## Goal 1 — visible pricing-type filter (risk transfer)

Give users a visible, always-shown filter above the contract list. The filter operates
on **four pricing buckets**:

| Bucket | Finnish label (decided) | Rule |
|---|---|---|
| Spot | Pörssisähkö | `pricing_model = Spot` |
| Market-following resets | Jaksoittain vaihtuva hinta — sub-line "muuttuu kuukausittain tai harvemmin" | NOT spot AND `canonical_pricing->recurring_schedule.present` with cadence in monthly/quarterly/seasonal/other |
| Consumption effect | Kulutusvaikutus | existing `PricingCategory::ConsumptionEffect` rules |
| Fixed | Kiinteä hinta | existing `PricingCategory::Fixed` rules |

These four buckets are the existing `ContractCard\PricingCategoryResolver` categories,
with `Market` split into spot / non-spot. Together they partition all contracts: every
contract is in exactly one bucket, and the card band the user sees agrees with the
bucket that listed it (same resolver rules).

Requirements:

- The filter is a row of toggle pills, always visible above the list, on **every
  contract listing page** (`ContractsList` homepage, `SahkosopimusIndex`, all
  `SeoContractsList` pages, `CheapestContracts`). Exception: single-company pages
  (`CompanyDetail`) keep their current layout.
- Pills are two-line where needed: main label plus a short sub-line (the market-
  following pill uses this; the pattern matches the consumption preset info-cards).
- Multi-select **include** semantics: no pill active = show all. Activating one or more
  pills shows only those buckets. Excluding spot = activate the other three pills.
  (Simplest model; no tri-state include/exclude control.)
- Pills reuse the card band category tints (see `<x-card.legend />` and DESIGN.md
  "Semantic — Pricing Category") so the filter, the legend, and the cards read as one
  system. Show a per-bucket contract count on each pill when cheap to compute.
- Filtering must happen in SQL through a shared scope beside
  `PricingCategoryResolver::scopeCategory()` (new granular variant), so the resolver
  and the filter cannot drift. Parity must be pinned by a test like
  `test_the_query_scope_agrees_with_the_resolver`.
- The other filters (duration, energy source, postcode) stay in the collapsed
  accordion. The old "Hinnoittelumalli" section in the accordion is removed or slimmed;
  aikasähkö / kausisähkö stay reachable through their SEO pages and "Katso myös" links.
- State is URL-bound (`#[Url]`) as `hintatyyppi` (comma-separated bucket keys); the
  legacy `?pricingModelFilter=` values keep working (map Spot/FixedPrice/Hybrid onto
  the new buckets, keep the rest functioning as before or redirect).
- Cacheability: the default listing prepared-data cache only covers the no-filter
  state. The new filter must be counted in `hasActiveFilters()` /
  `isDefaultListingCacheable()` and must reset pagination.
- Analytics: dispatch the existing `Contracts Filter Applied` Plausible event with
  `filter_type = 'pricing_category'`.
- SEO: on `/sahkosopimus` with no active filters, pills may render as crawlable links
  (existing dual-behavior pattern) pointing at the canonical SEO pages where one
  exists (porssisahko, kiintea-hinta, kulutusvaikutus). The market-following bucket
  has no canonical SEO page; it stays a Livewire toggle (or links to /kvartaalisahko
  only if product wants that).
- Business pages and bill mode must keep working; the filter applies before
  `buildBillModePaginator()` like the other filters.

Out of scope: changing ranking, changing the SEO page set, per-bucket landing pages.

## Goal 2 — rewrite the hybrid band detail

`ContractCardCopy::band()` for `PricingCategory::ConsumptionEffect` currently returns:

> Kiinteä hinta + kulutusvaikutus · Korjaus riippuu kulutusprofiilistasi

"Korjaus" and "kulutusprofiilistasi" are unnatural. Replace only the detail part.
Candidates (pick in review, recommendation first):

1. **"Vaikutus riippuu siitä, mihin aikaan käytät sähköä"** — matches the existing
   popover sentence in `hybridBody()` ("riippuu siitä, mihin aikaan käytät sähköä").
2. "Hinta tarkentuu sähkönkäyttösi mukaan"
3. "Lisä tai hyvitys sähkönkäytön ajoituksen mukaan"

Also check `CardReceiptLines` row "Kulutusvaikutus · ± profiilisi mukaan" for the same
"profiili" wording and align it if it reads unnatural in context.

## Acceptance

- Four-bucket filter visible without opening the accordion; buckets partition the
  active household set (sum of bucket counts = total).
- A card listed under a bucket always renders the matching band category.
- Default no-filter page still serves from the prepared-data cache.
- Hybrid band detail no longer says "Korjaus riippuu kulutusprofiilistasi".
- Tests green: `php artisan test` (esp. ContractsFilterTest, ContractsListPageTest,
  ContractCardPresenterTest, SahkosopimusBillModeTest).
