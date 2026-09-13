# Offline forecast accuracy experiments

## Result and decision boundary

**The fixed-delivery-basket feature does not improve the primary test over rolling futures or retail-only.** On 12 canonical issue days, the 30-day MAEs are unchanged **0.608625**, repaired fixed gap **0.656925**, learned retail **0.479421**, learned retail + rolling futures **0.436333**, and learned retail + fixed basket **0.485761 c/kWh**. Fixed basket has 0.049428 more error than rolling futures and 0.006340 more than retail-only. Abstention changes none of these 36 primary predictions and does not help.

The learned models beat unchanged on this selected 30-day cohort, but this is **not a production recommendation**. Their positive intercepts carry much of the predicted rise. No intercept-only model was predeclared; the experiment does not identify how much of the gain is due to feature information rather than the older regime's mean target change. The older observed rolling-origin 30-day result reverses the gain against unchanged. At the secondary 14-day target, all learned and gap models lose to unchanged on MAE, although learned RMSE is lower. In the primary test, all learned models improve MAE only for the six-month term; they lose to unchanged for 12 and 24 months. The pooled gain is not a shared term-level gain.

These are retrospective results on a chronological holdout already inspected by prior research. They are contaminated by prior analysis, not untouched out-of-sample proof. The specification was frozen before this task computed new metrics; no parameter or cohort was changed after those metrics.

## Source, target and model freeze

The only data export used is `tasks/supplier-premium-coverage/export-20260913T094138Z`. It is complete for April 8–September 13, 2026. Manifest SHA256: `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`. All payload hashes, bytes, expected/actual counts, SQL and count-SQL hashes pass. The export has 477 scoped public statistics and 2,239 FI Base futures rows. Owned medians and futures have no invalid numeric values. Latest-ID statistic ownership is applied before validity checks.

Target: public 6/12/24-month `energy_price` median, null consumption, `unit_statistics_v1`. Eligible Time/Season offers are included. This is not a General-only supplier median or an identical-product price. No controlled index is constructed here. The separate index task must remain a separate estimand until the manager combines its evidence.

Unchanged is the zero-change reference, not an application default changed by this task. Fixed gap reuses hash-verified exact prior rows checked against the actual local undeployed v3 service: current + .30 × (H + EWMA normal premium − current), alpha .25, at least 10 strictly prior usable days. Its canonical EWMA has an observed prefix before the canonical seam and canonical-only continuation. That continuity assumption differs from strictly same-basis learned rolling-origin training. The 14-day gap is a reference sensitivity, not a separately calibrated v3 model.

Primary learned features use seven-day exact endpoint changes. Fourteen-day input is a separate predeclared sensitivity, not a winning-window search. Retail-only and the two futures models use the same training rows, retail feature and test identities. All coefficients are shared across terms, with equal row weights. Each fit minimizes mean squared delta error + 1.0 × squared standardized slopes. Population scales come from training only; the intercept is unpenalized. There are no term dummies, supplier parameters or hyperparameter choices. Missing inputs stay missing. Zero-valued data remain valid. There is no annual-v2 history ban.

Rolling H uses next-full-month delivery at each endpoint. Fixed basket instead selects the issue-date next-full-month strip and prices those EXACT months at both strictly earlier trade vintages, with month → quarter → year fallback, complete coverage, VAT 1.255 and identical actual month-day weights. It removes delivery-roll movement, not the forecast level. Fallback instruments can differ between vintages. No future curve corrects a past roll. Five of the 12 primary issue days cross a calendar-month delivery roll over the seven-day input window (August 3–7). On the other seven days, fixed-basket and rolling input changes are equal. Their fitted predictions can still differ because their training features and fitted coefficients differ.

Raw learned predictions are not rounded. The separate basket abstention output sets absolute predicted changes below .15 c/kWh to zero. Direction accuracy uses down/flat/up with that same strict threshold; all rows are in its denominator. Thus abstention cannot change this three-class direction score. Bias is prediction minus actual; skill is 1 − model MAE / unchanged MAE on exactly paired rows.

## Training and test identity audit

Training freezes at July 27 using only observed-basis labels STRICTLY before July 27. Current, prior retail endpoint and target all share the observed basis. Canonical holdout inputs and targets share canonical basis. Observed-to-canonical coefficient transfer is a history-continuity ASSUMPTION, not a proven common data-generating process. No canonical outcome fits or selects the frozen model.

