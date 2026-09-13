# P20 leading indicators — offline study

**This is an already inspected retrospective period, not an untouched holdout. No model is selected or released.**

Prices and errors are consumer c/kWh. P20 is the 20th percentile of offered unit prices. It is not the mean of the cheapest 20%, and not a fixed set of companies. The target is public fixed-term 6/12/24-month energy-price unit statistics, not annual-cost statistics. Product turnover and promotions can move these quantiles without the same offers changing price.

## Main findings

**A has limited support; B does not show a stable p20-change lead.** With pooled coefficients, rolling futures lower p20 MAE versus unchanged, history and intercept in both horizons and both regimes. That consistency is stronger than for median or p80 versus their own unchanged/intercept controls. But the primary futures increment versus history is smaller for p20 (−0.0345) than median (−0.0422) or p80 (−0.0697). A higher total p20 skill is not proof of stronger independent futures information.

Primary pooled p20 skill is +34.2%, but the 24-month term loses to unchanged and both 12/24-month terms lose to intercept. Independent per-term p20 rolling fits lower aggregate error versus history by only 0.0094 and lose to intercept by 0.0294. At 14 days they lose to history by 0.0034. Thus the benefit is not shared reliably across all duration/control choices. Primary fixed-basket pooled forecasts lose to rolling for all three quantiles.

For B, primary pooled p20-change lowers median MAE by 0.0559 versus history and 0.0605 versus intercept. The six-month gain offsets losses at 12 and 24 months. Combined futures add another 0.0557 reduction versus p20. All these pooled primary models call UP on every row: 27/36 correct, exactly the always-UP result. This is a numerical gain, not a directional lead advantage.

The p20-change result reverses in independent per-term primary fits (+0.0149 error versus history) and older observed pooled 30-day fits (+0.0049 versus history, +0.1902 versus intercept). At 14 days p20-change also loses to history in both regimes and fit scopes. Spread helps older observed 14-day fits (−0.0310 pooled, −0.0535 per-term versus history), but loses in canonical 14-day fits. There is no stable spread lead across regimes.

Direction is also inconsistent. Pooled B p20/history correct counts are 27/27 of 36 primary, 38/38 of 84 canonical 14-day, 39/39 of 66 older 30-day, and 90/90 of 162 older 14-day rows. Per-term p20/history counts are 27/26, 52/60, 39/39 and 64/69 on those respective denominators. A small primary gain does not repeat across horizons. Full unfavorable cases and controls are below.

## Frozen method

Primary: 30-day target, seven-day inputs. Secondary: 14-day target, the same inputs. Training labels are strictly before July 27 for the fixed observed-to-canonical transfer. Current, seven-day lag and target each stay within one basis. Earlier rolling-origin fits use only same-basis labels strictly before each issue. The transfer assumes coefficients can cross the basis change; no feature or label does. All three quantiles and all three terms share native rolling-H input support. No missing price is filled.

Pooled fits share slopes across terms. Independent per-term fits are a prespecified sensitivity, not a model choice after the result. Each uses at least 20 unique training issue days. Ridge is mean squared change error plus 1.0 times squared training-standardized slopes; the intercept is the historical mean target change. There are no term dummies, tuned thresholds or extra lag grids. Raw fitted changes are used for numerical errors and model direction. Actual direction uses four-decimal change, with +/-0.15 inclusive up/down. Always-UP/DOWN are direction-only controls.

The fixed-basket A-only sensitivity prices identical issue-selected delivery months at both strictly prior trade vintages. Rolling H selects next-full-month delivery separately at each endpoint. Both use FI Base, complete month-quarter-year fallback, actual month-day weights and VAT 1.255. No gap/EWMA model is included, so the prior study's unrelated ten-day warmup does not restrict this training cohort.

## Exact coverage

