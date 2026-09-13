# Intercept-only post-hoc diagnostic

This user-approved continuation is separate from the original frozen experiment. The holdout was already inspected. This is **not untouched out-of-sample validation** and no result permits production adoption. `intercept-spec.md` and its checksum were frozen before these metrics. Original outputs and predictions remain unchanged.

## Main result

The primary training mean is **0.604894 c/kWh**. Intercept-only MAE is **0.484324**, versus unchanged 0.608625, retail 0.479421, rolling 0.436333, and basket 0.485761.

- retail: MAE minus intercept -0.004902 c/kWh (-1.01%).
- rolling: MAE minus intercept -0.047990 c/kWh (-9.91%).
- basket: MAE minus intercept +0.001437 c/kWh (+0.30%).

Retail's gain against unchanged is mostly reproduced by the historical mean: its features lower primary MAE only slightly versus intercept. Rolling lowers primary error versus intercept. Fixed basket does not. These are paired error differences, not causal percentages of gain or variance explained. Against unchanged, the primary pooled gain is limited to the six-month term; 12 and 24 months lose, including intercept-only. No term-specific model is selected.

## Method and identities

Price = current + equal-row mean(training target − current), shared across all three terms. The mean is the standardized ridge intercept, not raw-feature b0. Each of 136 original fit sets keeps its exact retail/rolling/basket training IDs; no model is refit. Frozen training uses observed labels strictly before July 27. Rolling-origin means use each issue's same-basis labels strictly before that issue. The 20-prior-issue-DAY minimum is unchanged. Every training and test day has all three terms, so equal row weights and equal issue weights agree. No feature-free row expansion, term mean, calibration or intercept abstention is used.

Strict canonical rolling remains unavailable (maximum 13 prior issue days); no older-basis fill is used. Original availability and exclusions remain pinned. Predictions retain all 3,564 original rows and add 594 intercept rows. Each row saves its fit ID, pair ID, current, target, intercept, predicted price and paired absolute-error increment. `means.json` saves all training IDs, counts and cutoffs. Target is the same public offered energy-price median, not a controlled index or a price level fitted as the response.

Bias is predicted minus actual; direction is down/flat/up with absolute delta strictly below .15 flat. Skill = 1 − MAE / unchanged MAE. MAE − intercept and relative % use the same paired rows; negative is better. All price/error units are c/kWh. Signed errors can cancel across terms, and term MAE gains and losses can offset in the pooled difference. No causal fraction follows.

## All original cohorts, with per-term scores

### frozen_transfer: input 7, target 30

2026-08-03–2026-08-14; 36 rows / 12 days. Overlapping issue-window pairs: 66/66.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 36/12 | 0.608625 | 0.824744 | -0.608447 | 25.00 | +0.00 | +0.124301 | +25.66 |
| intercept | 36/12 | 0.484324 | 0.556782 | -0.003553 | 75.00 | +20.42 | +0.000000 | +0.00 |
| gap | 36/12 | 0.656925 | 0.859306 | -0.656925 | 27.78 | -7.94 | +0.172601 | +35.64 |
| retail | 36/12 | 0.479421 | 0.549793 | +0.007920 | 75.00 | +21.23 | -0.004902 | -1.01 |
| rolling | 36/12 | 0.436333 | 0.491109 | +0.022788 | 75.00 | +28.31 | -0.047990 | -9.91 |
| basket | 36/12 | 0.485761 | 0.552325 | -0.008165 | 75.00 | +20.19 | +0.001437 | +0.30 |
| basket_abstain | 36/12 | 0.485761 | 0.552325 | -0.008165 | 75.00 | +20.19 | +0.001437 | +0.30 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 12/12 | 1.334042 | 1.354380 | -1.334042 | 0.00 | +0.00 | +0.604894 | +82.96 |
| intercept | 12/12 | 0.729147 | 0.765725 | -0.729147 | 100.00 | +45.34 | +0.000000 | +0.00 |
| gap | 12/12 | 1.397800 | 1.405900 | -1.397800 | 8.33 | -4.78 | +0.668653 | +91.70 |
| retail | 12/12 | 0.704291 | 0.744358 | -0.704291 | 100.00 | +47.21 | -0.024857 | -3.41 |
| rolling | 12/12 | 0.614494 | 0.636581 | -0.614494 | 100.00 | +53.94 | -0.114654 | -15.72 |
| basket | 12/12 | 0.736651 | 0.759075 | -0.736651 | 100.00 | +44.78 | +0.007503 | +1.03 |
| basket_abstain | 12/12 | 0.736651 | 0.759075 | -0.736651 | 100.00 | +44.78 | +0.007503 | +1.03 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 12/12 | 0.291608 | 0.363813 | -0.291075 | 25.00 | +0.00 | -0.027546 | -8.63 |
| intercept | 12/12 | 0.319154 | 0.382253 | +0.313819 | 75.00 | -9.45 | +0.000000 | +0.00 |
| gap | 12/12 | 0.357333 | 0.401566 | -0.357333 | 25.00 | -22.54 | +0.038179 | +11.96 |
| retail | 12/12 | 0.329897 | 0.393468 | +0.323976 | 75.00 | -13.13 | +0.010743 | +3.37 |
| rolling | 12/12 | 0.297359 | 0.350603 | +0.285711 | 75.00 | -1.97 | -0.021795 | -6.83 |
| basket | 12/12 | 0.319184 | 0.376239 | +0.310707 | 75.00 | -9.46 | +0.000030 | +0.01 |
| basket_abstain | 12/12 | 0.319184 | 0.376239 | +0.310707 | 75.00 | -9.46 | +0.000030 | +0.01 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 12/12 | 0.200225 | 0.271847 | -0.200225 | 50.00 | +0.00 | -0.204444 | -50.52 |
| intercept | 12/12 | 0.404669 | 0.444486 | +0.404669 | 50.00 | -102.11 | +0.000000 | +0.00 |
| gap | 12/12 | 0.215642 | 0.278229 | -0.215642 | 50.00 | -7.70 | -0.189028 | -46.71 |
| retail | 12/12 | 0.404076 | 0.444895 | +0.404076 | 50.00 | -101.81 | -0.000594 | -0.15 |
| rolling | 12/12 | 0.397147 | 0.442048 | +0.397147 | 50.00 | -98.35 | -0.007522 | -1.86 |
| basket | 12/12 | 0.401448 | 0.444340 | +0.401448 | 50.00 | -100.50 | -0.003221 | -0.80 |
| basket_abstain | 12/12 | 0.401448 | 0.444340 | +0.401448 | 50.00 | -100.50 | -0.003221 | -0.80 |
### frozen_transfer: input 7, target 14

