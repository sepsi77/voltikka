#!/usr/bin/env python3
"""Short-term model (S), phase 2: as-of feature table, baselines, research candidates, backtest.

Reads only the pinned export of s_export.py (checks every SHA-256 first). Writes a report, a
results JSON and the test predictions (CSV, git-ignored).

Issue rule (spec.md): one run per Helsinki day D at 11:30 Helsinki, for every hour of the
Helsinki days D+1..D+7. Target: FI day-ahead price per UTC hour, EUR/MWh, VAT 0.

What a run knows (as-of rule):
  - day-ahead prices (FI, SE1, SE3, EE, DE-LU) for every hour up to the end of D: the D result
    was published on D-1 at about 13:00;
  - Fingrid observations for hours that start before D 10:00 Helsinki (production fetches them
    daily at 10:20; s-model phase 4 must keep a fetch before the run);
  - ENTSO-E outage plans (nuclear A77/A80, FI import borders A78): per document, the latest
    revision created at or before the issue time; a cancelled revision removes the document;
  - FI Base month futures with trade date at or before D-1 (EOD prices arrive in the evening).

Candidates (hyperparameters fixed before the first run, no tuning on the test blocks; *_cal
  variants add the range calibration described in predict_block):
  h_asof         gradient-boosted quantile model (p10/p50/p90) on as-of inputs only. Honest.
  p_standin      h_asof + weather stand-ins: Fingrid 246 wind forecast for the target hour (the
                 last stored value, made about one day before the hour, so far better than a
                 real D+2..D+7 forecast) and the realised ERA5 daily temperature of the target
                 day (a perfect forecast). It shows an UPPER bound of the value of weather inputs;
                 its skill is overstated by construction.
Baselines (ranges from the empirical residual quantiles of the training rows, per horizon):
  a_last_week    same Helsinki hour 7 days before the target day
  b_last_day     same Helsinki hour on day D
  c_thirdparty   the nordpool-predict-fi vintage committed at or before the issue time
                 (s_thirdparty_history.py, git history from 2024-02-23); scoring only. Its own
                 output is a point value; the range here is built from its training residuals.
                 Compare it only on the "thirdparty_rows" bucket (same rows for every model).

Backtest: expanding window, refit every quarter from 2024-01. A block trains on every row whose
target day is before the block start, and tests the issues inside the block.

Usage: nice research/.venv/bin/python -I s_backtest.py <YYYYMMDD of the export> [--end YYYY-MM-DD]
       (2 threads by default, about 15 min; the export must contain thirdparty_history.csv)
"""
import os

# Limit the OpenMP threads of scikit-learn (all cores loaded the user's machine, 2026-11-02).
# Set before sklearn is imported; override with OMP_NUM_THREADS=N for a faster run.
os.environ.setdefault("OMP_NUM_THREADS", "2")

import argparse
import bisect
import hashlib
import json
import math
import sys
import time
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd

HERE = Path(__file__).resolve().parent
# The model code lives in the forecast/ package (2026-11-07, s-model-freeze.md section 4); this
# script keeps the export reader, the scoring and the experiment setup.
sys.path.insert(0, str(HERE.parents[2] / "forecast" / "src"))
from voltikka_forecast.s.core import *  # noqa: E402,F403  (settings dicts are shared, mutated in place)


# --- inputs -------------------------------------------------------------------------------------


def load_export(stamp):
    pins = json.loads((HERE / f"s-inputs-{stamp}.json").read_text())
    base = HERE / pins["export"]
    for name, pin in pins["files"].items():
        digest = hashlib.sha256((base / name).read_bytes()).hexdigest()
        if digest != pin["sha256"]:
            raise SystemExit(f"{name}: SHA-256 differs from s-inputs-{stamp}.json")
    read = lambda n: pd.read_csv(base / n)
    return pins, {n[:-4]: read(n) for n in pins["files"] if n != "thirdparty_runs.csv"}


def pinball(y, f, q):
    d = y - f
    return np.mean(np.maximum(q * d, (q - 1) * d))


def direction_hit(day):
    """Share of days where the forecast daily mean moves from day D's mean the same way as the
    real one. Days with an unchanged forecast are left out; a flat baseline gives NaN."""
    f = np.sign(day["f_mean"] - day["d_mean"])
    moved = f != 0
    if not moved.any():
        return float("nan")
    return float((f[moved] == np.sign(day["y_mean"] - day["d_mean"])[moved]).mean())


