# AGENTS.md

Context for Livewire components under `laravel/app/Livewire`.

Use this file as a shortcut to find component-specific behavior. It does **not** replace reading the code.

See also:
- `../AGENTS.md` for Laravel-level behavior
- `../Services/ContractReplacement/AGENTS.md` for replacement matching/linking rules

## `ContractPriceStatistics`

Primary files:
- `ContractPriceStatistics.php`
- `../../resources/views/livewire/contract-price-statistics.blade.php`
- `../Services/ContractStatistics/AGENTS.md`

Purpose:
- renders `/sahkosopimus/tilastot`
- reads precomputed `contract_price_daily_statistics` rows
- groups daily rows into daily/weekly/monthly UI views by averaging daily statistics

Important semantics:
- the page does not calculate contract prices directly during requests
- the component caches its prepared view payload per period + consumption + source-data fingerprint until the next day; keep this cache in place unless a replacement avoids full-table aggregation on every page load
- cache invalidation is automatic through cheap `contract_price_daily_statistics` / `contract_price_snapshots` max-date/update fingerprints, so daily imports/backfills should not need manual page-cache clearing
- run `contracts:backfill-price-statistics` before expecting historical data
- spot metrics are split between `spot_margin` and `spot_total_energy_price`
- deep-dive c/kWh charts may show current/day-period spot total price, but non-spot “vs pörssisähkö” quotable comparisons must use `annual_cost` at the selected consumption so unusually cheap/expensive spot days do not distort contract-type comparisons
- the lead chart caption must be generated from `leadChartPayload` / `annual_cost`, not from c/kWh callouts, so the text always matches the plotted trend
- segment and consumption tables hide rows with fewer than 10 contracts to avoid over-interpreting sparse segment statistics

## `ArticleContractPriceComparisonChart`

Primary files:
- `ArticleContractPriceComparisonChart.php`
- `../../resources/views/livewire/article-contract-price-comparison-chart.blade.php`

Purpose:
- embeds the contract-price statistics lead chart on editorial pages such as `/sahkosopimus/kannattaako-porssisahko`
- reuses precomputed `contract_price_daily_statistics` annual-cost rows for spot, 12-month fixed-term, open-ended, and hybrid segments
- uses the shared `resources/js/contract-price-statistics.js` `data-line-chart` renderer

Important semantics:
- the article embed intentionally shows one static view: weekly aggregation at 5 000 kWh/year, with no period or consumption selectors
- keep its aggregation aligned with `ContractPriceStatistics`: weekly views average daily median statistics so trends remain market-day weighted
- do not calculate contract prices during the article request; the component only reads aggregate statistics rows
- article chart data is cached with short TTLs (typically 6 hours) because it is derived from daily/hourly precomputed market tables and does not need per-request freshness
- do not Livewire-lazy-load the article chart widgets unless their pushed scripts/chart initializers are moved to a non-lazy parent bundle; otherwise the widget markup can hydrate without the chart drawing

## `ContractTypeComparison`

Primary files:
- `ContractTypeComparison.php`
- `../../resources/views/livewire/contract-type-comparison.blade.php`

Purpose:
- interactive editorial comparison widget for pörssisähkö vs fixed price and fixed-term vs open-ended contracts

Important semantics:
- widget actions can be slow because contract candidates are recalculated; keep visible `wire:loading` feedback on mode, consumption, and contract-selector updates
- do not server-render every available contract as `<option>` elements; the editorial article embed must avoid dumping all contract names into the initial DOM for crawler quality and UX
- contract selection is interaction-gated: the default view shows only auto-selected/explicit contracts, and searchable async results render only after the user opens a selector and types at least 2 characters
- default `contract_term` mode compares määräaikainen vs toistaiseksi voimassa oleva for the määräaikainen article
- `comparisonContext="spot_article"` keeps pörssisähkö as the left-side anchor in both tabs: pörssisähkö vs kiinteähintainen and pörssisähkö vs määräaikainen

## Contract listing page caching