2026-08-03–2026-08-30; 84 rows / 28 days. Overlapping issue-window pairs: 273/378.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 84/28 | 0.289119 | 0.462442 | -0.258888 | 53.57 | +0.00 | -0.026279 | -8.33 |
| intercept | 84/28 | 0.315398 | 0.385735 | +0.044299 | 45.24 | -9.09 | +0.000000 | +0.00 |
| gap | 84/28 | 0.318807 | 0.490248 | -0.298745 | 52.38 | -10.27 | +0.003409 | +1.08 |
| retail | 84/28 | 0.318988 | 0.391061 | +0.038115 | 45.24 | -10.33 | +0.003591 | +1.14 |
| rolling | 84/28 | 0.306564 | 0.359491 | +0.004824 | 38.10 | -6.03 | -0.008834 | -2.80 |
| basket | 84/28 | 0.328629 | 0.401532 | -0.000870 | 38.10 | -13.67 | +0.013231 | +4.20 |
| basket_abstain | 84/28 | 0.335658 | 0.411701 | -0.007899 | 38.10 | -16.10 | +0.020260 | +6.42 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 28/28 | 0.576375 | 0.726890 | -0.574232 | 17.86 | +0.00 | +0.179611 | +45.27 |
| intercept | 28/28 | 0.396764 | 0.521625 | -0.271045 | 82.14 | +31.16 | +0.000000 | +0.00 |
| gap | 28/28 | 0.647854 | 0.767075 | -0.646432 | 14.29 | -12.40 | +0.251089 | +63.28 |
| retail | 28/28 | 0.405082 | 0.533393 | -0.291646 | 82.14 | +29.72 | +0.008318 | +2.10 |
| rolling | 28/28 | 0.398881 | 0.475836 | -0.308358 | 60.71 | +30.79 | +0.002117 | +0.53 |
| basket | 28/28 | 0.449148 | 0.559347 | -0.348223 | 60.71 | +22.07 | +0.052384 | +13.20 |
| basket_abstain | 28/28 | 0.470235 | 0.581102 | -0.369309 | 60.71 | +18.42 | +0.073471 | +18.52 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 28/28 | 0.201154 | 0.288452 | -0.116175 | 64.29 | +0.00 | -0.093993 | -31.85 |
| intercept | 28/28 | 0.295146 | 0.323545 | +0.187012 | 32.14 | -46.73 | +0.000000 | +0.00 |
| gap | 28/28 | 0.203886 | 0.312860 | -0.151321 | 64.29 | -1.36 | -0.091260 | -30.92 |
| retail | 28/28 | 0.295016 | 0.321286 | +0.185310 | 32.14 | -46.66 | -0.000130 | -0.04 |
| rolling | 28/28 | 0.280316 | 0.312778 | +0.135934 | 32.14 | -39.35 | -0.014830 | -5.02 |
| basket | 28/28 | 0.288553 | 0.321370 | +0.150280 | 32.14 | -43.45 | -0.006593 | -2.23 |
| basket_abstain | 28/28 | 0.288553 | 0.321370 | +0.150280 | 32.14 | -43.45 | -0.006593 | -2.23 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 28/28 | 0.089829 | 0.173157 | -0.086257 | 78.57 | +0.00 | -0.164454 | -64.67 |
| intercept | 28/28 | 0.254283 | 0.263821 | +0.216930 | 21.43 | -183.08 | +0.000000 | +0.00 |
| gap | 28/28 | 0.104682 | 0.186397 | -0.098482 | 78.57 | -16.54 | -0.149601 | -58.83 |
| retail | 28/28 | 0.256867 | 0.266559 | +0.220682 | 21.43 | -185.95 | +0.002585 | +1.02 |
| rolling | 28/28 | 0.240494 | 0.251894 | +0.186895 | 21.43 | -167.73 | -0.013789 | -5.42 |
| basket | 28/28 | 0.248185 | 0.259877 | +0.195331 | 21.43 | -176.29 | -0.006097 | -2.40 |
| basket_abstain | 28/28 | 0.248185 | 0.259877 | +0.195331 | 21.43 | -176.29 | -0.006097 | -2.40 |
### frozen_transfer: input 14, target 30