| Protocol | Horizon | Test rows/days | Issue dates | Target dates |
|---|---:|---|---|---|
| transfer | 30 | 36/12 | 2026-08-03–2026-08-14 | 2026-09-02–2026-09-13 |
| transfer | 14 | 84/28 | 2026-08-03–2026-08-30 | 2026-08-17–2026-09-13 |
| rolling_observed | 30 | 66/22 | 2026-06-05–2026-06-26 | 2026-07-05–2026-07-26 |
| rolling_observed | 14 | 162/54 | 2026-05-20–2026-07-12 | 2026-06-03–2026-07-26 |

These counts apply separately to each quantile and each model, not to independent term observations. Every retained date has 6/12/24-month rows. Native A and B share the exact test and training identities; A-median and B controls have identical predictions. Fixed-basket support is identical to native support in this export; its controls are nevertheless fitted on its explicitly matched cohort. All 477 owned quantile vectors are finite and ordered; none are rejected.

| Horizon | Frozen pooled training rows/days | First/last issue | Last target |
|---|---|---|---|
| 30 | 216/72 | 2026-04-16–2026-06-26 | 2026-07-26 |
| 14 | 264/88 | 2026-04-16–2026-07-12 | 2026-07-26 |

Strict canonical rolling-origin fits are unavailable: maximum training issue days are 0 at 30 days and 13 at 14 days, below 20. They do not borrow observed rows.

## A: Does futures information help p20 more?

Each q has its own target change and history input. Negative MAE increments mean lower error. Skill is relative to that quantile's own unchanged-price MAE. Raw MAEs across different quantile targets do not show which target is more predictable.

| Fit | Quantile | Term | Unchanged MAE | Rolling MAE | Skill | Rolling − history | Rolling − intercept |
|---|---|---|---:|---:|---:|---:|---:|
| pooled | p20 | all | 0.6702 | 0.4412 | +34.2% | -0.0345 | -0.0793 |
| pooled | p20 | 6 | 1.5152 | 0.6844 | +54.8% | -0.0895 | -0.2894 |
| pooled | p20 | 12 | 0.3299 | 0.2440 | +26.0% | -0.0132 | +0.0325 |
| pooled | p20 | 24 | 0.1654 | 0.3952 | -139.0% | -0.0008 | +0.0192 |
| pooled | median | all | 0.6086 | 0.4359 | +28.4% | -0.0422 | -0.0468 |
| pooled | median | 6 | 1.3340 | 0.6208 | +53.5% | -0.0917 | -0.1155 |
| pooled | median | 12 | 0.2916 | 0.2937 | -0.7% | -0.0310 | -0.0206 |
| pooled | median | 24 | 0.2002 | 0.3932 | -96.4% | -0.0040 | -0.0043 |
| pooled | p80 | all | 0.5099 | 0.4299 | +15.7% | -0.0697 | -0.0589 |
| pooled | p80 | 6 | 1.0033 | 0.5139 | +48.8% | -0.1780 | -0.1665 |
| pooled | p80 | 12 | 0.3062 | 0.3606 | -17.8% | -0.0274 | -0.0118 |
| pooled | p80 | 24 | 0.2201 | 0.4151 | -88.6% | -0.0036 | +0.0016 |
| per_term | p20 | all | 0.6702 | 0.2088 | +68.8% | -0.0094 | +0.0294 |
| per_term | p20 | 6 | 1.5152 | 0.3768 | +75.1% | -0.0310 | +0.0018 |
| per_term | p20 | 12 | 0.3299 | 0.2154 | +34.7% | +0.0073 | +0.1154 |
| per_term | p20 | 24 | 0.1654 | 0.0343 | +79.2% | -0.0045 | -0.0291 |
| per_term | median | all | 0.6086 | 0.1706 | +72.0% | -0.0008 | +0.0012 |
| per_term | median | 6 | 1.3340 | 0.1329 | +90.0% | -0.0368 | -0.0305 |
| per_term | median | 12 | 0.2916 | 0.2635 | +9.6% | +0.0339 | +0.0733 |
| per_term | median | 24 | 0.2002 | 0.1154 | +42.4% | +0.0006 | -0.0393 |
| per_term | p80 | all | 0.5099 | 0.2522 | +50.5% | -0.0111 | -0.0383 |
| per_term | p80 | 6 | 1.0033 | 0.4336 | +56.8% | -0.0148 | -0.0536 |
| per_term | p80 | 12 | 0.3062 | 0.1244 | +59.4% | -0.0187 | -0.0501 |
| per_term | p80 | 24 | 0.2201 | 0.1987 | +9.7% | +0.0001 | -0.0112 |

