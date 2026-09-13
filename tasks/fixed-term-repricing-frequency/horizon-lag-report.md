# Fixed-term horizons and descriptive change lags

## Result

The export does **not** support a longer public forecast horizon or one universal supplier response lag. On the canonical all-model cohorts, unchanged price has lower mean absolute error (MAE) than the repaired fixed-gap model and the untuned retail momentum baseline. The canonical 45-day all-model test and every canonical 60-day test are unavailable. These are data limits, not failed forecasts.

This is research only. No application code, production service, existing database, dependency, forecast constant or release state changed. The local repaired v3 remains undeployed. The earlier [cadence report](report.md) and its measurement artifacts are preserved.

## Frozen design and evidence

Only `supplier-premium-coverage/export-20260913T094138Z` is used: April 8–September 13, 2026. Its manifest SHA256 is `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`. Every payload, byte count, expected row count, SQL hash and count-SQL hash passes verification. There are 477 market unit statistics and 2,239 FI Base futures rows. Latest-ID ownership is applied before value validation. This export has no duplicate owned statistic dates, invalid owned medians or invalid futures settlements. Missing evidence stays missing; zero is valid.

These are **revised export-time records, not archived original vintages**. A trade date before an issue date does not prove that the trade row or revised retail statistic was available to the original job. The earlier PHP replay found three stored median hedge differences on May 26 because the export has May 25 trades while the original job used May 22. No adjustment uses a later target, and no value is carried across a target-basis seam.

Predeclared horizons are **14/30/45/60 days**. Main outcomes are public market medians for 6/12/24-month `energy_price`, null consumption, `unit_statistics_v1`. This statistic includes eligible Time/Season offers. The separate supplier panel uses only eligible FixedPrice General snapshot medians. It is not the public target. Changes in eligibility, campaigns, discounts, variants and survivor composition remain in both panels; supplier medians are not identical-product prices.

Forecasts:

- **Unchanged:** current same-basis median.
- **Fixed gap:** the actual repaired v3 mathematics: `current + .30 × (H + normal premium − current)`. The normal premium is EWMA with alpha .25 and at least 10 usable strictly prior retail/hedge days. Canonical learning uses observed evidence only before the term's first canonical unit presence, then canonical only. Invalid canonical presence still owns the boundary; no observed fallback follows it. Observed mode learns from observed evidence only. This continuity is an assumption, not proof of basis equivalence.
- **Retail momentum:** `current + (current − same-basis price exactly 14 days earlier) × horizon / 14`. The lag is fixed and untuned. Missing historical same-basis price makes this baseline unavailable. Baseline output is rounded to four decimals with decimal half-up rounding; it is not clipped. The gap prediction uses the service's four-decimal output.

`H` uses the latest FI Base trade **strictly before** each issue/history date, next full calendar month delivery, 6/12/24 monthly delivery slots, actual calendar-day weights, month → quarter → year fallback, and VAT 1.255 (`EUR/MWh / 10 × 1.255`). Missing slots reject H. The non-30-day fixed-gap results are **sensitivity tests only**, not horizon-calibrated forecasts. No response coefficient was fitted. Thus there is no training-target choice or holdout-dependent parameter selection.

## Horizon coverage

Counts below pool the three terms with equal row weight. Each term has one row per available issue day. `Exact` requires current and exact target in one basis; `Gap` also needs the hedge and learning history; `All` also requires same-basis momentum evidence. All three model errors use exactly the same rows in the **All** comparison.

| Basis | Horizon | Exact rows | Gap rows | All-model rows | All-model issue days |
|---|---:|---:|---:|---:|---:|
| Observed | 14 | 288 | 255 | 246 | 82 |
| Observed | 30 | 240 | 207 | 198 | 66 |
| Observed | 45 | 195 | 162 | 153 | 51 |
| Observed | 60 | 150 | 117 | 108 | 36 |
| Canonical | 14 | 105 | 105 | 63 | 21 |
| Canonical | 30 | 57 | 57 | 15 | 5 |
| Canonical | 45 | 12 | 12 | 0 | 0 |
| Canonical | 60 | 0 | 0 | 0 | 0 |