2026-08-10–2026-08-14; 15 rows / 5 days. Overlapping issue-window pairs: 10/10.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 15/5 | 0.699040 | 0.811800 | -0.699040 | 0.00 | +0.00 | +0.361921 | +107.36 |
| intercept | 15/5 | 0.337119 | 0.421996 | -0.087852 | 100.00 | +51.77 | +0.000000 | +0.00 |
| gap | 15/5 | 0.739333 | 0.861852 | -0.739333 | 0.00 | -5.76 | +0.402214 | +119.31 |
| retail | 15/5 | 0.317332 | 0.388510 | -0.053304 | 100.00 | +54.60 | -0.019787 | -5.87 |
| rolling | 15/5 | 0.248910 | 0.294584 | -0.004326 | 100.00 | +64.39 | -0.088209 | -26.17 |
| basket | 15/5 | 0.325230 | 0.397956 | -0.058750 | 100.00 | +53.47 | -0.011889 | -3.53 |
| basket_abstain | 15/5 | 0.325230 | 0.397956 | -0.058750 | 100.00 | +53.47 | -0.011889 | -3.53 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 5/5 | 1.244760 | 1.245412 | -1.244760 | 0.00 | +0.00 | +0.611188 | +96.47 |
| intercept | 5/5 | 0.633572 | 0.634852 | -0.633572 | 100.00 | +49.10 | +0.000000 | +0.00 |
| gap | 5/5 | 1.329520 | 1.330685 | -1.329520 | 0.00 | -6.81 | +0.695948 | +109.85 |
| retail | 5/5 | 0.555954 | 0.557726 | -0.555954 | 100.00 | +55.34 | -0.077618 | -12.25 |
| rolling | 5/5 | 0.355860 | 0.366269 | -0.355860 | 100.00 | +71.41 | -0.277712 | -43.83 |
| basket | 5/5 | 0.570356 | 0.576551 | -0.570356 | 100.00 | +54.18 | -0.063216 | -9.98 |
| basket_abstain | 5/5 | 0.570356 | 0.576551 | -0.570356 | 100.00 | +54.18 | -0.063216 | -9.98 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 5/5 | 0.482360 | 0.511455 | -0.482360 | 0.00 | +0.00 | +0.345762 | +253.12 |
| intercept | 5/5 | 0.136598 | 0.213336 | +0.128828 | 100.00 | +71.68 | +0.000000 | +0.00 |
| gap | 5/5 | 0.514020 | 0.537366 | -0.514020 | 0.00 | -6.56 | +0.377422 | +276.30 |
| retail | 5/5 | 0.160100 | 0.238189 | +0.160100 | 100.00 | +66.81 | +0.023502 | +17.21 |
| rolling | 5/5 | 0.157306 | 0.203851 | +0.109320 | 100.00 | +67.39 | +0.020709 | +15.16 |
| basket | 5/5 | 0.167909 | 0.235921 | +0.156679 | 100.00 | +65.19 | +0.031311 | +22.92 |
| basket_abstain | 5/5 | 0.167909 | 0.235921 | +0.156679 | 100.00 | +65.19 | +0.031311 | +22.92 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 5/5 | 0.370000 | 0.405487 | -0.370000 | 0.00 | +0.00 | +0.128812 | +53.41 |
| intercept | 5/5 | 0.241188 | 0.292731 | +0.241188 | 100.00 | +34.81 | +0.000000 | +0.00 |
| gap | 5/5 | 0.374460 | 0.410949 | -0.374460 | 0.00 | -1.21 | +0.133272 | +55.26 |
| retail | 5/5 | 0.235942 | 0.291596 | +0.235942 | 100.00 | +36.23 | -0.005246 | -2.18 |
| rolling | 5/5 | 0.233563 | 0.290914 | +0.233563 | 100.00 | +36.87 | -0.007625 | -3.16 |
| basket | 5/5 | 0.237426 | 0.295020 | +0.237426 | 100.00 | +35.83 | -0.003762 | -1.56 |
| basket_abstain | 5/5 | 0.237426 | 0.295020 | +0.237426 | 100.00 | +35.83 | -0.003762 | -1.56 |
### frozen_transfer: input 14, target 14

2026-08-10–2026-08-30; 63 rows / 21 days. Overlapping issue-window pairs: 182/210.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 63/21 | 0.230648 | 0.339147 | -0.199025 | 57.14 | +0.00 | -0.050956 | -18.09 |
| intercept | 63/21 | 0.281603 | 0.298906 | +0.118049 | 41.27 | -22.09 | +0.000000 | +0.00 |
| gap | 63/21 | 0.260810 | 0.380749 | -0.234060 | 53.97 | -13.08 | -0.020794 | -7.38 |
| retail | 63/21 | 0.276030 | 0.296854 | +0.099114 | 41.27 | -19.68 | -0.005573 | -1.98 |
| rolling | 63/21 | 0.251604 | 0.281889 | +0.063270 | 47.62 | -9.09 | -0.029999 | -10.65 |
| basket | 63/21 | 0.270335 | 0.296686 | +0.070985 | 46.03 | -17.21 | -0.011268 | -4.00 |
| basket_abstain | 63/21 | 0.270845 | 0.296763 | +0.066733 | 46.03 | -17.43 | -0.010758 | -3.82 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 21/21 | 0.386457 | 0.462508 | -0.383600 | 23.81 | +0.00 | +0.139080 | +56.22 |
| intercept | 21/21 | 0.247377 | 0.266815 | -0.066526 | 76.19 | +35.99 | +0.000000 | +0.00 |
| gap | 21/21 | 0.465510 | 0.534405 | -0.463614 | 14.29 | -20.46 | +0.218132 | +88.18 |
| retail | 21/21 | 0.235339 | 0.271229 | -0.133620 | 76.19 | +39.10 | -0.012039 | -4.87 |
| rolling | 21/21 | 0.203727 | 0.270014 | -0.169459 | 95.24 | +47.28 | -0.043650 | -17.65 |
| basket | 21/21 | 0.237456 | 0.292501 | -0.177681 | 90.48 | +38.56 | -0.009921 | -4.01 |
| basket_abstain | 21/21 | 0.238987 | 0.292734 | -0.190436 | 90.48 | +38.16 | -0.008391 | -3.39 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 21/21 | 0.201129 | 0.304985 | -0.112452 | 71.43 | +0.00 | -0.134417 | -40.06 |
| intercept | 21/21 | 0.335546 | 0.349629 | +0.204622 | 23.81 | -66.83 | +0.000000 | +0.00 |
| gap | 21/21 | 0.199076 | 0.324328 | -0.128990 | 71.43 | +1.02 | -0.136470 | -40.67 |
| retail | 21/21 | 0.324083 | 0.334753 | +0.203250 | 23.81 | -61.13 | -0.011463 | -3.42 |
| rolling | 21/21 | 0.301707 | 0.312092 | +0.160581 | 23.81 | -50.01 | -0.033839 | -10.08 |
| basket | 21/21 | 0.315625 | 0.325045 | +0.179595 | 23.81 | -56.93 | -0.019921 | -5.94 |
| basket_abstain | 21/21 | 0.315625 | 0.325045 | +0.179595 | 23.81 | -56.93 | -0.019921 | -5.94 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 21/21 | 0.104357 | 0.195274 | -0.101024 | 76.19 | +0.00 | -0.157529 | -60.15 |
| intercept | 21/21 | 0.261886 | 0.273137 | +0.216050 | 23.81 | -150.95 | +0.000000 | +0.00 |
| gap | 21/21 | 0.117843 | 0.210075 | -0.109576 | 76.19 | -12.92 | -0.144043 | -55.00 |
| retail | 21/21 | 0.268668 | 0.280609 | +0.227712 | 23.81 | -157.45 | +0.006782 | +2.59 |
| rolling | 21/21 | 0.249379 | 0.260912 | +0.198687 | 23.81 | -138.97 | -0.012507 | -4.78 |
| basket | 21/21 | 0.257923 | 0.269919 | +0.211041 | 23.81 | -147.15 | -0.003963 | -1.51 |
| basket_abstain | 21/21 | 0.257923 | 0.269919 | +0.211041 | 23.81 | -147.15 | -0.003963 | -1.51 |
### rolling_observed_seller_data: input 7, target 30

