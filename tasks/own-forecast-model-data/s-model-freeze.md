# S model freeze: `s_v1` (phase 3, 2026-11-06)

This file is the contract between this task and `tasks/temporal-orchestration/` (the S forecast is
its pilot workflow). It defines the model that production must reproduce, the inputs it reads
and their exact cuts, the interface of the model steps and the live decision rule. A change to
anything in sections 1–4 is a new model version (`s_v2`), with a new backtest and a new live count.

Evidence: `research/experiments.md` (E30, E32 and the "Current best S model" paragraph),
`decisions.md` (2026-11-06 entries).

## 1. Model

**One run per issue day D**, after the FI day-ahead prices of D+1 are stored, nominal issue time
D 14:30 Helsinki. Output: p10, p50 and p90 for every FI hour (Helsinki days) of D+2..D+7, VAT 0,
€/MWh. D+1 is the published price, not a forecast. No D+8.

The output per target day comes from one model part on one input view (section 2):

| Target day | k in the view | Part | View |
|---|---|---|---|
| D+2 | 1 | `best_d1` | afternoon |
| D+3, D+4 | 3, 4 | `best_d24` p50 blended 0.5 with `l_lear_hour` p50; the `best_d24` range moved by the same change | morning |
| D+5..D+7 | 5..7 | `best_d57` blended the same way with `l_lear_hour` | morning |

k counts days from the last day with known prices (morning view: D; afternoon view: D+1).

**Parts** (all in `research/s_backtest.py`; the `w_netload` list = `H_FEATURES + W_FEATURES +
SOUTH_FEATURES + CAPACITY_FEATURES + NETLOAD_FEATURES`):

| Part | Inputs | Fit |
|---|---|---|
| `best_d1` | `w_netload` + `WIND_FEATURES` | HistGradientBoosting quantile (`MODEL_PARAMS`), rows with k = 1 only |
| `best_d24` | `w_netload` + `se3_nuc_unavail`, `se3_nuc_unavail_day` | `MODEL_PARAMS`, rows with k = 2..4 |
| `best_d57` | `w_netload` | small trees (`max_leaf_nodes` 15, `min_samples_leaf` 300, `l2_regularization` 3.0), rows with k = 1..7 |
| `l_lear_hour` | `w_netload` | one ridge (alpha 1) per Helsinki hour on asinh(price/10), clipped to the training target range |

Two stage-1 models are fitted inside each refit, before the parts: the wind capacity factor from
the 100 m wind at the 29 FI stations (`add_wind`, out-of-fold for training rows), and the
expected net load (`add_netload`). A column without two distinct values in the training rows is
left out of that fit (as in the backtest).

**Training:** expanding window, every row with a target day before the refit day. Refit weekly on
Monday (the backtest blocks start on Mondays from 2024-01-01), so a refit on Monday uses targets
through Sunday. The first training issue is 2023-01-15. The morning parts train on the morning
view, `best_d1` on the afternoon view. There is no validation gate in the frozen method: a
technically valid refit (every part fitted, finite p50 for the current issue) becomes active.

**Range:** p10/p90 of each part are first calibrated on its last 91 training days (`*_cal`),
then moved by the 0.9 quantile of the out-of-sample misses of earlier weeks, same season
(winter = Nov–Mar) and same k, targets in the 365 days before the refit, at least 500 misses
(`seasonal_calibration`, `*_scal`). Production therefore needs a store of its own out-of-sample
misses. It starts with the backtest misses (E30 file for the morning parts, E32 file for
`best_d1`) and appends each scored live vintage.

**Backtest result** (HOLD, issues 2025-07-01..2026-10-24, the hours with a third-party forecast):
MAE 26.70 €/MWh, against 28.13 for the third party at its 14:30 vintage (−1.42 [−2.05, −0.79]).
Per day: D+2 19.6 (21.7), D+3 23.1 (25.5), D+4 24.8 (28.5), D+5 29.1 (29.8), D+6 31.1 (30.8),
D+7 32.6 (32.5).

