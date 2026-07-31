# AGENTS.md

Laravel-specific guidance for Voltikka agents.

See root `../AGENTS.md` for project overview and architecture. Keep implementation details here, close to the code.

## Data investigation docs

Research/planning documents live in `data-investigation/`; read `data-investigation/AGENTS.md` first. The fixed-term contract price forecasting plan is `data-investigation/price-forecasting-plan.md`; local-only Python model exploration lives under `data-investigation/price-forecasting/`. The production implementation lives under `app/Services/PriceForecasting/`, persists forecasts, and serves `/sahkosopimus/sahkon-hintaennuste` through `app/Livewire/FixedContractPriceForecast.php`.

## Contract replacement system

Voltikka keeps inactive contracts in `electricity_contracts` for historical continuity, SEO cleanup, and long-term price-history stitching.

### Behavior summary
- If a contract is active, `ContractDetail` renders the full contract detail page normally.
- If a contract is inactive and has a trusted replacement chain ending in an active contract, `ContractDetail` returns a **301** redirect to the latest active replacement.
- If a contract is inactive and no trusted replacement exists, `ContractDetail` still renders the normal contract detail page for historical reference with a `noindex` robots meta tag. Its history timeline includes a newest “no longer on sale” status node; because availability transitions are not persisted, the date shown is explicitly the last import date on which Voltikka observed that exact contract (`MAX(price_components.price_date)`), not an asserted expiry date.
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
- after the authoritative data transaction commits, `app/Services/ContractImport/ContractPostImportCoordinator.php` clears stale application caches and bumps cache versions; for database cache this uses `TRUNCATE TABLE cache` so expired large page-data rows also release InnoDB disk space
- cache invalidation and contract/company version bumps are required post-import stages; cache warming is optional and has a separate failure boundary
- `contracts:fetch` bumps and warms `ContractListCacheService`; this is the main invalidation signal for contract-page prepared payloads
- full response/HTML caching is intentionally not the first layer because Livewire snapshots/tokens should not be cached blindly
- page-level prepared-data caching is disabled under `app()->runningUnitTests()` to avoid cross-test cache pollution
- contract detail redirects for inactive contracts still happen before view-data caching

### Edge HTML caching vs hashed assets (known race)

`app/Http/Middleware/SetPublicCacheHeaders` marks the comparison/detail HTML
`public, max-age=300, s-maxage=3600, stale-while-revalidate=86400`, so Railway's edge can serve
that HTML for an hour and serve it **stale for a further 24 hours** while it revalidates.

Vite filenames are content-hashed and the Docker image runs a clean `npm run build`, so the
previous release's `public/build/assets/app-<hash>.css` **does not exist** on the new container
(verified: a removed hash returns 404). For up to that stale window after a deploy, a visitor can
receive HTML from the old release that links a CSS file the new one no longer serves, and the page
renders with no styling at all until the HTML is revalidated. This was observed once in the July
2026 contract-detail critique. Keep the two facts together in mind before shortening or lengthening
either window.

`Caddyfile` sends `Cache-Control: public, max-age=31536000, immutable` for `/build/assets/*` only.
Those names are content-hashed so the bytes behind one URL never change; **do not widen the matcher
to `/build/*`**, because `build/manifest.json` has a fixed name and changes on every build. Before
editing `Caddyfile`, validate it with the real runtime — plain `caddy` rejects the `frankenphp`
global option:

```bash
docker run --rm -v "$PWD/Caddyfile:/etc/caddy/Caddyfile:ro" dunglas/frankenphp:1-php8.4 \
  frankenphp validate --config /etc/caddy/Caddyfile --adapter caddyfile
```

## Public contract API

`GET /api/contracts` and `GET /api/contracts/{id}` use canonical-only current pricing when
`CANONICAL_PRICING_ENABLED=true`; they omit relational component resources and expose typed
`current_pricing` plus the canonical `calculated_cost` when consumption is requested. The
feature-off branch keeps the legacy response. See `app/Http/AGENTS.md` for the response and batch
query rules.

