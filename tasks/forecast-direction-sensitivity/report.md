# Forecast direction sensitivity — offline, post-hoc

## Decision

**Keep the 0.15 prediction threshold. Do not start a lower-threshold trial for repaired v3 from this evidence.** Of its 33 missed rises, only six predictions are positive, none are zero, and 27 are negative. Lower thresholds mostly turn a neutral signal into a wrong fall signal. This is a wrong-sign problem, not mainly an excess of small positive predictions.

This does not require months of delay or proof of superiority. The present test directly rejects this simple candidate on the available rows. If a practical learned indicator is wanted, a small separately approved shadow trial of the historical-mean control and retail/rolling signals is reasonable, but these data do not identify a better production model. Do not replace price forecasts with an always-UP rule.

## Fixed definitions and provenance

Prediction thresholds: **0.15 / 0.10 / 0.05 c/kWh**. Actual outcomes always use **±0.15**, inclusive. Actual target minus current is Decimal, rounded half away from zero to four decimals. A change strictly inside the actual band is flat. This tests different signal rules against one unchanged target definition; extra alerts are not themselves improved accuracy. Lambda stays 0.30. No model was fit again.

V3 uses the original continuity replay JSON `expected_change_cents_per_kwh`; v2 uses the stored export field. The forecast and current price were rounded separately. Their difference is only a cross-check (maximum permitted error 0.0001), not the primary signal. All saved/numeric direction categories agree at 0.15. Slightly rising and slightly falling both map to flat. The separate saved-label baseline uses raw Decimal actual differences, as in the prior investigation; it is not overwritten.

Code inspection detail: `FixedTermForecastEvaluationService` rounds actual change to four decimals before classification. In the pinned working tree, `FixedTermPriceForecastService` still passes unrounded lambda × gap to `directionLabel`, then stores expected change at four decimals. The requested research rule uses that published field. There are zero saved-label/numeric disagreements in these selected v3/v2 rows; do not assume this proves all future rounding boundaries agree.

Learned and intercept signals use original unrounded prediction price minus current, with Decimal arithmetic and no rounding. They agree with original prediction_delta within 1e-12. Primary gap uses replay expected change; 141 older/secondary gap rows use the earlier published four-decimal price minus current because those artifacts have no expected-change field. This is explicitly separate from the primary replay field rule.

All 477 exported public-median statistic groups also match independently reconstructed raw snapshot medians and contract counts. Current and target prices and exact statistic IDs are checked again for selected rows. Original fits, predictions, prior verification artifacts and export bytes are pinned; no previous writer runs.

## Matched 48 median rows

July 27–August 14: 16 issue days × three terms. Stored v2 is absent on August 2–4 (nine median rows). Actual balance: **36 up, 12 flat, zero down**. Three-class balanced accuracy is unavailable. Down recall is undefined, not 0%. Counts below are correct / missed move / false move / wrong way.

| Model | Prediction threshold | Correct | Missed | False | Wrong | Correct rate |
|---|---:|---:|---:|---:|---:|---:|
| matched48 unchanged all | 0.15 | 12 | 36 | 0 | 0 | 25.0% |
| matched48 unchanged all | 0.10 | 12 | 36 | 0 | 0 | 25.0% |
| matched48 unchanged all | 0.05 | 12 | 36 | 0 | 0 | 25.0% |
| matched48 v2 all | 0.15 | 11 | 28 | 3 | 6 | 22.9% |
| matched48 v2 all | 0.10 | 5 | 20 | 9 | 14 | 10.4% |
| matched48 v2 all | 0.05 | 5 | 5 | 11 | 27 | 10.4% |
| matched48 v3 all | 0.15 | 13 | 33 | 0 | 2 | 27.1% |
| matched48 v3 all | 0.10 | 10 | 29 | 3 | 6 | 20.8% |
| matched48 v3 all | 0.05 | 8 | 18 | 5 | 17 | 16.7% |

The saved labels reproduce v3 **13/33/0/2**, v2 **11/28/3/6**, and unchanged **12/36/0/0**. V3 term baselines are 6 months **1/13/0/2**, 12 months **3/13/0/0**, and 24 months **9/7/0/0**.

### Lower-threshold transitions

| Cohort/model/term | Threshold | Rescued misses | New false moves | New wrong ways | Correct lost |
|---|---:|---:|---:|---:|---:|
| matched48 v3 12 | 0.10 | 0 | 2 | 1 | 2 |
| matched48 v3 12 | 0.05 | 0 | 3 | 6 | 3 |
| matched48 v3 24 | 0.10 | 0 | 1 | 0 | 1 |
| matched48 v3 24 | 0.05 | 0 | 2 | 1 | 2 |
| matched48 v3 6 | 0.10 | 0 | 0 | 3 | 0 |
| matched48 v3 6 | 0.05 | 0 | 0 | 8 | 0 |
| matched48 v3 all | 0.10 | 0 | 3 | 4 | 3 |
| matched48 v3 all | 0.05 | 0 | 5 | 15 | 5 |

Both lower thresholds rescue **zero** of the 33 misses. Thus none of the six positive missed predictions reaches 0.05. The 0.10 rule creates four new wrong-way calls and three false moves; 0.05 creates 15 new wrong-way calls and five false moves. Every lost correct row was an actual flat. Price errors do not change when only direction thresholds change.