2026-06-08–2026-06-26; 57 rows / 19 days. Overlapping issue-window pairs: 171/171.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 57/19 | 0.471821 | 0.601563 | -0.437084 | 28.07 | +0.00 | +0.033421 | +7.62 |
| intercept | 57/19 | 0.438400 | 0.511302 | +0.321567 | 68.42 | +7.08 | +0.000000 | +0.00 |
| gap | 57/19 | 0.512905 | 0.646770 | -0.492547 | 28.07 | -8.71 | +0.074505 | +16.99 |
| retail | 57/19 | 0.640128 | 0.947769 | +0.371860 | 68.42 | -35.67 | +0.201729 | +46.01 |
| rolling | 57/19 | 0.576215 | 0.799633 | +0.153246 | 61.40 | -22.13 | +0.137816 | +31.44 |
| basket | 57/19 | 0.572526 | 0.791408 | +0.116407 | 57.89 | -21.34 | +0.134126 | +30.59 |
| basket_abstain | 57/19 | 0.578633 | 0.796348 | +0.110299 | 57.89 | -22.64 | +0.140234 | +31.99 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 19/19 | 0.818558 | 0.894452 | -0.818558 | 0.00 | +0.00 | +0.527966 | +181.69 |
| intercept | 19/19 | 0.290592 | 0.345391 | -0.059907 | 100.00 | +64.50 | +0.000000 | +0.00 |
| gap | 19/19 | 0.918621 | 0.971350 | -0.918621 | 0.00 | -12.22 | +0.628029 | +216.12 |
| retail | 19/19 | 1.001217 | 1.411683 | +0.347071 | 100.00 | -22.31 | +0.710625 | +244.54 |
| rolling | 19/19 | 0.924532 | 1.194680 | +0.024167 | 89.47 | -12.95 | +0.633940 | +218.15 |
| basket | 19/19 | 0.934587 | 1.189375 | -0.027598 | 84.21 | -14.17 | +0.643995 | +221.62 |
| basket_abstain | 19/19 | 0.941910 | 1.194678 | -0.034920 | 84.21 | -15.07 | +0.651318 | +224.13 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 19/19 | 0.291847 | 0.385109 | -0.227111 | 42.11 | +0.00 | -0.239693 | -45.09 |
| intercept | 19/19 | 0.531541 | 0.605522 | +0.531541 | 47.37 | -82.13 | +0.000000 | +0.00 |
| gap | 19/19 | 0.305589 | 0.402810 | -0.266958 | 42.11 | -4.71 | -0.225951 | -42.51 |
| retail | 19/19 | 0.503863 | 0.631867 | +0.366142 | 47.37 | -72.65 | -0.027677 | -5.21 |
| rolling | 19/19 | 0.478752 | 0.537644 | +0.177175 | 36.84 | -64.04 | -0.052789 | -9.93 |
| basket | 19/19 | 0.467248 | 0.524679 | +0.140539 | 31.58 | -60.10 | -0.064292 | -12.10 |
| basket_abstain | 19/19 | 0.478249 | 0.534955 | +0.129538 | 31.58 | -63.87 | -0.053292 | -10.03 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 19/19 | 0.305058 | 0.370513 | -0.265584 | 42.11 | +0.00 | -0.188009 | -38.13 |
| intercept | 19/19 | 0.493067 | 0.546204 | +0.493067 | 57.89 | -61.63 | +0.000000 | +0.00 |
| gap | 19/19 | 0.314505 | 0.386209 | -0.292063 | 42.11 | -3.10 | -0.178562 | -36.21 |
| retail | 19/19 | 0.415305 | 0.550174 | +0.402367 | 57.89 | -36.14 | -0.077762 | -15.77 |
| rolling | 19/19 | 0.325363 | 0.449350 | +0.258395 | 57.89 | -6.66 | -0.167704 | -34.01 |
| basket | 19/19 | 0.315742 | 0.434829 | +0.236280 | 57.89 | -3.50 | -0.177325 | -35.96 |
| basket_abstain | 19/19 | 0.315742 | 0.434829 | +0.236280 | 57.89 | -3.50 | -0.177325 | -35.96 |
### rolling_observed_seller_data: input 7, target 14

