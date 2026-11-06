# Model experiments (S and M)

User instruction (2026-11-02): work iteratively. For each result, form a hypothesis, test it,
keep it if it works, discard it if not, and keep track here. Second opinions may come from the
Codex CLI. Work independently until the models cannot be improved further.

## S protocol (fixed 2026-11-02, before E1)

- Backtest: `s_backtest.py 20261102 --candidates ... --tag E<n>` (quarterly expanding refit,
  as-of inputs, 2 threads, nice). Tagged reports go to `data/experiments/` (git-ignored).
- Comparison: `s_compare.py [--k=a-b] BASELINE CANDIDATE <prediction files>` on the rows where
  the reference (nordpool-predict-fi vintage) and both models exist.
- Periods: DEV = issues 2024-07-01..2025-06-30 (decide), HOLD = 2025-07-01..2026-10 (confirm).
- KEEP when the MAE difference is < 0 on DEV with the upper bound of its 90 % bootstrap interval
  (whole issue days resampled) < 0, AND HOLD difference <= 0, AND the pinball loss of the
  calibrated range is not worse by more than 0.1. Else DISCARD. A horizon group can be decided
  alone (`--k`), with the same rule inside the group.
- Caveat: DEV and HOLD were both visible in the first results table (2026-11-02); the live FMI
  shadow scoring from 2026-11-03 is the only fully fresh holdout.

## Research stop (2026-11-06)

User decision: E32 is the last experiment of this task. Further ideas: `tasks/own-forecast-model-improvements/spec.md`. The next step is the production version. E32 is complete; the production design is the first paragraph below.

## Current best S model

**2026-11-06 (E32, production design):** one run at D 14:30, after the D+1 prices are stored. D+1 shows the published prices. D+2 = `best_d1` fitted on the afternoon view (prices through D+1, Fingrid before 14:00, outage plans and third-party vintage at 14:30; `--issue-after-prices`, k=1). D+3..D+7 = the E30 parts on the morning view (inputs as of D 11:30, Fingrid before 10:00; k=3..7): `best_d24|l_lear_hour|0.5|shift` at D+3–D+4, `best_d57|l_lear_hour|0.5|shift` at D+5–D+7. Weather in both views: the 00 UTC run of D. Weekly refit. No D+8 output. HOLD MAE on D+2..D+7 (third-party rows): 26.70, the third party at 14:30 28.13, E30 27.05. Per target day (HOLD): D+2 19.6 (third party 21.7), D+3 23.1 (25.5), D+4 24.8 (28.5), D+5 29.1 (29.8), D+6 31.1 (30.8), D+7 32.6 (32.5). The morning-view inputs are a subset of what is stored at 14:30 (all vintages are append-only), so one run can build both views. Both prediction files are needed to score it: `data/s-predictions-20261102-E30.csv.gz` and `-E32.csv.gz`.

**2026-11-06 (E30):** the E26 model refitted each week (`--refit-days 7`, new default). Against the reference: DEV −6.45 [−7.78, −5.29], HOLD −1.27 [−1.87, −0.67] (HOLD per horizon: D+1 −0.86, D+2 −0.91, D+3 −3.19, D+4 −3.73, D+5 −0.51, D+6 +0.39, D+7 −0.08). Baseline file for new experiments: `data/s-predictions-20261102-E30.csv.gz`. Its `s_best` column still has l_lear_k at D+2–4 (the run started before the E27 removal), so compare with the explicit spec `best_d1@1-1+best_d24|l_lear_hour|0.5|shift@2-4+best_d57|l_lear_hour|0.5|shift@5-7`. Production implication: refit S each week.

**2026-11-06 (E26):** `s_best` (unchanged parts and composition) with the monthly refit
(default) and the issue-day weather. Against the reference: DEV −5.41 [−6.73, −4.22], HOLD −1.06
[−1.69, −0.42] (HOLD per horizon: D+1 −0.37, D+2 −0.68, D+3 −2.96, D+4 −3.40, D+5 −0.64,
D+6 +0.36, D+7 +0.30). Baseline file for new experiments: `data/s-predictions-20261102-E26.csv.gz`.

