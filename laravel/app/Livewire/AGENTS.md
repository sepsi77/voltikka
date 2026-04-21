# AGENTS.md

Context for Livewire components under `laravel/app/Livewire`.

Use this file as a shortcut to find component-specific behavior. It does **not** replace reading the code.

See also:
- `../AGENTS.md` for Laravel-level behavior
- `../Services/ContractReplacement/AGENTS.md` for replacement matching/linking rules

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
