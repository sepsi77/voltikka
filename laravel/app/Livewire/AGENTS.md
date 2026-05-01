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
- run `contracts:backfill-price-statistics` before expecting historical data
- spot metrics are split between `spot_margin` and `spot_total_energy_price`

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

## `ContractTypeComparison`

Primary files:
- `ContractTypeComparison.php`
- `../../resources/views/livewire/contract-type-comparison.blade.php`

Purpose:
- interactive editorial comparison widget for pörssisähkö vs fixed price and fixed-term vs open-ended contracts

Important semantics:
- widget actions can be slow because contract candidates are recalculated; keep visible `wire:loading` feedback on mode, consumption, and contract-selector updates

## `ContractDetail`

Primary files:
- `ContractDetail.php`
- `../../resources/views/livewire/contract-detail.blade.php`
- `../Models/ElectricityContract.php`

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