### Sign split of saved v3 misses

| Term | Positive | Zero | Negative |
|---|---:|---:|---:|
| 6 | 1 | 0 | 12 |
| 12 | 2 | 0 | 11 |
| 24 | 3 | 0 | 4 |
| all | 6 | 0 | 27 |

### Every saved v3 missed move

IDs end in term and median. All actual directions below are up; all saved categories and numeric 0.15 categories are flat. Prices and changes are c/kWh.

| ID | Target | Current | Actual | Forecast | Expected change | Forecast − current | Saved label | At 0.10 | At 0.05 |
|---|---|---:|---:|---:|---:|---:|---|---|---|
| 2026-07-27 / 6 / median | 2026-08-26 | 11.6488 | 13.795 | 11.6845 | 0.0357 | 0.0357 | slightly_rising | flat | flat |
| 2026-07-28 / 6 / median | 2026-08-27 | 11.8096 | 13.795 | 11.7135 | -0.0961 | -0.0961 | slightly_falling | flat | down |
| 2026-07-29 / 6 / median | 2026-08-28 | 11.885 | 13.785 | 11.7677 | -0.1173 | -0.1173 | slightly_falling | down | down |
| 2026-07-30 / 6 / median | 2026-08-29 | 11.975 | 13.85 | 11.9736 | -0.0014 | -0.0014 | slightly_falling | flat | flat |
| 2026-07-31 / 6 / median | 2026-08-30 | 12.0625 | 13.85 | 12.0248 | -0.0377 | -0.0377 | slightly_falling | flat | flat |
| 2026-08-05 / 6 / median | 2026-09-04 | 12.64 | 14.0342 | 12.6123 | -0.0277 | -0.0277 | slightly_falling | flat | flat |
| 2026-08-06 / 6 / median | 2026-09-05 | 12.64 | 14.0342 | 12.5355 | -0.1045 | -0.1045 | slightly_falling | down | down |
| 2026-08-07 / 6 / median | 2026-09-06 | 12.64 | 14.0342 | 12.5613 | -0.0787 | -0.0787 | slightly_falling | flat | down |
| 2026-08-10 / 6 / median | 2026-09-09 | 13.195 | 14.465 | 13.0546 | -0.1404 | -0.1404 | slightly_falling | down | down |
| 2026-08-11 / 6 / median | 2026-09-10 | 13.25 | 14.43 | 13.1783 | -0.0717 | -0.0717 | slightly_falling | flat | down |
| 2026-08-12 / 6 / median | 2026-09-11 | 13.25 | 14.465 | 13.1741 | -0.0759 | -0.0759 | slightly_falling | flat | down |
| 2026-08-13 / 6 / median | 2026-09-12 | 13.25 | 14.5294 | 13.2018 | -0.0482 | -0.0482 | slightly_falling | flat | flat |
| 2026-08-14 / 6 / median | 2026-09-13 | 13.25 | 14.5294 | 13.1624 | -0.0876 | -0.0876 | slightly_falling | flat | down |
| 2026-07-27 / 12 / median | 2026-08-26 | 10.48 | 11.29 | 10.5009 | 0.0209 | 0.0209 | slightly_rising | flat | flat |
| 2026-07-28 / 12 / median | 2026-08-27 | 10.5225 | 11.2888 | 10.467 | -0.0555 | -0.0555 | slightly_falling | flat | down |
| 2026-07-29 / 12 / median | 2026-08-28 | 10.6 | 11.2 | 10.5119 | -0.0881 | -0.0881 | slightly_falling | flat | down |
| 2026-07-30 / 12 / median | 2026-08-29 | 10.66 | 11.2 | 10.663 | 0.003 | 0.003 | slightly_rising | flat | flat |
| 2026-07-31 / 12 / median | 2026-08-30 | 10.8038 | 11.2 | 10.7524 | -0.0514 | -0.0514 | slightly_falling | flat | down |
| 2026-08-01 / 12 / median | 2026-08-31 | 10.8667 | 11.2 | 10.852 | -0.0147 | -0.0147 | slightly_falling | flat | flat |
| 2026-08-05 / 12 / median | 2026-09-04 | 10.99 | 11.3284 | 10.9614 | -0.0286 | -0.0286 | slightly_falling | flat | flat |
| 2026-08-06 / 12 / median | 2026-09-05 | 11.16 | 11.3284 | 11.0325 | -0.1275 | -0.1275 | slightly_falling | down | down |
| 2026-08-10 / 12 / median | 2026-09-09 | 11.33 | 11.49 | 11.2645 | -0.0655 | -0.0655 | slightly_falling | flat | down |
| 2026-08-11 / 12 / median | 2026-09-10 | 11.32 | 11.85 | 11.3094 | -0.0106 | -0.0106 | slightly_falling | flat | flat |
| 2026-08-12 / 12 / median | 2026-09-11 | 11.32 | 11.8 | 11.2989 | -0.0211 | -0.0211 | slightly_falling | flat | flat |
| 2026-08-13 / 12 / median | 2026-09-12 | 11.32 | 11.9409 | 11.3099 | -0.0101 | -0.0101 | slightly_falling | flat | flat |
| 2026-08-14 / 12 / median | 2026-09-13 | 11.32 | 11.9409 | 11.269 | -0.051 | -0.051 | slightly_falling | flat | down |
| 2026-07-27 / 24 / median | 2026-08-26 | 9.64 | 10.0125 | 9.6252 | -0.0148 | -0.0148 | slightly_falling | flat | flat |
| 2026-07-28 / 24 / median | 2026-08-27 | 9.7638 | 10.0125 | 9.6798 | -0.084 | -0.0840 | slightly_falling | flat | down |
| 2026-08-10 / 24 / median | 2026-09-09 | 10 | 10.18 | 9.9843 | -0.0157 | -0.0157 | slightly_falling | flat | flat |
| 2026-08-11 / 24 / median | 2026-09-10 | 10 | 10.2 | 10.0105 | 0.0105 | 0.0105 | slightly_rising | flat | flat |
| 2026-08-12 / 24 / median | 2026-09-11 | 10 | 10.35 | 10.0014 | 0.0014 | 0.0014 | slightly_rising | flat | flat |
| 2026-08-13 / 24 / median | 2026-09-12 | 10 | 10.56 | 10.0077 | 0.0077 | 0.0077 | slightly_rising | flat | flat |
| 2026-08-14 / 24 / median | 2026-09-13 | 10 | 10.56 | 9.9738 | -0.0262 | -0.0262 | slightly_falling | flat | flat |