2026-05-23–2026-07-12; 153 rows / 51 days. Overlapping issue-window pairs: 572/1275.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 153/51 | 0.430065 | 0.647921 | -0.323371 | 29.41 | +0.00 | -0.009281 | -2.11 |
| intercept | 153/51 | 0.439346 | 0.587902 | -0.016806 | 55.56 | -2.16 | +0.000000 | +0.00 |
| gap | 153/51 | 0.405569 | 0.617670 | -0.330145 | 33.33 | +5.70 | -0.033778 | -7.69 |
| retail | 153/51 | 0.447811 | 0.593307 | -0.001553 | 55.56 | -4.13 | +0.008465 | +1.93 |
| rolling | 153/51 | 0.391073 | 0.556030 | -0.006777 | 54.90 | +9.07 | -0.048273 | -10.99 |
| basket | 153/51 | 0.425921 | 0.589638 | -0.001219 | 54.25 | +0.96 | -0.013425 | -3.06 |
| basket_abstain | 153/51 | 0.427758 | 0.594844 | -0.003056 | 54.25 | +0.54 | -0.011588 | -2.64 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 51/51 | 0.793155 | 1.038591 | -0.709908 | 19.61 | +0.00 | +0.059948 | +8.18 |
| intercept | 51/51 | 0.733207 | 0.886207 | -0.403343 | 64.71 | +7.56 | +0.000000 | +0.00 |
| gap | 51/51 | 0.759000 | 0.993913 | -0.722259 | 31.37 | +4.31 | +0.025793 | +3.52 |
| retail | 51/51 | 0.747731 | 0.891419 | -0.383582 | 64.71 | +5.73 | +0.014524 | +1.98 |
| rolling | 51/51 | 0.656243 | 0.845920 | -0.349952 | 62.75 | +17.26 | -0.076964 | -10.50 |
| basket | 51/51 | 0.720837 | 0.891158 | -0.376089 | 60.78 | +9.12 | -0.012370 | -1.69 |
| basket_abstain | 51/51 | 0.726347 | 0.901478 | -0.381599 | 60.78 | +8.42 | -0.006860 | -0.94 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 51/51 | 0.311725 | 0.341356 | -0.125996 | 15.69 | +0.00 | -0.000545 | -0.17 |
| intercept | 51/51 | 0.312270 | 0.397221 | +0.180568 | 54.90 | -0.17 | +0.000000 | +0.00 |
| gap | 51/51 | 0.287090 | 0.316846 | -0.133118 | 15.69 | +7.90 | -0.025180 | -8.06 |
| retail | 51/51 | 0.318410 | 0.404949 | +0.197563 | 54.90 | -2.14 | +0.006140 | +1.97 |
| rolling | 51/51 | 0.285072 | 0.366585 | +0.171911 | 54.90 | +8.55 | -0.027199 | -8.71 |
| basket | 51/51 | 0.306228 | 0.398145 | +0.198776 | 54.90 | +1.76 | -0.006042 | -1.93 |
| basket_abstain | 51/51 | 0.306228 | 0.398145 | +0.198776 | 54.90 | +1.76 | -0.006042 | -1.93 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 51/51 | 0.185314 | 0.253394 | -0.134208 | 52.94 | +0.00 | -0.087247 | -32.01 |
| intercept | 51/51 | 0.272561 | 0.306165 | +0.172357 | 47.06 | -47.08 | +0.000000 | +0.00 |
| gap | 51/51 | 0.170616 | 0.237261 | -0.135059 | 52.94 | +7.93 | -0.101945 | -37.40 |
| retail | 51/51 | 0.277293 | 0.312139 | +0.181359 | 47.06 | -49.63 | +0.004732 | +1.74 |
| rolling | 51/51 | 0.231905 | 0.278469 | +0.157712 | 47.06 | -25.14 | -0.040656 | -14.92 |
| basket | 51/51 | 0.250698 | 0.300559 | +0.173655 | 47.06 | -35.28 | -0.021863 | -8.02 |
| basket_abstain | 51/51 | 0.250698 | 0.300559 | +0.173655 | 47.06 | -35.28 | -0.021863 | -8.02 |
### rolling_observed_seller_data: input 14, target 30

2026-06-12–2026-06-26; 45 rows / 15 days. Overlapping issue-window pairs: 105/105.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 45/15 | 0.543320 | 0.661592 | -0.535320 | 22.22 | +0.00 | +0.130705 | +31.68 |
| intercept | 45/15 | 0.412615 | 0.479574 | +0.279478 | 77.78 | +24.06 | +0.000000 | +0.00 |
| gap | 45/15 | 0.585231 | 0.703101 | -0.581787 | 22.22 | -7.71 | +0.172616 | +41.83 |
| retail | 45/15 | 0.696156 | 0.946426 | +0.452314 | 77.78 | -28.13 | +0.283541 | +68.72 |
| rolling | 45/15 | 0.596100 | 0.729364 | +0.023219 | 57.78 | -9.71 | +0.183485 | +44.47 |
| basket | 45/15 | 0.623400 | 0.759935 | +0.061182 | 60.00 | -14.74 | +0.210785 | +51.09 |
| basket_abstain | 45/15 | 0.638437 | 0.779026 | +0.046146 | 60.00 | -17.51 | +0.225821 | +54.73 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 15/15 | 0.927880 | 0.979751 | -0.927880 | 0.00 | +0.00 | +0.641550 | +224.06 |
| intercept | 15/15 | 0.286330 | 0.337172 | -0.113082 | 100.00 | +69.14 | +0.000000 | +0.00 |
| gap | 15/15 | 1.005860 | 1.045980 | -1.005860 | 0.00 | -8.40 | +0.719530 | +251.29 |
| retail | 15/15 | 1.200188 | 1.419221 | +0.601455 | 100.00 | -29.35 | +0.913858 | +319.16 |
| rolling | 15/15 | 1.013160 | 1.078943 | -0.062656 | 73.33 | -9.19 | +0.726830 | +253.84 |
| basket | 15/15 | 1.056141 | 1.115736 | -0.008955 | 73.33 | -13.82 | +0.769811 | +268.85 |
| basket_abstain | 15/15 | 1.081007 | 1.144155 | -0.033822 | 73.33 | -16.50 | +0.794678 | +277.54 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 15/15 | 0.334673 | 0.425221 | -0.322673 | 40.00 | +0.00 | -0.157451 | -31.99 |
| intercept | 15/15 | 0.492124 | 0.565303 | +0.492124 | 60.00 | -47.05 | +0.000000 | +0.00 |
| gap | 15/15 | 0.360507 | 0.447826 | -0.360507 | 40.00 | -7.72 | -0.131618 | -26.74 |
| retail | 15/15 | 0.482607 | 0.637064 | +0.374556 | 60.00 | -44.20 | -0.009518 | -1.93 |
| rolling | 15/15 | 0.488154 | 0.528614 | +0.014931 | 26.67 | -45.86 | -0.003971 | -0.81 |
| basket | 15/15 | 0.511939 | 0.564227 | +0.048535 | 33.33 | -52.97 | +0.019815 | +4.03 |
| basket_abstain | 15/15 | 0.532181 | 0.585038 | +0.028293 | 33.33 | -59.02 | +0.040057 | +8.14 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 15/15 | 0.367407 | 0.415194 | -0.355407 | 26.67 | +0.00 | -0.091984 | -20.02 |
| intercept | 15/15 | 0.459391 | 0.506676 | +0.459391 | 73.33 | -25.04 | +0.000000 | +0.00 |
| gap | 15/15 | 0.389327 | 0.434086 | -0.378993 | 26.67 | -5.97 | -0.070064 | -15.25 |
| retail | 15/15 | 0.405675 | 0.516841 | +0.380932 | 73.33 | -10.42 | -0.053716 | -11.69 |
| rolling | 15/15 | 0.286986 | 0.390341 | +0.117382 | 73.33 | +21.89 | -0.172405 | -37.53 |
| basket | 15/15 | 0.302121 | 0.411440 | +0.143967 | 73.33 | +17.77 | -0.157270 | -34.23 |
| basket_abstain | 15/15 | 0.302121 | 0.411440 | +0.143967 | 73.33 | +17.77 | -0.157270 | -34.23 |
### rolling_observed_seller_data: input 14, target 14

