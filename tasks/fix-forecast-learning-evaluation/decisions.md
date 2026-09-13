# Decisions

- Correction: the initial canonical-only plan is superseded. V3 uses `observed_prefix_canonical_continuation_v1`, preserving older observed unit history before the first canonical unit observation for that term and then updating only with canonical observations. No coefficient or futures changes.
- The unit transition is derived from same-term energy_price/unit_statistics_v1/null-consumption canonical presence no later than asOf. It is not hardcoded to July 27 or the unrelated September 12 annual_cost_as_of_v2 switch. Current-date presence can own the boundary, but all learned observations strictly precede asOf. Future rows cannot establish an earlier boundary. Missing or invalid canonical dates after transition never permit observed fallback.
- The independent reader traced ordinary relational General and canonical signup-phase General prices to the same unit quantity, not an annual energy average. Tariff weights match, but discounts, corrected rates, eligibility, and classification can differ. Continuity is therefore an explicit assumption, not universal equivalence. Canonical-only learning remains an offline validation alternative, not a new runtime flag.
- Preserve original basis counts and date bounds, add the policy and transition date, and count all accepted evidence in history_observations. Minimum history remains 10 combined observations. Confidence uses only accepted matching-current-basis observations, stored as confidence_history_observations, with unchanged 120/365 thresholds. This permits an observed initialization without claiming more accuracy confidence from unverified cross-basis evidence. Alpha remains 0.25 and lambda remains 0.30. No model or premium schema change.
- Only known v1 may default missing basis and threshold. Known v1/v2 may default missing unit method. Invalid present values never default.
- Production evidence replay was completed in a separately authorized local-only unit. See the results below and replay-results.md.
- Generation validates the model label in the command before freshness recovery. Thus a reserved environment pin cannot cause a statistics refresh before rejection.
- Latest-ID daily evidence is selected before null/finite validation. An invalid newer row does not revive an older same-date value.
- Evaluation returns calculated in-memory rows in both modes; dry run never calls save. Existing completed evaluations remain outside the query.
- The v3 default hides existing public v2 forecasts until eligible v3 generation. A v2 environment pin blocks generation. Production preflight must check that pin, accepted continuity history/current unit evidence and current-basis confidence counts, and futures coverage. Release and first v3 generation require explicit approval. No seamless transition or accuracy superiority is claimed.
- The preliminary 39-date/nine-matured-date claim came from the restrictive plan and is superseded for continuity. The completed replay measures 49 generated dates and 19 matured dates for continuity, versus 39 and nine for the separate canonical-only fixture.
- Evaluation safeguards are unchanged. V1 targets with canonical evidence only remain missing rather than asserting cross-basis accuracy. V2 canonical-current targets can use valid canonical exact-date unit evidence.
- Initial test failures came from comparing unrefreshed Eloquent create attributes with stored SQLite rows, and from PDO binding PHP INF as text. Fixtures now compare stored rows and use SQLite numeric overflow for a real non-finite value. All final tests pass.

- Final scope: keep the measured supplier findings separate. The [supplier coverage report](../supplier-premium-coverage/coverage-report.md) has 411 compatible General formal premium rows from 10 suppliers, not 411 independent price changes. The [supplier forecast pilot report](../supplier-forecast-pilot/report.md) finds that the exploratory supplier index does not beat the unchanged-price baseline. No smoothing or copy changes were made, and no supplier production model was adopted. Production release remains pending explicit approval.

## Initial draft verification (before the continuity correction)

- `php artisan test --filter=FixedContractPriceForecastingTest`: 17 passed, 151 assertions.
- Related forecasting/freshness/statistics/article filter: 56 passed, 565 assertions before the final extra non-finite test.
- Final `php artisan test`: 2318 passed, 11159 assertions, 90.33 seconds. Log: `/tmp/voltikka-forecast-fix-tests-final.log`.
- `php -l` on all 8 changed PHP files: passed.
- `vendor/bin/pint --test` on all 8 changed PHP files: passed.
- No CSS or JS changes; no asset build needed.
- No production access, commit, push, replay script, or changes to the previous review folder.

## Continuity correction verification

- Initial focused run: 3 new tests failed because SQLite returned a timestamp for the transition date. Normalize this metadata to YYYY-MM-DD; corrected runs pass.
- `php artisan test --filter=FixedContractPriceForecastingTest`: final run passed, 20 tests / 184 assertions.
- `php artisan test --filter='FixedContractPriceForecast|ContractPriceStatistics|MorningJobFreshnessGateTest|ArticleFixedTermContractTest'`: final related run passed, 102 tests / 749 assertions.
- Earlier related subsets passed: 74 tests / 486 assertions and 28 tests / 261 assertions, before the last two added assertions.
- `vendor/bin/pint --test app/Services/PriceForecasting/FixedTermPriceForecastService.php tests/Feature/FixedContractPriceForecastingTest.php`: passed. `php -l` on both files: passed.
- `git diff --check`: passed. Final diff and status reviewed. No asset build required.
- The earlier full-suite result applies only to the initial draft. The corrected policy now has evidence replay and a manager-verified full-suite result, recorded below.
- No production operations, commit, push, CSS/JS changes, or changes to tasks/supplier-premium-coverage and tasks/forecast-stability-review.