**2026-11-06 (E8):** `s_best` (unchanged parts) with `--weather single`: the weather inputs come
from the 00 UTC ECMWF HRES run of the issue day. Against the reference: DEV −4.40 [−5.70, −3.25],
HOLD +0.08 [−0.57, +0.78] (HOLD per horizon: D+1 −0.07, D+2 +0.61, D+3 −1.77, D+4 −1.82,
D+5 +0.91, D+6 +1.65, D+7 +1.08). Baseline file for new experiments:
`data/s-predictions-20261102-E8.csv.gz` (`s_best#2`). Earlier state:

`e7_kgroups@1-1 + (e18_k|l_lear_hour|0.5|shift)@2-4 + (e22_pool|l_lear_hour|0.5|shift)@5-7`
(E22: smaller trees for D+5–D+7). Before E22, D+5–D+7 used w_netload; E18 gave the D+2–D+4 trees
the planned SE3 nuclear unavailability. Before E18:
`e7_kgroups@1-1 + (e4_kgroups|l_lear_hour|0.5|shift)@2-4 + (w_netload|l_lear_hour|0.5|shift)@5-7`
(E15b: the linear part is one ridge per hour). Rebuild check: the E14-15 run reproduces the E7 and
E10-12 predictions exactly. Before E15b: as below, with the p50 of D+2–D+7 a 50/50 blend with the bounded linear model and the tree
range moved by the same change. Against the reference: DEV −3.56 [−4.84, −2.42], HOLD +1.02
[+0.32, +1.76] (HOLD per horizon: D+1 +0.17, D+2 +1.15, D+3 −0.93, D+4 −0.66, D+5 +1.06, D+6 +2.19,
D+7 +4.26).

Previous best: `e7_kgroups@1-1+e4_kgroups@2-4+w_netload@5-7`, run with `--netload-wind stage1`: the w_netload
inputs (as-of data + Open-Meteo ECMWF IFS 0.25° forecasts + southern temperature + border capacity
+ stage-1 net load with a stage-1 wind model from 100 m wind); for D+1 also the direct wind MW,
solar proxy and radiation (e7); separate models for D+1 and D+2–D+4, the pooled model for
D+5–D+7; range `*_scal`. Against the reference on the same rows: DEV −2.43 [−3.72, −1.22]
(better), HOLD +1.62 [+0.80, +2.45] (worse; HOLD per horizon: D+1 +0.17, D+2 +1.78, D+3 −0.31,
D+4 −0.02, D+5 +2.25, D+6 +3.09, D+7 +4.43). The reference improved over time (MAE 30.7 DEV, 27.0 HOLD).

## Log