2026-05-27–2026-07-12; 141 rows / 47 days. Overlapping issue-window pairs: 520/1081.

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 141/47 | 0.401186 | 0.603056 | -0.285411 | 31.91 | +0.00 | -0.041318 | -9.34 |
| intercept | 141/47 | 0.442504 | 0.563994 | +0.052784 | 51.77 | -10.30 | +0.000000 | +0.00 |
| gap | 141/47 | 0.371719 | 0.562224 | -0.289877 | 36.17 | +7.34 | -0.070785 | -16.00 |
| retail | 141/47 | 0.454704 | 0.580289 | +0.081716 | 51.77 | -13.34 | +0.012200 | +2.76 |
| rolling | 141/47 | 0.419429 | 0.567017 | +0.076657 | 52.48 | -4.55 | -0.023075 | -5.21 |
| basket | 141/47 | 0.464591 | 0.608910 | +0.105996 | 52.48 | -15.80 | +0.022087 | +4.99 |
| basket_abstain | 141/47 | 0.463975 | 0.608856 | +0.105379 | 52.48 | -15.65 | +0.021471 | +4.85 |

#### 6 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 47/47 | 0.711798 | 0.953993 | -0.621466 | 21.28 | +0.00 | +0.041489 | +6.19 |
| intercept | 47/47 | 0.670308 | 0.806717 | -0.283270 | 61.70 | +5.83 | +0.000000 | +0.00 |
| gap | 47/47 | 0.670447 | 0.892328 | -0.630579 | 34.04 | +5.81 | +0.000138 | +0.02 |
| retail | 47/47 | 0.710262 | 0.841651 | -0.223786 | 61.70 | +0.22 | +0.039953 | +5.96 |
| rolling | 47/47 | 0.666484 | 0.831234 | -0.199729 | 63.83 | +6.37 | -0.003825 | -0.57 |
| basket | 47/47 | 0.734776 | 0.881779 | -0.182022 | 63.83 | -3.23 | +0.064468 | +9.62 |
| basket_abstain | 47/47 | 0.732927 | 0.881667 | -0.183871 | 63.83 | -2.97 | +0.062618 | +9.34 |

#### 12 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 47/47 | 0.305828 | 0.337613 | -0.104291 | 17.02 | +0.00 | -0.042503 | -12.20 |
| intercept | 47/47 | 0.348330 | 0.433194 | +0.233904 | 51.06 | -13.90 | +0.000000 | +0.00 |
| gap | 47/47 | 0.276362 | 0.307222 | -0.109285 | 17.02 | +9.63 | -0.071968 | -20.66 |
| retail | 47/47 | 0.346213 | 0.432294 | +0.250392 | 51.06 | -13.21 | -0.002117 | -0.61 |
| rolling | 47/47 | 0.315250 | 0.414835 | +0.229941 | 51.06 | -3.08 | -0.033080 | -9.50 |
| basket | 47/47 | 0.350934 | 0.458147 | +0.271844 | 51.06 | -14.75 | +0.002604 | +0.75 |
| basket_abstain | 47/47 | 0.350934 | 0.458147 | +0.271844 | 51.06 | -14.75 | +0.002604 | +0.75 |

#### 24 months

| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| unchanged | 47/47 | 0.185932 | 0.258740 | -0.130477 | 57.45 | +0.00 | -0.122941 | -39.80 |
| intercept | 47/47 | 0.308873 | 0.340320 | +0.207719 | 42.55 | -66.12 | +0.000000 | +0.00 |
| gap | 47/47 | 0.168349 | 0.240111 | -0.129766 | 57.45 | +9.46 | -0.140524 | -45.50 |
| retail | 47/47 | 0.307636 | 0.339045 | +0.218540 | 42.55 | -65.46 | -0.001237 | -0.40 |
| rolling | 47/47 | 0.276554 | 0.318571 | +0.199758 | 42.55 | -48.74 | -0.032319 | -10.46 |
| basket | 47/47 | 0.308064 | 0.353386 | +0.228165 | 42.55 | -65.69 | -0.000809 | -0.26 |
| basket_abstain | 47/47 | 0.308064 | 0.353386 | +0.228165 | 42.55 | -65.69 | -0.000809 | -0.26 |

## Feature adjustment from the training mean

Adjustment = existing learned predicted delta − the corresponding intercept-only delta. The absolute mean and signed range measure departure from the training mean, not independent feature causality. Slopes are conditional estimates with ridge shrinkage. Both retail and futures coefficients change between joint fits; a futures-versus-intercept comparison is not an isolated futures coefficient effect.