## Data model

### Contract source snapshots and automatic interpretation

`app/Services/ContractImport/ContractImporter.php` stores each distinct complete upstream contract payload in `contract_source_snapshots` and each contiguous observation episode in `contract_source_observations` inside one authoritative import transaction. `electricity_contracts.current_source_observation_id` is the only source-currentness rule. `CONTRACT_INTERPRETATION_ENABLED=true` is set in production, so the post-import coordinator sends each observed pointed episode through the fingerprint-idempotent dispatcher after commit, with one failure boundary per observation. An unchanged pointed episode extends. A transition, including A→B→A recurrence, creates a new point episode and atomically moves the pointer. Stored A output is revalidated at the recurrent episode date: valid output rematerializes without another LLM call, while date-sensitive invalid output is superseded and uses one date-scoped fallback fingerprint.

Primary implementation:
- `app/Services/ContractInterpretation/AGENTS.md`
- `app/Services/ContractInterpretation/ContractSourceCanonicalizer.php`
- `app/Models/ContractSourceSnapshot.php`

Important semantics:
- the SHA-256 fingerprint ignores object-key order, harmless string whitespace, and shared SpotFutures market data, but preserves list order
- unchanged payloads update the snapshot's aggregate `last_observed_at` and extend only the pointed episode; meaningful source changes create a new immutable snapshot and a point episode. Snapshot first/last timestamps are legacy aggregate evidence and never select currentness or day coverage
- each import refreshes existing relational contract fields from the current API payload without changing the local contract ID or replacement link
- same-day price components are replaced from the complete current payload, so corrected, removed, and new components do not leave stale rows; source snapshots retain each complete payload version
- before same-day `upsert()`, source components that resolve to the same relational key are collapsed deterministically: keep the first positive-price row, or the first row if none is positive. This prevents null-UUID duplicate placeholders from overwriting real prices while preserving valid zero-only package components
- postcode and DSO relationships for fetched contracts are replaced from the current payload instead of remaining additive
- optional legacy short/long descriptions are refreshed only when the API includes those keys; omission does not erase them
- `contract_interpretations` stores strict output, validation errors, schema/prompt/validator provenance, usage, execution state, and the complete initial/correction call history; validator version participates in idempotency so stricter rules cause reanalysis
- deterministic validation failure can cause at most two automatic LLM correction calls before the interpretation fails; corrected output must pass the same full validator
- valid latest output publishes compatible classifications plus current `canonical_pricing`, `canonical_source_consistency`, and `canonical_calculation` JSON, and sets `electricity_contracts.published_interpretation_id`
- each interpretation records `published_fields`; later imports preserve only those canonical fields until a newer interpretation publishes
- new contracts stay inactive until first validation, and changed source prices for interpreted contracts wait for the new validation before relational publication
- durable `relational_pricing_published` gates later activation and relational price writes, so unsafe pricing cannot appear on the next import
- that gate asks only "can the structured components still be trusted as the current disclosed price?" — not "can we total the year from them". It blocks on a named reason: a detected deception, `conflicting` structured pricing, or an issue code not classified as harmless (unknown codes block). `calculation.status` does not gate at all. Conflating the two closed the gate permanently on all 49 Hybrid contracts on 2026-07-24 and blanked the `hybrid` segment of `/sahkosopimus/tilastot`, because a Hybrid's consumption effect is never quantified by the seller
- because the flag is decided once at publication and read by every later import, relaxing the gate reaches already-published contracts only through `php artisan contracts:republish-gated-pricing` (dry run by default; `--apply` lifts the flags and refills each lost day from its one covering source-observation episode; the selected snapshot must have its own stored valid safe interpretation, and unknown, ambiguous, absent-interpretation, or unsafe coverage stays empty)
- versioned interpretation output is the LLM-validated pricing history; `app/Services/CanonicalPricing/` consumes the published `canonical_*` JSON for phase-aware pricing and the deceptive-pricing label behind `CANONICAL_PRICING_ENABLED` (default off, **true in production** — see `app/Services/CanonicalPricing/AGENTS.md`)
- `CanonicalPricing/PricingMode` snapshots canonical and reset-shift flags once per request/command and owns the expected statistics basis and cache marker. All calculated-cost-dependent caches include `CalculatedCostPayloadSchema::VERSION`; their outer wrapper versions remain independent
- market-reset products (monthly/quarterly/seasonal/other repricing) are annualised by `app/Services/CanonicalPricing/MarketReset/` with a shape-only FI forward-curve shift behind its own `RESET_FORWARD_SHIFT_ENABLED` flag (default off). Cadence `other` uses the quarterly calendar and reference proxy because exact phase boundaries are not published. It must stay a separate flag because `CANONICAL_PRICING_ENABLED` is already on in production; the flag also varies the list/ranking/page cache keys. See `app/Services/CanonicalPricing/MarketReset/AGENTS.md`
- use `php artisan contracts:interpret` to dispatch active contracts' pointed observations; add `--retry-failed`, `--contract=`, or `--include-inactive` when needed. The command can reuse stored output without an OpenRouter key

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
- future daily calculations are run during `contracts:fetch` and use `active_contracts`; in canonical mode all current numeric metrics and measured offer state come from batched typed canonical outcomes and no `price_components` query runs
- `/sahkosopimus/tilastot` serves cached prepared view data per period + consumption and automatically busts that cache when statistics/snapshot/source spot-price fingerprints change
- the contract post-import coordinator calls `ContractPriceStatisticsService::calculateForDate()` before optional percentile badge thresholds so `/sahkosopimus/tilastot` continues to advance even if percentile recalculation fails; it captures exact start and completion timestamps immediately around this call for the morning freshness checkpoint
- `contracts:warm-price-statistics-cache` queues `App\Jobs\WarmContractPriceStatisticsCache` by default; use `--sync` only for manual immediate warming/tests. The contract post-import coordinator dispatches that job directly for weekly/5 000 after successful statistics; `spot:fetch` also queues it after source data updates.
- Production containers start `php artisan queue:work --timeout=420 --tries=3` through root `supervisord.conf`; keep database queue `retry_after` at 450 seconds or more. Queued cache warmers and contract interpretation depend on that worker running.
- historical backfills infer availability from `price_components.price_date` and always store observed seller evidence; `pricing_basis` distinguishes these rows from canonical forward calculations in the page and CSV
- public current-statistics consumers use one shared rule: canonical flag on requires `canonical_calculation`, while feature-off requires `observed_seller_data`; they select the latest date for that basis and never fall back across bases
- one pricing basis owns each newly calculated date: inside the calculation transaction, the target date loses opposite-basis snapshots and the run's own prior snapshots before aggregates are rebuilt; this removes stale canonical exclusions without deleting other dates
- missing contract rows for a date are excluded; prices are not carried forward
- spot contracts store both supplier margin and total spot energy price (`stored spot average + margin`)