def score(pred, models):
    out = []
    pred = pred.assign(season=np.where(pred["month"].isin(list(WINTER)), "winter", "summer"))
    fut_mask = pred["issue"] >= FUTURES_FROM
    buckets = [("all", np.ones(len(pred), bool)), ("winter", pred["season"] == "winter"),
               ("summer", pred["season"] == "summer"), ("futures_period", fut_mask),
               # Every model on the same rows as baseline (c): the fair comparison with it.
               ("thirdparty_rows", pred["c_thirdparty_p50"].notna()),
               ("weather_period", pred["issue"] >= WEATHER_FROM)]
    for m in models:
        for bname, mask in buckets:
            sub = pred[mask]
            for k in list(range(1, 8)) + ["1-7"]:
                s = sub if k == "1-7" else sub[sub["k"] == k]
                if s.empty:
                    continue
                y, p10, p50, p90 = s["y"].to_numpy(), s[f"{m}_p10"].to_numpy(), s[f"{m}_p50"].to_numpy(), s[f"{m}_p90"].to_numpy()
                ok = ~np.isnan(p50)
                rk = ok & ~np.isnan(p10) & ~np.isnan(p90)
                err = p50[ok] - y[ok]
                g = s[ok].assign(f=p50[ok]).groupby(["issue", "tdate"])
                day = g.agg(y_mean=("y", "mean"), f_mean=("f", "mean"), y_max=("y", "max"), f_max=("f", "max"),
                            d_mean=("fi_mean_d", "first"))
                cheap, dear = [], []
                for _, gg in g:
                    yy, ff = gg["y"].to_numpy(), gg["f"].to_numpy()
                    cheap.append(len(set(np.argsort(yy)[:3]) & set(np.argsort(ff)[:3])) / 3)
                    dear.append(len(set(np.argsort(-yy)[:3]) & set(np.argsort(-ff)[:3])) / 3)
                out.append({
                    "model": m, "bucket": bname, "k": str(k), "days": len(day), "hours": int(ok.sum()),
                    "mae": float(np.abs(err).mean()), "rmse": float(math.sqrt((err ** 2).mean())),
                    "day_mae": float((day["f_mean"] - day["y_mean"]).abs().mean()),
                    "max_ae": float((day["f_max"] - day["y_max"]).abs().mean()),
                    "cheap3": float(np.mean(cheap)), "dear3": float(np.mean(dear)),
                    "dir": direction_hit(day),
                    # Range metrics only where the model has a range (baseline c has none in its
                    # first block, which has no training residuals yet).
                    "pinball": float(np.mean([pinball(y[rk], f[rk], q) for f, q in ((p10, 0.1), (p50, 0.5), (p90, 0.9))])) if rk.any() else float("nan"),
                    "cover80": float(np.mean((y[rk] >= p10[rk]) & (y[rk] <= p90[rk]))) if rk.any() else float("nan"),
                })
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("stamp")
    ap.add_argument("--end", default=None, help="last issue day (default: last with 7 complete target days)")
    ap.add_argument("--candidates", default=None,
                    help="comma list: fit only these model candidates (baselines a, b, c always run)")
    ap.add_argument("--tag", default=None, help="suffix for the output files of an experiment run")
    ap.add_argument("--netload-wind", default="stage1", choices=("proxy", "stage1"),
                    help="wind in the stage-1 net load (E7a kept stage1, 2026-11-02)")
    ap.add_argument("--weather", default="single", choices=("previous", "single"),
                    help="weather source: Open-Meteo Previous Runs, or the issue-day 00 UTC run (E8 kept single, 2026-11-06)")
    ap.add_argument("--refit-months", type=int, default=1, choices=(1, 3), help="refit interval (E26 kept monthly, 2026-11-06)")
    ap.add_argument("--refit-days", type=int, default=7,
                    help="refit interval in days, overrides --refit-months (E30 kept weekly, 2026-11-06); 0 = use --refit-months")
    ap.add_argument("--netload-interact", action="store_true", help="E29: hour interactions in the stage-1 net load")
    ap.add_argument("--issue-after-prices", action="store_true",
                    help="E32: issue at D 14:30 after the D+1 day-ahead result; targets D+2..D+8")
    ap.add_argument("--wind-shrink", action="store_true", help="E28: shrink D+5..D+7 stage-1 wind to the month/hour mean")
    args = ap.parse_args()
    t0 = time.time()
    pins, data = load_export(args.stamp)
    fi = data["spot"][data["spot"]["region"] == "FI"]
    last_price_day = datetime.fromtimestamp(int(fi["timestamp"].max()), timezone.utc).astimezone(HEL).date()
    fg_last = datetime.fromtimestamp(int(data["fingrid"]["timestamp"].max()), timezone.utc).astimezone(HEL).date()
    end_issue = date.fromisoformat(args.end) if args.end else min(last_price_day, fg_last) - timedelta(days=8)

    NETLOAD_WIND["mode"] = args.netload_wind
    WEATHER_SOURCE["mode"] = args.weather
    REFIT["months"] = args.refit_months
    REFIT["days"] = args.refit_days or None
    ISSUE["after_prices"] = args.issue_after_prices
    NETLOAD_DESIGN["interact"] = args.netload_interact
    WIND_SHRINK["on"] = args.wind_shrink
    table = build_table(data, end_issue)
    if "weather_previous_runs" in data:
        CANDIDATES["w_forecast"] = H_FEATURES + W_FEATURES
        LS_CANDIDATES["ls_forecast"] = (LS_LEVEL + ["om_fi_temp_day", "om_fi_windp_day"],
                                        LS_SHAPE + ["om_fi_windp", "om_fi_temp", "om_lead"])
        if table["om_fi_temp_south"].notna().any():
            CANDIDATES["w_south"] = H_FEATURES + W_FEATURES + SOUTH_FEATURES
            CANDIDATES["w_capacity"] = H_FEATURES + W_FEATURES + SOUTH_FEATURES + CAPACITY_FEATURES
            CANDIDATES["w_netload"] = H_FEATURES + W_FEATURES + SOUTH_FEATURES + CAPACITY_FEATURES + NETLOAD_FEATURES
            LINEAR_CANDIDATES["l_linear"] = CANDIDATES["w_netload"]
            # Experiments (research/experiments.md).
            CANDIDATES["e4_kgroups"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["e4_kgroups"] = {"k_groups": [(1, 1), (2, 4), (5, 7)]}
            CANDIDATES["e5_nosouth"] = [c for c in CANDIDATES["w_netload"] if c not in SOUTH_FEATURES]
            CANDIDATES["e6_recent"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["e6_recent"] = {"half_life_days": 365}
            # E10: grouped models with more capacity (more leaves, more iterations, lower rate).
            CANDIDATES["e10_kbig"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["e10_kbig"] = {"k_groups": [(1, 1), (2, 4), (5, 7)],
                                          "params": {"max_iter": 600, "learning_rate": 0.03, "max_leaf_nodes": 63,
                                                     "min_samples_leaf": 50}}
            # E18: planned SE3 nuclear unavailability added to the best tree parts.
            SE3_FEATURES = ["se3_nuc_unavail", "se3_nuc_unavail_day"]
            CANDIDATES["e18_k"] = CANDIDATES["w_netload"] + SE3_FEATURES
            CANDIDATE_OPTS["e18_k"] = {"k_groups": [(1, 1), (2, 4), (5, 7)]}
            CANDIDATES["e18_pool"] = CANDIDATES["w_netload"] + SE3_FEATURES
            # E19: + the 7-day median of the same hour.
            CANDIDATES["e19_k"] = CANDIDATES["w_netload"] + ["p_hour_med7"]
            CANDIDATE_OPTS["e19_k"] = {"k_groups": [(1, 1), (2, 4), (5, 7)]}
            CANDIDATES["e19_pool"] = CANDIDATES["w_netload"] + ["p_hour_med7"]
            # E22: smaller, more regularised trees (E10 showed more capacity overfits).
            SMALL = {"max_leaf_nodes": 15, "min_samples_leaf": 300, "l2_regularization": 3.0}
            CANDIDATES["e22_pool"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["e22_pool"] = {"params": SMALL}
            CANDIDATES["e22_k"] = CANDIDATES["w_netload"] + ["se3_nuc_unavail", "se3_nuc_unavail_day"]
            CANDIDATE_OPTS["e22_k"] = {"k_groups": [(2, 4)], "params": SMALL}
            # s_best (consolidated current best, experiments.md): one fitted part per horizon group;
            # composed in compose_best() after the range calibration.
            SE3 = ["se3_nuc_unavail", "se3_nuc_unavail_day"]
            if "om_ghi_fi" in table:
                CANDIDATES["best_d1"] = CANDIDATES["w_netload"] + WIND_FEATURES
                CANDIDATE_OPTS["best_d1"] = {"k_groups": [(1, 1)]}
            CANDIDATES["best_d24"] = CANDIDATES["w_netload"] + SE3
            CANDIDATE_OPTS["best_d24"] = {"k_groups": [(2, 4)]}
            CANDIDATES["best_d57"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["best_d57"] = {"params": SMALL}  # pooled over all horizons (as e22_pool); used for D+5..D+7
            # E25: with the issue-day weather (E8), the D+1 wind/solar inputs for D+2..D+7 too.
            if "om_ghi_fi" in table:
                CANDIDATES["e25_d24"] = CANDIDATES["best_d24"] + WIND_FEATURES
                CANDIDATE_OPTS["e25_d24"] = {"k_groups": [(2, 4)]}
                CANDIDATES["e25_d57"] = CANDIDATES["best_d57"] + WIND_FEATURES
                CANDIDATE_OPTS["e25_d57"] = {"params": SMALL}
            # E31: the s_best tree parts with recency weights (half-life 730 days), retest of E6
            # under the weekly refit (E30).
            for part in ("best_d1", "best_d24", "best_d57"):
                if part in CANDIDATES:
                    CANDIDATES[part.replace("best", "e31")] = CANDIDATES[part]
                    CANDIDATE_OPTS[part.replace("best", "e31")] = {**CANDIDATE_OPTS[part], "half_life_days": 730}
            # E23: even smaller trees for the long horizons.
            CANDIDATES["e23_tiny"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["e23_tiny"] = {"params": {"max_leaf_nodes": 7, "min_samples_leaf": 1000, "l2_regularization": 10.0}}
            # E9: a separate model for each of D+1..D+4 (E4 grouped D+2-D+4).
            CANDIDATES["e9_keach"] = CANDIDATES["w_netload"]
            CANDIDATE_OPTS["e9_keach"] = {"k_groups": [(1, 1), (2, 2), (3, 3), (4, 4), (5, 7)]}
            if "om_ghi_fi" in table:
                CANDIDATES["e7_wind"] = CANDIDATES["w_netload"] + WIND_FEATURES
                CANDIDATES["e7_kgroups"] = CANDIDATES["e7_wind"]
                CANDIDATE_OPTS["e7_kgroups"] = {"k_groups": [(1, 1), (2, 4), (5, 7)]}
                LINEAR_CANDIDATES["l_linear_e7"] = CANDIDATES["e7_wind"]          # E15a
                LINEAR_CANDIDATES["l_lear_hour"] = CANDIDATES["w_netload"]        # E15b
                LINEAR_CANDIDATES["l_lear_k"] = CANDIDATES["w_netload"]           # E27
                CANDIDATES["e18_d1"] = CANDIDATES["e7_wind"] + ["se3_nuc_unavail", "se3_nuc_unavail_day"]
                # E20: the D+1 model (e7 inputs) + the 7-day hourly median; D+1 only.
                CANDIDATES["e20_d1"] = CANDIDATES["e7_wind"] + ["p_hour_med7"]
                CANDIDATE_OPTS["e20_d1"] = {"k_groups": [(1, 1)]}
                CANDIDATE_OPTS["e18_d1"] = {"k_groups": [(1, 1), (2, 4), (5, 7)]}
    if args.candidates:
        keep = set(args.candidates.split(","))
        for group in (CANDIDATES, LS_CANDIDATES, LINEAR_CANDIDATES):
            for name in list(group):
                if name not in keep:
                    del group[name]
        missing = keep - set(CANDIDATES) - set(LS_CANDIDATES) - set(LINEAR_CANDIDATES)
        if missing:
            raise SystemExit(f"unknown candidates: {sorted(missing)}")
    print(f"feature table: {len(table)} rows, {table['issue'].nunique()} issues, {time.time() - t0:.0f} s", flush=True)

    preds = []
    for start, end in blocks(end_issue):
        p = predict_block(table, start, end)
        if not p.empty:
            preds.append(p)
            print(f"block {start}: {p['issue'].nunique()} issues, {time.time() - t0:.0f} s", flush=True)
    pred = pd.concat(preds, ignore_index=True)
    for c in list(CANDIDATES) + list(LS_CANDIDATES) + list(LINEAR_CANDIDATES):
        seasonal_calibration(pred, c)
    for c in list(CANDIDATES):
        risk_calibration(pred, c)
    has_best = compose_best(pred)
    models = ([m for c in CANDIDATES for m in (c, f"{c}_cal", f"{c}_scal")]
              + [m for c in list(LS_CANDIDATES) + list(LINEAR_CANDIDATES) for m in (c, f"{c}_scal")]
              + (["s_best", "s_best_scal"] if has_best else []) + ["a_last_week", "b_last_day", "c_thirdparty"])
    results = score(pred, models)

    out_dir = HERE / "data"
    suffix = f"{args.stamp}-{args.tag}" if args.tag else args.stamp
    # Tagged experiment runs keep their reports in the git-ignored data/experiments folder.
    report_dir = HERE / "data" / "experiments" if args.tag else HERE
    report_dir.mkdir(parents=True, exist_ok=True)
    keep_cols = ["issue", "target_ts", "tdate", "k", "month", "hour", "y", "fi_mean_d"] + [
        c for c in ("import_need", "om_fi_temp_south", "resid_load") if c in pred] + [
        c for c in pred.columns if c.endswith(("_p10", "_p50", "_p90"))]
    pred[keep_cols].to_csv(out_dir / f"s-predictions-{suffix}.csv.gz", index=False)
    wind = table.dropna(subset=["wind_fc"]).drop_duplicates("target_ts")
    fg = data["fingrid"].pivot(index="timestamp", columns="dataset_id", values="value")
    real = fg[75].reindex(wind["target_ts"]).to_numpy()
    wind_mae = float(np.nanmean(np.abs(wind["wind_fc"].to_numpy() - real)))
    summary = {
        "inputs": pins, "issue_rule": "D 14:30 Helsinki, prices known through D+1, targets D+2..D+8" if ISSUE["after_prices"] else "D 11:30 Helsinki, targets D+1..D+7",
        "issues": [str(pred["issue"].min()), str(pred["issue"].max()), int(pred["issue"].nunique())],
        "model_params": MODEL_PARAMS, "candidates": CANDIDATES, "calibration_days": CALIBRATION_DAYS,
        "calibration_shifts_eur_mwh": CAL_FACTORS,
        "fingrid_246_vs_75_mae_mw": wind_mae, "netload_fit": NETLOAD_FIT, "wind_fit": WIND_FIT, "linear_candidates": LINEAR_CANDIDATES,
        "results": results,
    }
    (report_dir / f"s-backtest-results-{suffix}.json").write_text(json.dumps(summary, indent=1, default=str) + "\n")

    lines = [f"S backtest on export {args.stamp}; test issues {summary['issues'][0]}..{summary['issues'][1]} "
             f"({summary['issues'][2]} days); EUR/MWh VAT 0; quarterly expanding refit from {FIRST_BLOCK}",
             "h_asof = as-of inputs only (honest). p_standin = + perfect-forecast weather stand-ins (overstated).",
             f"*_cal = same p50, p10-p90 range shifted by conformal misses on the last {CALIBRATION_DAYS} training days of each block.",
             "dir = daily-mean direction vs day D, only on days where the forecast moves (nan for a flat baseline).",
             "*_scal = p10/p90 shifted by earlier blocks' out-of-sample misses, same season and horizon (fallback *_cal).",
             "w_forecast = h_asof + Open-Meteo forecasts as issued (Previous Runs API, honest; only when exported).",
             "ls_* = daily level + hourly shape models (as-of inputs; ls_forecast adds the Open-Meteo forecasts).",
             "w_south = w_forecast + population-weighted southern Finland temperature forecast.",
             "w_capacity = w_south + as-of border capacity (Fingrid NTC to 2024-10-29, JAO maxBorderFlow after; A78 limits).",
             "w_netload = w_capacity + stage-1 expected net load, residual load after nuclear, and import need (add_netload).",
             "l_linear = LEAR-type ridge on asinh prices with the w_netload inputs (linear baseline).",
             f"Fingrid 246 stand-in wind forecast vs realised wind (75): MAE {wind_mae:.0f} MW",
             ""]
    hdr = f"{'model':14} {'bucket':14} {'k':>3} {'days':>5} {'MAE':>7} {'RMSE':>7} {'dayMAE':>7} {'maxAE':>7} {'cheap3':>6} {'dear3':>6} {'dir':>5} {'pinbal':>7} {'cov80':>6}"
    for bucket in ("all", "winter", "summer", "futures_period", "thirdparty_rows", "weather_period"):
        lines += [f"== {bucket}", hdr]
        for r in results:
            if r["bucket"] == bucket:
                lines.append(f"{r['model']:14} {r['bucket']:14} {r['k']:>3} {r['days']:>5} {r['mae']:7.2f} {r['rmse']:7.2f} "
                             f"{r['day_mae']:7.2f} {r['max_ae']:7.2f} {r['cheap3']:6.2f} {r['dear3']:6.2f} {r['dir']:5.2f} "
                             f"{r['pinball']:7.2f} {r['cover80']:6.2f}")
        lines.append("")
    report = report_dir / f"s-backtest-{suffix}.txt"
    report.write_text("\n".join(lines))
    print(f"{report} written, {time.time() - t0:.0f} s")


if __name__ == "__main__":
    main()