| Input days | Target days | Training rows / issue days | Training issues | Last training target | Test rows / issue days | Test issues |
|---:|---:|---:|---|---|---:|---|
| 7 | 30 | 207 / 69 | 2026-04-19–2026-06-26 | 2026-07-26 | 36 / 12 | 2026-08-03–2026-08-14 |
| 7 | 14 | 255 / 85 | 2026-04-19–2026-07-12 | 2026-07-26 | 84 / 28 | 2026-08-03–2026-08-30 |
| 14 | 30 | 195 / 65 | 2026-04-23–2026-06-26 | 2026-07-26 | 15 / 5 | 2026-08-10–2026-08-14 |
| 14 | 14 | 243 / 81 | 2026-04-23–2026-07-12 | 2026-07-26 | 63 / 21 | 2026-08-10–2026-08-30 |

Raw canonical 30-day pairs cover 19 issue days (July 27–August 14), 57 term rows. Exact seven-day canonical retail history removes seven days, leaving August 3–14: 12 days / 36 rows. Exact 14-day history removes 14 days, leaving August 10–14: five days / 15 rows. No observed value fills these gaps. Raw canonical 14-day pairs have 35 days / 105 rows; paired seven-/14-day inputs retain 28/21 days. Every retained day has all three terms. All curves are complete on retained rows.

`pairs.json` saves issue, target and lag dates, all three statistic IDs, basis, term, current, actual and features. `fits.json` saves every fit's exact training IDs. `predictions.csv` links each model row to that same pair ID and fit ID. `exclusions.csv` saves one result for every current-date/term/window/horizon candidate. Only same-basis current dates start candidates. No calendar date is filled.

The fixed seven-day training cohort starts April 19 because the paired v3 reference requires 10 prior hedge/retail days. Fourteen-day input starts April 23 because April 22's earlier futures endpoint has no strictly prior trade. These gates are applied identically to all learned models.

## Primary 30-day target, seven-day inputs

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 36 / 12 | 0.608625 | 0.824744 | -0.608447 | 25.00 | +0.00 |
| gap | 36 / 12 | 0.656925 | 0.859306 | -0.656925 | 27.78 | -7.94 |
| retail | 36 / 12 | 0.479421 | 0.549793 | +0.007920 | 75.00 | +21.23 |
| rolling | 36 / 12 | 0.436333 | 0.491109 | +0.022788 | 75.00 | +28.31 |
| basket | 36 / 12 | 0.485761 | 0.552325 | -0.008165 | 75.00 | +20.19 |
| basket_abstain | 36 / 12 | 0.485761 | 0.552325 | -0.008165 | 75.00 | +20.19 |

### Primary per-term results

#### 6 months

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 12 / 12 | 1.334042 | 1.354380 | -1.334042 | 0.00 | +0.00 |
| gap | 12 / 12 | 1.397800 | 1.405900 | -1.397800 | 8.33 | -4.78 |
| retail | 12 / 12 | 0.704291 | 0.744358 | -0.704291 | 100.00 | +47.21 |
| rolling | 12 / 12 | 0.614494 | 0.636581 | -0.614494 | 100.00 | +53.94 |
| basket | 12 / 12 | 0.736651 | 0.759075 | -0.736651 | 100.00 | +44.78 |
| basket_abstain | 12 / 12 | 0.736651 | 0.759075 | -0.736651 | 100.00 | +44.78 |

#### 12 months

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 12 / 12 | 0.291608 | 0.363813 | -0.291075 | 25.00 | +0.00 |
| gap | 12 / 12 | 0.357333 | 0.401566 | -0.357333 | 25.00 | -22.54 |
| retail | 12 / 12 | 0.329897 | 0.393468 | +0.323976 | 75.00 | -13.13 |
| rolling | 12 / 12 | 0.297359 | 0.350603 | +0.285711 | 75.00 | -1.97 |
| basket | 12 / 12 | 0.319184 | 0.376239 | +0.310707 | 75.00 | -9.46 |
| basket_abstain | 12 / 12 | 0.319184 | 0.376239 | +0.310707 | 75.00 | -9.46 |

