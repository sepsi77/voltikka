# Local PHP forecast replay — 2026-09-13

## Scope and limits

Actual revised FixedTermPriceForecastService, FixedTermHedgeCostService and FixedTermForecastEvaluationService ran locally. This is a retrospective replay of export-time records, not an archived-vintage-perfect trial or proof of historical HTML. Older source values and curve availability can differ from the original run. No production operation, app edit, dependency install, or existing local database use occurred.

V3 uses observed_prefix_canonical_continuation_v1. Continuity is an explicit assumption: ordinary unit quantities are comparable, but discounts, corrected rates, eligibility and classification can differ. It is not cross-basis equivalence. All statistics use unit_statistics_v1. The September 12 annual_cost_as_of_v2 change does not restrict this unit history. Alpha 0.25, lambda 0.30, minimum 10 and direction threshold 0.15 are fixed. Confidence counts only canonical evidence.

## Isolation and data checks

APP_ENV=testing, absent config-cache and environment-file paths, empty DB URLs, SQLite :memory:, disabled Sentry, null logging and array cache are set before bootstrap. Connections are reduced to SQLite before providers boot. HTTP stray requests are blocked. Before each schema/load, PDO driver, configured :memory: and PRAGMA main file='' are checked and printed. Three independent memory fixtures load complete forecasts/statistics/futures; only the counterfactual fixture then removes observed statistics. No runtime feature was added. Three actual create-table migrations run, with statistics-only column/index changes derived from the April 29, July 27 and August 6 migrations.

Manifest SHA256: `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`. All manifest files pass hash, byte and expected/actual count checks before and after the run. The manifest itself is unchanged. Completeness means the manifest's bounded export, not all production tables. Loaded rows: 1,314 forecasts; 477 unit statistics; 2,239 FI Base futures.

## Coverage and learning

| Fixture | Generated dates | Rows | First date | Matured dates per term/quantile | Evaluated rows |
|---|---:|---:|---|---:|---:|
| continuity | 49 | 441 | 2026-07-27 | 19 | 171 |
| canonical_only | 39 | 351 | 2026-08-06 | 9 | 81 |

The full July 27–September 13 calendar has 49 days. Continuity has no gaps. Canonical-only skips July 27–August 5 because fewer than 10 prior canonical days exist. Its first run is August 6. Continuity matured issue dates are July 27–August 14 (targets August 26–September 13); canonical-only has August 6–14 (targets September 5–13). Stored v2 lacks August 2–4, plus August 22 in the full replay interval. Thus stored-v2 matching has 16 matured dates per term/quantile, not 19. Full exact-date CSV comparisons contain 405 rows.

Independent raw-array history selection and EWMA checks confirm every generated row's counts, bounds and premium. On September 13, each of nine rows has 157 accepted days: 109 observed (April 9–July 26) plus 48 canonical (July 27–September 12). April 8 statistics exist but have no strictly earlier futures trade in this export. Current day September 13 never enters learning. The boundary is derived from canonical presence, not hardcoded. V2 remains at 109; v3 advances through September 12. Confidence remains low (48 current-basis days, below 120). Canonical-only has 48 accepted days; its current forecasts equal continuity at four decimals after 48 EWMA updates.

## September 13 median forecasts

Prices and premiums are c/kWh, VAT included. Target date is October 13; no actual exists yet. Other quantiles are in JSON/CSV.

| Term | Current | Stored v2 | V3 | Canonical only | V2 normal premium | V3 normal premium | V2 direction → V3 |
|---|---:|---:|---:|---:|---:|---:|---|
| 6 | 14.5294 | 14.6839 | 14.5981 | 14.5981 | 1.3692 | 1.0833 | rising → slightly_rising |
| 12 | 11.9409 | 11.7091 | 11.9272 | 11.9272 | 1.7866 | 2.5138 | falling → slightly_falling |
| 24 | 10.5600 | 10.5333 | 10.5305 | 10.5305 | 2.1343 | 2.1247 | slightly_falling → slightly_falling |

## Original evaluation safety

The original full-table ID-ordered JSON byte string, including JSON metadata strings and every column, is identical before/after the actual dry run. Original versions: v1=909, v2=405. The 639 completed original evaluations remain byte-identical. Actual dry run: 144 evaluated, 270 missing actual, 0 unsupported. All 144 new evaluations are canonical-current v2 with exact same-basis canonical targets (16 dates × 3 terms × 3 quantiles). The 270 missing records are v1 observed-basis targets; canonical values are not relabelled as equivalent actuals. Original completed evaluations are not used for the canonical accuracy cohort.

Local apply evaluates the same 144 rows, preserves every original forecast field, and leaves completed records unchanged. Repeat apply evaluates zero rows and leaves the full table byte-identical. Separate v3 dry runs evaluate 171 continuity and 81 canonical-only rows, with no missing/unsupported targets. Their full tables remain byte-identical on dry run. In both replay fixtures all original versions remain unchanged after v3 insertion. Independent signed error, absolute error, baseline and direction-category checks pass.

## Matched accuracy — median is the main result

Exact same issue date, target date, 30-day horizon, term and quantile are required. V2 and v3 use identical exported canonical current and target prices; these are stored statistical outcomes, not invented or projected actuals. MAE/RMSE/bias use forecast minus actual. Direction accuracy uses rising/falling/flat categories; slight moves map to flat. All metrics are c/kWh except direction.

