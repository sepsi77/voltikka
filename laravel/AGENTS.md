# AGENTS.md

Laravel-specific guidance for Voltikka agents.

See root `../AGENTS.md` for project overview and architecture. Keep implementation details here, close to the code.

## Contract replacement system

Voltikka keeps inactive contracts in `electricity_contracts` for historical continuity, SEO cleanup, and long-term price-history stitching.

### Behavior summary
- If a contract is active, `ContractDetail` renders the full contract detail page normally.
- If a contract is inactive and has a trusted replacement chain ending in an active contract, `ContractDetail` returns a **301** redirect to the latest active replacement.
- If a contract is inactive and no trusted replacement exists, `ContractDetail` still renders the normal contract detail page for historical reference with a `noindex` robots meta tag.
- Inactive contract detail pages without a trusted replacement must not be included in the sitemap.
- On the current/live contract detail page, the visible contract history is built from the backward replacement chain so users can see older linked versions, newest first.

Primary implementation:
- `app/Livewire/ContractDetail.php`
- `app/Livewire/AGENTS.md`
- `app/Models/ElectricityContract.php`
- `app/Services/ContractReplacementMatcher.php`
- `app/Services/ContractReplacementLinker.php`

## Data model

### Contract price statistics

Voltikka stores daily contract-price trend data for `/sahkosopimus/tilastot`.

Primary tables:
- `contract_price_snapshots` — one daily row per included contract with normalized component prices and annual-cost estimates for 2000/5000/18000 kWh.
- `contract_price_daily_statistics` — aggregate daily min/p20/average/p80/max rows by segment and metric.

Primary implementation:
- `app/Services/ContractStatistics/ContractPriceStatisticsService.php`
- `app/Services/ContractStatistics/AGENTS.md`
- `app/Console/Commands/CalculateContractPriceStatistics.php`
- `app/Console/Commands/BackfillContractPriceStatistics.php`
- `app/Livewire/ContractPriceStatistics.php`

Commands:
```bash
php artisan contracts:calculate-price-statistics --date=2026-04-29 --overwrite
php artisan contracts:backfill-price-statistics --from=2025-01-01 --to=2026-04-29 --overwrite
```

Important semantics:
- future daily calculations are run after `contracts:fetch` and use `active_contracts`
- historical backfills infer availability from `price_components.price_date`
- missing contract rows for a date are excluded; prices are not carried forward
- spot contracts store both supplier margin and total spot energy price (`stored spot average + margin`)

### `electricity_contracts.replaced_by_contract_id`
- Nullable FK to `electricity_contracts.id`
- Points forward from an old contract to the contract that replaced it
- Only high-confidence links are persisted automatically
- Existing links are preserved so chains can grow over time instead of being rewritten

Typical chain:
- `A -> B -> C`
- if `C` is active, requests for `A` and `B` should resolve to `C`

Migration:
- `database/migrations/2026_04_21_000001_add_replaced_by_contract_id_to_electricity_contracts.php`

## Matching algorithm

The matcher is deliberately conservative.

### Hard candidate filters
A replacement candidate must match the inactive contract on:
- `company_name`
- `contract_type`
- `metering`
- `pricing_model`
- `target_group`
- `fixed_time_range` when `contract_type === 'FixedTerm'`

### Name scoring
After structural filtering, candidates are scored with normalized-name signals:
- base token overlap after stripping promo/noise text
- identity token overlap for core product labels like `duo`, `varma`, `joustosahko`, `vire`, `verraton`
- profile token overlap for important variant words like `tuuli`, `aurinko`, `vesi`, `fossiilivapaa`, `yrityksille`
- full-string similarity
- compact/base-string similarity

### Noise/promo tolerance
The matcher tolerates marketing differences such as:
- `0 € perusmaksu`
- `ensimmäiset 3 kk`
- `-50 %`
- similar campaign wording

It should **not** blindly collapse materially different product variants when variant/profile tokens diverge.

### Confidence levels
- `high`: safe to persist automatically
- `medium`: plausible candidate, review before persisting
- `low`: do not persist; contract should remain 410 unless manually linked

## Commands

### Refresh data and auto-link replacements
```bash
cd laravel
php artisan contracts:fetch --skip-logos
```
This imports current contracts, refreshes `active_contracts`, and runs high-confidence replacement linking.

### Inspect matcher output
```bash
php artisan contracts:detect-replacements --min-score=70 --limit=100
php artisan contracts:detect-replacements --confidence=medium --limit=100
php artisan contracts:detect-replacements --json=storage/app/replacement-matches.json
```

### Persist high-confidence matches manually
```bash
php artisan contracts:link-replacements
```

## Chain querying

Use the helpers on `ElectricityContract`:
- `replacedBy()` — direct forward replacement
- `replacements()` — direct predecessors
- `getReplacementChainForward()` — follow replacements forward
- `getReplacementChainBackward()` — collect all known predecessors
- `resolveLatestReplacement()` — get the latest reachable replacement in the chain

Example:
```php
$contract = ElectricityContract::find($id);
$latest = $contract->resolveLatestReplacement();
$historyContracts = $latest?->getReplacementChainBackward() ?? collect();
```

For long price history, start from the current/live contract and merge `priceComponents` across its backward chain.

## Guardrails
- Do not delete inactive contracts just to fix SEO.
- Do not auto-link medium-confidence matches during import.
- Do not overwrite existing `replaced_by_contract_id` links in bulk imports; allow forward chains to accumulate.
- If you change matching rules, run `contracts:detect-replacements` and inspect medium/low results before enabling broader auto-linking.

## Documentation maintenance

After changing replacement behavior, import flow, or chain semantics:
- update root `../AGENTS.md` with the high-level summary
- update this file with implementation details, commands, and guardrails
- if you add a new source-of-truth file closer to the implementation, move detailed notes there and leave pointers here
