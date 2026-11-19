# pricing-reviewer — staged-api-imports focused final check
Range: 743f1c14a0066e6e65ac042bc529ff91ef877a5c..a906fac9fe3c0873269e9d19ad3f3f2d3b0e208e · Result: clean

Static check of the two verification residuals and their direct write-path changes only. No tests, Artisan commands, live API calls, database probes or production access. Local untracked review prompts are outside source scope.

## Residual verdicts

### PRC-001 — contract superseded check: **Fixed**

- `laravel/app/Console/Commands/FetchContracts.php:44-48,212-226` acquires the same `contract_import_store_v1` lock before the freshness read. It is unconditional for full/scoped and default/replay stores. Acquisition timeout returns `store_busy` without entering the importer.
- The superseded read (`FetchContracts.php:372-378`) now precedes replay checkpoint takeover (`:229-236`) and the authoritative import (`:258-266`), all inside the protected block. `ContractImporter.php:62-88` owns and completes the database transaction before returning; `FetchContracts.php:281-283` releases the lock afterward, including exceptional exits. The trait does not wrap this command in a later outer transaction (`FetchContracts.php:92-96`). No other application caller of `ContractImporter::import()` was found.
- Consequently, if newer B (12 c/kWh) commits while old A (8 c/kWh) waits, A reads B after acquiring the lock and refuses before source, availability or price writes. At 5,000 kWh with no fees, B remains €600/year rather than reverting to €400. If A acquires first, B waits for A's commit and then supersedes it. Fetch/network work and independently guarded post-import work remain outside this lock.
- Regression source inspected, not run: `laravel/tests/Feature/FetchContractsCommandTest.php:1317-1417` checks busy full/scoped stores and B committing during A's simulated acquisition wait.

### PRC-002 — Spot-forecast recency check: **Fixed**

- `laravel/app/Console/Commands/FetchSpotForecast.php:66-70` disables the trait's outer transaction. `:108-113` instead acquires the source/region lock and opens the database transaction inside its callback.
- `:140-174` performs the freshness decision, display upsert and append-only run insertion within that transaction. `:184-194` still checks both archived vintages and display timestamps, including unchanged newer refetches that add no archive row. There is no application display writer outside this command.
- Laravel commits before `DB::transaction()` returns (`laravel/vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:49-73`); the lock callback releases only after returning (`laravel/vendor/laravel/framework/src/Illuminate/Cache/Lock.php:131-137`). Thus a waiting old A ([8]) sees committed B ([11, 12]), archives A only, and leaves the public curve [11, 12], not [8, 12]. The price/provenance upsert remains atomic. Cache invalidation is still conditional on a display write and runs after commit (`FetchSpotForecast.php:122`; `laravel/app/Console/Concerns/StagedImport.php:100-113`).
- Regression source inspected, not run: `laravel/tests/Feature/FetchSpotForecastCommandTest.php:333-408` checks busy-store no-write behavior, archive retention and the page's newer values after a simulated acquisition wait.

## Stored price identity: unchanged

- Contract import arguments retain the payload's effective date and fetch time (`FetchContracts.php:259-265`); importer, component writer, source fingerprint/episode rules and schemas are unchanged in this range.
- Forecast display identity remains `(source, region, timestamp)` (`FetchSpotForecast.php:155-168`); archived vintage identity remains source plus raw-body feed hash, with original `fetched_at`, sorted points and values (`:204-226`). Parser, models and migrations are unchanged.
- `SpotPriceAverageService.php` has no further diff: the previously verified date lookup correction, rolling-window identities and numerical calculation are unchanged. No canonical calculation, VAT basis, annual method, pricing-basis marker or calculated-cost cache schema changes occur. The new keys are execution mutexes, not price/cache-payload identities.

The serialization assessment assumes the documented shared cache and stores finishing within their execution leases (contracts 1,800 s; forecast 300 s). `config/deploy.yml:113` selects shared Redis; its lock uses atomic NX acquisition and owner-checked release (`laravel/vendor/laravel/framework/src/Illuminate/Cache/RedisLock.php:34-40,63-65`). This is static ordering verification, not an executed cross-process Redis/MySQL concurrency certification.

## New findings

Findings: none.

## Files inspected

- The complete two-command production diff and their current store paths; `StagedImport.php`; relevant `ContractImporter.php` and `ContractPostImportCoordinator.php` sections.
- `NordpoolPredictFiService.php`; Laravel lock/Redis-lock/transaction implementations; `config/deploy.yml` cache selection.
- The two added command regression groups (read only); public forecast page and CSV readers (`SpotPrice.php:759-796`, `SpotPriceCsvController.php:76-94`).
- Task spec, decisions, tasks and preceding pricing verification report; root/Laravel and nearest import, forecast, canonical pricing, statistics, caching, premium and card contexts. Confirmed no diff in pricing services, models, migrations or configuration identity paths.
