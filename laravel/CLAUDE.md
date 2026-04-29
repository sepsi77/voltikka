# CLAUDE.md

Laravel-specific guidance for Voltikka Claude sessions.

See root `../CLAUDE.md` for project overview and architecture. Keep implementation details here, close to the code.

## Contract replacement system

Voltikka keeps inactive contracts in `electricity_contracts` for historical continuity, SEO cleanup, and long-term price-history stitching.

### Behavior summary
- If a contract is active, `ContractDetail` renders normally.
- If a contract is inactive and has a trusted replacement chain ending in an active contract, `ContractDetail` returns a **301** redirect to the latest active replacement.
- If a contract is inactive and no trusted replacement exists, `ContractDetail` returns **410 Gone** and sets `X-Robots-Tag: noindex, nofollow`.

Primary implementation:
- `app/Livewire/ContractDetail.php`
- `app/Models/ElectricityContract.php`
- `app/Services/ContractReplacementMatcher.php`
- `app/Services/ContractReplacementLinker.php`

## Data model

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

## Contract price statistics page

`/sahkosopimus/tilastot` is an SEO link-acquisition page aimed at journalists, Reddit/HS commenters, and data-curious users. It is not a buyer tool. The page is treated as an editorial data artifact: the lead chart and one editorial sentence are the load-bearing surface; the rest serves the long tail.

Primary files:
- `app/Livewire/ContractPriceStatistics.php` — Livewire component. Computed properties produce chart payloads, segment rows, period-over-period deltas, dynamic captions, and citation strings.
- `resources/views/livewire/contract-price-statistics.blade.php` — light-theme editorial layout. No dark hero, no card-grid, no coral gradients.
- `resources/js/contract-price-statistics.js` — uPlot chart bootstrap. Single coral lead line, slate supporting lines, direct end-labels with de-overlap, unit badge in the corner. Skips end-labels under 560 px and falls back to the Blade-rendered legend.
- `app/Http/Controllers/ContractPriceStatisticsCsvController.php` — streaming CSV download at `/sahkosopimus/tilastot.csv`. Includes attribution and license header lines.

### Important conventions
- **Aesthetic register.** Light theme, ≤ 5 % coral on the surface, slate substrate. The dark slate-950 hero from `DESIGN.md` is **not** used here. Anything that would make the page read as marketing or SaaS dashboard is wrong here on purpose.
- **Honesty about the data window.** Real contract data starts 1.1.2026. The meta strip shows the current window, the dek says the aineisto grows over time, and we never zero-pad. As more data accrues, the same components scale automatically.
- **Single chart library.** uPlot is intentionally the only chart lib in the project. New chart needs on this surface should reuse `data-line-chart` containers and the same payload shape (`{ x, series, unit, decimals }`). uPlot defaults are tuned to honor `DESIGN.md` (no gradients, no shadows, slate axes).
- **CSV is first-class.** The CSV download is the SEO link play's actual weapon. Header includes CC BY 4.0 attribution requirement, VAT note, and source URL. Don't dilute these.
- **Citation block.** The `Viittaa tähän` block is the second weapon. If you change the URL, brand name, or page title, update the `getCitationsProperty()` strings as well.
- **Schema.org Dataset JSON-LD.** Search engines treat stats pages well when they self-describe as datasets. Keep the `Dataset` + `DataDownload` JSON-LD intact; broken schema means losing the entire SEO play.
- **Query params.** `?kulutus=` (consumption) and `?jakso=` (period) are deep-linkable on purpose so journalists can link to a specific cut. Do not turn these into Livewire-internal-only state.

### Empty / sparse / missing data
- Empty state must stay public-safe. Never leak `php artisan` instructions to the empty state.
- Per-row missing data shows `–`, never zero. Sparkline shows the gap.
- The page must keep working when the latest snapshot has no rows yet (e.g. immediately after a fresh deployment).

## Documentation maintenance

After changing replacement behavior, import flow, or chain semantics:
- update root `../CLAUDE.md` with the high-level summary
- update this file with implementation details, commands, and guardrails
- if you add a new source-of-truth file closer to the implementation, move detailed notes there and leave pointers here