### Fixed-term price forecasts

Voltikka stores fixed-term price forecasts for the public `/sahkosopimus/sahkon-hintaennuste` page and later accuracy evaluation.

Primary files:
- `app/Services/PriceForecasting/AGENTS.md`
- `app/Models/FixedContractPriceForecast.php`
- `app/Console/Commands/RunFixedContractPriceForecasts.php`
- `app/Console/Commands/EvaluateFixedContractPriceForecasts.php`

Commands:
```bash
php artisan forecasting:run-fixed-contracts --as-of=today --horizon=30
php artisan forecasting:evaluate-fixed-contracts --as-of=today
```
Scheduled in `routes/console.php`: EEX futures fetch runs overnight at 04:00 Europe/Helsinki so previous trading-day FI settlements are available before the forecast run; forecast run daily at 07:30 Europe/Helsinki, evaluation daily at 07:45. Scheduled retail collection and forecast generation use `--require-freshness`; the shared `app/Services/MorningFreshness/` gate requires same-date full contract/EEX checkpoints, exactly one observed pointed episode and a current publication for each active contract, current-run prior-date FI Base proof, and recent FI Base database data. Forecasts also require at least one current 6/12/24 statistic for the selected pricing basis and a statistics start after the newest required publication.

Important semantics:
- model v2 forecasts fixed-term 6/12/24 month market p20/median/p80 `energy_price` indices from `contract_price_daily_statistics`
- canonical mode requires `canonical_calculation` for the current retail input and never falls back to observed rows; feature-off requires `observed_seller_data`
- historical EWMA evidence remains dated observed seller statistics before the forecast date; metadata records its basis counts separately from the current input
- futures hedge costs use FI EEX Base futures with no same-day leakage (`trade_date < forecast_date`)
- forecasts are skipped when required current-basis statistics or delivery months are missing rather than silently using another basis or stale futures
- reruns skip existing same date/horizon/duration/quantile/model-version rows unless `--overwrite` is passed, preserving historical forecast records
- evaluation keeps matured actuals as observed seller data and records that provenance in `source_metadata`
- the public page and comparison-page teaser accept only the configured model version and expected current-input basis; old or missing-provenance rows are hidden
- the public offered-price history is separate from forecast-run eligibility: it reads the complete non-null fixed-term `energy_price` median timeline from daily statistics, with older `observed_seller_data` evidence followed by canonical daily calculations. A model-version rollout cannot truncate the history, and a canonical rollout cannot freeze its latest point

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