## 2. Inputs and cuts

Both views read only data that existed at their cut. **The morning view must use the 11:30
vintages, not newer ones**, because its parts were trained and scored on them.

| Input | Used as | Morning view (as of D 11:30) | Afternoon view (as of D 14:30) |
|---|---|---|---|
| FI spot, hourly | prices of the last known day and the 28 days before; last week's same hour | through D | through D+1 |
| SE1, SE3, EE, DE-LU spot | daily means of the last known day and 7 days | through D | through D+1 |
| Fingrid 75, 124, 188, 191, 194 | 24 h means before the cut; 7-day consumption by hour (124) | before D 10:00 | before D 14:00 |
| Fingrid 268 (wind capacity), 267 (solar capacity) | last value before the cut | before D 10:00 | before D 14:00 |
| ENTSO-E outages: FI nuclear (B14 A77/A80), SE3 nuclear (B14 A77/A80), A78 for SE1_FI, SE3_FI, EE_FI | planned MW per target hour, from the revisions created before the issue time | before D 11:30 | before D 14:30 |
| Border capacity (JAO `maxBorderFlow` since 2024-10-30) | median of the last 7 known days per border | delivery through the end of D | delivery through the end of D+1 |
| ECMWF IFS HRES 00 UTC run of D, 38 points (29 FI stations, 9 neighbour points) | 2 m temperature, 10 m wind, 100 m wind (FI stations), shortwave radiation (FI stations) | the same run | the same run |
| FI futures, EEX FI Base month | settlement of each target month, trade date ≤ D−1 | same | same |
| Finnish holidays | calendar | code | code |

**Not model inputs** (they must not gate the run): the third-party forecast (needed only to
score the live vintage), Fingrid 245 and 246 wind forecasts, observed weather. The Temporal
phase 0 draft lists Fingrid 245 and the third-party forecast as run inputs; remove them from the
run readiness check (the third-party vintage at 14:30 belongs to the scoring step).

**Training targets** (needed only by the weekly refit): FI hourly prices; Fingrid 124 − 75 (net
load) and 75 / 268 (wind capacity factor) for the stage-1 models.

## 3. Production sources and gaps

| Input | Production source today | Status |
|---|---|---|
| Spot FI and neighbours | `spot:fetch`, `spot:fetch-neighbours` (hourly) | ready |
| Fingrid | `fingrid:fetch` 10:20 | the afternoon view needs one more fetch after 14:00 |
| ENTSO-E outages | `entsoe:fetch` 10:50 | the afternoon view needs one more fetch after 14:00 |
| JAO capacity | `jao:fetch` 12:40 (today − 2 to tomorrow) | ready; D+1 is published about 11–12 Helsinki |
| FI futures | `futures:fetch-eex` 04:00 | ready |
| (FMI ECMWF copy, `fmi:fetch` 11:05) | not a model input; collected for the comparison | – |
| Weather: all four variables at the 38 points | `weather:fetch --only=ecmwf_00z` (Single Runs API, hourly 09:15–14:15; local, not deployed). Ready when every one of the 38 points has a row with product `ecmwf_00z` and `issued_at` = D 00:00 UTC | gap 1 closed (option A) |

**Gap 1: the weather source. Decided 2026-11-06: option A** (user decision; collector `weather:fetch --only=ecmwf_00z`, product `ecmwf_00z` in `hourly_forecast_runs`, source `open_meteo`, `issued_at` = D 00:00 UTC, values at the API time; local, not deployed). Background: The model was trained on the Open-Meteo
Single Runs API (`ecmwf_ifs`, run D 00 UTC, 38 points, 4 variables, one request). All parts need
the 100 m wind (through the stage-1 wind in the net load), and `best_d1` also needs the radiation.
Production does not collect either. The Single Runs API answered for today's run on 2026-11-06
(19:10 Helsinki: 216 hours, all values; the time when the run becomes available is not checked
yet). Options:
- A. **Single Runs for all four variables** (the exact training source and the same request as
  the research fetch). This needs an exception to the rule "Open-Meteo only where FMI has no data"
  for temperature and 10 m wind. FMI collection continues beside it for the planned comparison
  (`tasks/own-forecast-model-improvements/spec.md`, item 12).
