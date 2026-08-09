# AGENTS.md

Laravel-specific guidance for Voltikka agents.

See root `../AGENTS.md` for project overview and architecture. Keep implementation details here, close to the code.

## Data investigation docs

Research/planning documents live in `data-investigation/`; read `data-investigation/AGENTS.md` first. The fixed-term contract price forecasting plan is `data-investigation/price-forecasting-plan.md`; local-only Python model exploration lives under `data-investigation/price-forecasting/`. The production implementation lives under `app/Services/PriceForecasting/`, persists forecasts, and serves `/sahkosopimus/sahkon-hintaennuste` through `app/Livewire/FixedContractPriceForecast.php`.

## Livewire form input boundary

Editable text, number, email, telephone, URL, date/time, and textarea controls use `wire:model.blur`. They must not send requests, validate, recalculate, or normalize on each keystroke. Search and autocomplete controls are marked `data-search-input` and use `wire:model.live.debounce.Nms`; results must update while the visitor types. Discrete controls such as checkboxes, radio buttons, selects, ranges, files, and explicit preset/action buttons can react immediately. Numeric inputs define a non-negative `min` unless the domain is explicitly signed, and Livewire must reject or normalize invalid values before calculation with visible feedback. `tests/Unit/FormInputBlurPolicyTest.php` scans every Blade template and prevents accidental live editable bindings, non-live search bindings, and missing numeric minima. See `app/Livewire/AGENTS.md` for the detailed rule.

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

## First-party analytics and private admin

Primary files:

- `app/Services/Analytics/AGENTS.md`
- `app/Filament/AGENTS.md`
- `app/Http/Controllers/Api/AnalyticsEventController.php`
- `database/migrations/2026_08_05_000001_create_contract_order_clicks_table.php`
- `database/migrations/2026_08_05_000002_add_is_admin_to_users_table.php`

ContractDetail has two direct seller CTAs. Both send the closed `contract_order_click` event through Beacon with a keepalive fetch fallback and keep the separate Plausible event. Browser attribution has a strict 30-minute inactivity rule and no visitor or session ID. Server-derived contract, price, and live-rank facts use a versioned signed context with a 96-hour lifetime.

The typed event rows have indefinite retention at the initial release. There is no cleanup job. The private Filament panel is at `/admin`; `users.is_admin` defaults to false, and non-admin credentials cannot enter. The analytics resource is read-only and paginated. Production admin provisioning is an explicit data mutation and never runs as part of deployment.

The installed compatible dependency set is Filament 5.7.5 and Livewire 4.3.5 on Laravel 11. Composer publishes Filament assets during the Docker build through `filament:upgrade`. Generated Filament public assets are not tracked source files.

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
- `contract_interpretations` stores strict output, validation errors, schema/prompt/validator provenance, usage, execution state, and the complete initial/correction call history; validator version participates in idempotency so stricter rules cause reanalysis. A transport failure can start a new queue execution, but the job merges and renumbers all earlier persisted `llm_attempts` and recomputes full usage and latency instead of replacing the first execution's history
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
- historical interpretation backfill is a separate append-only system. `contracts:backfill-historical-interpretations` discovers exact snapshot+component episodes through the deliberate 2026-07-22 cutoff in deterministic 25-contract chunks. Semantic evidence and analysis fingerprints ignore storage IDs, but each episode also stores an exact manifest fingerprint over target dates, snapshot IDs, component composite IDs, and normalized target economic digests. The compact plan includes that fingerprint, apply verifies it before writes, the job recomputes it, and the batched AsOf resolver checks both exact IDs and exact target values. Backcast prose can recover stable classification/mechanism facts only. Addendum v3 and backcast validator v2 bind every numeric billed/package fact one-to-one to a cited exact source component by the normal validator's canonical type mapping, source unit, role, and scoped discount timing; recurring dates and consumption-effect numbers stay null, duplicate billed components fail, and backcast deception is never `detected`. Historical calls use a 300-second timeout and one HTTP attempt. Three model calls fit the 1,000-second historical job envelope, and queue retries handle transport failures. `--dispatch` still requires an API key and `--yes`; output stops at `validated` and never publishes. Eligible pre-cutoff episodes can align semantic methods across the rollout, but component-only dates and genuine evidence/method transitions remain unavailable or gaps. Later dates use immutable source chronology. The public annual method stays legacy

### Legacy contract pricing