## B: Does p20 add early information for the median?

P20-change and spread inputs use issue-date or earlier prices only. Spread is median minus p20 at issue. Combined means median-history + p20-change + rolling futures change; it was fixed before results. Compare it with p20, not with a model selected as best after the test.

| Fit | Term | Model | MAE | Skill | − history | − intercept | Correct/wrong/missed/false |
|---|---|---|---:|---:|---:|---:|---|
| pooled | all | history | 0.4781 | +21.4% | +0.0000 | -0.0046 | 27/0/0/9 |
| pooled | all | p20 | 0.4222 | +30.6% | -0.0559 | -0.0605 | 27/0/0/9 |
| pooled | all | spread | 0.4795 | +21.2% | +0.0014 | -0.0032 | 27/0/0/9 |
| pooled | all | combined | 0.3665 | +39.8% | -0.1116 | -0.1162 | 27/0/0/9 |
| pooled | 6 | history | 0.7125 | +46.6% | +0.0000 | -0.0238 | 12/0/0/0 |
| pooled | 6 | p20 | 0.4775 | +64.2% | -0.2350 | -0.2589 | 12/0/0/0 |
| pooled | 6 | spread | 0.6831 | +48.8% | -0.0294 | -0.0532 | 12/0/0/0 |
| pooled | 6 | combined | 0.3422 | +74.3% | -0.3703 | -0.3942 | 12/0/0/0 |
| pooled | 12 | history | 0.3247 | -11.4% | +0.0000 | +0.0104 | 9/0/0/3 |
| pooled | 12 | p20 | 0.3658 | -25.4% | +0.0411 | +0.0515 | 9/0/0/3 |
| pooled | 12 | spread | 0.3335 | -14.3% | +0.0087 | +0.0191 | 9/0/0/3 |
| pooled | 12 | combined | 0.3343 | -14.6% | +0.0096 | +0.0200 | 9/0/0/3 |
| pooled | 24 | history | 0.3971 | -98.4% | +0.0000 | -0.0003 | 6/0/0/6 |
| pooled | 24 | p20 | 0.4233 | -111.4% | +0.0262 | +0.0258 | 6/0/0/6 |
| pooled | 24 | spread | 0.4218 | -110.7% | +0.0247 | +0.0244 | 6/0/0/6 |
| pooled | 24 | combined | 0.4229 | -111.2% | +0.0258 | +0.0254 | 6/0/0/6 |
| per_term | all | history | 0.1714 | +71.8% | +0.0000 | +0.0019 | 26/0/7/3 |
| per_term | all | p20 | 0.1863 | +69.4% | +0.0149 | +0.0169 | 27/0/9/0 |
| per_term | all | spread | 0.1646 | +73.0% | -0.0068 | -0.0049 | 28/0/4/4 |
| per_term | all | combined | 0.1795 | +70.5% | +0.0081 | +0.0101 | 26/0/10/0 |
| per_term | 6 | history | 0.1697 | +87.3% | +0.0000 | +0.0063 | 12/0/0/0 |
| per_term | 6 | p20 | 0.1821 | +86.4% | +0.0123 | +0.0186 | 12/0/0/0 |
| per_term | 6 | spread | 0.2124 | +84.1% | +0.0427 | +0.0490 | 12/0/0/0 |
| per_term | 6 | combined | 0.1178 | +91.2% | -0.0519 | -0.0456 | 12/0/0/0 |
| per_term | 12 | history | 0.2296 | +21.3% | +0.0000 | +0.0394 | 5/0/7/0 |
| per_term | 12 | p20 | 0.2496 | +14.4% | +0.0200 | +0.0594 | 5/0/7/0 |
| per_term | 12 | spread | 0.1821 | +37.6% | -0.0475 | -0.0081 | 7/0/4/1 |
| per_term | 12 | combined | 0.2928 | -0.4% | +0.0632 | +0.1026 | 5/0/7/0 |
| per_term | 24 | history | 0.1148 | +42.6% | +0.0000 | -0.0399 | 9/0/0/3 |
| per_term | 24 | p20 | 0.1273 | +36.4% | +0.0124 | -0.0274 | 10/0/2/0 |
| per_term | 24 | spread | 0.0992 | +50.5% | -0.0156 | -0.0555 | 9/0/0/3 |
| per_term | 24 | combined | 0.1279 | +36.1% | +0.0131 | -0.0268 | 9/0/3/0 |