## Full v3 sensitivity: separate 57-row cohort

19 issue days, July 27–August 14; actual balance 44 up / 13 flat / zero down. This is not the matched 48-row cohort. Compare thresholds only within this table.

| Model/cohort/term | Threshold | Correct | Missed | False | Wrong | Correct rate |
|---|---:|---:|---:|---:|---:|---:|
| full57 unchanged all | 0.15 | 13 | 44 | 0 | 0 | 22.8% |
| full57 unchanged all | 0.10 | 13 | 44 | 0 | 0 | 22.8% |
| full57 unchanged all | 0.05 | 13 | 44 | 0 | 0 | 22.8% |
| full57 v3 all | 0.15 | 16 | 39 | 0 | 2 | 28.1% |
| full57 v3 all | 0.10 | 13 | 35 | 3 | 6 | 22.8% |
| full57 v3 all | 0.05 | 12 | 22 | 5 | 18 | 21.1% |

## Learned models and constant-direction controls

All models within a cohort have exactly the same IDs, dates, current values and targets. Primary: 36 rows / 12 days, August 3–14, 30-day canonical target and seven-day input. Older: 57 rows / 19 days, observed rolling-origin, 30-day target and seven-day input. Secondary: 84 rows / 28 days, frozen canonical 14-day target and seven-day input. These are separate targets/regimes, not a pooled score or horizon selection.

Always-UP and always-DOWN are fixed, hindsight-free **direction-only controls**. Neither is a numeric price forecast. They show class imbalance, not a method that can identify a future move. No constants were fitted.