`app/Services/ContractPriceCalculator.php` owns both feature-off annual pricing and the typed exact-period path used by all bill-comparison surfaces. Both paths resolve rates, legacy Spot detection and the first non-monthly margin, component-scoped discount amounts, supported normalized/upstream units, and inclusive `UntilDate` timing in one place. Exact periods use flat local-day kWh, 85/15 Time and winter-day splits, actual winter dates, realized with-VAT Spot averages, and day/30 ordinary fees. `BillComparisonService` keeps queries, consumption-cap policy, annualization, and canonical fail-closed behavior, but does not calculate relational rates itself. Its legacy annual estimate anchors promotion timing at the bill start.

### Contract price statistics

Voltikka stores daily contract-price trend data for `/sahkosopimus/tilastot`.

Primary tables:
- `contract_price_snapshots` — one daily row per included contract with normalized component prices and annual-cost estimates for 2000/5000/18000 kWh.
- `contract_price_annual_costs` — versioned annual-only rows by date, contract, consumption, and method; initially shadow persistence only.
- `contract_price_daily_statistics` — aggregate daily min/p20/average/p80/max rows by segment, metric, and method version.

Primary implementation:
- `app/Services/ContractStatistics/ContractPriceStatisticsService.php`
- `app/Services/ContractStatistics/AGENTS.md`
- `app/Console/Commands/CalculateContractPriceStatistics.php`
- `app/Console/Commands/BackfillContractPriceStatistics.php`
- `app/Livewire/ContractPriceStatistics.php`

Commands:
```bash
php artisan contracts:calculate-price-statistics --overwrite
php artisan contracts:backfill-price-statistics --from=2025-01-01 --to=2026-04-29 --overwrite
php artisan contracts:rebuild-annual-cost-statistics --from=2025-01-01 --to=2026-04-29
php artisan contracts:rebuild-annual-cost-statistics --date=2026-04-29 --apply
```