| Protocol | Input/target | Term | Model | Mean absolute adjustment | Minimum | Maximum |
|---|---|---|---|---:|---:|---:|
| frozen_transfer | 7/14 | 12 | basket | 0.060549 | -0.137359 | +0.078684 |
| frozen_transfer | 7/14 | 24 | basket | 0.036622 | -0.090042 | +0.051785 |
| frozen_transfer | 7/14 | 6 | basket | 0.107864 | -0.226576 | +0.104252 |
| frozen_transfer | 7/14 | all | basket | 0.068345 | -0.226576 | +0.104252 |
| frozen_transfer | 7/14 | 12 | retail | 0.011938 | -0.024318 | +0.010931 |
| frozen_transfer | 7/14 | 24 | retail | 0.004907 | -0.007571 | +0.007496 |
| frozen_transfer | 7/14 | 6 | retail | 0.024678 | -0.057040 | +0.009691 |
| frozen_transfer | 7/14 | all | retail | 0.013841 | -0.057040 | +0.010931 |
| frozen_transfer | 7/14 | 12 | rolling | 0.062988 | -0.131569 | +0.046371 |
| frozen_transfer | 7/14 | 24 | rolling | 0.039098 | -0.090614 | +0.042771 |
| frozen_transfer | 7/14 | 6 | rolling | 0.144560 | -0.229620 | +0.343139 |
| frozen_transfer | 7/14 | all | rolling | 0.082215 | -0.229620 | +0.343139 |
| frozen_transfer | 7/30 | 12 | basket | 0.051457 | -0.080710 | +0.127731 |
| frozen_transfer | 7/30 | 24 | basket | 0.027888 | -0.044299 | +0.069422 |
| frozen_transfer | 7/30 | 6 | basket | 0.082951 | -0.128284 | +0.193603 |
| frozen_transfer | 7/30 | all | basket | 0.054099 | -0.128284 | +0.193603 |
| frozen_transfer | 7/30 | 12 | retail | 0.011017 | -0.005163 | +0.016311 |
| frozen_transfer | 7/30 | 24 | retail | 0.002049 | -0.005415 | +0.004849 |
| frozen_transfer | 7/30 | 6 | retail | 0.024857 | +0.009455 | +0.038707 |
| frozen_transfer | 7/30 | all | retail | 0.012641 | -0.005415 | +0.038707 |
| frozen_transfer | 7/30 | 12 | rolling | 0.051044 | -0.091626 | +0.084175 |
| frozen_transfer | 7/30 | 24 | rolling | 0.031509 | -0.053307 | +0.071239 |
| frozen_transfer | 7/30 | 6 | rolling | 0.213658 | -0.147367 | +0.498313 |
| frozen_transfer | 7/30 | all | rolling | 0.098737 | -0.147367 | +0.498313 |
| frozen_transfer | 14/14 | 12 | basket | 0.035143 | -0.082609 | +0.030474 |
| frozen_transfer | 14/14 | 24 | basket | 0.014522 | -0.034603 | +0.019049 |
| frozen_transfer | 14/14 | 6 | basket | 0.111155 | -0.229504 | -0.034865 |
| frozen_transfer | 14/14 | all | basket | 0.053606 | -0.229504 | +0.030474 |
| frozen_transfer | 14/14 | 12 | retail | 0.025558 | -0.054385 | +0.028383 |
| frozen_transfer | 14/14 | 24 | retail | 0.013050 | -0.012574 | +0.018571 |
| frozen_transfer | 14/14 | 6 | retail | 0.069737 | -0.123713 | +0.013878 |
| frozen_transfer | 14/14 | all | retail | 0.036115 | -0.123713 | +0.028383 |
| frozen_transfer | 14/14 | 12 | rolling | 0.050009 | -0.117159 | +0.020257 |
| frozen_transfer | 14/14 | 24 | rolling | 0.020344 | -0.050425 | +0.013071 |
| frozen_transfer | 14/14 | 6 | rolling | 0.108810 | -0.269534 | +0.034964 |
| frozen_transfer | 14/14 | all | rolling | 0.059721 | -0.269534 | +0.034964 |
| frozen_transfer | 14/30 | 12 | basket | 0.035196 | -0.018362 | +0.065388 |
| frozen_transfer | 14/30 | 24 | basket | 0.015095 | -0.027258 | +0.014489 |
| frozen_transfer | 14/30 | 6 | basket | 0.063216 | +0.000497 | +0.116419 |
| frozen_transfer | 14/30 | all | basket | 0.037836 | -0.027258 | +0.116419 |
| frozen_transfer | 14/30 | 12 | retail | 0.031272 | +0.017629 | +0.041281 |
| frozen_transfer | 14/30 | 24 | retail | 0.007871 | -0.010444 | +0.006562 |
| frozen_transfer | 14/30 | 6 | retail | 0.077618 | +0.065196 | +0.090612 |
| frozen_transfer | 14/30 | all | retail | 0.038920 | -0.010444 | +0.090612 |
| frozen_transfer | 14/30 | 12 | rolling | 0.038878 | -0.064996 | +0.026525 |
| frozen_transfer | 14/30 | 24 | rolling | 0.019506 | -0.031957 | +0.017444 |
| frozen_transfer | 14/30 | 6 | rolling | 0.277712 | +0.219373 | +0.335600 |
| frozen_transfer | 14/30 | all | rolling | 0.112032 | -0.064996 | +0.335600 |
| rolling_observed_seller_data | 7/14 | 12 | basket | 0.047996 | -0.107450 | +0.148020 |
| rolling_observed_seller_data | 7/14 | 24 | basket | 0.033410 | -0.106570 | +0.082117 |
| rolling_observed_seller_data | 7/14 | 6 | basket | 0.072560 | -0.150373 | +0.205748 |
| rolling_observed_seller_data | 7/14 | all | basket | 0.051322 | -0.150373 | +0.205748 |
| rolling_observed_seller_data | 7/14 | 12 | retail | 0.019203 | -0.013797 | +0.053261 |
| rolling_observed_seller_data | 7/14 | 24 | retail | 0.009734 | -0.005966 | +0.027440 |
| rolling_observed_seller_data | 7/14 | 6 | retail | 0.028457 | -0.039009 | +0.104108 |
| rolling_observed_seller_data | 7/14 | all | retail | 0.019131 | -0.039009 | +0.104108 |
| rolling_observed_seller_data | 7/14 | 12 | rolling | 0.063111 | -0.111556 | +0.157650 |
| rolling_observed_seller_data | 7/14 | 24 | rolling | 0.047247 | -0.110681 | +0.099591 |
| rolling_observed_seller_data | 7/14 | 6 | rolling | 0.135087 | -0.142734 | +0.494813 |
| rolling_observed_seller_data | 7/14 | all | rolling | 0.081815 | -0.142734 | +0.494813 |
| rolling_observed_seller_data | 7/30 | 12 | basket | 0.427571 | -0.782116 | +0.248404 |
| rolling_observed_seller_data | 7/30 | 24 | basket | 0.278671 | -0.520335 | +0.155435 |
| rolling_observed_seller_data | 7/30 | 6 | basket | 0.744887 | -0.821463 | +1.993676 |
| rolling_observed_seller_data | 7/30 | all | basket | 0.483709 | -0.821463 | +1.993676 |
| rolling_observed_seller_data | 7/30 | 12 | retail | 0.258521 | -0.482138 | +0.305586 |
| rolling_observed_seller_data | 7/30 | 24 | retail | 0.162241 | -0.251831 | +0.213433 |
| rolling_observed_seller_data | 7/30 | 6 | retail | 0.710625 | -0.596827 | +2.621708 |
| rolling_observed_seller_data | 7/30 | all | retail | 0.377129 | -0.596827 | +2.621708 |
| rolling_observed_seller_data | 7/30 | 12 | rolling | 0.390295 | -0.707359 | +0.218734 |
| rolling_observed_seller_data | 7/30 | 24 | rolling | 0.255682 | -0.472698 | +0.131526 |
| rolling_observed_seller_data | 7/30 | 6 | rolling | 0.715948 | -0.795955 | +1.948605 |
| rolling_observed_seller_data | 7/30 | all | rolling | 0.453975 | -0.795955 | +1.948605 |
| rolling_observed_seller_data | 14/14 | 12 | basket | 0.058791 | -0.084469 | +0.126833 |
| rolling_observed_seller_data | 14/14 | 24 | basket | 0.039377 | -0.076142 | +0.074041 |
| rolling_observed_seller_data | 14/14 | 6 | basket | 0.132428 | -0.202843 | +0.483702 |
| rolling_observed_seller_data | 14/14 | all | basket | 0.076865 | -0.202843 | +0.483702 |
| rolling_observed_seller_data | 14/14 | 12 | retail | 0.022941 | -0.037722 | +0.072851 |
| rolling_observed_seller_data | 14/14 | 24 | retail | 0.014523 | -0.020499 | +0.044551 |
| rolling_observed_seller_data | 14/14 | 6 | retail | 0.071601 | -0.111255 | +0.274113 |
| rolling_observed_seller_data | 14/14 | all | retail | 0.036355 | -0.111255 | +0.274113 |
| rolling_observed_seller_data | 14/14 | 12 | rolling | 0.049944 | -0.121182 | +0.100591 |
| rolling_observed_seller_data | 14/14 | 24 | rolling | 0.034654 | -0.102422 | +0.066508 |
| rolling_observed_seller_data | 14/14 | 6 | rolling | 0.136031 | -0.183595 | +0.298147 |
| rolling_observed_seller_data | 14/14 | all | rolling | 0.073543 | -0.183595 | +0.298147 |
| rolling_observed_seller_data | 14/30 | 12 | basket | 0.465311 | -0.752221 | +0.162905 |
| rolling_observed_seller_data | 14/30 | 24 | basket | 0.324328 | -0.515715 | +0.066786 |
| rolling_observed_seller_data | 14/30 | 6 | basket | 0.781847 | -0.769315 | +1.579718 |
| rolling_observed_seller_data | 14/30 | all | basket | 0.523829 | -0.769315 | +1.579718 |
| rolling_observed_seller_data | 14/30 | 12 | retail | 0.247415 | -0.425270 | +0.276207 |
| rolling_observed_seller_data | 14/30 | 24 | retail | 0.144297 | -0.250316 | +0.171504 |
| rolling_observed_seller_data | 14/30 | 6 | retail | 0.925894 | -0.290129 | +2.243864 |
| rolling_observed_seller_data | 14/30 | all | retail | 0.439202 | -0.425270 | +2.243864 |
| rolling_observed_seller_data | 14/30 | 12 | rolling | 0.484957 | -0.759779 | +0.058226 |
| rolling_observed_seller_data | 14/30 | 24 | rolling | 0.344500 | -0.527586 | +0.018684 |
| rolling_observed_seller_data | 14/30 | 6 | rolling | 0.738866 | -0.773042 | +1.549980 |
| rolling_observed_seller_data | 14/30 | all | rolling | 0.522774 | -0.773042 | +1.549980 |