| Model/cohort/term | Threshold | Correct | Missed | False | Wrong | Correct rate |
|---|---:|---:|---:|---:|---:|---:|
| older57 always_down all | 0.15 | 2 | 0 | 16 | 39 | 3.5% |
| older57 always_down all | 0.10 | 2 | 0 | 16 | 39 | 3.5% |
| older57 always_down all | 0.05 | 2 | 0 | 16 | 39 | 3.5% |
| older57 always_up all | 0.15 | 39 | 0 | 16 | 2 | 68.4% |
| older57 always_up all | 0.10 | 39 | 0 | 16 | 2 | 68.4% |
| older57 always_up all | 0.05 | 39 | 0 | 16 | 2 | 68.4% |
| older57 basket all | 0.15 | 33 | 6 | 16 | 2 | 57.9% |
| older57 basket all | 0.10 | 35 | 4 | 16 | 2 | 61.4% |
| older57 basket all | 0.05 | 36 | 3 | 16 | 2 | 63.2% |
| older57 gap all | 0.15 | 16 | 36 | 0 | 5 | 28.1% |
| older57 gap all | 0.10 | 16 | 31 | 0 | 10 | 28.1% |
| older57 gap all | 0.05 | 11 | 23 | 5 | 18 | 19.3% |
| older57 intercept all | 0.15 | 39 | 0 | 16 | 2 | 68.4% |
| older57 intercept all | 0.10 | 39 | 0 | 16 | 2 | 68.4% |
| older57 intercept all | 0.05 | 39 | 0 | 16 | 2 | 68.4% |
| older57 retail all | 0.15 | 39 | 0 | 16 | 2 | 68.4% |
| older57 retail all | 0.10 | 39 | 0 | 16 | 2 | 68.4% |
| older57 retail all | 0.05 | 39 | 0 | 16 | 2 | 68.4% |
| older57 rolling all | 0.15 | 35 | 4 | 16 | 2 | 61.4% |
| older57 rolling all | 0.10 | 36 | 3 | 16 | 2 | 63.2% |
| older57 rolling all | 0.05 | 38 | 1 | 16 | 2 | 66.7% |
| older57 unchanged all | 0.15 | 16 | 41 | 0 | 0 | 28.1% |
| older57 unchanged all | 0.10 | 16 | 41 | 0 | 0 | 28.1% |
| older57 unchanged all | 0.05 | 16 | 41 | 0 | 0 | 28.1% |
| primary36 always_down all | 0.15 | 0 | 0 | 9 | 27 | 0.0% |
| primary36 always_down all | 0.10 | 0 | 0 | 9 | 27 | 0.0% |
| primary36 always_down all | 0.05 | 0 | 0 | 9 | 27 | 0.0% |
| primary36 always_up all | 0.15 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 always_up all | 0.10 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 always_up all | 0.05 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 basket all | 0.15 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 basket all | 0.10 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 basket all | 0.05 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 gap all | 0.15 | 10 | 24 | 0 | 2 | 27.8% |
| primary36 gap all | 0.10 | 8 | 21 | 2 | 5 | 22.2% |
| primary36 gap all | 0.05 | 7 | 13 | 4 | 12 | 19.4% |
| primary36 intercept all | 0.15 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 intercept all | 0.10 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 intercept all | 0.05 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 retail all | 0.15 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 retail all | 0.10 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 retail all | 0.05 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 rolling all | 0.15 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 rolling all | 0.10 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 rolling all | 0.05 | 27 | 0 | 9 | 0 | 75.0% |
| primary36 unchanged all | 0.15 | 9 | 27 | 0 | 0 | 25.0% |
| primary36 unchanged all | 0.10 | 9 | 27 | 0 | 0 | 25.0% |
| primary36 unchanged all | 0.05 | 9 | 27 | 0 | 0 | 25.0% |
| secondary84 always_down all | 0.15 | 1 | 0 | 45 | 38 | 1.2% |
| secondary84 always_down all | 0.10 | 1 | 0 | 45 | 38 | 1.2% |
| secondary84 always_down all | 0.05 | 1 | 0 | 45 | 38 | 1.2% |
| secondary84 always_up all | 0.15 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 always_up all | 0.10 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 always_up all | 0.05 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 basket all | 0.15 | 32 | 6 | 45 | 1 | 38.1% |
| secondary84 basket all | 0.10 | 35 | 3 | 45 | 1 | 41.7% |
| secondary84 basket all | 0.05 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 gap all | 0.15 | 44 | 35 | 2 | 3 | 52.4% |
| secondary84 gap all | 0.10 | 41 | 31 | 5 | 7 | 48.8% |
| secondary84 gap all | 0.05 | 35 | 17 | 12 | 20 | 41.7% |
| secondary84 intercept all | 0.15 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 intercept all | 0.10 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 intercept all | 0.05 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 retail all | 0.15 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 retail all | 0.10 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 retail all | 0.05 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 rolling all | 0.15 | 32 | 6 | 45 | 1 | 38.1% |
| secondary84 rolling all | 0.10 | 35 | 3 | 45 | 1 | 41.7% |
| secondary84 rolling all | 0.05 | 38 | 0 | 45 | 1 | 45.2% |
| secondary84 unchanged all | 0.15 | 45 | 39 | 0 | 0 | 53.6% |
| secondary84 unchanged all | 0.10 | 45 | 39 | 0 | 0 | 53.6% |
| secondary84 unchanged all | 0.05 | 45 | 39 | 0 | 0 | 53.6% |

The original **75%** primary direction result is exactly 27/36. Intercept, retail, rolling and basket all say up on every primary row at every tested threshold. So does always-UP. Actual balance is 27 up / nine flat / zero down. This cannot establish falling-price performance or feature value for direction. Always-DOWN scores zero, with nine false moves and 27 wrong ways.

Older actual balance is 39 up / 16 flat / two down. Intercept and retail equal always-UP: 39/57 (68.4%). Rolling rises from 35 to 36 to 38 correct as the threshold falls; basket from 33 to 35 to 36. Neither beats the up-only control. Two falling rows are too few to establish stable fall performance.

Secondary balance is 38 up / 45 flat / one down. Unchanged scores 45/84 (53.6%); gap at 0.15 scores 44/84. Intercept and retail equal always-UP at 38/84 (45.2%). Rolling and basket move from 32 to 35 to 38 correct, but still lose to unchanged. One falling row is weak evidence. Lower thresholds do not supply a stable cross-regime improvement.

### Term trade-offs

Matched v3 has no rescued misses in any term. Its six-month wrong-sign risk grows; 12/24-month flat outcomes can become false calls. Primary learned models all call up in every term: their term scores reflect only actual class balance, not term skill. Previous numerical-price gains were concentrated in six months; the price-error report is unchanged. No term-specific model or threshold is selected.

