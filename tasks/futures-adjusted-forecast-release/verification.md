# Local verification

## Final results

| Check | Actual result |
|---|---|
| `cd laravel && php artisan test --filter='FuturesAdjustedForecastTest\|HistoricalChangeForecastTest\|FixedContractPriceForecastingTest\|FixedContractForecastReportTest\|MorningJobFreshnessGateTest'` | 54 passed; 726 assertions; 2.48 seconds |
| `cd laravel && php artisan test` | 2,338 passed; 11,708 assertions; 91.08 seconds |
| Scoped `vendor/bin/pint --test` | Passed for all changed PHP sources/tests and the new PHP replay |
| `php -l` over the 16 changed/new non-Blade PHP files | No syntax errors; component lint repeated after final copy changes |
| `cd laravel && npm run build` | Passed; 60 modules; final build 735 ms |
| `git diff --check` | Passed |
| `python3 tasks/futures-adjusted-forecast-release/verify_replay.py` | Passed; two isolated real-PHP runs, identical outputs |
| Frozen artifact hash recheck | All 27 research/export artifacts unchanged |

Pint scope command from the repository root:
```bash
files=$(git diff --name-only -- '*.php' | grep -v '\.blade\.php$' | sed 's#^laravel/##')
cd laravel
vendor/bin/pint --test $files tests/Concerns/SeedsFlatForecastFutures.php tests/Feature/FuturesAdjustedForecastTest.php ../tasks/futures-adjusted-forecast-release/replay.php
```

The initial focused run exposed missing required short-code data in the new test fixture and old copy assertions. These were corrected. The first full run exposed a test comparing a newly created Eloquent instance with a reloaded database row; the baseline now comes from a fresh row. No final test fails.

Build warning: the existing Browserslist dataset is nine months old. No dependency update was made. PHP 8.5 emitted existing vendor PDO MySQL deprecations in the first direct replay; the final replay command filters deprecation notices, not errors.

## Model and safety coverage

- Synthetic all-nine-lane checks independently compute full unrestricted mean, smaller feature-cohort mean, population standard deviation, fixed ridge slope, contribution and four-decimal persisted predictions.
- Hard 20-start floors apply to both cohorts, even when configured minimum is 1 or 10. Missing all futures, missing lag and current-only vintages, insufficient feature history and incomplete latest curves omit forecasts.
- Month-roll test holds both baskets at next month of issue. Latest trades are strictly before both issue and issue minus seven days. Existing optional-delivery-start defaults still roll independently.
- One futures query per build across all terms/quantiles; an update between two builds is visible. No process-wide curve cache.
- Existing ownership/finite-current/seam/exact-date/no-target-leakage tests remain. Tests retain zero and negative retail values, zero feature variance, unchanged confidence thresholds and direction rounding.
- Historical-change/gap generation pins fail before freshness recovery. Saved historical-change, gap and new-model rows remain evaluable from saved provenance. Completed rows remain unchanged.
- Restored forecast EEX tests cover missing checkpoint/data, stale data and missing current-run FI proof. Existing retail-premium, unit-statistic, source-episode and publication-order recovery tests pass.

## Rendered copy checks

Laravel HTTP and Livewire render tests verify the new headline, plain method/futures explanation, stored horizon and dates, current prices, empty state, mixed/incomplete directions and crossed quantiles without reordering. The rendered page contains exactly one `Ennuste voi muuttua markkinatilanteen mukana.` notice. The old repeated disclaimer and technical ridge/cohort words are absent. Existing Preferred Sources/source-policy and article ordered-range tests pass in the full suite. No visual redesign or browser screenshot claim is made.

## Isolated export replay

`replay.php` boots the actual application with APP_ENV=testing, an absent environment/config-cache path, only SQLite `:memory:` configured, and disabled network functions/URL streams. It proves driver, PDO and empty PRAGMA database filename **before schema creation or data load**. Laravel Http also rejects stray requests. The normal local database is never opened. Only the isolated database receives the pinned export rows and replay forecasts/evaluations.

`verify_replay.py` launches this PHP replay twice with a minimal environment. It independently reads raw export arrays and calculates calendar-weighted month/quarter/year baskets, latest pre-issue/pre-lag vintages, owned retail pairs and the exact ridge formula. It does not import research or application fitting helpers.

Results:
- All **123** eligible primary (30-day, fixed-cohort) median predictions match frozen Python `production_futures`: **41 per term**, on **41 issue dates**.
- All **123** new saved rows evaluate successfully.
- All **1,314** exported old forecasts remain byte-equivalent, including completed evaluations.
- All **nine** September 13 lanes match the independent formula, including cohort metadata, means, standard deviations, slopes, contributions and rounded prices.
- September 13 uses **98 full pairs / 90 feature pairs** for every lane in this export.
- Two independent PHP memory-database runs return identical output. A later final rerun also matches the earlier result byte for byte. Replay output is recorded once in `replay-results.json`; older research/replay artifacts were not overwritten.

Export-only September 13 forecast prices (c/kWh):

| Term | p20 | Median | p80 |
|---|---:|---:|---:|
| 6 months | 15.6749 | 16.3375 | 17.6527 |
| 12 months | 11.6208 | 12.3025 | 13.1866 |
| 24 months | 10.3164 | 10.7440 | 11.3074 |

These are not exact production predictions. Production has January 21 onward retail history; the export begins April 8. Formula checks for p20/p80 do not establish measured quantile prediction accuracy. Frozen median research is not an untouched future holdout.

## Release status

Local implementation only. No production calls or mutations, commit, push, new migration or backfill. The original untracked `tasks/forecast-futures-increment-test/` folder remains intact. Review `release-plan.md` before requesting `git push origin main` approval and separate literal-date manual generation approval.