| ID | Hypothesis | Change | Result (MAE diff vs baseline; DEV / HOLD) | Decision |
|---|---|---|---|---|
| E0 | Honest weather forecasts help | w_forecast vs h_asof | 36.9 → 30.1 (all thirdparty rows) | KEEP (basis) |
| E1a | Southern (load-weighted) temperature helps | w_south vs w_forecast | −0.05 [−0.29, +0.36] / −0.46 | Not passed on DEV (CI includes 0); stays as part of w_netload until the ablation E5 |
| E1b | Border capacity helps | w_capacity vs w_south | −0.03 [−0.42, +0.40] / +0.22 | DISCARD alone (kept inside E1c) |
| E1c | Stage-1 net load + import need helps | w_netload vs w_south | −0.55 [−1.05, −0.04] / −0.02; D+1–D+4 better, D+5–D+7 worse in both periods | KEEP (w_netload new base) |
| E2 | Use w_south for D+5–D+7 (net load hurts there) | horizon split | all: −0.07 [−0.27, +0.13] / −0.17; D+5–7 only: −0.25 [−0.93, +0.43] / −0.41 | DISCARD (DEV CI includes 0; direction consistent) |
| E3 | Fixes from the Codex review: complete days need their exact hour count; stage-1 net load for the CQR fits refitted on the early rows | correctness, no decision | w_netload p50 unchanged (DEV 30.75, HOLD 29.58) | applied |
| E4 | Separate models per horizon group (D+1, D+2–4, D+5–7) | `k_groups` | all: −0.93 [−1.30, −0.56] / +0.45; D+1–4: −2.23 [−2.69, −1.79] / −1.17 (D+1 −4.70 / −3.85); D+5–7 worse (+0.8..+3.4) | KEEP for D+1–D+4 only |
| E4c | Composite: E4 for D+1–D+4, pooled w_netload for D+5–D+7 | horizon split | −1.57 [−1.90, −1.26] / −0.67 | KEEP (new best) |
| E5 | Ablation: w_netload without the south temperature as tree input | remove 2 features | +0.26 [+0.08, +0.45] / +1.00 | DISCARD (south temperature confirmed useful) |
| E6 | Recency weights (half-life 365 days) | sample_weight | −0.25 [−0.60, +0.09] / +0.74 | DISCARD |
| E7a | Stage-1 wind from 100 m wind at the 29 FI stations (cross-fitted HGB capacity factor) in the net-load estimate | `--netload-wind stage1`, best composite rerun | −0.84 [−1.10, −0.58] / −0.26 (every horizon better on DEV) | KEEP (stage1 is the new default) |
| E7b | + direct wind MW, solar proxy and radiation as tree inputs | e7 candidates | all: −0.93 / +0.03 (DISCARD); D+1 only: −0.27 [−0.50, −0.03] / −0.41 | KEEP for D+1 only (group chosen after seeing per-horizon numbers: post hoc) |
| E8 | Issue-time weather (00 UTC ECMWF HRES run of D, Single Runs API) removes the D+1 age handicap and the D+7 gaps, and matches production (FMI ECMWF) | `s_weather_single_runs.py`; 962 issue days × 38 calls, fetched over about 4 days within the free daily limit (2026-11-02 19:00 UTC onward). 2026-11-03: the 00:05 UTC run stopped after 5 JSONDecodeError days (96/962 cached); a retest of one failed day gave a valid 274 kB reply after about 2 min, so the failures were temporary incomplete replies. Fetch now tries each day 3 times and stops only after 5 failed days in sequence. The 08:00–10:40 UTC run added 23 days (119/962, through 2024-07-10), about 6 min per day, then stopped on 2024-07-11..15 (incomplete replies and 180 s timeouts): the server is slow, not the request. 2026-11-03 10:55 UTC (user feedback: an early stop wastes the free quota): fetch now runs continuously until all days are cached: one attempt per day per pass, failed days return in the next pass, 5 failures in sequence pause 30 min, HTTP 429 pauses 1 h, reply timeout 300 s, calls counted per UTC day in `data/om-single-runs/ecmwf_ifs-calls.json` (today seeded at 3,000) with a wait for the next UTC day at 9,000; log `data/om-single-runs/fetch.log`. The nightly loop was stopped. 11:10 UTC (user decision): no client-side limit; the API enforces the quota. On HTTP 429 the run reads the reason: daily → wait for the next UTC day, hourly → next hour, minutely → 60 s, other → 1 h. The calls file only logs calls sent. 2026-11-05 00:54 UTC: the run stopped on an uncaught `http.client.IncompleteRead` (658/962 cached); HTTPException is now caught like the other transient errors and the run was restarted. 2026-11-06 01:15 UTC: all 962 days cached; `reduce` wrote weather_single_runs.csv (207,792 rows, pinned). s_backtest `--weather single` (apply_single_runs) replaces the om_* values for every covered issue; run `--candidates best_d1,best_d24,best_d57,l_lear_hour --tag E8 --weather single` (69 % of rows use the issue-day run). s_best E8 vs s_best: DEV −0.20 [−0.45, +0.05], HOLD −0.80 [−1.10, −0.53], pinball −0.20/−0.21; D+1 DEV −0.13 [−0.47, +0.21] HOLD −0.24; D+2–4 DEV −0.36 [−0.64, −0.08] HOLD −0.75 (rule: KEEP); D+5–7 DEV +0.04 [−0.56, +0.66] HOLD −1.05. Against the reference: DEV −4.40 (was −4.20), HOLD +0.08 [−0.57, +0.78] (was +0.88) | **KEEP (all horizons)**. The rule passes only for D+2–4; D+1 and D+5–7 are kept too because the source swap is a train/serve consistency fix (production reads the newest run, so Previous Runs training inputs are older than live inputs), no group is worse on DEV beyond noise, all are better on HOLD and in pinball. `--weather single` is the default from 2026-11-06; the s_best baseline file is now `data/s-predictions-20261102-E8.csv.gz` | – |

## Second opinions