Primary files:
- `ContractsList.php`
- `SeoContractsList.php`
- `../Services/Caching/ContractPageCacheVersion.php`

Purpose:
- cache prepared view payloads for high-traffic canonical/default contract listing landings such as `/sahkosopimus` and SEO listing pages

Important semantics:
- only cache canonical default GET states: page 1, no query string, no interactive filters/search input
- do not cache arbitrary filter/query combinations because they can explode cache cardinality and are less important for search-landing TTFB
- cache keys include route/filter context plus `ContractPageCacheVersion::hash()` so contract imports and source-table changes bust stale payloads
- this is prepared-data caching, not full HTML caching; Livewire actions still recompute/serve their interactive state normally
- page-level caching is disabled when `app()->runningUnitTests()` to avoid cross-test cache pollution from Laravel's array cache driver

## `ContractDetail`

Primary files:
- `ContractDetail.php`
- `../../resources/views/livewire/contract-detail.blade.php`
- `../Models/ElectricityContract.php`
- `../Services/Caching/ContractPageCacheVersion.php`

### Prepared view-data caching

Contract detail pages cache their contract lookup and prepared default GET payload until tomorrow with a `ContractPageCacheVersion` key.

Important semantics:
- only the canonical default consumption state is cached (`5000 kWh`, clamped into the contract's allowed range)
- query-string/Livewire interaction states are not cached by this page-level cache
- inactive redirect decisions still happen in `mount()` before view-data caching
- inactive historical pages without replacements can be cached, but the cached layout data must keep `robots => noindex, follow`
- page-level caching is disabled when `app()->runningUnitTests()` to avoid cross-test cache pollution from Laravel's array cache driver

### Internal links

The contract detail hero intentionally links key entities/attributes to indexable internal pages:
- company name -> `/sahkosopimus/sahkoyhtiot/{companySlug}` when a slug exists
- duration badges -> `/sahkosopimus/maaraaikainen` or `/sahkosopimus/toistaiseksi`
- pricing/metering badges -> existing SEO listing pages such as `/sahkosopimus/porssisahko`, `/sahkosopimus/joustosahko`, `/sahkosopimus/yleissahko`, `/sahkosopimus/aikasahko`, and `/sahkosopimus/kausisahko`

Primary mapping file:
- `../Support/ContractInternalLinks.php`

Use broad existing SEO pages for duration badges instead of creating exact-duration pages unless product explicitly wants those pages and they will have substantial unique content.

### Contract history UI

The contract detail page now builds its visible history from the replacement-link chain instead of only the current contract row.

Current intended behavior:
- active contracts render the full `contract-detail.blade.php` page
- inactive contracts without a trusted replacement also render the normal `contract-detail.blade.php` page for historical reference
- those inactive historical pages should include a `noindex` robots meta tag
- inactive historical pages should not appear in the sitemap
- start from the currently rendered contract
- walk backward with `ElectricityContract::getReplacementChainBackward()`
- include the current contract itself as the newest history entry
- sort versions in reverse chronological order using each version's latest known `price_date`
- show, for each version:
  - contract name
  - latest relevant prices per component type
  - promotion/discount summary when present

### Discount display guardrail

When showing a promotion/discount summary for a contract or a historical version:
- read the discounted `price_component_type` / `payment_unit`
- do **not** assume every absolute discount is `c/kWh`
- monthly-component discounts must be shown as monthly-fee discounts (for example `€/kk`), not energy-price discounts

### Important decision: do not flatten the chain

Even if an older contract could redirect straight to the newest active version, the UI should preserve intermediate versions.

Reason:
- the sequence itself is useful historical context
- pricing and campaign information can change between versions
- overwriting the visible sequence would throw away information the chain was designed to preserve

### Price-change summary semantics

`ContractDetail` also merges `priceComponents` across the backward chain for the price-change teaser/details table.

That means:
- change counts are computed across all linked versions, not only the current row
- detailed history rows may reference different contract names in the same chain

If future work changes how history is grouped or collapsed, keep the per-version timeline visible unless product explicitly decides otherwise.