- B. FMI for temperature and 10 m wind, Open-Meteo only for 100 m wind and radiation. This keeps
  the rule, but the model then gets FMI's copy of the run for two variables that it was trained
  on from Open-Meteo, and the effect is not measured.

**Gap 2: training history in production. Plan approved by the user 2026-11-06.** The research
exports came from a production snapshot, so most inputs are already in production (spot, Fingrid,
outages, futures, JAO since 2024-10-29). Only data downloaded directly from outside sources is
local (`research/data/`, git-ignored). Three paths:

| Data | Size | Used by | Path |
|---|---|---|---|
| ECMWF 00 UTC runs 2024-03-14..2026-10-31 (962 raw Single Runs answers, `data/om-single-runs/ecmwf_ifs/`) | 273 MB | S training and live run | production MySQL, `hourly_forecast_runs` product `ecmwf_00z` |
| ECMWF 00 UTC runs 2026-11-01..the first live day | small | same | `ecmwf_00z` API backfill (a `--date` option, about 38 calls per day) |
| Out-of-sample misses of E30 (morning parts) and E32 (`best_d1`) | 106 MB files, only the needed columns | S range (`*_scal`) | production MySQL, the phase 4 miss table (the live scoring appends to it) |
| Open-Meteo Previous Runs (only issues before 2024-03-14 depend on it) | 153 MB | S training | archive bucket |
| Fingrid NTC 24–27, 112, 115, 2023-01-01..2024-10-29 (`data/fingrid-ntc/`, part of `border_capacity.csv`) | 10 MB | S training | archive bucket |
| ENTSO-E A44 prices 2015–2022 (FI, SE1, SE3, DE) for M | 66 MB | M training | archive bucket |
| Third-party history, SEAS5, other experiment files | about 400 MB | research only | not pushed |

1. **Production MySQL for series that continue live.** A one-time artisan import command (committed
   and tested first) reads the raw files through the existing importer
   (`OpenMeteoWeatherImporter::storeHourlyForecastRun`, so the same unit and time checks apply)
   and inserts with the payload-hash rule, so a second run adds nothing. It runs once from the
   local repository with `railway run` (the connection that `scripts/sync-production-database.sh`
   uses, here writing), at the deployed commit. Row counts are shown to the user before and after.
   Rule for these rows: their `fetched_at` is the import time, so the model selects `ecmwf_00z` by
   `issued_at` (the run time; public about 7 h later, `WEATHER_PUBLISH_LAG`), never by
   `fetched_at`. The Single Runs archive returns the values of the run itself, so the as-of rule
   holds.
2. **A new private archive bucket for history that never changes**, read only by the weekly
   refit. Each file is uploaded once with a SHA-256 pin, the pattern of
   `research/s-inputs-20261102.json`; the refit checks every pin. Not the backup bucket (the worker
   must not reach the database backups); not the web volume (it belongs to one service). The M
   prices do not go into `spot_prices_hour`, because public `/spot-price` history and averages
   read that table. Creating the bucket is a new production resource and needs its own approval.
3. **Proof:** before the first live run, the production training rebuilds the input table for
   past issues from the production sources; it must equal the research table (hash per issue
   day), with every difference explained.

Order: after `tasks/temporal-orchestration/` phase 0 decides where the worker runs and how it
reads data (the bucket credentials go to the worker service); then bucket, imports, rebuild check.

## 4. Interface for the workflow (proposal for phase 4)