| Model/cohort/term | Threshold | Correct | Missed | False | Wrong | Correct rate |
|---|---:|---:|---:|---:|---:|---:|
| matched48 unchanged 12 | 0.15 | 3 | 13 | 0 | 0 | 18.8% |
| matched48 unchanged 12 | 0.10 | 3 | 13 | 0 | 0 | 18.8% |
| matched48 unchanged 12 | 0.05 | 3 | 13 | 0 | 0 | 18.8% |
| matched48 unchanged 24 | 0.15 | 9 | 7 | 0 | 0 | 56.2% |
| matched48 unchanged 24 | 0.10 | 9 | 7 | 0 | 0 | 56.2% |
| matched48 unchanged 24 | 0.05 | 9 | 7 | 0 | 0 | 56.2% |
| matched48 unchanged 6 | 0.15 | 0 | 16 | 0 | 0 | 0.0% |
| matched48 unchanged 6 | 0.10 | 0 | 16 | 0 | 0 | 0.0% |
| matched48 unchanged 6 | 0.05 | 0 | 16 | 0 | 0 | 0.0% |
| matched48 v2 12 | 0.15 | 0 | 7 | 3 | 6 | 0.0% |
| matched48 v2 12 | 0.10 | 0 | 6 | 3 | 7 | 0.0% |
| matched48 v2 12 | 0.05 | 0 | 2 | 3 | 11 | 0.0% |
| matched48 v2 24 | 0.15 | 9 | 7 | 0 | 0 | 56.2% |
| matched48 v2 24 | 0.10 | 3 | 5 | 6 | 2 | 18.8% |
| matched48 v2 24 | 0.05 | 1 | 1 | 8 | 6 | 6.2% |
| matched48 v2 6 | 0.15 | 2 | 14 | 0 | 0 | 12.5% |
| matched48 v2 6 | 0.10 | 2 | 9 | 0 | 5 | 12.5% |
| matched48 v2 6 | 0.05 | 4 | 2 | 0 | 10 | 25.0% |
| matched48 v3 12 | 0.15 | 3 | 13 | 0 | 0 | 18.8% |
| matched48 v3 12 | 0.10 | 1 | 12 | 2 | 1 | 6.2% |
| matched48 v3 12 | 0.05 | 0 | 7 | 3 | 6 | 0.0% |
| matched48 v3 24 | 0.15 | 9 | 7 | 0 | 0 | 56.2% |
| matched48 v3 24 | 0.10 | 8 | 7 | 1 | 0 | 50.0% |
| matched48 v3 24 | 0.05 | 7 | 6 | 2 | 1 | 43.8% |
| matched48 v3 6 | 0.15 | 1 | 13 | 0 | 2 | 6.2% |
| matched48 v3 6 | 0.10 | 1 | 10 | 0 | 5 | 6.2% |
| matched48 v3 6 | 0.05 | 1 | 5 | 0 | 10 | 6.2% |
| older57 always_down 12 | 0.15 | 2 | 0 | 8 | 9 | 10.5% |
| older57 always_down 12 | 0.10 | 2 | 0 | 8 | 9 | 10.5% |
| older57 always_down 12 | 0.05 | 2 | 0 | 8 | 9 | 10.5% |
| older57 always_down 24 | 0.15 | 0 | 0 | 8 | 11 | 0.0% |
| older57 always_down 24 | 0.10 | 0 | 0 | 8 | 11 | 0.0% |
| older57 always_down 24 | 0.05 | 0 | 0 | 8 | 11 | 0.0% |
| older57 always_down 6 | 0.15 | 0 | 0 | 0 | 19 | 0.0% |
| older57 always_down 6 | 0.10 | 0 | 0 | 0 | 19 | 0.0% |
| older57 always_down 6 | 0.05 | 0 | 0 | 0 | 19 | 0.0% |
| older57 always_up 12 | 0.15 | 9 | 0 | 8 | 2 | 47.4% |
| older57 always_up 12 | 0.10 | 9 | 0 | 8 | 2 | 47.4% |
| older57 always_up 12 | 0.05 | 9 | 0 | 8 | 2 | 47.4% |
| older57 always_up 24 | 0.15 | 11 | 0 | 8 | 0 | 57.9% |
| older57 always_up 24 | 0.10 | 11 | 0 | 8 | 0 | 57.9% |
| older57 always_up 24 | 0.05 | 11 | 0 | 8 | 0 | 57.9% |
| older57 always_up 6 | 0.15 | 19 | 0 | 0 | 0 | 100.0% |
| older57 always_up 6 | 0.10 | 19 | 0 | 0 | 0 | 100.0% |
| older57 always_up 6 | 0.05 | 19 | 0 | 0 | 0 | 100.0% |
| older57 basket 12 | 0.15 | 6 | 3 | 8 | 2 | 31.6% |
| older57 basket 12 | 0.10 | 7 | 2 | 8 | 2 | 36.8% |
| older57 basket 12 | 0.05 | 8 | 1 | 8 | 2 | 42.1% |
| older57 basket 24 | 0.15 | 11 | 0 | 8 | 0 | 57.9% |
| older57 basket 24 | 0.10 | 11 | 0 | 8 | 0 | 57.9% |
| older57 basket 24 | 0.05 | 11 | 0 | 8 | 0 | 57.9% |
| older57 basket 6 | 0.15 | 16 | 3 | 0 | 0 | 84.2% |
| older57 basket 6 | 0.10 | 17 | 2 | 0 | 0 | 89.5% |
| older57 basket 6 | 0.05 | 17 | 2 | 0 | 0 | 89.5% |
| older57 gap 12 | 0.15 | 8 | 11 | 0 | 0 | 42.1% |
| older57 gap 12 | 0.10 | 8 | 11 | 0 | 0 | 42.1% |
| older57 gap 12 | 0.05 | 4 | 9 | 4 | 2 | 21.1% |
| older57 gap 24 | 0.15 | 8 | 11 | 0 | 0 | 42.1% |
| older57 gap 24 | 0.10 | 8 | 11 | 0 | 0 | 42.1% |
| older57 gap 24 | 0.05 | 7 | 10 | 1 | 1 | 36.8% |
| older57 gap 6 | 0.15 | 0 | 14 | 0 | 5 | 0.0% |
| older57 gap 6 | 0.10 | 0 | 9 | 0 | 10 | 0.0% |
| older57 gap 6 | 0.05 | 0 | 4 | 0 | 15 | 0.0% |
| older57 intercept 12 | 0.15 | 9 | 0 | 8 | 2 | 47.4% |
| older57 intercept 12 | 0.10 | 9 | 0 | 8 | 2 | 47.4% |
| older57 intercept 12 | 0.05 | 9 | 0 | 8 | 2 | 47.4% |
| older57 intercept 24 | 0.15 | 11 | 0 | 8 | 0 | 57.9% |
| older57 intercept 24 | 0.10 | 11 | 0 | 8 | 0 | 57.9% |
| older57 intercept 24 | 0.05 | 11 | 0 | 8 | 0 | 57.9% |
| older57 intercept 6 | 0.15 | 19 | 0 | 0 | 0 | 100.0% |
| older57 intercept 6 | 0.10 | 19 | 0 | 0 | 0 | 100.0% |
| older57 intercept 6 | 0.05 | 19 | 0 | 0 | 0 | 100.0% |
| older57 retail 12 | 0.15 | 9 | 0 | 8 | 2 | 47.4% |
| older57 retail 12 | 0.10 | 9 | 0 | 8 | 2 | 47.4% |
| older57 retail 12 | 0.05 | 9 | 0 | 8 | 2 | 47.4% |
| older57 retail 24 | 0.15 | 11 | 0 | 8 | 0 | 57.9% |
| older57 retail 24 | 0.10 | 11 | 0 | 8 | 0 | 57.9% |
| older57 retail 24 | 0.05 | 11 | 0 | 8 | 0 | 57.9% |
| older57 retail 6 | 0.15 | 19 | 0 | 0 | 0 | 100.0% |
| older57 retail 6 | 0.10 | 19 | 0 | 0 | 0 | 100.0% |
| older57 retail 6 | 0.05 | 19 | 0 | 0 | 0 | 100.0% |
| older57 rolling 12 | 0.15 | 7 | 2 | 8 | 2 | 36.8% |
| older57 rolling 12 | 0.10 | 8 | 1 | 8 | 2 | 42.1% |
| older57 rolling 12 | 0.05 | 9 | 0 | 8 | 2 | 47.4% |
| older57 rolling 24 | 0.15 | 11 | 0 | 8 | 0 | 57.9% |
| older57 rolling 24 | 0.10 | 11 | 0 | 8 | 0 | 57.9% |
| older57 rolling 24 | 0.05 | 11 | 0 | 8 | 0 | 57.9% |
| older57 rolling 6 | 0.15 | 17 | 2 | 0 | 0 | 89.5% |
| older57 rolling 6 | 0.10 | 17 | 2 | 0 | 0 | 89.5% |
| older57 rolling 6 | 0.05 | 18 | 1 | 0 | 0 | 94.7% |
| older57 unchanged 12 | 0.15 | 8 | 11 | 0 | 0 | 42.1% |
| older57 unchanged 12 | 0.10 | 8 | 11 | 0 | 0 | 42.1% |
| older57 unchanged 12 | 0.05 | 8 | 11 | 0 | 0 | 42.1% |
| older57 unchanged 24 | 0.15 | 8 | 11 | 0 | 0 | 42.1% |
| older57 unchanged 24 | 0.10 | 8 | 11 | 0 | 0 | 42.1% |
| older57 unchanged 24 | 0.05 | 8 | 11 | 0 | 0 | 42.1% |
| older57 unchanged 6 | 0.15 | 0 | 19 | 0 | 0 | 0.0% |
| older57 unchanged 6 | 0.10 | 0 | 19 | 0 | 0 | 0.0% |
| older57 unchanged 6 | 0.05 | 0 | 19 | 0 | 0 | 0.0% |
| primary36 always_down 12 | 0.15 | 0 | 0 | 3 | 9 | 0.0% |
| primary36 always_down 12 | 0.10 | 0 | 0 | 3 | 9 | 0.0% |
| primary36 always_down 12 | 0.05 | 0 | 0 | 3 | 9 | 0.0% |
| primary36 always_down 24 | 0.15 | 0 | 0 | 6 | 6 | 0.0% |
| primary36 always_down 24 | 0.10 | 0 | 0 | 6 | 6 | 0.0% |
| primary36 always_down 24 | 0.05 | 0 | 0 | 6 | 6 | 0.0% |
| primary36 always_down 6 | 0.15 | 0 | 0 | 0 | 12 | 0.0% |
| primary36 always_down 6 | 0.10 | 0 | 0 | 0 | 12 | 0.0% |
| primary36 always_down 6 | 0.05 | 0 | 0 | 0 | 12 | 0.0% |
| primary36 always_up 12 | 0.15 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 always_up 12 | 0.10 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 always_up 12 | 0.05 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 always_up 24 | 0.15 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 always_up 24 | 0.10 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 always_up 24 | 0.05 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 always_up 6 | 0.15 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 always_up 6 | 0.10 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 always_up 6 | 0.05 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 basket 12 | 0.15 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 basket 12 | 0.10 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 basket 12 | 0.05 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 basket 24 | 0.15 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 basket 24 | 0.10 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 basket 24 | 0.05 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 basket 6 | 0.15 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 basket 6 | 0.10 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 basket 6 | 0.05 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 gap 12 | 0.15 | 3 | 9 | 0 | 0 | 25.0% |
| primary36 gap 12 | 0.10 | 1 | 8 | 2 | 1 | 8.3% |
| primary36 gap 12 | 0.05 | 0 | 5 | 3 | 4 | 0.0% |
| primary36 gap 24 | 0.15 | 6 | 6 | 0 | 0 | 50.0% |
| primary36 gap 24 | 0.10 | 6 | 6 | 0 | 0 | 50.0% |
| primary36 gap 24 | 0.05 | 5 | 6 | 1 | 0 | 41.7% |
| primary36 gap 6 | 0.15 | 1 | 9 | 0 | 2 | 8.3% |
| primary36 gap 6 | 0.10 | 1 | 7 | 0 | 4 | 8.3% |
| primary36 gap 6 | 0.05 | 2 | 2 | 0 | 8 | 16.7% |
| primary36 intercept 12 | 0.15 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 intercept 12 | 0.10 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 intercept 12 | 0.05 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 intercept 24 | 0.15 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 intercept 24 | 0.10 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 intercept 24 | 0.05 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 intercept 6 | 0.15 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 intercept 6 | 0.10 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 intercept 6 | 0.05 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 retail 12 | 0.15 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 retail 12 | 0.10 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 retail 12 | 0.05 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 retail 24 | 0.15 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 retail 24 | 0.10 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 retail 24 | 0.05 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 retail 6 | 0.15 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 retail 6 | 0.10 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 retail 6 | 0.05 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 rolling 12 | 0.15 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 rolling 12 | 0.10 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 rolling 12 | 0.05 | 9 | 0 | 3 | 0 | 75.0% |
| primary36 rolling 24 | 0.15 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 rolling 24 | 0.10 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 rolling 24 | 0.05 | 6 | 0 | 6 | 0 | 50.0% |
| primary36 rolling 6 | 0.15 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 rolling 6 | 0.10 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 rolling 6 | 0.05 | 12 | 0 | 0 | 0 | 100.0% |
| primary36 unchanged 12 | 0.15 | 3 | 9 | 0 | 0 | 25.0% |
| primary36 unchanged 12 | 0.10 | 3 | 9 | 0 | 0 | 25.0% |
| primary36 unchanged 12 | 0.05 | 3 | 9 | 0 | 0 | 25.0% |
| primary36 unchanged 24 | 0.15 | 6 | 6 | 0 | 0 | 50.0% |
| primary36 unchanged 24 | 0.10 | 6 | 6 | 0 | 0 | 50.0% |
| primary36 unchanged 24 | 0.05 | 6 | 6 | 0 | 0 | 50.0% |
| primary36 unchanged 6 | 0.15 | 0 | 12 | 0 | 0 | 0.0% |
| primary36 unchanged 6 | 0.10 | 0 | 12 | 0 | 0 | 0.0% |
| primary36 unchanged 6 | 0.05 | 0 | 12 | 0 | 0 | 0.0% |
| secondary84 always_down 12 | 0.15 | 1 | 0 | 18 | 9 | 3.6% |
| secondary84 always_down 12 | 0.10 | 1 | 0 | 18 | 9 | 3.6% |
| secondary84 always_down 12 | 0.05 | 1 | 0 | 18 | 9 | 3.6% |
| secondary84 always_down 24 | 0.15 | 0 | 0 | 22 | 6 | 0.0% |
| secondary84 always_down 24 | 0.10 | 0 | 0 | 22 | 6 | 0.0% |
| secondary84 always_down 24 | 0.05 | 0 | 0 | 22 | 6 | 0.0% |
| secondary84 always_down 6 | 0.15 | 0 | 0 | 5 | 23 | 0.0% |
| secondary84 always_down 6 | 0.10 | 0 | 0 | 5 | 23 | 0.0% |
| secondary84 always_down 6 | 0.05 | 0 | 0 | 5 | 23 | 0.0% |
| secondary84 always_up 12 | 0.15 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 always_up 12 | 0.10 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 always_up 12 | 0.05 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 always_up 24 | 0.15 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 always_up 24 | 0.10 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 always_up 24 | 0.05 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 always_up 6 | 0.15 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 always_up 6 | 0.10 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 always_up 6 | 0.05 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 basket 12 | 0.15 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 basket 12 | 0.10 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 basket 12 | 0.05 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 basket 24 | 0.15 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 basket 24 | 0.10 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 basket 24 | 0.05 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 basket 6 | 0.15 | 17 | 6 | 5 | 0 | 60.7% |
| secondary84 basket 6 | 0.10 | 20 | 3 | 5 | 0 | 71.4% |
| secondary84 basket 6 | 0.05 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 gap 12 | 0.15 | 18 | 10 | 0 | 0 | 64.3% |
| secondary84 gap 12 | 0.10 | 16 | 8 | 2 | 2 | 57.1% |
| secondary84 gap 12 | 0.05 | 10 | 6 | 8 | 4 | 35.7% |
| secondary84 gap 24 | 0.15 | 22 | 6 | 0 | 0 | 78.6% |
| secondary84 gap 24 | 0.10 | 22 | 6 | 0 | 0 | 78.6% |
| secondary84 gap 24 | 0.05 | 21 | 4 | 1 | 2 | 75.0% |
| secondary84 gap 6 | 0.15 | 4 | 19 | 2 | 3 | 14.3% |
| secondary84 gap 6 | 0.10 | 3 | 17 | 3 | 5 | 10.7% |
| secondary84 gap 6 | 0.05 | 4 | 7 | 3 | 14 | 14.3% |
| secondary84 intercept 12 | 0.15 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 intercept 12 | 0.10 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 intercept 12 | 0.05 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 intercept 24 | 0.15 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 intercept 24 | 0.10 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 intercept 24 | 0.05 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 intercept 6 | 0.15 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 intercept 6 | 0.10 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 intercept 6 | 0.05 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 retail 12 | 0.15 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 retail 12 | 0.10 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 retail 12 | 0.05 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 retail 24 | 0.15 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 retail 24 | 0.10 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 retail 24 | 0.05 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 retail 6 | 0.15 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 retail 6 | 0.10 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 retail 6 | 0.05 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 rolling 12 | 0.15 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 rolling 12 | 0.10 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 rolling 12 | 0.05 | 9 | 0 | 18 | 1 | 32.1% |
| secondary84 rolling 24 | 0.15 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 rolling 24 | 0.10 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 rolling 24 | 0.05 | 6 | 0 | 22 | 0 | 21.4% |
| secondary84 rolling 6 | 0.15 | 17 | 6 | 5 | 0 | 60.7% |
| secondary84 rolling 6 | 0.10 | 20 | 3 | 5 | 0 | 71.4% |
| secondary84 rolling 6 | 0.05 | 23 | 0 | 5 | 0 | 82.1% |
| secondary84 unchanged 12 | 0.15 | 18 | 10 | 0 | 0 | 64.3% |
| secondary84 unchanged 12 | 0.10 | 18 | 10 | 0 | 0 | 64.3% |
| secondary84 unchanged 12 | 0.05 | 18 | 10 | 0 | 0 | 64.3% |
| secondary84 unchanged 24 | 0.15 | 22 | 6 | 0 | 0 | 78.6% |
| secondary84 unchanged 24 | 0.10 | 22 | 6 | 0 | 0 | 78.6% |
| secondary84 unchanged 24 | 0.05 | 22 | 6 | 0 | 0 | 78.6% |
| secondary84 unchanged 6 | 0.15 | 5 | 23 | 0 | 0 | 17.9% |
| secondary84 unchanged 6 | 0.10 | 5 | 23 | 0 | 0 | 17.9% |
| secondary84 unchanged 6 | 0.05 | 5 | 23 | 0 | 0 | 17.9% |