Canonical spans July 27–September 13 (49 days). Canonical momentum first exists on August 10. Thus the 45-day exact pairs (July 27–30 issues) have no canonical 14-day momentum history. Crossing the seam to fill that baseline would change the requested test. Canonical all-model issues are August 10–30 at 14 days, and August 10–14 at 30 days. Observed all-model issues start April 22; their last dates are July 12, June 26, June 11 and May 27 respectively.

The all-model **14/30/45 common-issue intersection** is empty for canonical data. It has 153 observed rows, 51 issue days, at each horizon (April 22–June 11). These use common issue/term pairs, but different exact target dates. Equal issue cohorts do not make raw MAEs at different horizons comparable. Per-term counts, unavailable reasons, export-end exclusions and common-cohort metrics are in the CSV files.

## Errors on identical all-model cohorts

MAE is c/kWh. Improvement is `1 − model MAE / unchanged MAE`; negative means worse. These are descriptive overlapping samples, not independent trials. **Five canonical 30-day issue days are a severe small-N limit; 15 term rows are not 15 independent time observations.**

| Basis | Horizon | Unchanged MAE | Fixed gap MAE | Gap improvement | Momentum MAE | Momentum improvement |
|---|---:|---:|---:|---:|---:|---:|
| Observed | 14 | 0.3817 | 0.3576 | +6.3% | 0.4900 | −28.4% |
| Observed | 30 | 0.6440 | 0.6480 | −0.6% | 0.8218 | −27.6% |
| Observed | 45 | 0.9749 | 0.9729 | +0.2% | 1.0462 | −7.3% |
| Observed | 60 | 1.2254 | 1.2099 | +1.3% | 0.8269 | +32.5% |
| Canonical | 14 | 0.2306 | 0.2608 | −13.1% | 0.4855 | −110.5% |
| Canonical | 30 | 0.6990 | 0.7393 | −5.8% | 1.0089 | −44.3% |

On the observed common 14/30/45 issue cohort, gap improvements are +3.5%, +1.1%, +0.2%; momentum improvements are −15.0%, −6.3%, −7.3%. The observed 60-day momentum result is a selected older-regime result, not evidence that 60 days is best today. Neither absolute MAE nor the ranking of these percentages across different targets identifies a winning horizon.

### Gap-only sensitivity and previous replay control

The full canonical gap-only cohort retains 105/57/12 rows at 14/30/45 days. Gap MAEs are 0.4048/0.7320/1.5814, versus unchanged 0.3816/0.7026/1.5351. Relative improvements are −6.1%/−4.2%/−3.0%. The 45-day sample has only **four issue days**. Momentum is not compared on these larger cohorts.

The previous stored-v2-matched 30-day median cohort is preserved separately: **48 rows, 16 issue days; repaired v3 MAE 0.7458104 and unchanged MAE 0.7002938**. These match the prior actual-service replay exactly. The broader 57-row cohort includes August 2–4 issues absent from stored v2. The new 15-row all-model cohort is smaller because of same-basis momentum history. Their different means are cohort differences, not a failure to reproduce v3.

## Descriptive delay test: changes, not trending levels

The fixed lag grid is **0/7/14/21/30/45/60 days**. For retail date d and lag L, the pair is:

`retail(d) − retail(d−7)` versus `H(d−L) − H(d−L−7)`.

Every retail calendar day from d−7 through d must exist in the same basis. The futures endpoints each use their own strictly earlier trade vintage. Correlations are Pearson correlations of changes. Sign agreement uses only pairs with both changes nonzero (retail tolerance 0.0001 c/kWh; hedge numerical tolerance 1e−10). CSV gives the nonzero denominator. A zero retail change is not counted as a correct directional response.

A distinct retail change event is an adjacent daily median change greater than 0.0001, counted once by its date within each company/term/basis series over the union of included seven-day windows. It is not the number of nonzero seven-day rows, nor a known seller decision. The report guard is at least **five distinct events**, fixed before calculation; it is not a statistical significance test. Raw correlations below the guard remain in CSV with `reportable=0` for audit, not for lag claims.

### Basket-roll control