Important semantics:
- future daily calculations are run during `contracts:fetch` and use `active_contracts`; in canonical mode all current numeric metrics and measured offer state come from batched typed canonical outcomes and no `price_components` query runs
- `/sahkosopimus/tilastot` serves cached prepared view data per period + consumption. Its v15 key and source fingerprint include the configured active annual method. Production has used `annual_cost_as_of_v1` since 2026-08-09; legacy rows remain for rollback
- the contract post-import coordinator calls `ContractPriceStatisticsService::calculateForDate()` before optional percentile badge thresholds so `/sahkosopimus/tilastot` continues to advance even if percentile recalculation fails; it captures exact start and completion timestamps immediately around this call for the morning freshness checkpoint
- `contracts:warm-price-statistics-cache` queues `App\Jobs\WarmContractPriceStatisticsCache` by default; use `--sync` only for manual immediate warming/tests. The contract post-import coordinator dispatches that job directly for weekly/5 000 after successful statistics; `spot:fetch` also queues it after source data updates.
- Production containers start `php artisan queue:work --queue=default,historical-interpretation --timeout=1020 --tries=3` through root `supervisord.conf`; database queue `retry_after` has a code-enforced minimum of 1,050 seconds. A lower environment value cannot release a job while it still runs. The default queue has priority, and historical interpretation uses its isolated queue. Historical model calls have a separate 300-second total timeout and one HTTP attempt. One initial call plus two repair calls stays below the 1,000-second historical job timeout. Other job-level timeouts stay unchanged. Queued cache warmers and contract interpretation depend on that worker running.
- historical backfills infer availability from `price_components.price_date` and always store observed seller evidence; `pricing_basis` distinguishes these rows from canonical forward calculations in the page and CSV
- the `annual_cost_as_of_v1` historical core uses the union of exact-date snapshot and component contract identities. A component-only identity has no safe historical classification, so it yields exactly three typed `missing_historical_snapshot_identity` exclusions under `unclassified`; snapshot-only canonical evidence remains eligible. It first uses one unambiguous covering source snapshot and a parser-valid interpretation completed by that date. Only when no source observation covers the date can one current-builder dedicated historical episode supply canonical data. The episode must contain the exact target snapshot and sorted component composite identities, and exactly one validated current-contract historical analysis must pass a fresh parser check. It batches components, chronology, both interpretation stores, exact-target Spot assumptions, and supplier episode anchors. It does not read active contracts, current canonical columns, prose, or current interpretation pointers. `contracts:rebuild-annual-cost-statistics` selects the union of snapshot/component dates, previews by default, and applies only with `--apply`. Apply rejects empty or incomplete three-consumption identity sets before replacement. Canonical current daily calculation instead adapts the exact already-calculated public canonical outcomes after snapshots exist, with one batched current-pointer provenance query and no `price_components` query. Its writer stays inside the same date transaction. Feature-off and historical observed calculations do not run the current adapter. Public readers remain method-isolated
- `ContractStatisticsSegmentClassifier` owns the one basis-aware segment rule and label map. Canonical card facts map every non-Spot monthly/quarterly/seasonal/other reset to `market_reset` (`Jaksoittain vaihtuva hinta`), with Spot and reset-over-Hybrid precedence from `PricingBucket`. Observed rows keep the old text-first `quarterly` rule and are never relabelled or rewritten
- all public annual-cost consumers explicitly select the configured active annual method. `annual_cost_as_of_v1` has been active in production since 2026-08-09. Unit consumers explicitly select `unit_statistics_v1`; mixed queries use the model scope that combines those two branches. Canonical flag on still requires `canonical_calculation` for current unit data, while feature-off requires `observed_seller_data`. On the main page endpoint, active annual rows must use that expected basis or `mixed_evidence`, so feature-off cannot expose a stale canonical AsOf row. Stored compatibility stays strict for audit. Public aggregate median charts derive a display regime from the dominant estimate method in valid AsOf basis counts, so minority changes do not split a line but dominant transitions still do. Weekly/monthly statistics series append the latest daily median at its exact date and show point markers. Daily charts mark only each series' latest non-null point, so a current isolated value remains visible without covering the dense history in dots. Company comparison branches by the active method: legacy keeps snapshot annual columns, while AsOf reads seller totals only from `contract_price_annual_costs` and requires same-date active-method market rows
- one pricing basis owns each newly calculated date: inside the calculation transaction, the target date loses opposite-basis snapshots and the run's own prior snapshots before aggregates are rebuilt; this removes stale canonical exclusions without deleting other dates. Legacy/current calculations replace only `unit_statistics_v1` and `annual_cost_legacy_v1` daily rows, so AsOf daily rows and annual-only rows survive current calculations and historical backfills
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
Scheduled in `routes/console.php`: EEX futures fetch runs overnight at 04:00 Europe/Helsinki so previous trading-day FI settlements are available before the forecast run; forecast run daily at 07:30 Europe/Helsinki, evaluation daily at 07:45. Scheduled retail collection and forecast generation use `--require-freshness`; the shared `app/Services/MorningFreshness/` gate requires same-date full contract/EEX checkpoints, exactly one observed pointed episode and a current publication for each active contract, current-run prior-date FI Base proof, and recent FI Base database data. Forecasts also require at least one current 6/12/24 statistic for the selected pricing basis and a statistics start after the newest required publication. When publication order is the only forecast failure, the scheduled non-dry command overwrites that date's statistics from all current active contracts and runs the complete gate again against the new calculation start. Retail freshness does not use this recovery.

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

### Asset loading

`resources/views/layouts/app.blade.php` relies on Laravel Vite's generated CSS preload/modulepreload tags and Livewire's own versioned script tag. Do not add a manual `as="script"` preload for the Vite module: its request mode differs from the module request and can cause a duplicate download. Do not preload an unversioned `/vendor/livewire/livewire.min.js`; Livewire emits the versioned file it actually executes. `tests/Feature/LayoutAssetLoadingTest.php` guards both rules.

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

## Local production database sync

Use the repository wrapper from the root:

```bash
scripts/sync-production-database.sh
scripts/sync-production-database.sh --yes
```

The wrapper creates a `.production-sync-*` temporary SQLite file in `laravel/database`. Each Artisan child uses a unique nonexistent config-cache path, local environment, an empty `DB_URL`, and the explicit SQLite target. A verification-only command proves the effective target before migration. The Railway child receives these local-target values after variable injection while it keeps injected MySQL source values. The MySQL read uses one read-only consistent transaction and unbuffered PDO. The copy excludes local authentication and runtime tables, preserves the fresh target `migrations` table, and validates row counts, foreign keys, and SQLite integrity. Application-table drift fails in both directions, with one temporary production-schema-lag exception: if production lacks the local-derived `contract_source_observations` table, the fresh target reconstructs its rows and contract pointers with the unchanged `2026_07_30_000002` migration logic before final validation. If production has that table, the sync copies it normally.