## Regime and horizon checks

These are separate regimes and different target windows. Describe sign consistency; do not select an optimal horizon. All term-level errors, bias, RMSE and full three-class precision/recall remain in `results/metrics.csv` and JSON.

| Protocol | Horizon | Study/target/model | Fit | MAE | Skill | − history | − intercept |
|---|---:|---|---|---:|---:|---:|---:|
| transfer | 30 | A/p20/rolling | pooled | 0.4412 | +34.2% | -0.0345 | -0.0793 |
| transfer | 30 | A/median/rolling | pooled | 0.4359 | +28.4% | -0.0422 | -0.0468 |
| transfer | 30 | A/p80/rolling | pooled | 0.4299 | +15.7% | -0.0697 | -0.0589 |
| transfer | 30 | B/median/p20 | pooled | 0.4222 | +30.6% | -0.0559 | -0.0605 |
| transfer | 30 | B/median/spread | pooled | 0.4795 | +21.2% | +0.0014 | -0.0032 |
| transfer | 30 | B/median/combined | pooled | 0.3665 | +39.8% | -0.1116 | -0.1162 |
| transfer | 30 | A/p20/rolling | per_term | 0.2088 | +68.8% | -0.0094 | +0.0294 |
| transfer | 30 | A/median/rolling | per_term | 0.1706 | +72.0% | -0.0008 | +0.0012 |
| transfer | 30 | A/p80/rolling | per_term | 0.2522 | +50.5% | -0.0111 | -0.0383 |
| transfer | 30 | B/median/p20 | per_term | 0.1863 | +69.4% | +0.0149 | +0.0169 |
| transfer | 30 | B/median/spread | per_term | 0.1646 | +73.0% | -0.0068 | -0.0049 |
| transfer | 30 | B/median/combined | per_term | 0.1795 | +70.5% | +0.0081 | +0.0101 |
| transfer | 14 | A/p20/rolling | pooled | 0.2495 | +18.6% | -0.0140 | -0.0142 |
| transfer | 14 | A/median/rolling | pooled | 0.3027 | -4.7% | -0.0116 | -0.0086 |
| transfer | 14 | A/p80/rolling | pooled | 0.3101 | -22.2% | -0.0335 | -0.0313 |
| transfer | 14 | B/median/p20 | pooled | 0.3148 | -8.9% | +0.0005 | +0.0035 |
| transfer | 14 | B/median/spread | pooled | 0.3189 | -10.3% | +0.0045 | +0.0076 |
| transfer | 14 | B/median/combined | pooled | 0.2995 | -3.6% | -0.0149 | -0.0118 |
| transfer | 14 | A/p20/rolling | per_term | 0.1815 | +40.8% | +0.0034 | -0.0015 |
| transfer | 14 | A/median/rolling | per_term | 0.2298 | +20.5% | +0.0019 | +0.0050 |
| transfer | 14 | A/p80/rolling | per_term | 0.2772 | -9.2% | -0.0286 | -0.0331 |
| transfer | 14 | B/median/p20 | per_term | 0.2497 | +13.6% | +0.0219 | +0.0249 |
| transfer | 14 | B/median/spread | per_term | 0.2380 | +17.7% | +0.0101 | +0.0132 |
| transfer | 14 | B/median/combined | per_term | 0.2442 | +15.5% | +0.0163 | +0.0194 |
| rolling_observed | 30 | A/p20/rolling | pooled | 0.4218 | +7.2% | -0.0095 | -0.0076 |
| rolling_observed | 30 | A/median/rolling | pooled | 0.5733 | -34.7% | -0.0668 | +0.1184 |
| rolling_observed | 30 | A/p80/rolling | pooled | 0.4760 | -20.8% | -0.1030 | +0.0334 |
| rolling_observed | 30 | B/median/p20 | pooled | 0.6451 | -51.5% | +0.0049 | +0.1902 |
| rolling_observed | 30 | B/median/spread | pooled | 0.6042 | -41.9% | -0.0359 | +0.1493 |
| rolling_observed | 30 | B/median/combined | pooled | 0.5675 | -33.3% | -0.0727 | +0.1126 |
| rolling_observed | 30 | A/p20/rolling | per_term | 0.2647 | +41.8% | -0.0124 | -0.0035 |
| rolling_observed | 30 | A/median/rolling | per_term | 0.5900 | -38.6% | -0.0372 | +0.1505 |
| rolling_observed | 30 | A/p80/rolling | per_term | 0.4089 | -3.8% | -0.0785 | -0.0393 |
| rolling_observed | 30 | B/median/p20 | per_term | 0.6955 | -63.4% | +0.0683 | +0.2560 |
| rolling_observed | 30 | B/median/spread | per_term | 0.7412 | -74.1% | +0.1140 | +0.3017 |
| rolling_observed | 30 | B/median/combined | per_term | 0.6701 | -57.4% | +0.0429 | +0.2306 |
| rolling_observed | 14 | A/p20/rolling | pooled | 0.2471 | +20.6% | -0.0512 | -0.0453 |
| rolling_observed | 14 | A/median/rolling | pooled | 0.3773 | +11.7% | -0.0559 | -0.0465 |
| rolling_observed | 14 | A/p80/rolling | pooled | 0.3619 | +7.4% | -0.0287 | -0.0178 |
| rolling_observed | 14 | B/median/p20 | pooled | 0.4404 | -3.1% | +0.0072 | +0.0166 |
| rolling_observed | 14 | B/median/spread | pooled | 0.4023 | +5.8% | -0.0310 | -0.0216 |
| rolling_observed | 14 | B/median/combined | pooled | 0.3854 | +9.8% | -0.0478 | -0.0384 |
| rolling_observed | 14 | A/p20/rolling | per_term | 0.2513 | +19.2% | -0.0250 | -0.0020 |
| rolling_observed | 14 | A/median/rolling | per_term | 0.3799 | +11.1% | -0.0253 | -0.0213 |
| rolling_observed | 14 | A/p80/rolling | per_term | 0.3425 | +12.3% | -0.0091 | +0.0012 |
| rolling_observed | 14 | B/median/p20 | per_term | 0.4216 | +1.3% | +0.0164 | +0.0204 |
| rolling_observed | 14 | B/median/spread | per_term | 0.3518 | +17.6% | -0.0535 | -0.0495 |
| rolling_observed | 14 | B/median/combined | per_term | 0.3993 | +6.5% | -0.0060 | -0.0020 |