## Complete machine evidence

- `results/rows.csv` and `.json`: exact cohorts, IDs, fit IDs, prices, numerical signals and actual classes.
- `results/metrics.csv` and `.json`: 348 groups, including all terms and full57, complete 3×3 confusion matrices (actual rows, prediction columns), exhaustive outcome counts/rates, dates, class balance, and all precision/recall numerators and denominators. Empty CSV / JSON null means undefined. Balanced accuracy is null unless all three actual classes occur.
- `results/transitions.csv` and `.json`: 232 groups with complete 4×4 outcome and 3×3 prediction transitions and conserved totals.
- `results/misses.*`, `saved-baselines.*`, `precision-audit.*`: full audit without changes to the previous baseline.
- `results/summary.json`, `verification.json`, `reproducibility.json`: exclusions, checks and repeat hashes. No selected row failed or is missing. Nine mature v2 rows are unavailable; 90 immature v3 median rows and 294 nonmedian generated rows are out of scope. The learned source has 3,096 excluded rows (other windows/protocols and basket abstention), not failed forecasts.

## Limits and release boundary

This is post-hoc sensitivity on an already inspected holdout. All 66 pairs of primary issue windows overlap; terms share shocks. Revised export-time prices and futures do not prove original-vintage availability. Observed-to-canonical continuity is an assumption. Strict canonical rolling training remains unavailable: at most 13 matured prior issue days versus the unchanged 20-day gate. No future labels were used to train the pinned fits, but prior inspection still prevents fresh validation claims.

The qualified outlook application work already exists locally and is undeployed. This task changes no application behavior. The application currently saves one direction threshold and reuses it for outcome evaluation. A future signal-threshold change would need explicit separate signal/outcome metadata and model identity, plus approval. Keep the outcome threshold 0.15 and preserve historical labels. Do not rename accuracy by moving its target boundary.

## Reproduce

From repository root:

```sh
python3 tasks/forecast-direction-sensitivity/reproduce.py
```

This runs only the new analysis, independent verifier and report renderer twice. It checks byte-identical outputs and all 1,886 preserved files. No application tests or frontend build apply because this task changes no application, CSS or JS. No production, DB, dependencies, commit or push is used.