## Completed local PHP evidence replay

- Command: `php -l tasks/fix-forecast-learning-evaluation/replay.php` passed. Command: `php -d memory_limit=512M tasks/fix-forecast-learning-evaluation/replay.php > /tmp/voltikka-forecast-replay.log 2>&1` passed. It executes the actual revised forecast, hedge and evaluation services, not replacement forecast math.
- Repeated the finalized replay with output in `/tmp/voltikka-forecast-replay-repeat.log`. `shasum -a 256 -c /tmp/voltikka-replay-first.sha` reports OK for JSON, CSV and Markdown: all three artifacts are byte-identical across runs. `git diff --check` passed. Final task files and working-tree status were reviewed; unrelated application edits were left untouched.
- Verified every manifest file's SHA256, bytes and expected/actual counts. Loaded all 1,314 forecasts, 477 unit statistics and 2,239 FI Base futures. Rechecked original export hashes at the end. No source export changes.
- Environment/config-cache/DB URL isolation is set before Laravel bootstrap. Before each schema/load, verify testing, SQLite PDO, configured `:memory:` and empty PRAGMA main file. Only three actual table-create migrations plus their statistics-only schema additions run. Each fixture uses a new memory database. No existing database is opened. Sentry is disabled, logs use the null channel and HTTP stray requests are blocked.
- Continuity produces 441 rows across all 49 calendar dates July 27–September 13. Canonical-only produces 351 rows across 39 dates, starting August 6 after 10 prior canonical days. This alternative removes observed statistics only inside its separate memory fixture; no app flag was added.
- September 13 learning is independently verified as 109 observed + 48 canonical = 157 accepted days, April 9–September 12. April 8 lacks an earlier futures trade. Current-day history is excluded. The first canonical unit presence is July 27. Confidence is low from only 48 current-basis days. V2 remains frozen at 109. The annual-cost method change does not limit unit history.
- Original actual dry run evaluates 144 canonical v2 records (16 issue dates per term/quantile), leaves 270 observed v1 targets missing, and reports zero unsupported provenance. The full original table byte string remains identical, including metadata and all 639 completed evaluations. Local apply preserves all forecast fields and completed rows. Repeat apply evaluates zero and is byte-identical. All original model rows remain unchanged in generation fixtures.
- V3 dry-run evaluation yields 171 rows: July 27–August 14 issue dates, August 26–September 13 targets. Canonical-only yields 81 rows: August 6–14 issue dates. Stored v2 lacks August 2–4 (and August 22 in the full interval), so the exact common matured cohort is 16 dates × 3 terms × 3 quantiles. V1 cross-basis accuracy is excluded.
- Exact matched median aggregate: v2 MAE 0.7952; v3 MAE 0.7458; unchanged-price MAE 0.7003 c/kWh (48 rows each). Direction accuracy: 11/48 versus 13/48. Six-month median worsens: MAE 1.5132 → 1.5489 and direction 2/16 → 1/16. No measured superiority over unchanged prices is claimed. P20 and p80 have separate tables. All errors underpredict; bias is negative.
- September 13 median predictions, v2 → v3: 6 months 14.6839 → 14.5981; 12 months 11.7091 → 11.9272; 24 months 10.5333 → 10.5305 c/kWh. Canonical-only agrees at four decimals after 48 canonical EWMA updates. Current targets mature October 13; no accuracy claim is possible yet.
- Actual PHP H matches independent raw-curve H over the full source calendar and all 438 stored median rows. 435 stored H values match within rounding. Three May 26 values differ because original stored trade date May 22 differs from export-time latest May 25; maximum difference 0.301027 c/kWh. This is export-time replay, not archived-vintage-perfect evidence. It does not prove historical HTML. Older inputs can be revised.
- Independent raw-array checks cover all generated EWMA premiums, history counts/bounds, gap-closure prices, signed/absolute/baseline errors and direction categories. JSON contains full predictions/evaluations and exact switch records; CSV contains 405 exact stored-v2 comparisons. Markdown contains measured coverage, current outputs, median main results and separate quantile/counterfactual metrics.
- Existing vendor PDO MySQL constant deprecation notices occur under PHP 8.5; no MySQL connection occurs. No app code, dependencies, production state, commit or push changed in this replay unit.

## Final full-suite verification (manager)

- The manager ran `cd laravel && php artisan test` on finalized continuity code: **2,321 passed / 11,192 assertions, 92.86 seconds**. Log: `/tmp/voltikka-forecast-continuity-tests.log`. The replay agent also checked the log summary. The manager reports no app changes during that run. This was concurrent with the replay work, not a second full-suite run after replay. The replay changes task artifacts only.