- Codex CLI (model gpt-6.1-sol, 2026-11-02; brief in the session scratchpad): ranked ideas:
  1 separate models per horizon group; 2 weather from the latest complete cycle before issue
  (removes the D+1 age handicap; needs archived single runs or live vintages); 3 winter tail
  calibration by risk (horizon, cold, residual-load band); 4 wind generation from spatial weather,
  fitted power curves, hub-height wind; 5 a separate demand model; 6 a bounded linear model in a
  blend; 7 separate neighbour regions, Swedish nuclear plans; 8 weather-error scenarios for
  D+5–D+7 ranges. Code findings: stage-1 net load is fitted on the calibration rows (fix), a 23-hour
  day passes as complete outside the DST day (fix), range metrics in `score()` use model-specific
  rows (s_compare uses the intersection), counts are issue-target-day pairs, not independent days.

## M protocol (fixed 2026-11-02 before M1; main metric fixed before M3)

- Backtest: `m_backtest.py 20261102` (weekly Monday issues, targets = mean FI price of the next 3
  calendar months, as-of inputs, scored from 2019-01-07). Inputs: `m_prices.py` (ENTSO-E A44
  2015–2022, checked equal to the local FI 2022 data: mean difference 0.000) and `m_export.py`.
- Periods: DEV = issues 2019-01..2022-12, HOLD = 2023-01.. .
- Main metric: MALE = mean |log(forecast / real)| (scale-free; the 2022 crisis dominates MAE).
  KEEP when MALE improves on DEV and HOLD and the wrong-way share (3 classes, ±10 % band) does not
  rise by more than 0.02 in either period. Ranges: coverage target 0.80 (task target), then pinball.

## Current best M model

`m21_015`: 0.85 × (log level of m16_w07) + 0.15 × (log median of the same calendar month in
completed earlier years). MALE 0.273 / 0.458, wrong way 0.02 / 0.13, coverage 0.82 / 0.81.
m16_w07 (the M10 recipe with blend weight 0.7): level = log-space 0.7/0.3 blend of a ridge
correction of the seasonal change (m1: Nordic and FI reservoir deviation, DE/FI level, FI trend,
temperature and snow anomalies; alpha 30) and the seasonal change itself (c_seasonal); direction
class only where the blend and c_seasonal agree, else "about unchanged"; range r3: earlier
out-of-sample log errors, 2.5 % and 97.5 % quantiles, same h. MALE 0.287 / 0.506, wrong way
0.03 / 0.14, coverage 0.79 / 0.80.

## M log

| ID | Hypothesis | MALE DEV / HOLD | Wrong way DEV / HOLD | Decision |
|---|---|---|---|---|
| base | c_seasonal (ref30 × median past log change, ≤ 15 years) | 0.322 / 0.550 | 0.08 / 0.18 | baseline |
| base | b_unchanged | 0.363 / 0.569 | 0.00 / 0.00 | baseline |
| M0 | Ridge on 12 features (alpha 1) | 0.361 / 0.510 | 0.21 / 0.25 | DISCARD (overfits; MAE 29.9 vs 26.9) |
| M1 | Seasonal + ridge correction (6 fundamentals, alpha 30) | 0.305 / 0.505 | 0.15 / 0.26 | DISCARD (wrong-way +0.07/+0.08) |
| M2 | Seasonal + hydro + DE only | 0.301 / 0.496 | 0.15 / 0.24 | DISCARD (wrong-way) |
| M3 | M1 without crisis targets in training | 0.312 / 0.538 | 0.15 / 0.26 | DISCARD |
| M4 | 50/50 log blend of M1 and c_seasonal | 0.289 / 0.514 | 0.07 / 0.22 | DISCARD alone (HOLD wrong-way +0.04) |
| M5 | Damped seasonal (50/50 with unchanged) | 0.338 / 0.555 | 0.01 / 0.06 | DISCARD |
| M7 | Seasonal prior from the latest 3 / 5 years | 0.305 / 0.585; 0.316 / 0.576 | 0.08 / 0.28; 0.08 / 0.26 | DISCARD (HOLD worse) |
| M10 | M4 level, direction only where M4 and c_seasonal agree | 0.289 / 0.514 | 0.03 / 0.14 | KEEP (new best) |
| R1 | Range from same-season errors | cov 0.67 / 0.62, pinball 10.46 / 6.48 | – | DISCARD |
| R2 | Range from 5 %/95 % error quantiles | cov 0.75 / 0.73, pinball 7.97 / 5.99 (vs 8.29 / 6.32) | – | KEEP, then replaced by R3 |
| R3 | 2.5 %/97.5 % error quantiles | cov 0.79 / 0.81, pinball 8.52 / 6.15 | – | KEEP (meets the 0.80 target; pinball cost recorded) |
| R4 | 5 %/95 %, errors of the last 3 years only | cov 0.75 / 0.78, pinball 7.99 / 6.10 | – | DISCARD |