## Fixed-basket sensitivity (A only)

| Protocol | Horizon | Quantile | Fit | Basket MAE | − history | − intercept | − rolling |
|---|---:|---|---|---:|---:|---:|---:|
| transfer | 30 | p20 | pooled | 0.4745 | -0.0012 | -0.0460 | +0.0333 |
| transfer | 30 | median | pooled | 0.4853 | +0.0071 | +0.0025 | +0.0494 |
| transfer | 30 | p80 | pooled | 0.4771 | -0.0225 | -0.0118 | +0.0472 |
| transfer | 30 | p20 | per_term | 0.2157 | -0.0026 | +0.0362 | +0.0068 |
| transfer | 30 | median | per_term | 0.1842 | +0.0128 | +0.0147 | +0.0135 |
| transfer | 30 | p80 | per_term | 0.2404 | -0.0230 | -0.0502 | -0.0119 |
| transfer | 14 | p20 | pooled | 0.2687 | +0.0053 | +0.0051 | +0.0193 |
| transfer | 14 | median | pooled | 0.3252 | +0.0109 | +0.0139 | +0.0225 |
| transfer | 14 | p80 | pooled | 0.3365 | -0.0070 | -0.0049 | +0.0264 |
| transfer | 14 | p20 | per_term | 0.1945 | +0.0164 | +0.0116 | +0.0131 |
| transfer | 14 | median | per_term | 0.2422 | +0.0144 | +0.0175 | +0.0125 |
| transfer | 14 | p80 | per_term | 0.2930 | -0.0128 | -0.0173 | +0.0159 |
| rolling_observed | 30 | p20 | pooled | 0.4278 | -0.0035 | -0.0016 | +0.0060 |
| rolling_observed | 30 | median | pooled | 0.5786 | -0.0615 | +0.1237 | +0.0053 |
| rolling_observed | 30 | p80 | pooled | 0.4728 | -0.1061 | +0.0302 | -0.0032 |
| rolling_observed | 30 | p20 | per_term | 0.2562 | -0.0208 | -0.0120 | -0.0085 |
| rolling_observed | 30 | median | per_term | 0.5570 | -0.0702 | +0.1175 | -0.0330 |
| rolling_observed | 30 | p80 | per_term | 0.3725 | -0.1149 | -0.0756 | -0.0363 |
| rolling_observed | 14 | p20 | pooled | 0.2533 | -0.0450 | -0.0390 | +0.0062 |
| rolling_observed | 14 | median | pooled | 0.4052 | -0.0281 | -0.0187 | +0.0279 |
| rolling_observed | 14 | p80 | pooled | 0.3872 | -0.0035 | +0.0074 | +0.0252 |
| rolling_observed | 14 | p20 | per_term | 0.2518 | -0.0245 | -0.0015 | +0.0005 |
| rolling_observed | 14 | median | per_term | 0.3887 | -0.0166 | -0.0126 | +0.0088 |
| rolling_observed | 14 | p80 | per_term | 0.3550 | +0.0035 | +0.0138 | +0.0126 |