The model code is a Python package that builds the views with the same functions as
`research/s_backtest.py` (`build_table`, `issue_time`, `apply_single_runs`, `predict_block`,
`seasonal_calibration`), reading from MySQL instead of the CSV export. Same code path, so the
backtest stays the test of the production code (replay check: a rebuilt table for a past issue
equals the research table).

| Activity | Input | Output |
|---|---|---|
| `s_predict` | issue date D, active model version | one append-only vintage: p10/p50/p90 per hour of D+2..D+7, model version, code hash, issue time, and per input the vintage ID or fetch time used; returns status and vintage ID |
| `s_train` | refit Monday | a new model version (fitted parts, stage-1 models, calibration shifts) and its fit facts; does not activate |
| `s_activate` | model version | one write that makes it active |
| `s_score` | target day | errors of each own vintage for that day (and of the third-party 14:30 vintage); appends to the miss store |

The readiness check stays in the orchestration layer (user decision 2026-11-06); the model code
assumes complete inputs and fails on a missing one.

Runtime pins: Python 3.13, scikit-learn 1.9.1, pandas 3.1.0, numpy 2.5.4 (the research venv). A
fitted model is only valid with the same scikit-learn version.

## 5. Live decision rule (pre-declared, before the first live run)

- Scored rows: every hour of D+2..D+7 of each live issue, with official FI prices, that also has
  a third-party value in the latest third-party vintage stored before D 14:30.
- Primary metric: paired MAE difference own − third party, bootstrap of issue days, 90 % interval.
- Decision after 56 scored issue days: **recommend public use** (phase 5) when the difference is
  below 0 with the upper bound below 0, and the p10–p90 coverage is between 0.70 and 0.90. If the
  interval includes 0, extend once to 112 issue days with the same rule; then report "not proved
  better" and keep the forecast internal.
- Secondary (reported, no decision): per day D+2, D+3–D+4, D+5–D+7; MAE of the daily mean; winter
  and summer.
- No change during the live count other than the weekly refit with unchanged code. A code change
  is a new version and starts a new count.

## 6. Frozen files (SHA-256)

| File | SHA-256 |
|---|---|
| `research/s_backtest.py` (commit 237a9e4) | `4e5e8fc2bc16cbaa4f4b83bbd28885f1195699829c8b0ad671a6cae1b34d9c75` |
| `research/s_compare.py` | `5c0bc053a45ca33aaf76adaac8fc210154a82cf4facd8c6023de65dd83eb8baf` |
| `research/s_export.py` | `b750be66235fd06f75a020576d4ca4df3bff3fc656c2efabe16ed52b54548533` |
| `research/s_weather_single_runs.py` | `8c245d0d219c3ece565c59cf4120a985e937057da93432af1bc2bdd2603a3102` |
| `research/s_weather_previous_runs.py` | `463a5d36cb5fc18a824eb496b3ee536e81cd3ca6045d301b8ef6aa0a2a1cfbbd` |
| `research/s_border_capacity.py` | `9c66c2f0d06db6eb4c81ed89047cb96170496ad6b02ceb283cc55a57ae9f75b2` |
| `research/s-inputs-20261102.json` (input pins) | `505a9c8f36dc1d04fbca59d16f90b0bb745b12dc93835b7ddbab6e92aa8a3130` |
| `research/data/s-predictions-20261102-E30.csv.gz` (git-ignored) | `9ce7f4d6addfe28b99db32e03af44d17b31395b4800ccccffe7ef1036483422c` |
| `research/data/s-predictions-20261102-E32.csv.gz` (git-ignored) | `c35388e85a3009752eed05e306e552bd7c4e2760f3a0d5faeeb2bdd27f448e71` |

Backtest runs: E30 (`--tag E30`, weekly refit; its file also holds `l_lear_k`, which `s_v1` does
not use) and E32 (`s_backtest.py 20261102 --candidates best_d1,best_d24,best_d57,l_lear_hour
--tag E32 --issue-after-prices`). Defaults of both: `--weather single`, `--netload-wind stage1`,
`--refit-days 7`.