Primary pairs exclude a seven-day H difference if its delivery basket changes. The script checks **all eight calendar basket starts**, not only the endpoints, and independently checks that the result equals endpoint-start inequality. Thus no retained interval crosses a calendar-month basket roll. It does not freeze instrument fallback type, curve composition or the normal-premium learning baskets. No future curve is used to adjust a roll.

At canonical market lags 0/7/14/21/30/45/60, each term has 42 unscreened windows. The primary screen retains **30/29/35/35/31/35/31**, removing **12/13/7/7/11/7/11**. At observed market lags the unscreened counts are 102/95/88/81/72/57/42; the screen retains 81/74/67/62/58/43/35. The first observed seven-day window lacks the earlier hedge endpoint. Larger lags lose more early dates because the export has no earlier trade history.

### Market results by term

Each cell below is **unscreened / roll-screened correlation**. Rows are not estimates of a causal supplier reaction. Canonical unscreened windows contain 23/24/25 distinct retail events for 6/12/24-month terms. Screened event counts range 17–23 / 19–24 / 19–25 by lag.

| Canonical term | Lag 0 | 7 | 14 | 21 | 30 | 45 | 60 |
|---|---:|---:|---:|---:|---:|---:|---:|
| 6 | −.006 / .086 | .559 / −.118 | .376 / .344 | −.059 / .799 | −.016 / .286 | −.299 / −.809 | −.337 / −.091 |
| 12 | .486 / .562 | .397 / .199 | .236 / .278 | .504 / .509 | −.161 / −.444 | −.753 / −.726 | .237 / .550 |
| 24 | .611 / .711 | .589 / .348 | −.238 / −.184 | .190 / .238 | −.280 / −.435 | −.449 / −.392 | .277 / .593 |

The six-month 7-day association changes sign after roll screening, while its 21-day value rises from −.059 to .799. This large sensitivity prevents a stable lag conclusion. The 12/24-month short-lag associations differ from the six-month pattern.

Observed roll-screened correlations at lags 0/7/14 are .224/.444/−.084 (6 months), .633/.682/.442 (12), and .723/.601/.441 (24). At lags 21/30/45/60 they are .069/.325/−.178/.441, .017/−.275/−.659/.362, and .012/−.355/−.502/.179. Some short-lag association exists in older data, but there is no stable shared maximum across terms, bases and controls.

### Common-date checks

All seven unscreened lags share 42 market dates per term in either basis. For observed data this restriction removes 60 of the 102 lag-0 windows; apparent correlation peaks therefore depend on the selected dates. The roll-screened all-seven-lag intersection has **only one date per term in either basis**, so correlation is unavailable. Supplier intersections have zero or one date and no reportable correlation. A large maximum-lag requirement plus roll removal is too restrictive for this export.

A fixed secondary common-date grid of 0/7/14 days retains 10 canonical market dates. For 6 months there are five events and correlations −.413/.676/.203; for 12 months eight events and −.779/.504/.517. The 24-month series has only four events and fails the reporting guard. Observed common 0/7/14 windows retain 25 dates, with 17/20/21 events and correlations .800/.865/.651, .686/.773/.815, .756/.815/.564. These high, selected, overlapping-window associations do not calibrate a response speed.

### Supplier General panel

There are 68 observed and 58 canonical company/term/basis cells. Keep them separate from the public market statistics. The table summarizes primary individual-lag cells that pass the five-event guard and have a defined correlation. It is an **unweighted median of per-cell correlations**, not a pooled retail/futures correlation. Eligible cells and dates change across columns; do not rank lags from this table.

| Lag | Observed reportable cells / median r | Canonical reportable cells / median r |
|---|---:|---:|
| 0 | 27 / .507 | 7 / .384 |
| 7 | 27 / .519 | 4 / −.581 |
| 14 | 26 / .164 | 9 / −.092 |
| 21 | 25 / −.142 | 9 / .014 |
| 30 | 24 / −.225 | 8 / −.266 |
| 45 | 20 / −.590 | 9 / −.405 |
| 60 | 16 / .195 | 8 / .426 |