Desktop dropdown menus in the same layout are hover-opened Alpine children. Keep their absolute dropdown panels physically touching the trigger (no top margin gap), otherwise moving the pointer from the trigger to secondary menu items can close the panel before click and make navigation feel like the first/top item was clicked.

### Teleported Alpine panels inside Livewire components

`resources/views/components/info-popover.blade.php` (the card "Arvio" popover) and
`resources/views/components/info-tip.blade.php` both teleport their panel to `<body>` with
`x-teleport`, because the contract card sets `overflow-hidden` and applies a hover transform,
so neither an absolute nor a plain fixed child of the card can escape it. That puts the panel
outside the Livewire component root, and Livewire then reaches it only through the
`from._x_teleport <-> to._x_teleport` bridge inside its morph. Two rules follow, and both
already caused a visible defect once:

- **A teleported panel must have a morph key that is stable across renders.** Livewire's morph
  key is `wire:id`, then `wire:key`, then `el.id`. The popover panel's id was
  `Str::random(8)`, redrawn on every render, so the keys never matched and every Livewire
  update replaced the live panel with a scopeless `cloneNode(true)`. Alpine re-initialised
  that copy against an empty scope, so `x-show="open"` resolved `window.open` and the style
  binding wrote the string `"undefined"`, leaving the panel at the viewport origin. The panel
  now pins a constant `wire:key`; the tooltip bubble has no id and therefore needs none.
- **Do not position a teleported panel through a reactive `:style` binding.** The server
  markup inside `<template>` carries no `style`, so a morph strips whatever Alpine wrote and
  the binding does not re-run afterwards if the coordinates are unchanged. Both components
  write `style.top` / `style.left` imperatively each time the panel opens, and re-resolve the
  trigger at that moment instead of trusting a node cached at init.

## Backups and disaster recovery

Voltikka uses `spatie/laravel-backup` for first-pass production database backups. Configuration lives in `config/backup.php`; scheduling lives in `routes/console.php`.

Current semantics:
- scheduled production database backup: `backup:run --only-db` daily at 03:00 Europe/Helsinki
- scheduled production public-storage backup: `backup:run --only-files` weekly on Sunday at 02:30 Europe/Helsinki; this backs up `storage/app/public` only
- cleanup runs daily at 03:30 and monitor runs daily at 03:45
- backups are written to `BACKUP_DISK` (production: `s3`) and encrypted with `BACKUP_ARCHIVE_PASSWORD`
- MySQL dumps use `useSingleTransaction` in `config/database.php` to avoid table locking for InnoDB tables and explicitly use `skip-ssl` so `mysqldump` does not fail on Railway's self-signed internal MySQL certificate
- the Docker image must include `default-mysql-client` because Spatie shells out to `mysqldump`
- backup success notifications are disabled; failure/unhealthy/cleanup-failure notifications go to `BACKUP_NOTIFICATION_EMAIL` or mail defaults