Notes: futures (only 62 rows, 2026-04..09) had MAE 21.4, worse than unchanged (18.6) and M1
(17.2) on the same rows; too few rows for a conclusion. Year 2022 MAE 67–75 for every model.
| M12 | M10 recipe with snow×spring and hydro×winter interactions | 0.292 / 0.514 | 0.04 / 0.14 | DISCARD |
| M14 | M10 recipe with a correction fitted per horizon | 0.292 / 0.513 | 0.03 / 0.15 | DISCARD |
| M16 | M10 recipe, blend weight 0.3 / 0.7 for the correction | 0.298 / 0.524; 0.287 / 0.506 | 0.04 / 0.15; 0.03 / 0.14 | 0.3 DISCARD; 0.7 KEEP (small gain) |
| M17 | Current recipe + latest 7-day level vs ref30 | 0.288 / 0.500 (best 0.287 / 0.506) | 0.03 / 0.13 | DISCARD (DEV not better; HOLD better) |
| E10 | More capacity for the grouped models (600 iterations, rate 0.03, 63 leaves, min leaf 50) | e10_kbig | D+1–4: +0.40 [+0.09, +0.73] / +0.25; D+5–7: +3.38 / +1.78 | DISCARD (overfits) |
| E12 | Linear model bounded to the training range (asinh space), blended with the trees (all columns) | `A|B|w`, D+2–7 | 0.2–0.5 pass on MAE; DEV pinball worse (+0.14..+0.70); 0.6 fails pinball | superseded by E12b |
| E12b | Same, but p50 blend only; tree range moved by the p50 change | `A|B|w|shift`, D+2–7 | w 0.3: −0.90 / −0.64; 0.4: −1.05 / −0.67; 0.5: −1.13 [−1.47, −0.80] / −0.59; 0.6: −1.14 / −0.41; pinball better in all | KEEP w = 0.5 (DEV MAE tie with 0.6 within 0.05 → smaller weight) |
| E14 | Range calibration also by risk band (import-need tercile, cold flag), fallback to season + horizon | twin `<name>r` | p50 equal; pinball −0.079 / +0.025 | DISCARD |
| E15a | Bounded linear model with the e7 wind inputs | l_linear_e7 in the E12b blend | +0.21 / +0.23 | DISCARD |
| E15b | LEAR-like linear model: one ridge per Helsinki hour | l_lear_hour in the E12b blend | −0.13 [−0.22, −0.04] / −0.01 | KEEP (small) |
| E17 | Per-hour linear blend also for D+1 (0.2 / 0.3, shift) | D+1 only | −0.35 / +0.25; −0.20 / +0.73 | DISCARD |
| E18 | Planned SE3 nuclear unavailability (MW, as-of A77/A80, from 2019-12) as tree input | e18_d1 / e18_k / e18_pool in the best composite | all: −0.18 [−0.28, −0.09] / −0.06; D+1: −0.06 / +0.24 (DISCARD); D+2–4: −0.40 [−0.54, −0.26] / −0.12 (KEEP); D+5–7: +0.13 / −0.09 (DISCARD) | KEEP for D+2–D+4 only |
| E19 | 7-day median price of the same hour as input | e19_k / e19_pool | D+1 vs e4: −0.33 [−0.61, −0.04] / −0.27; D+2–4: −0.51 / +0.51 (DISCARD); D+5–7 in the blend: +0.06 / −0.05 (DISCARD) | D+1 promising → E20 on the current D+1 model |
| E20 | The current D+1 model (e7 inputs) + the 7-day hourly median | e20_d1, D+1 only | −0.02 [−0.27, +0.21] / −0.05 | DISCARD |
| E22 | Smaller, more regularised trees (15 leaves, min leaf 300, L2 3) | e22_pool D+5–7; e22_k D+2–4 (with SE3), in the best blend | D+5–7: −0.22 [−0.41, −0.03] / −0.18 (KEEP); D+2–4: −0.11 [−0.27, +0.05] / +0.05 (DISCARD) | KEEP for D+5–D+7 |
| E13 | Blends of pooled and grouped models: D+5–7 0.5/0.5; D+1–4 0.7 grouped + 0.3 pooled | `A|B|w` spec | D+5–7: +0.85 / +0.96; D+1–4: +0.30 / +0.01 | DISCARD both |
| E9 | One model per day for D+2, D+3, D+4 | k_groups (1)(2)(3)(4)(5–7) vs E4 on D+2–4 | +0.47 [+0.12, +0.82] / +0.09 | DISCARD |
| E25 | With the issue-day weather (E8), the D+1 wind MW / solar inputs (WIND_FEATURES) help D+2–D+7 too | e25_d24 (best_d24 + WIND_FEATURES) / e25_d57 (best_d57 + WIND_FEATURES) in the s_best blend, vs best_d24 / best_d57 of the same run | D+2–4: −0.07 [−0.22, +0.06] / +0.08, pinball −0.07/+0.02; D+5–7: −0.13 [−0.29, +0.01] / +0.24 [+0.14, +0.35], pinball +0.04/+0.18 | DISCARD (HOLD worse in both groups) |
| E26 | Monthly refit instead of quarterly (Codex 2026-11-06, idea 2) | s_best parts (E8 composition), `--refit-months 1`, vs the E8 run | all: −1.01 [−1.28, −0.77] / −1.14 [−1.47, −0.82], pinball −0.13/−0.60; D+1 −1.89 / −0.29; D+2–4 −0.81 / −1.36; D+5–7 −0.85 / −1.21. Against the reference: DEV −5.41 [−6.73, −4.22], HOLD −1.06 [−1.69, −0.42] (HOLD D+1 −0.37, D+2 −0.68, D+3 −2.96, D+4 −3.40, D+5 −0.64, D+6 +0.36, D+7 +0.30). Leakage check: train = target days < block start ≤ every issue in the block; calibration, stage-1 net load and wind use the same rows | **KEEP (all horizons)**; monthly is the default from 2026-11-06. Probable reason: a quarterly model was up to 3 months stale in a fast-changing 2024–26 market |
| E27 | Horizon-group slopes in the per-hour ridge (Codex idea 1): resid_load, import_need, southern temperature, FI/SE/EE/DK-DE wind × 1[D+2–4], × 1[D+5–7] | l_lear_k vs l_lear_hour in the s_best blend, same run | D+2–4: −0.16 [−0.22, −0.10] / −0.17, pinball −0.04/−0.07; D+5–7: +0.02 [−0.10, +0.15] / +0.53, pinball +0.02/+0.20 | KEEP for D+2–4 with the quarterly refit, DISCARD for D+5–7. **Removed again after E26:** BEST2 (monthly + l_lear_k) vs E26 (monthly + l_lear_hour) on D+2–4: DEV +0.08 [−0.02, +0.20], HOLD −0.00, pinball +0.04/+0.00; the simpler composition stays |
| E29 | Stage-1 net load with temperature × hour and weekend × hour terms (Codex idea 4) | `--netload-interact`, s_best parts vs the E27 run | D+1: −0.24 [−0.47, +0.03] / +0.17; D+2–4: +0.09 / +0.06; D+5–7: −0.01 / +0.44 [+0.31, +0.58] | DISCARD (all groups) |
| E28 | Shrink the D+5–7 stage-1 wind capacity factor towards the training month × hour mean (Codex idea 3); one coefficient per block, least squares on the out-of-fold training values, clipped to [0, 1] | `--wind-shrink`, s_best parts vs the E27 run (both quarterly) | lambda 0.27 (2024-07) rising to 0.67 (2026); D+2–4: −0.10 [−0.27, +0.06] / −0.09, pinball −0.01/+0.11; D+5–7: +0.05 / +0.56 [+0.39, +0.75], pinball +0.08/+0.15 | DISCARD |
| E30 | Weekly refit (7-day blocks) instead of monthly (follow-up to E26) | `--refit-days 7`; parts recomposed as s_best (l_lear_hour at D+2–7) vs E26. Note: `#N` goes on each base name of a blend (`A#2|B#2|0.5|shift`) | D+1 DEV −0.82 [−1.20, −0.46], HOLD −0.49; D+2–4 DEV −1.33 [−1.82, −0.86], HOLD −0.26; D+5–7 DEV −0.84 [−1.51, −0.15], HOLD −0.07; all DEV −1.09 [−1.44, −0.73], HOLD −0.21 [−0.63, +0.22]; pinball better in every group. Run time 2 h 2 min (CPU 2 threads) | **KEEP** (all horizons; weekly refit is the new default) |
| E31 | Recency weights (half-life 730 days) on the s_best tree parts, retest of E6 under the weekly refit | `e31_d1/e31_d24/e31_d57`, blended with l_lear_hour from the E30 file, vs E30 parts | D+1 DEV −0.18 [−0.46, +0.12], HOLD +0.13; D+2–4 DEV −0.07 [−0.22, +0.09], HOLD −0.18 [−0.28, −0.08]; D+5–7 DEV −0.23 [−0.35, −0.11], HOLD +0.04 | DISCARD (no group passes both DEV and HOLD; D+2–4 is better on HOLD only, a near miss) |
| E32 | Issue after the D+1 day-ahead result (user question 2026-11-06): D 14:30, prices known through D+1, Fingrid before D 14:00, targets D+2..D+8 (k=1 is D+2); weather still the 00 UTC run of D | `--issue-after-prices`, s_best parts unchanged (run 2 h 1 min). Compared with E30 on the same target hours D+2..D+7, each file composed with its own k, paired on (issue, target_ts), bootstrap by issue day (scratchpad script `e32_compare.py`, not kept) | E32 minus E30: D+2 DEV −0.27 [−0.98, +0.47], HOLD −2.08 [−3.02, −1.21]; D+3–D+4 DEV +0.78 [+0.49, +1.08], HOLD +1.60 [+0.90, +2.39]; D+5–D+7 DEV +0.27 [−0.01, +0.56], HOLD +0.86 [+0.27, +1.52]. April 2026 holds most of the HOLD loss: the linear part l_lear_hour blew up (month MAE 42.5 in E30, 73.6 in E32), because fut_month starts on 2026-04-08 and the first weekly blocks have few training rows with a value. Without April 2026: D+2 HOLD −1.37 [−2.19, −0.60], D+3–D+4 HOLD +0.66 [+0.39, +0.95], D+5–D+7 HOLD +0.00. The input tables were checked (2025-07..12): weather identical, prices shifted one day as intended. The afternoon trees are also worse at D+3–D+4 (best_d24 HOLD 22.6→23.4); no cause found. **Combined design** (D+2 from E32 k=1, D+3..D+7 from E30 k=3..7): vs E30 DEV −0.06 [−0.21, +0.10], HOLD −0.35 [−0.51, −0.20]; vs the third party at its 14:30 vintage DEV −5.03 [−5.92, −4.16], HOLD −1.42 [−2.05, −0.79] (HOLD MAE 26.70 vs 28.13) | KEEP the combined design for production; DISCARD the afternoon models for D+3..D+8 |
| M18 | Median regression for the correction (fits MALE), L1 0.001 / 0.01 | 0.288 / 0.506; 0.290 / 0.508 | 0.03 / 0.13 | DISCARD |
| M19 | Nordic spreads added / instead of DE level | 0.300 / 0.512; 0.328 / 0.544 | 0.04 / 0.14; 0.06 / 0.16 | DISCARD |
| M20 | Training weights, half-life 2 / 4 / 6 years | 0.288 / 0.505; 0.288 / 0.506; 0.288 / 0.507 | 0.03 / 0.14 | DISCARD |
| M21 | Blend 0.15 / 0.30 of the calendar-month median level (completed earlier years) into the best | 0.273 / 0.458; 0.294 / 0.415 | 0.02 / 0.13; 0.02 / 0.10 | 0.15 KEEP (new best); 0.30 DISCARD (DEV worse) |