## Transfer and secondary checks

- frozen_transfer, input 7/target 30: intercept MAE 0.484324; rolling minus intercept -0.047990 (-9.91%); rolling minus retail -0.043088.
- frozen_transfer, input 7/target 14: intercept MAE 0.315398; rolling minus intercept -0.008834 (-2.80%); rolling minus retail -0.012425.
- frozen_transfer, input 14/target 30: intercept MAE 0.337119; rolling minus intercept -0.088209 (-26.17%); rolling minus retail -0.068422.
- frozen_transfer, input 14/target 14: intercept MAE 0.281603; rolling minus intercept -0.029999 (-10.65%); rolling minus retail -0.024426.
- rolling_observed_seller_data, input 7/target 30: intercept MAE 0.438400; rolling minus intercept +0.137816 (+31.44%); rolling minus retail -0.063913.
- rolling_observed_seller_data, input 7/target 14: intercept MAE 0.439346; rolling minus intercept -0.048273 (-10.99%); rolling minus retail -0.056738.
- rolling_observed_seller_data, input 14/target 30: intercept MAE 0.412615; rolling minus intercept +0.183485 (+44.47%); rolling minus retail -0.100057.
- rolling_observed_seller_data, input 14/target 14: intercept MAE 0.442504; rolling minus intercept -0.023075 (-5.21%); rolling minus retail -0.035274.

With seven-day inputs, rolling improves over intercept in the frozen 14-day test, but both still lose to unchanged. In older observed rolling-origin tests, rolling loses to intercept at 30 days and beats it at 14 days. The same signs hold with 14-day inputs. Thus, added feature information does not show a stable benefit across targets and regimes, although rolling beats retail in all eight pooled cohorts.

The common issue/term cross-horizon subsets are retained separately in `common-metrics.csv` and `common-identities.json`, including per-term scores. Different labels and cohort lengths do not identify an optimal horizon. Fourteen-day inputs remain a predeclared sensitivity, not a replacement primary.

## Limits and reproduction

The primary has 36 term rows but only 12 issue days, August 3–14. All 66 primary issue-window pairs overlap. Training windows overlap too. Term rows share shocks. The observed-to-canonical transfer assumes regime continuity, not a common proven process. Older observed rolling results are separate evidence. Revised export-time statistics and curves do not prove original-vintage availability. Offer composition can move medians without identical offers repricing. The selected sample and prior inspection prevent independent validation or causal conclusions. No model, term, horizon, application default, collector or future experiment is selected or started.

Run from the repository root:

```sh
python3 tasks/forecast-accuracy-experiments/scripts/intercept_experiment.py
python3 tasks/forecast-accuracy-experiments/scripts/verify_intercept.py
python3 tasks/forecast-accuracy-experiments/scripts/reproduce_intercept.py
```

Independent verification reads raw export statistic identities and recomputes every training delta and mean, checks strict chronology, 20-day gates, balanced weights, exact original cohorts and predictions, all reported metrics, common subsets and synthetic constant/changing-mean data. It also runs original verification with writes disabled and reuses original PHP/H evidence by pinned hash. The two-run command checks identical new outputs and unchanged pinned originals and export sources. Machine evidence is in `report/intercept-artifacts/verification.json` and `reproducibility.json`. No Laravel test or build is needed: no application, CSS or JS changed.