The wrapper stops if `database/database.sqlite` or one of its WAL/shared-memory/journal sidecars is in use. It does not open or change the active database until the fresh target passes sync validation. It then uses `sqlite3` backup for a consistent timestamped backup in `/tmp`, validates that backup, checkpoints the active database, removes every old sidecar, makes the final possible `lsof` check, and atomically replaces the main file. `lsof` cannot prevent a process from opening the file after a check, so all local processes must stay stopped for the full operation. A migration, connection, copy, validation, backup, checkpoint, or lock failure stops replacement. See `app/Services/DevelopmentDatabase/AGENTS.md` for copy rules. Do not run the Artisan command directly; its target guards accept only the wrapper-shaped temporary file and reject an inode alias of the active database. Never print Railway database variables.

Prerequisites are PHP with `pdo_sqlite` and `pdo_mysql`, Railway CLI authentication, `sqlite3`, and `lsof`. Stop Laravel, queue workers, and database tools before you run the wrapper. Restart them after replacement.

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

`AppServiceProvider::boot()` centrally listens for Laravel scheduler lifecycle events. It logs a `ScheduledTaskFinished` error only for a non-zero exit code, logs a `ScheduledTaskFailed` error with the exception class but never its message, and logs a `ScheduledTaskSkipped` error only when `withoutOverlapping` is active. Deliberate filter skips and secondary-replica `onOneServer` decisions do not create alerts. Context is limited to the public task display summary, cron expression, timezone, and applicable exit code/runtime or exception class. Do not add repeated callbacks to each schedule.

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
- `app/Services/SpotPriceImport/SpotPriceImporter.php`
- `app/Services/SpotPriceImport/AGENTS.md`
- `app/Console/Commands/FetchSpot.php`
- `app/Console/Commands/BackfillSpot.php`
- `app/Models/SpotPriceForecast.php`
- `app/Services/SpotForecasts/AGENTS.md`
- `app/Console/Commands/FetchSpotForecast.php`

Important semantics:
- `SpotPriceImporter` is the source of truth for official record normalization, Helsinki-local historical VAT, direct hourly persistence, and region+UTC-hour arithmetic aggregation from quarter-hour records. It uses insert-only `insertOrIgnore()` chunks of 500.
- Backfill skips a half-open UTC chunk only when every exact expected FI hourly timestamp exists. Partial data and off-hour rows do not satisfy coverage. Exhausted request/connection failures do not stop later chunks, but any failed chunk makes the command return failure after averages are calculated for records imported by other chunks.
- `spot:fetch` only persists spot prices, calculates averages, and warms the statistics cache. Manual or repeated imports never invoke social publication. Its hourly Europe/Helsinki schedule uses one-server execution and a 60-minute overlap-lock expiry so an interrupted run does not block the next day of imports.
- `spot:check-freshness` is a read-only independent check at minute 10 of each Helsinki hour. It has no overlap mutex. It writes one Laravel error log when the latest official FI UTC hour is older than the current Helsinki hour start, so the configured log stack sends the error to Sentry.
- `social:publish-daily-spot` is scheduled independently at minute 15 each hour. It defers until exact hourly rows exist for both the Helsinki content date and next date.
- Real PostFast publication is disabled by default through `SPOT_SOCIAL_PUBLISHING_ENABLED=false`. Dry-run, skip-post, and draft modes do not use the `spot_social_publications` ledger. Draft still requires the enable setting because it calls PostFast.
- The durable ledger permits one first claim per Helsinki `content_date`. Normal calls never retry. `--retry --date=YYYY-MM-DD` permits only failed or processing attempts that are at least 30 minutes old. Published rows never retry.
- A PostFast timeout has an uncertain external result. Some posts can already exist. The command records failure and tells the operator to inspect PostFast before an explicit retry. Partial success (`posted_count > 0`) is published and skipped platforms are metadata, not automatic retry work.
- Detailed rules are in `app/Services/SpotSocial/AGENTS.md`.
- ENTSO-E fetches use an explicit 5-second connection timeout and 30-second total request timeout by default, configurable under `services.entsoe`, then retry transient server errors and connection failures/timeouts (`ConnectionException`, including cURL 28) before failing.
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