#### 24 months

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 12 / 12 | 0.200225 | 0.271847 | -0.200225 | 50.00 | +0.00 |
| gap | 12 / 12 | 0.215642 | 0.278229 | -0.215642 | 50.00 | -7.70 |
| retail | 12 / 12 | 0.404076 | 0.444895 | +0.404076 | 50.00 | -101.81 |
| rolling | 12 / 12 | 0.397147 | 0.442048 | +0.397147 | 50.00 | -98.35 |
| basket | 12 / 12 | 0.401448 | 0.444340 | +0.401448 | 50.00 | -100.50 |
| basket_abstain | 12 / 12 | 0.401448 | 0.444340 | +0.401448 | 50.00 | -100.50 |

## Secondary 14-day target, seven-day inputs

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 84 / 28 | 0.289119 | 0.462442 | -0.258888 | 53.57 | +0.00 |
| gap | 84 / 28 | 0.318807 | 0.490248 | -0.298745 | 52.38 | -10.27 |
| retail | 84 / 28 | 0.318988 | 0.391061 | +0.038115 | 45.24 | -10.33 |
| rolling | 84 / 28 | 0.306564 | 0.359491 | +0.004824 | 38.10 | -6.03 |
| basket | 84 / 28 | 0.328629 | 0.401532 | -0.000870 | 38.10 | -13.67 |
| basket_abstain | 84 / 28 | 0.335658 | 0.411701 | -0.007899 | 38.10 | -16.10 |

Fixed basket is worse than rolling by 0.022065 and retail-only by 0.009641 c/kWh. Abstention raises MAE from 0.328629 to 0.335658. It changes six rows on six issue days, only small predicted moves, not three-class direction.

## Predeclared 14-day input sensitivity

These five 30-day issue dates were known to be selected before fitting. Do not compare their apparent gain with the larger seven-day-input cohort as a model-selection result.

### 30-day target

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 15 / 5 | 0.699040 | 0.811800 | -0.699040 | 0.00 | +0.00 |
| gap | 15 / 5 | 0.739333 | 0.861852 | -0.739333 | 0.00 | -5.76 |
| retail | 15 / 5 | 0.317332 | 0.388510 | -0.053304 | 100.00 | +54.60 |
| rolling | 15 / 5 | 0.248910 | 0.294584 | -0.004326 | 100.00 | +64.39 |
| basket | 15 / 5 | 0.325230 | 0.397956 | -0.058750 | 100.00 | +53.47 |
| basket_abstain | 15 / 5 | 0.325230 | 0.397956 | -0.058750 | 100.00 | +53.47 |

### 14-day target

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 63 / 21 | 0.230648 | 0.339147 | -0.199025 | 57.14 | +0.00 |
| gap | 63 / 21 | 0.260810 | 0.380749 | -0.234060 | 53.97 | -13.08 |
| retail | 63 / 21 | 0.276030 | 0.296854 | +0.099114 | 41.27 | -19.68 |
| rolling | 63 / 21 | 0.251604 | 0.281889 | +0.063270 | 47.62 | -9.09 |
| basket | 63 / 21 | 0.270335 | 0.296686 | +0.070985 | 46.03 | -17.21 |
| basket_abstain | 63 / 21 | 0.270845 | 0.296763 | +0.066733 | 46.03 | -17.43 |

Fixed basket again loses to rolling at both targets. It loses to retail-only at 30 days and slightly improves on retail-only at 14 days, while still losing to unchanged. The extra abstention sensitivity is retained separately for completeness; it does not alter the primary definition.

## Coefficients and scales

Every row below uses training only. `b0` is the raw-feature intercept. `mean delta` is the standardized-coordinate intercept. Features are in c/kWh; raw slopes multiply raw input changes. The feature order is retail, then futures. Complete precision, standardized slopes and 136 fit identities are in `fits.json`.