Codex second opinion on M (2026-11-02, gpt-6.1-sol): ideas M18–M21 above, plus dated supply
capacity (wind, nuclear) and NVE percentiles; no clear future-target leak found. Points: HOLD has
been used to select models (only future issues are a clean test); report directional-call share
and wrong-way among calls (added: `calls`, `w/call`); minor date/window details (min(day, 28),
28-day month completeness, row-count windows, the floor inverse below 5 EUR/MWh).
| M23 | NVE Norway hydro deviation (normal from 1995) instead of / with ENTSO-E NO+SE, in the best recipe | 0.275 / 0.458; 0.277 / 0.461 | 0.02 / 0.13 | DISCARD |
| M24 | Calendar-month weight 0.10 / 0.20 (best 0.15) | 0.273 / 0.474; 0.277 / 0.443 | 0.02 / 0.13; 0.02 / 0.12 | DISCARD (0.10 HOLD worse; 0.20 DEV worse) |
| M25 | Planned FI nuclear unavailability (MW) in the target month, as-of A77/A80 revisions (0 before 2020; no plans in the data) | 0.273 / 0.513 | 0.02 / 0.13 | DISCARD (HOLD worse) |

Protocol fix (2026-11-02, before E12b): the pinball check of s_compare now uses only DEV + HOLD
rows; it included the early blocks before DEV, where the first linear blocks were very poor.

