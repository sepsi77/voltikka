# Verification

All application tests used the repository's testing SQLite-memory setup. No ordinary database report or migration command was run.

## Final commands and results

From `laravel/`:

```bash
php artisan test --filter='HistoricalChangeForecastTest|FixedContractPriceForecastingTest|MorningJobFreshnessGateTest|FixedContractForecastReportTest|ArticleFixedTermContractTest|FixedDurationContractsListingTest|FormInputBlurPolicyTest'
```

Result: **82 passed, 1,151 assertions**, 2.18 seconds.

```bash
php artisan test
```

Result: **2,332 passed, 11,575 assertions**, 95.61 seconds.

```bash
vendor/bin/pint --test app/Services/PriceForecasting app/Services/MorningFreshness/MorningJobFreshnessService.php app/Console/Commands/RunFixedContractPriceForecasts.php app/Console/Commands/ReportFixedContractPriceForecasts.php app/Console/Commands/EvaluateFixedContractPriceForecasts.php app/Livewire/FixedContractPriceForecast.php app/Models/FixedContractPriceForecast.php app/Services/ContractMarketInsights/ContractMarketInsightService.php config/price_forecasting.php database/migrations/2026_09_14_000001_make_forecast_gap_diagnostics_nullable.php tests/Feature/HistoricalChangeForecastTest.php tests/Feature/FixedContractPriceForecastingTest.php tests/Feature/FixedContractForecastReportTest.php tests/Feature/MorningJobFreshnessGateTest.php
```

Result: **passed**. An earlier dirty-file formatting run also changed unrelated existing fully-qualified imports in `ContractsList`; those incidental changes were restored. Its pre-existing qualified-outlook cache changes remain intact.

```bash
npm run build
```

Result: **passed**, 60 modules, 838 ms. The existing Browserslist database age warning remains; no dependency was installed or updated.

From repository root:

- `php -l` on all changed Laravel PHP/Blade files, new Laravel PHP files and the new replay script: **26 files passed**.
- `git diff --check`: **passed**.
- `php -d memory_limit=512M tasks/historical-change-forecast-release/replay.php`: **passed**. Actual SQLite memory target was proved before migrations/load. All 180 fits matched the independent raw-array mean. All 171 matured rows had exact targets. Original forecasts and each dry stage stayed byte-identical. Dry/apply row identities and results matched exactly. Repeated JSON output was byte-identical; see `replay-results.md`.
- SHA-256 verification against frozen p20 task pins: **164 prior research/export files unchanged**. The hedge service also matches its prior pin. Old research pins were not rewritten.
- Final diff/status review: existing uncommitted work remains; no commit or push was made.

## Test coverage

Generation-specific old v3 repair tests were replaced by intended historical-change tests. Legacy evaluation tests remain. New tests cover 19/20 with configured minimum 10, higher configured minimum, independent known means for all terms/quantiles, full earliest history, daily completed-pair addition, exact dates, current/future target exclusion, future boundary presence, invalid/latest ownership, absent and non-finite canonical endpoints, no fallback or seam crossing, zero/negative prices, cancellation, stored-precision threshold, 120/365 confidence thresholds, saved pair counts/bounds/bases, null diagnostics, generation without futures queries, legacy row preservation, dry run, calendar-date reruns and completed-row overwrite protection.

Freshness tests prove no forecast EEX checkpoint or futures queries, while retail-premium readiness still needs EEX data and age. An annual-method unit-shaped row cannot pass forecast readiness. Contract/publication recovery and its recheck tests remain.

Page tests cover honest historical-change copy, schema without obsolete diagnostics, nullable data, saved qualified outlooks, crossing notice without sorting, article ordered-distribution rejection, legacy public-model isolation and the unchanged form policy.

## Findings fixed during verification

- Existing command identity used a plain date string against SQLite's Eloquent midnight representation. A rerun could miss the row and hit the unique key. It now queries the exact calendar date and updates/creates the selected row explicitly.
- Completed rows now stay unchanged even with `--overwrite`.
- Initial old-model tests failed because they asserted removed gap-model mathematics or EEX forecast dependencies. They now test the approved model; final targeted/full runs pass.
- Initial replay exceeded the default 128 MB limit. The offline export verification command uses 512 MB; no application memory configuration changed. Existing PHP 8.5 vendor PDO deprecation warnings remain outside scope.

## Remaining approvals

No production access or mutation was performed by this agent. Review the exact release diff, approve branch/commit/push and normal deployment including the nullable migration, then approve an exact-context first generation separately if needed. The manager's read-only preflight found no model/minimum/threshold/horizon pins, so no variable mutation is currently indicated. Recheck if delayed. Model selection hides old public forecasts until new rows exist. Production may now have newer data than the export.