Do not expose backup S3 credentials or `BACKUP_ARCHIVE_PASSWORD` in chat/logs. Do not run restores, delete backup archives, reset bucket credentials, or trigger manual production backups without explicit user confirmation.

Restore drill outline:
1. Download the selected encrypted backup archive from the Railway bucket without printing credentials.
2. Decrypt/unzip using `BACKUP_ARCHIVE_PASSWORD` from Railway variables.
3. Restore into a temporary MySQL service/database, not production.
4. Run sanity checks before considering any production restore.

## Observability

### Sentry

Sentry is configured for Laravel exception capture and optional log forwarding.

Primary files:
- `bootstrap/app.php` — registers `Sentry\Laravel\Integration::handles($exceptions)`.
- `config/sentry.php` — SDK configuration published by `sentry/sentry-laravel`; reads `SENTRY_LARAVEL_DSN`, trace/profiling sample rates, and log settings from env. It uses `App\Support\SentryProfilesSampler` to keep console/queue profiling disabled by default under the 128 MB worker limit. It ignores `Psy\Exception\ParseErrorException` and `Symfony\Component\Console\Exception\RuntimeException` because malformed local `tinker --execute` smoke commands and invalid Artisan options fail before application code runs and otherwise create Sentry noise.
- `config/logging.php` — defines the `sentry_logs` channel using the Sentry log driver.

Production env guidance:
```bash
SENTRY_LARAVEL_DSN=...
# Keep performance spans/profiles off by default to preserve Sentry span quota.
# Exception capture and sentry_logs forwarding still work with trace/profile sample rates at 0.0.
SENTRY_TRACES_SAMPLE_RATE=0.0
SENTRY_PROFILES_SAMPLE_RATE=0.0
SENTRY_PROFILE_CONSOLE_ENABLED=false
SENTRY_PROFILE_QUEUE_ENABLED=false
SENTRY_ENABLE_LOGS=true
LOG_CHANNEL=stack
LOG_STACK=single,sentry_logs
```

The production Docker image installs and enables the Excimer PHP extension for profiling. Console/queue profiling is disabled by default because long-running `queue:work` transactions can accumulate large profiling logs and exhaust the 128 MB worker while Sentry serializes the profile; temporarily enable `SENTRY_PROFILE_CONSOLE_ENABLED` / `SENTRY_PROFILE_QUEUE_ENABLED` only for short diagnostic runs.

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
- `app/Models/SpotPriceForecast.php`
- `app/Services/SpotForecasts/AGENTS.md`
- `app/Console/Commands/FetchSpotForecast.php`