M26 (information for phase 3, not a model change): direction-call strictness for the best M (truth
classes stay ±10 %; a call needs |log change| > L and agreement with the seasonal baseline):

| L | calls DEV / HOLD | right among calls DEV / HOLD | wrong way among calls DEV / HOLD |
|---|---|---|---|
| 0.10 | 0.32 / 0.47 | 0.86 / 0.62 | 0.07 / 0.27 |
| 0.15 | 0.28 / 0.42 | 0.91 / 0.66 | 0.03 / 0.24 |
| 0.20 | 0.21 / 0.36 | 0.96 / 0.73 | 0.02 / 0.21 |
| 0.30 | 0.08 / 0.27 | 1.00 / 0.80 | 0.00 / 0.16 |

The public wording and thresholds are frozen in phase 3 (spec); a stricter limit gives fewer but
more reliable direction calls.
| M27 | Planned SE3 nuclear unavailability in the target month (as-of; 0 before 2020) in the best recipe | 0.275 / 0.445 | 0.03 / 0.09 | DISCARD by rule (DEV worse); HOLD clearly better — DEV 2019 rows have no SE3 plans (filled 0). Recheck on future issues |
| M28 | Equal total training weight per target month (Codex 2026-11-06; weekly issues repeat each month) in the best recipe | 0.275 / 0.460 (best 0.273 / 0.458) | 0.02 / 0.13 | DISCARD |
| M29 | 4-week change of the NO+SE reservoir deviation (Codex 2026-11-06) in the best recipe | 0.274 / 0.459 | 0.02 / 0.13 | DISCARD |