## Direction and class balance

Rows are up/down/flat counts. Always-UP accuracy equals the up share: 75% when 27 of 36 actuals rise is not independent skill. Downward recall is undefined when there are no actual falls. No zero-denominator metric is replaced with zero.

| Protocol | Horizon | Quantile | Actual up/down/flat | Rolling correct/n | UP correct/n | DOWN correct/n |
|---|---:|---|---|---|---|---|
| transfer | 30 | p20 | 31/0/5 | 31/36 | 31/36 | 0/36 |
| transfer | 30 | median | 27/0/9 | 27/36 | 27/36 | 0/36 |
| transfer | 30 | p80 | 25/0/11 | 25/36 | 25/36 | 0/36 |
| transfer | 14 | p20 | 45/0/39 | 38/84 | 45/84 | 0/84 |
| transfer | 14 | median | 38/1/45 | 32/84 | 38/84 | 1/84 |
| transfer | 14 | p80 | 28/5/51 | 27/84 | 28/84 | 5/84 |
| rolling_observed | 30 | p20 | 40/0/26 | 38/66 | 40/66 | 0/66 |
| rolling_observed | 30 | median | 39/5/22 | 36/66 | 39/66 | 5/66 |
| rolling_observed | 30 | p80 | 41/0/25 | 39/66 | 41/66 | 0/66 |
| rolling_observed | 14 | p20 | 84/15/63 | 89/162 | 84/162 | 15/162 |
| rolling_observed | 14 | median | 90/23/49 | 86/162 | 90/162 | 23/162 |
| rolling_observed | 14 | p80 | 92/14/56 | 68/162 | 92/162 | 14/162 |