Important semantics:
- `spot:fetch` only persists spot prices, calculates averages, and warms the statistics cache. Manual or repeated imports never invoke social publication.
- `social:publish-daily-spot` is scheduled independently at minute 15 each hour. It defers until exact hourly rows exist for both the Helsinki content date and next date.
- Real PostFast publication is disabled by default through `SPOT_SOCIAL_PUBLISHING_ENABLED=false`. Dry-run, skip-post, and draft modes do not use the `spot_social_publications` ledger. Draft still requires the enable setting because it calls PostFast.
- The durable ledger permits one first claim per Helsinki `content_date`. Normal calls never retry. `--retry --date=YYYY-MM-DD` permits only failed or processing attempts that are at least 30 minutes old. Published rows never retry.
- A PostFast timeout has an uncertain external result. Some posts can already exist. The command records failure and tells the operator to inspect PostFast before an explicit retry. Partial success (`posted_count > 0`) is published and skipped platforms are metadata, not automatic retry work.
- Detailed rules are in `app/Services/SpotSocial/AGENTS.md`.
- ENTSO-E fetches retry transient server errors and connection failures/timeouts (`ConnectionException`, including cURL 28) before failing.
- Spot fetch/backfill commands catch exhausted HTTP request/connection failures so scheduled jobs fail or continue gracefully instead of leaking raw exception stack traces.
- Do not log raw ENTSO-E exception messages without redacting `securityToken`, because Guzzle/Laravel exception text can include the full query string.
- Third-party spot forecasts are stored in `spot_price_forecasts`, never in `spot_prices_hour` or `spot_prices_quarter`, so forecasts cannot block later official ENTSO-E rows that use `insertOrIgnore()`.
- `spot:fetch-forecast` imports the public `vividfog/nordpool-predict-fi` feed. Its values are c/kWh including VAT; Voltikka derives VAT0 values only for internal comparisons/display metadata.
- The `/spot-price` forecast section must remain visually and textually separate from official prices and must cite `nordpool-predict-fi` by vividfog with the GitHub URL.

## Electricity futures imports

Primary files:
- `app/Services/ElectricityFutures/EexFuturesService.php`
- `app/Services/ElectricityFutures/AGENTS.md`
- `app/Console/Commands/FetchEexFutures.php`
- `app/Console/Commands/BackfillEexFutures.php`
- `config/eex_futures.php`

Important semantics:
- `futures:fetch-eex` collects EEX electricity futures end-of-day settlement prices into `electricity_futures_eod_prices`.
- Only the default non-dry scheduled scope writes the global `eex_futures` freshness checkpoint. Custom dates, maturities, areas, tenors, range counts, history windows, and dry runs never claim global readiness. A full run first writes a failed start marker, then becomes ready only with no fetch failures and at least one prior-date FI Base point extracted by that current run. An old database row cannot supply this proof; the dependent gate separately checks database presence and age.
- The command defaults to EEX Nordic System Price and Nordic zonal Base Month, Quarter, and Year futures (DK1, DK2, FI, NO1-NO5, SE1-SE4).
- EEX maturity strings are `YYYYMM`: month delivery month, quarter start month, and year January (`YYYY01`). The command probes the `price-ticker` endpoint first because out-of-bounds delivery dates return HTTP 200 with empty data; it discovers maturities once per tenor using a representative market, then fetches EOD data for those same maturity values across all configured markets.
- The public EEX chart endpoint requires `Referer: https://www.eex.com/` and only returns about 45 days of history; `futures:backfill-eex` fetches all history available from that public endpoint, and normal fetches cap requested ranges and safely upsert reruns.
- EEX API calls are deliberately slow-throttled by `EexFuturesService` with about 15 seconds plus/minus jitter between calls by default. Keep this polite throttle unless there is a strong reason to change it.
- The scheduled fetch runs overnight at 04:00 Europe/Helsinki instead of shortly after evening settlement because FI rows have been observed to lag the evening run; this gives the long polite-throttled import time to finish before the 07:30 fixed-contract forecast.
- Baltic power futures are not configured until verified EEX `area` + `shortCode` combinations exist in the EEX product-code file/API.

## Commands

### Refresh data and auto-link replacements
```bash
cd laravel
php artisan contracts:fetch --skip-logos
```
This uses `app/Services/ContractImport/` to import current contracts, refresh `active_contracts`, link high-confidence replacements, and run typed post-import work. Available contracts from a partial postcode acquisition are imported and the command reports the incomplete acquisition. Active rows absent from a partial response are preserved, and replacement linking waits for a complete acquisition. Only the default full scope writes the global `contract_import` freshness checkpoint; postcode-scoped runs never overwrite it. A full run first writes a failed start marker so a crash cannot preserve an old ready fact. Acquisition/import/required-stage failures are `failed`, partial acquisition is `incomplete`, and a complete successful post-import is `ready` with observed pointed-episode IDs, active IDs, and the exact statistics start and completion times.

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
