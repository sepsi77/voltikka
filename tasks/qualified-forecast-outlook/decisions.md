# Decisions

- Build on all existing local repair changes. Do not change frozen research tasks.
- Public text uses the saved direction, not legacy consumer_signal. Slight moves mean approximately unchanged; unknown means unavailable.
- V3 remains the numerical repair label. No coefficient, threshold, hysteresis or model change.
- Reports use stored completed median forecasts and saved actual provenance. Do not recalculate historical actuals from current statistics or configuration.
- Release, production preflight and initial generation require explicit approval. This task does not release code.

## Implementation

- Added one shared `ForecastOutlook` helper for known categories, Finnish labels, the complete three-term summary and the four mutually exclusive evaluation outcomes. Existing numerical `directionCategory` delegates to it and now returns null for unknown inputs.
- The forecast page, market teasers, SEO guide and decision article use saved direction, actual dates and the configured selected horizon. No forecast-based lock/wait or duration instruction remains. Unknown data is unavailable; partial term coverage is incomplete; different complete categories are mixed. The article retains the complete ordered p20/median/p80 requirement. Low history coverage does not suppress a known qualified rise/fall.
- Count-based confidence is described as the forecast data basis, not measured accuracy/probability. Market p20/p80 are not uncertainty intervals. SEO euro changes are annual rate equivalents, not savings from waiting. The existing layout and Preferred Sources are preserved.
- Insight payload v14, article v8, listing v6 and SEO listing v10 invalidate code-only copy. Inner and outer relevant cache keys include configured forecast model/horizon. The shared data fingerprint is unchanged.
- New evaluations add forecast/actual direction categories and correct/wrong_way/missed_move/false_move metadata. Actual-minus-current is rounded to existing four-decimal stored precision before threshold classification, so decimal boundary values cannot fall inside the band due to binary subtraction. Coefficients, forecast numerical prices, model label and saved thresholds are unchanged. Unknown saved forecast directions skip as unsupported. Completed evaluations stay outside the write query.
- `forecasting:report-fixed-contracts` is read-only. It defaults to configured model/horizon and accepts `--model-version` (including `all`), `--horizon`, `--from` and `--to` (issue-date window). It reads completed medians in 100-row pages ordered by issue date and ID. Group counters retain one last issue date, not all rows/date sets. It separates model/horizon/term/basis/unit method/evaluation method/threshold. Valid saved actual provenance is required; unsupported legacy actuals, unknown directions, incomplete rows and malformed values have named skips. No current statistics are fetched, and no completed record is saved. Rates use valid n only; empty data has no percentage. MAE is optional and was not added.
- The existing evaluate command prints the same compatible per-term median summary for new evaluated rows, including dry runs. There is no extra scheduler job or automatic old-actual apply.

## Verification

- Final targeted command: `cd laravel && php artisan test --filter='FixedContractPriceForecastingTest|FixedContractForecastReportTest|FixedDurationContractsListingTest|ArticleFixedTermContractTest|ContractMarketInsight'`: **60 passed, 848 assertions**, 1.74 seconds. Log `/tmp/qualified-targeted-final.log`.
- Final full command: `cd laravel && php artisan test`: **2,331 passed, 11,350 assertions**, 91.27 seconds. Log `/tmp/qualified-full.log`. This adds 10 tests to the repaired 2,321-test baseline.
- `cd laravel && npm run build`: passed, **60 modules**, 797 ms. Log `/tmp/qualified-build.log`. Existing warning: Browserslist data is nine months old. No dependency update was made.
- `vendor/bin/pint --test` on the 13 behavior/test PHP files: passed. The cache-key-only ContractsList file was not included in the final Pint subset: running Pint on it also reformatted pre-existing unrelated fully-qualified names. Those unrelated style edits were removed. Its final diff changes only the cache identity.
- `php -l` on all 14 changed/new PHP files, including ContractsList: passed. `git diff --check`: passed. Final code diff and working-tree status reviewed.
- Earlier targeted runs failed on six obsolete copy/summary assertions, then on a new report fixture missing required futures_trade_date, and finally on a test that fetched and emptied Artisan output more than once. Fixtures/assertions were corrected. All final tests pass.
- Tests cover all five directions, legacy-signal mismatch, unknown/partial/mixed data, low-coverage qualified rises, stored 45-day dates, title/schema copy, threshold boundaries, all outcomes, median-only counts, saved basis/method/threshold checks, no-write dry run/report, preserved old completed bytes, named malformed skips, no empty percentages, explicit selectors and stable multi-page counts.

## Remaining limits

- No browser-based visual check was performed. Feature HTML tests verify visible uncertainty and dated copy; responsive visual layout remains unverified. The manager will run the final mechanical design detector.
- No production access, normal database command, commit, push, deployment, dependency, migration, scheduler job or backfill was performed. Tests use the existing memory database configuration. Existing repair work and frozen research folders were preserved.
- Release and initial v3 generation remain pending explicit approval. This task makes no measured accuracy-superiority claim.