## Paired issue-date errors and unfavorable cases

Each date first averages its three term errors. Negative is better. All dates and all model/control differences are saved, not only favorable examples. Date windows overlap and share shocks; these ranges are not confidence intervals.

| Protocol | Study/target/model/control | Better/worse/tie days | Mean increment | Best date/value | Worst date/value |
|---|---|---|---:|---|---|
| transfer | A/p20/rolling/history | 9/3/0 (12 total) | -0.0345 | 2026-08-03 / -0.0848 | 2026-08-09 / +0.0021 |
| transfer | A/median/rolling/history | 5/7/0 (12 total) | -0.0422 | 2026-08-03 / -0.1301 | 2026-08-14 / +0.0256 |
| transfer | A/p80/rolling/history | 9/3/0 (12 total) | -0.0697 | 2026-08-03 / -0.1180 | 2026-08-14 / +0.0474 |
| transfer | B/median/p20/history | 11/1/0 (12 total) | -0.0559 | 2026-08-14 / -0.1352 | 2026-08-07 / +0.0276 |
| transfer | B/median/spread/history | 4/8/0 (12 total) | +0.0014 | 2026-08-11 / -0.0170 | 2026-08-05 / +0.0129 |
| transfer | B/median/combined/p20 | 9/3/0 (12 total) | -0.0557 | 2026-08-03 / -0.1488 | 2026-08-08 / +0.0106 |
| rolling_observed | A/p20/rolling/history | 10/12/0 (22 total) | -0.0095 | 2026-06-19 / -0.2124 | 2026-06-23 / +0.1243 |
| rolling_observed | A/median/rolling/history | 13/9/0 (22 total) | -0.0668 | 2026-06-11 / -0.3871 | 2026-06-19 / +0.1685 |
| rolling_observed | A/p80/rolling/history | 18/4/0 (22 total) | -0.1030 | 2026-06-16 / -0.3943 | 2026-06-05 / +0.2467 |
| rolling_observed | B/median/p20/history | 8/14/0 (22 total) | +0.0049 | 2026-06-05 / -0.1273 | 2026-06-06 / +0.1106 |
| rolling_observed | B/median/spread/history | 16/6/0 (22 total) | -0.0359 | 2026-06-06 / -0.2774 | 2026-06-05 / +0.2200 |
| rolling_observed | B/median/combined/p20 | 13/9/0 (22 total) | -0.0776 | 2026-06-11 / -0.3855 | 2026-06-19 / +0.1997 |

Past seven-day p20 and median changes also move together contemporaneously (`comovement.csv`). That same-day relation is not evidence that p20 leads. Only future-target predictions and the earlier rolling-origin check address lead value, and this short retrospective evidence is limited.

## Verification, limits and small next step

Independent verification passed 600 raw pairs, 432 feature rows, 954 Decimal daily-expanded baskets, 477 zero-lag comparisons to retained PHP-verified H evidence, 8112 normal-equation fit checks, 32712 predictions and 19 synthetic guards. Raw versus four-decimal saved model direction differs on 0 predictions. All exact IDs, cutoffs, training means/scales/slopes, feature contributions and unavailable fits are retained. Repeated origins create many fit instances; they do not add model definitions or search parameters.

Input/source/spec hashes are checked before and after. `reproduce.py` runs the analysis, independent verifier and renderer twice and requires byte-identical outputs. Run `python3 tasks/forecast-p20-leading-indicators/reproduce.py` from the repository root. No Laravel tests or asset build are needed because no application, CSS or JS changed.

This export can contain revisions and is not archived issue-time evidence. Overlapping dates are not independent, and term rows share market shocks. Distribution composition is uncontrolled. Error increments are not causal fractions or a variance decomposition. No automatic adoption follows.

Small next step: if the user wants to continue, repeat these same few comparisons on the next separate local export with matured targets, keeping dates, features and thresholds fixed. This needs no production model change or archive collector. Do not tune to this period or choose a model from these tables.