| Input / target days | Model | Mean delta | b0 | Raw slopes | Feature means | Population scales |
|---|---|---:|---:|---|---|---|
| 7 / 30 | basket | 0.604894 | 0.592858 | 0.025153, 0.299409 | 0.118920, 0.030208 | 0.341729, 0.298146 |
| 7 / 30 | retail | 0.604894 | 0.600132 | 0.040049 | 0.118920 | 0.341729 |
| 7 / 30 | rolling | 0.604894 | 0.584486 | 0.010253, 0.289633 | 0.118920, 0.066251 | 0.341729, 0.376211 |
| 7 / 14 | basket | 0.303187 | 0.289559 | -0.075440, 0.280464 | 0.110611, 0.078344 | 0.322083, 0.314150 |
| 7 / 14 | retail | 0.303187 | 0.309659 | -0.058515 | 0.110611 | 0.322083 |
| 7 / 14 | rolling | 0.303187 | 0.278858 | -0.084391, 0.244403 | 0.110611, 0.137738 | 0.322083, 0.443736 |
| 14 / 30 | basket | 0.611188 | 0.578034 | 0.060349, 0.183085 | 0.267395, 0.092945 | 0.491081, 0.468394 |
| 14 / 30 | retail | 0.611188 | 0.592241 | 0.070857 | 0.267395 | 0.491081 |
| 14 / 30 | rolling | 0.611188 | 0.564566 | 0.035852, 0.221042 | 0.267395, 0.167550 | 0.491081, 0.571828 |
| 14 / 14 | basket | 0.317074 | 0.321314 | -0.094954, 0.118748 | 0.212638, 0.134324 | 0.470561, 0.480656 |
| 14 / 14 | retail | 0.317074 | 0.335218 | -0.085328 | 0.212638 | 0.470561 |
| 14 / 14 | rolling | 0.317074 | 0.308640 | -0.108047, 0.127493 | 0.212638, 0.246360 | 0.470561, 0.642781 |

## Strictly same-basis rolling origin

At every eligible issue, each learned fit uses only same-basis training labels strictly before that issue and at least 20 unique prior issue DAYS. No canonical model is available: the maximum eligible prior count is **13 days**, below 20. No older basis is borrowed. All unavailable issue fits and their counts remain in `availability.csv`.

Observed rolling-origin results below are a separate older regime. They are not canonical validation. At 30 days, basket beats rolling slightly but all learned models lose to unchanged. At 14 days, rolling wins this older cohort, while basket is near unchanged. Thus the primary transfer result does not generalize across these two tests.

### Observed 30-day target, seven-day inputs

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 57 / 19 | 0.471821 | 0.601563 | -0.437084 | 28.07 | +0.00 |
| gap | 57 / 19 | 0.512905 | 0.646770 | -0.492547 | 28.07 | -8.71 |
| retail | 57 / 19 | 0.640128 | 0.947769 | +0.371860 | 68.42 | -35.67 |
| rolling | 57 / 19 | 0.576215 | 0.799633 | +0.153246 | 61.40 | -22.13 |
| basket | 57 / 19 | 0.572526 | 0.791408 | +0.116407 | 57.89 | -21.34 |
| basket_abstain | 57 / 19 | 0.578633 | 0.796348 | +0.110299 | 57.89 | -22.64 |

### Observed 14-day target, seven-day inputs

| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |
|---|---:|---:|---:|---:|---:|---:|
| unchanged | 153 / 51 | 0.430065 | 0.647921 | -0.323371 | 29.41 | +0.00 |
| gap | 153 / 51 | 0.405569 | 0.617670 | -0.330145 | 33.33 | +5.70 |
| retail | 153 / 51 | 0.447811 | 0.593307 | -0.001553 | 55.56 | -4.13 |
| rolling | 153 / 51 | 0.391073 | 0.556030 | -0.006777 | 54.90 | +9.07 |
| basket | 153 / 51 | 0.425921 | 0.589638 | -0.001219 | 54.25 | +0.96 |
| basket_abstain | 153 / 51 | 0.427758 | 0.594844 | -0.003056 | 54.25 | +0.54 |

Observed rolling-origin test ranges:

- Input 7, target 30: 2026-06-08–2026-06-26, 19 issue days; training grows from 20 to 38 prior issue days.
- Input 7, target 14: 2026-05-23–2026-07-12, 51 issue days; training grows from 20 to 70 prior issue days.
- Input 14, target 30: 2026-06-12–2026-06-26, 15 issue days; training grows from 20 to 34 prior issue days.
- Input 14, target 14: 2026-05-27–2026-07-12, 47 issue days; training grows from 20 to 66 prior issue days.

All secondary observed results and every term's MAE/RMSE/bias/direction/skill remain in `metrics.csv`. No horizon is selected from smaller absolute error.