## Codex second opinion (2026-11-06, gpt-6.1-sol, after E8/E25)

Full reply: `data/experiments/codex-20261106.txt`. S: (1) horizon × weather interactions in the
per-hour ridge (resid_load, southern temperature, neighbour wind); (2) monthly refit (E26);
(3) shrink the long-horizon stage-1 wind towards the training-only month/hour mean, coefficient
from chronological out-of-sample errors; (4) temperature × hour and hour × day-type terms in the
stage-1 net-load design. M: (1) equal weight per target month (M28); (2) 4-week reservoir change
(M29); (3) dated installed wind / nuclear capacity relative to the seasonal comparison years.

## Consolidation (2026-11-02 evening)

- `s_best` in s_backtest.py (parts best_d1, best_d24, best_d57, l_lear_hour; `compose_best`)
  reproduced the experiment composite exactly before the guard below.
- Guard: the linear model gives no prediction when < 30 % of its training rows have a weather
  forecast; `s_best` then uses the tree alone. Reason: 2024 Q1 (first block, no weather history)
  had linear MAE 389 and s_best MAE 184. Chosen from that failure, not from DEV; it also changed
  the 2024-07 block (DEV −0.23 [−0.44, −0.04]; HOLD unchanged).
- s_best on all reference rows (2024-02..2026-10, 6,171 target days): MAE 28.05 vs 28.58 for
  nordpool-predict-fi; pinball 9.45 vs 10.04; p10–p90 coverage 0.80 vs 0.81 (the reference range is
  built from its residuals; it publishes none); cheapest-3 hit 0.41 vs 0.37; dearest-3 0.44 vs 0.39.
  Winter coverage from 2024-07: 0.78.
- E24: linear blend weight retuned on the consolidated s_best parts (D+2–4: 0.3 / 0.4 / 0.6;
  D+5–7: 0.3 / 0.4 / 0.6, others 0.5): none passes (D+2–4 0.4: +0.01 / −0.27; D+5–7 0.6: −0.16 /
  +0.14). DISCARD; 0.5 stays.
