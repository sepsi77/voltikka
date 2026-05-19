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

## Public page caching

High-traffic public contract pages use prepared view-data caching rather than full HTML caching.

Primary implementation:
- `app/Livewire/ContractsList.php`
- `app/Livewire/SeoContractsList.php`
- `app/Livewire/ContractDetail.php`
- `app/Services/Caching/ContractPageCacheVersion.php`

Important semantics:
- contract listing and detail pages cache only canonical/default GET payloads, not arbitrary query/filter/Livewire states
- cache entries expire at tomorrow and also bust through `ContractPageCacheVersion`, which includes the import-bumped `ContractListCacheService` version plus cheap source-table aggregates
- after a successful data import, `contracts:fetch` clears stale application caches before bumping/warming fresh caches; for database cache this uses `TRUNCATE TABLE cache` so expired large page-data rows also release InnoDB disk space
- `contracts:fetch` bumps and warms `ContractListCacheService`; this is the main invalidation signal for contract-page prepared payloads
- full response/HTML caching is intentionally not the first layer because Livewire snapshots/tokens should not be cached blindly
- page-level prepared-data caching is disabled under `app()->runningUnitTests()` to avoid cross-test cache pollution
- contract detail redirects for inactive contracts still happen before view-data caching

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
- future daily calculations are run during `contracts:fetch` and use `active_contracts`
- `/sahkosopimus/tilastot` serves cached prepared view data per period + consumption and automatically busts that cache when statistics/snapshot/source spot-price fingerprints change
- `contracts:fetch` calculates daily contract-price statistics before optional percentile badge thresholds so `/sahkosopimus/tilastot` continues to advance even if percentile recalculation fails
- `contracts:warm-price-statistics-cache` queues `App\Jobs\WarmContractPriceStatisticsCache` by default; use `--sync` only for manual immediate warming/tests. `contracts:calculate-price-statistics` (including when called by `contracts:fetch`) and `spot:fetch` queue warming for the default weekly/5 000 kWh page state after their source data updates.
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

## Sitemap

- `/sitemap.xml` is generated by `app/Services/SitemapService.php` and cached with `SitemapService::CACHE_KEY`.
- `/sahkosopimus/tilastot` is a canonical statistics page and must remain in `getMainPageUrls()`.
- If production shows stale sitemap XML after adding/removing pages, clear or bump `SitemapService::CACHE_KEY`; `sitemap:generate` also forgets this cache key before writing `public/sitemap.xml`.

## Frontend behavior

### Global page navigation feedback

`resources/views/layouts/app.blade.php` shows an immediate page-loading indicator for normal same-origin link navigation. Same-document hash links (for example `/sahkosopimus/tilastot?kulutus=5000#viittaa`) must not start or leave this indicator active, because no page or Livewire request is expected. Keep the hash-only `click`/`popstate`/`hashchange` guards in sync if changing navigation feedback.

## Observability

### Sentry

Sentry is configured for Laravel exception capture and optional log forwarding.

Primary files:
- `bootstrap/app.php` — registers `Sentry\Laravel\Integration::handles($exceptions)`.
- `config/sentry.php` — SDK configuration published by `sentry/sentry-laravel`; reads `SENTRY_LARAVEL_DSN`, trace/profiling sample rates, and log settings from env.
- `config/logging.php` — defines the `sentry_logs` channel using the Sentry log driver.

Production env guidance:
```bash
SENTRY_LARAVEL_DSN=...
SENTRY_TRACES_SAMPLE_RATE=1.0
SENTRY_PROFILES_SAMPLE_RATE=1.0
SENTRY_ENABLE_LOGS=true
LOG_CHANNEL=stack
LOG_STACK=single,sentry_logs
```

The production Docker image installs and enables the Excimer PHP extension for profiling. Use lower sample rates later if event volume/cost gets too high.

Verification:
```bash
cd laravel
php artisan sentry:test
php artisan tinker --execute="\\Illuminate\\Support\\Facades\\Log::channel('sentry_logs')->info('Sentry log test'); \\Sentry\\logger()->flush();"
```

When using `tinker --execute` from a single-quoted shell string, PHP namespace separators should be written with a single backslash (for example `use Livewire\Livewire;`). Double-escaping them (`use Livewire\\Livewire;`) sends two literal backslashes to PsySH and causes `unexpected T_NAME_FULLY_QUALIFIED` parse errors before any application code runs.

## Spot price imports

Primary files:
- `app/Services/EntsoeService.php`
- `app/Console/Commands/FetchSpot.php`
- `app/Console/Commands/BackfillSpot.php`

Important semantics:
- ENTSO-E fetches retry transient server errors and connection failures/timeouts (`ConnectionException`, including cURL 28) before failing.
- Spot fetch/backfill commands catch exhausted HTTP request/connection failures so scheduled jobs fail or continue gracefully instead of leaking raw exception stack traces.
- Do not log raw ENTSO-E exception messages without redacting `securityToken`, because Guzzle/Laravel exception text can include the full query string.

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