Canonical reportable cells have only 5–7 distinct events and 25–35 sample days; observed reportable cells have 5–14 events and 30–81 sample days across these lags. Most supplier cells cannot support even this weak reporting guard. Canonical unscreened supplier pairs total 1,917 company/term/date rows at each lag; the screen removes 478/594/356/339/447/356/449. These row totals **are not independent futures observations**: all companies share one H per term/date. The full CSV retains excluded cells, zero-event cells, missing-window counts, unscreened and common-date results, sign denominators and event dates. No best-lag supplier estimate is fitted.

## Limits and next defensible test

Shared wholesale shocks, overlapping windows, serial dependence, a short time span, tariff/variant mix, survivor bias, basis changes and revised input vintages prevent causal or calibrated lag claims. A retail median can change without any identical contract changing price. Negative or high in-sample correlations do not identify procurement policy. No formal confidence interval or p-value is supplied because independent-row assumptions would be false.

Keep the 30-day public decision separate from this research. The next defensible test is an archived-vintage, forward-only canonical panel long enough to cover all four exact targets and the same-basis 14-day momentum history on common issue dates, across multiple month rolls and distinct price shocks. Freeze these baselines before outcomes arrive. If an exploratory coefficient fit is later authorized, use only targets strictly before each issue, the fixed lambda grid 0/.15/.30/.60/1, at least 20 matured prior issue **days** (not term rows), prior MAE with smallest-lambda ties, and explicit duration pooling. Then assess it on a separate later cohort; report unavailable fits instead of borrowing future targets. A fixed-delivery-basket lag experiment can retain more common dates, but it is a new sensitivity, not the public rolling H model.

## Reproduce and verify

From the repository root:

```sh
python3 tasks/fixed-term-repricing-frequency/horizon-lag-analysis.py
python3 tasks/fixed-term-repricing-frequency/verify-horizon-lag.py
php -l tasks/fixed-term-repricing-frequency/verify-horizon-lag-php.php
php -d memory_limit=512M tasks/fixed-term-repricing-frequency/verify-horizon-lag-php.php
python3 tasks/fixed-term-repricing-frequency/verify.py
# Or repeat the full research check twice and verify all retained artifact hashes:
python3 tasks/fixed-term-repricing-frequency/reproduce-horizon-lag.py
```

- Python analysis passes and compares 147 canonical generated median rows with the previous actual-service replay.
- Independent Python verification uses Decimal daily-expanded delivery costs and closed-form EWMA weights. It checks all 678 hedge calendar rows, 1,047 exact forecast-pair rows, 47,115 lag-change rows, all coverage/metric/common-intersection denominators, supplier medians rebuilt from raw snapshots, trade alignment, roll exclusions and event/sign/correlation arithmetic. Synthetic checks cover latest invalid ownership, no observed fallback, strict prior history, no future boundary, zero, non-finite inputs, missing trades and missing delivery slots.
- The new PHP verifier runs the actual local hedge and forecast services in testing with a proved SQLite `:memory:` fixture. It uses no existing database, migrations or application environment file; HTTP stray requests are blocked, Sentry is off and logging is null. All 678 hedge and 1,047 forecast coverage/value checks pass for both bases and all four horizons. PHP 8.5 emits existing PDO MySQL constant deprecation notices while configuration loads; no MySQL connection occurs.
- The initial development checks caught an overly strict manifest-bounds shape assertion and a float half-tie in momentum rounding. Both were corrected before the final runs. No forecast model coefficient changed.
- Repeated analysis and independent/PHP verification produce 10 byte-identical machine artifacts; 19 earlier cadence artifacts match the original manifest. The original cadence verifier also passes. See `horizon-lag-reproducibility.json` for hashes and checks.

Artifacts: `horizon-lag-forecasts.csv` has all exact pairs and auditable chosen current/lag/target values; `horizon-lag-coverage.csv` has exclusions; `horizon-lag-metrics.csv` has individual/common/term cohorts; `horizon-lag-hedges.csv` has trade dates and complete delivery strips; `horizon-lag-changes.csv` has every lag pair and event date; `horizon-lag-exclusions.csv` and `horizon-lag-associations.csv` retain all company/term/basis cells. `horizon-lag-summary.json`, `horizon-lag-independent-checks.json` and `horizon-lag-php-checks.json` hold machine-readable checks. No application or production recommendation is authorized by these findings.