## Common dates, dependence and limits

The frozen cross-horizon common cohort is August 3–14 (12 days / 36 term identities) for seven-day inputs, and August 10–14 (five days / 15 identities) for 14-day inputs. `common-horizons.json` gives the exact common IDs and each target's metrics. These are different labels even on common issue dates; no optimal horizon follows from their MAEs.

All 66 pairs of primary 30-day issue windows overlap: the 12 issues span only 11 calendar days. All 10 issue pairs in the five-day sensitivity overlap. At the 14-day/seven-day test, 273 of 378 issue pairs overlap. The three term rows share dates, retail conditions and futures shocks; 36 rows are not 36 independent observations. Training windows overlap as well. A 20-day minimum is a guard, not proof of adequate independent evidence. No independent-row confidence interval or p-value is supplied.

Export-time prices and statistics can be revised. Strict trade dates prevent nominal future-trade use, but do not prove that the original job had the revised rows. Prior replay found three May 26 stored median hedge differences because the export contains May 25 trades where the original job used May 22. No production archived-vintage collector is deployed for this experiment. Repeated errors, shared shocks, tariff/offer mix, eligibility, discounts and basis changes prevent causal supplier-response claims. Public medians can move when offers enter or leave without any identical offer repricing. Strong directional results on five days are not calibrated evidence.

## Verification and reproduction

From the repository root:

```sh
python3 tasks/forecast-accuracy-experiments/scripts/experiment.py
python3 tasks/forecast-accuracy-experiments/scripts/verify.py
python3 tasks/forecast-accuracy-experiments/scripts/report.py
python3 tasks/forecast-accuracy-experiments/scripts/reproduce.py
```

The verifier independently rebuilds raw owned statistic identities and expands delivery prices day by day with Decimal arithmetic. It checks all 729 basket vintages, including missing vintages; 474 matching issue-vintage rolling costs agree with the prior actual-PHP-verified hedge rows. It checks 954 features, 1,098 exact eligible pairs, 136 three-model fit sets, 3,564 prediction rows, all cohort identities, strict cutoffs, metric denominators and overlap arithmetic. Ridge normal-equation residuals independently validate every fit. Synthetic tests cover known lambda-zero and lambda-one solutions, zero-scale and all-constant features, missing trade, strict endpoint, valid zero, incomplete strip and invalid owned month without fallback. The prior PHP evidence is reused by verified hash; no application test, database fixture or production command is run here.

The reproducibility command runs calculation and verification twice, renders this report twice, and requires byte-identical artifacts and unchanged export/source hashes. Source and spec hashes are in `sources.json`; `spec.sha256` was saved before new metrics. Verification and reproducibility results are retained as JSON. No dependency was installed and no original export was changed.

## Minimal prospective experiment contract (proposal only)

Keep unchanged as the mandatory zero-change benchmark. Before future labels arrive, freeze one canonical target definition, the seven-day retail/rolling/fixed-basket features, shared-term ridge objective, 30-day primary and separately fitted 14-day secondary target, 20-prior-issue-day gate, .15 abstention, and exact paired-cohort/missing-data rules. Use strictly canonical-only rolling-origin labels; leave models unavailable until the gate passes. Do not silently reuse this observed-to-canonical transfer fit as prospective validation.

For every issue, archive immutable inputs with capture timestamps: exact public statistic IDs/values/basis/method, source revisions and constituent/eligibility evidence, full selected curve vintage with instrument IDs/settlements, identical-basket month/day weights at both feature endpoints, model/spec/source hashes, training IDs, scales and coefficients, predictions and unavailable reasons. Archive exact target-date evidence separately when it arrives; do not overwrite original inputs with later revisions. Retain original and revised-label scores separately.

Predeclare one review after at least 120 eligible issue days, multiple delivery rolls and distinct shocks; do not repeatedly inspect and stop at a winning result. Report unique dates, dependent-window uncertainty, all terms, common cohorts and unchanged skill. If an intercept-only control is desired to test the large mean-change contribution, freeze it in that new contract before new outcomes, not as a new winner in this report. Controlled-index results require their own frozen target and membership rules. Any application default or production collector needs separate approval. This is a proposal, not a deployed collector, dated follow-up or schedule.