| Quantile | Term | N | V2 MAE | V3 MAE | Unchanged MAE | V2 RMSE | V3 RMSE | V2 correct | V3 correct | Label/category switches |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| median | 6 | 16 | 1.5132 | 1.5489 | 1.4934 | 1.5527 | 1.5803 | 2/16 | 1/16 | 5/3 |
| median | 12 | 16 | 0.5720 | 0.4553 | 0.4023 | 0.6105 | 0.5067 | 0/16 | 3/16 | 10/9 |
| median | 24 | 16 | 0.3004 | 0.2333 | 0.2051 | 0.3421 | 0.2831 | 9/16 | 9/16 | 4/0 |
| median | all | 48 | 0.7952 | 0.7458 | 0.7003 | 0.9833 | 0.9720 | 11/48 | 13/48 | 19/12 |
| p20 | 6 | 16 | 1.7442 | 1.7406 | 1.6628 | 1.7744 | 1.7752 | 2/16 | 1/16 | 4/2 |
| p20 | 12 | 16 | 0.6220 | 0.4952 | 0.4399 | 0.6337 | 0.5248 | 0/16 | 0/16 | 11/10 |
| p20 | 24 | 16 | 0.3582 | 0.2975 | 0.2684 | 0.3794 | 0.3337 | 6/16 | 6/16 | 1/0 |
| p20 | all | 48 | 0.9081 | 0.8444 | 0.7903 | 1.1096 | 1.0860 | 8/48 | 7/48 | 16/12 |
| p80 | 6 | 16 | 1.2912 | 1.2613 | 1.1577 | 1.4087 | 1.3716 | 1/16 | 1/16 | 7/4 |
| p80 | 12 | 16 | 0.5980 | 0.4253 | 0.3579 | 0.6385 | 0.4871 | 0/16 | 3/16 | 12/12 |
| p80 | 24 | 16 | 0.3053 | 0.2573 | 0.2359 | 0.3706 | 0.3282 | 6/16 | 6/16 | 4/0 |
| p80 | all | 48 | 0.7315 | 0.6480 | 0.5838 | 0.9182 | 0.8614 | 7/48 | 10/48 | 23/16 |

V3 repairs frozen input learning; it does not beat the unchanged-price baseline on these aggregate quantiles. Median six-month MAE and direction accuracy are worse than stored v2. All matched errors are negative (underprediction), so signed mean bias is the negative MAE. Results do not establish measured forecasting superiority. Switches compare old/new predictions on the same cohort, not a forecast direction against a target direction or adjacent issue dates. JSON contains each switch's issue date, target date, term and labels.

## Full and counterfactual cohorts

| Quantile | Full continuity N / MAE / baseline | Counterfactual N | Canonical-only MAE | Continuity on same counterfactual cohort MAE | Same-cohort baseline |
|---|---|---:|---:|---:|---:|
| median | 57 / 0.7320 / 0.7026 | 27 | 0.655056 | 0.654744 | 0.586467 |
| p20 | 57 / 0.8178 / 0.7782 | 27 | 0.723270 | 0.722844 | 0.648148 |
| p80 | 57 / 0.6364 / 0.5933 | 27 | 0.559059 | 0.559204 | 0.475526 |

Do not compare the full 19-date and counterfactual nine-date errors as if the cohorts were equal. JSON includes term-level results, RMSE, bias and direction accuracy for each separate cohort. The counterfactual reduces warm-up coverage by 10 days and matured coverage by 90 rows.

## Independent hedge check

Raw exported curves are reconstructed with native DateTimeImmutable and arrays: strictly earlier trade, next full delivery month, day weighting, month→quarter→year fallback, VAT 1.255. Actual PHP H matches independent H to 1e-10 across the complete April 8–September 13 daily calendar for all terms and all 438 stored median rows. April 8 correctly returns no coverage. There are 1269 calendar/generated checks in addition to 438 stored checks. Of 438 stored H values, 435 match export-time reconstruction within four-decimal rounding. The three exceptions are May 26: original trade date May 22 versus export-time latest May 25; maximum difference 0.301027 c/kWh. These are not evidence of an H algorithm error. They demonstrate why this is not a vintage-perfect replay. Exact exception records are in JSON.

## Verification

- `php -l tasks/fix-forecast-learning-evaluation/replay.php`: passed.
- `php -d memory_limit=512M tasks/fix-forecast-learning-evaluation/replay.php > /tmp/voltikka-forecast-replay.log 2>&1`: passed; all fail guards and numeric checks passed. PHP 8.5 reports existing vendor PDO MySQL constant deprecations; no database connection to MySQL occurs.
- Manager-reported finalized-code run: `cd laravel && php artisan test`: 2,321 passed / 11,192 assertions, 92.86 seconds. Log `/tmp/voltikka-forecast-continuity-tests.log`. The manager reports no app changes during that run. The replay agent did not rerun the full suite.

Artifacts: this report, replay-results.json (full generated/evaluated rows and all cohorts), replay-results.csv (stored-v2 exact-date comparisons). No production rollout is authorized by these results.
