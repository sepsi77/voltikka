#!/usr/bin/env python3
"""Medium-term model (M), phase 2: as-of features, baselines and an expanding-window backtest.

Target (spec.md, M): the mean FI day-ahead price (EUR/MWh, VAT 0) of each of the next three
calendar months after the issue month (h = 1, 2, 3), issued every Monday. The consumer output is
the change from a reference level, so the model predicts y = log(target / ref30), where ref30 is the
mean FI price of the 30 days up to the issue day. Prices below 5 EUR/MWh are floored at 5 in the
logarithm only.

As-of rules (issue Monday D):
  - day-ahead prices: Helsinki days <= D (D's prices were published on D-1);
  - ENTSO-E reservoirs: weeks whose start + 10 days <= D (published on the Wednesday after);
  - ERA5 weather: days <= D - 7 (ERA5 is about 5-6 days late);
  - FI month futures: trade date <= D - 1 (baseline (a) only; data from 2026-04-08).

Models (fixed before the first run, 2026-11-02):
  m_ridge   ridge regression (alpha 1, standardised) on the features in FEATURES, refitted at
            every issue on the (issue, h) rows whose target month ended before D.
Baselines: b_unchanged (ref30), c_seasonal (ref30 x exp(median past log change for the same
calendar position)), a_futures (FI month future of the target month, where it exists).
Range (p10/p90): the 10 % / 90 % quantiles of the model's own out-of-sample log errors of earlier
issues for the same h (last 3 years), from 2019; before that, in-sample residuals.

Metrics: MAE of the monthly mean, MAE of the change, direction (3 classes: up > +10 %, down <
-10 %, else unchanged) hit rate, wrong-way share (forecast up and real down, or the reverse, beyond
the band), p10-p90 coverage. Periods: DEV = issues 2019-01..2022-12, HOLD = 2023-01.. .

Usage: nice research/.venv/bin/python -I m_backtest.py <YYYYMMDD of the export> [--tag T]
"""
import os

os.environ.setdefault("OMP_NUM_THREADS", "2")

import argparse
import hashlib
import json
from datetime import date, timedelta
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.linear_model import QuantileRegressor, Ridge

HERE = Path(__file__).resolve().parent
FIRST_ISSUE, FIRST_SCORED = date(2016, 1, 4), date(2019, 1, 7)
DEV_END = date(2023, 1, 1)
FLOOR = 5.0
BAND = 0.10
FEATURES = ["seasonal_prior", "h", "hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "de_trend_log",
            "fi_trend_log", "fi_se1_spread_log", "temp_anom_30", "snow_anom_season", "month_sin", "month_cos"]
# name -> features, offset column (the model learns y - offset), ridge alpha. Experiments are
# logged in experiments.md (M section).
MODELS = {
    "m_ridge": {"features": FEATURES, "offset": None, "alpha": 1.0},
    # M1: start from the seasonal change; a strongly regularised correction from fundamentals.
    "m1_seas_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                                  "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0},
    # M2: as M1 with only the hydro state and the German level.
    "m2_seas_hydro_de": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log"], "offset": "seasonal_prior", "alpha": 30.0},
    # M3: M1 trained without the crisis targets (2021-10..2023-03), whose relations misled 2023.
    "m3_seas_corr_nocrisis": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                                           "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0,
                              "exclude_targets": (date(2021, 10, 1), date(2023, 3, 31))},
    # M12: M1 + seasonal interactions (snow for spring targets, Nordic hydro for winter targets).
    "m12_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season", "snow_spring", "hydro_winter"], "offset": "seasonal_prior", "alpha": 30.0},
    # M17: M1 + the latest 7-day level against ref30.
    "m17_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season", "fi_last7_log"], "offset": "seasonal_prior", "alpha": 30.0},
    # M18: median regression (MALE fits the log median), L1 penalty grid.
    "m18a_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 0.001, "loss": "median"},
    "m18b_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 0.01, "loss": "median"},
    # M19: Nordic spreads added (a) / instead of the German level (b).
    "m19a_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30", "snow_anom_season"] + ["fi_se1_spread_log", "fi_se3_spread_log"], "offset": "seasonal_prior", "alpha": 30.0},
    "m19b_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "fi_se1_spread_log", "fi_se3_spread_log", "fi_trend_log",
                               "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0},
    # M20: older training rows weigh less (half-life in years).
    "m20a_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0, "half_life_years": 2},
    "m20b_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0, "half_life_years": 4},
    "m20c_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0, "half_life_years": 6},
    # M23: NVE long-normal Norway hydro deviation instead of / in addition to the ENTSO-E NO+SE one.
    "m23a_corr": {"features": ["hydro_no_nve_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                               "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0},
    "m23b_corr": {"features": ["hydro_no_nve_dev", "hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log",
                               "temp_anom_30", "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0},
    # M25: M1 + planned FI nuclear unavailability in the target month.
    "m25_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season", "nuclear_unavail_mw"], "offset": "seasonal_prior", "alpha": 30.0},
    # M27: M1 + planned SE3 nuclear unavailability in the target month.
    "m27_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season", "se3_nuc_unavail_mw"], "offset": "seasonal_prior", "alpha": 30.0},
    # M28 (Codex 2026-11-06): M1 with equal total weight per target month (weekly issues repeat it).
    "m28_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0, "month_equal": True},
    # M29 (Codex 2026-11-06): M1 + 4-week change of the NO+SE reservoir deviation.
    "m29_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season", "hydro_nose_chg4"], "offset": "seasonal_prior", "alpha": 30.0},
    # M14: M1 fitted separately per horizon.
    "m14_corr": {"features": ["hydro_nose_dev", "hydro_fi_dev", "de_fi_log", "fi_trend_log", "temp_anom_30",
                              "snow_anom_season"], "offset": "seasonal_prior", "alpha": 30.0, "per_h": True},
}
# Correction models that go through the M10 recipe (50/50 log blend with c_seasonal, agreement rule).
M10_RECIPE = {"m12": "m12_corr", "m14": "m14_corr"}
# M16: blend weight of the correction model in the M10 recipe (M10 uses 0.5).
M16_WEIGHTS = {"m16_w03": 0.3, "m16_w07": 0.7}
# Candidates through the current best recipe (weight 0.7, agreement rule, r3 range): name -> correction model.
BEST_RECIPE = {"m17_w07": "m17_corr", "m18a_w07": "m18a_corr", "m18b_w07": "m18b_corr", "m19a_w07": "m19a_corr",
               "m19b_w07": "m19b_corr", "m20a_w07": "m20a_corr", "m20b_w07": "m20b_corr", "m20c_w07": "m20c_corr"}
# M21: blend the current best with the calendar-month median level (weight).
M21_WEIGHTS = {"m21_015": 0.15, "m21_030": 0.30, "m24_010": 0.10, "m24_020": 0.20}
# Candidates through the full current best recipe (M21 0.15 included).
BEST21 = {"m23a_b21": "m23a_corr", "m23b_b21": "m23b_corr", "m25_b21": "m25_corr", "m27_b21": "m27_corr",
           "m28_b21": "m28_corr", "m29_b21": "m29_corr"}
BEST_RECIPE.update(BEST21)
# Fixed blends in log space (no fitting): name -> {model: weight}; M4/M5.
BLENDS = {"m4_blend_m1_seas": {"m1_seas_corr": 0.5, "c_seasonal": 0.5},
          "m5_damped_seas": {"c_seasonal": 0.5, "b_unchanged": 0.5}}


def load(stamp):
    pins = json.loads((HERE / f"m-inputs-{stamp}.json").read_text())
    base = HERE / pins["export"]
    data = {}
    for name, pin in pins["files"].items():
        if hashlib.sha256((base / name).read_bytes()).hexdigest() != pin["sha256"]:
            raise SystemExit(f"{name}: SHA-256 differs from the pin")
        data[name[:-4]] = pd.read_csv(base / name)
    return pins, data


def month_add(y, m, k):
    m0 = y * 12 + (m - 1) + k
    return m0 // 12, m0 % 12 + 1


def month_end(y, m):
    ny, nm = month_add(y, m, 1)
    return date(ny, nm, 1) - timedelta(days=1)


def nuclear_unavailability(table, zone_file="outages.csv", nominal=None):
    """M25: mean planned FI nuclear unavailability (MW) in the target month, from the ENTSO-E
    A77/A80 revisions published before the issue (S backtest parser; data from 2019-12). Issues
    before 2020-01-01 get 0 (no plans in the data; assumption recorded in experiments.md)."""
    import sys
    sys.path.insert(0, str(HERE))
    import s_backtest as sb  # same as-of outage logic as the S model
    pins = json.loads((HERE / "s-inputs-20261102.json").read_text())
    path = HERE / pins["export"] / zone_file
    if hashlib.sha256(path.read_bytes()).hexdigest() != pins["files"][zone_file]["sha256"]:
        raise SystemExit(f"{zone_file}: SHA-256 differs from the S pin")
    nominal = nominal or sb.NUCLEAR_NOMINAL_MW
    docs = sb.parse_outages(pd.read_csv(path))
    out = []
    for r in table.itertuples():
        if r.issue < date(2020, 1, 1):
            out.append(0.0)
            continue
        a = pd.Timestamp(date(r.target_year, r.target_month, 1), tz="Europe/Helsinki").timestamp()
        b = pd.Timestamp(r.target_end + timedelta(days=1), tz="Europe/Helsinki").timestamp()
        hts = np.arange(a, b, 3600.0)
        issue_ts = pd.Timestamp(r.issue, tz="Europe/Helsinki").replace(hour=11).timestamp()
        avail, _ = sb.outage_features(docs, issue_ts, hts, nominal=nominal)
        out.append(float(nominal - avail.mean()))
    return np.array(out)


def build(data):
    p = data["prices_daily"].copy()
    p["date"] = pd.to_datetime(p["date"]).dt.date
    px = p.pivot(index="date", columns="zone", values="eur_mwh").sort_index()
    px.index = pd.Index(px.index)
    fi = px["FI"]
    monthly = fi.groupby([lambda d: d.year, lambda d: d.month]).agg(["mean", "count"])
    full = {k: v["mean"] for k, v in monthly.iterrows() if v["count"] >= 28}

    res = data["reservoirs"].copy()
    res["week_start"] = pd.to_datetime(res["week_start_utc"]).dt.date
    res["avail"] = res["week_start"] + timedelta(days=10)
    res_w = res.pivot_table(index=["iso_year", "iso_week", "avail"], columns="zone", values="stored_energy_mwh").reset_index()
    res_w["nose"] = res_w[["NO", "SE"]].sum(axis=1, min_count=2)

    nve = data["nve"].copy() if "nve" in data else None
    if nve is not None:
        nve["avail"] = pd.to_datetime(nve["week_end_date"]).dt.date + timedelta(days=4)
    wx = data["weather"].copy()
    wx["date"] = pd.to_datetime(wx["date"]).dt.date
    wxd = wx.groupby("date")[["temperature_2m_mean", "snowfall_water_equivalent_sum"]].mean()

    rows = []
    d = FIRST_ISSUE
    last_day = px.index.max()
    while d <= last_day:
        known = fi.loc[:d]
        if len(known) < 400:
            d += timedelta(days=7)
            continue
        last30 = known.iloc[-30:]
        ref30 = float(last30.mean())
        prev90 = float(known.iloc[-120:-30].mean())
        de = px["DE"].loc[:d].dropna()
        se1 = px["SE1"].loc[:d].dropna()
        de30 = float(de.iloc[-30:].mean())
        de_prev = float(de.iloc[-120:-30].mean())
        se1_30 = float(se1.iloc[-30:].mean())
        se3 = px["SE3"].loc[:d].dropna()
        se3_30 = float(se3.iloc[-30:].mean())
        lg = lambda a, b: np.log(max(a, FLOOR) / max(b, FLOOR))

        # Reservoirs: latest week available at D, deviation from the median of the same ISO week in
        # the earlier years that are also available at D (at least 3 years).
        r_ok = res_w[res_w["avail"] <= d]
        hydro = {}
        if len(r_ok):
            last = r_ok.iloc[-1]
            same = r_ok[(r_ok["iso_week"] == last["iso_week"]) & (r_ok["iso_year"] < last["iso_year"])]
            for col, key in (("nose", "hydro_nose_dev"), ("FI", "hydro_fi_dev")):
                hydro[key] = (last[col] - same[col].median()) / 1e6 if len(same) >= 3 else np.nan  # TWh
            # M29 (Codex 2026-11-06): 4-week change of the NO+SE deviation, same method 4 weeks earlier.
            if len(r_ok) >= 5:
                prev = r_ok.iloc[-5]
                same_p = r_ok[(r_ok["iso_week"] == prev["iso_week"]) & (r_ok["iso_year"] < prev["iso_year"])]
                if len(same_p) >= 3 and not np.isnan(hydro.get("hydro_nose_dev", np.nan)):
                    hydro["hydro_nose_chg4"] = hydro["hydro_nose_dev"] - (prev["nose"] - same_p["nose"].median()) / 1e6

        if nve is not None:  # M23: NVE Norway filling ratio vs the median of the same ISO week, earlier years
            n_ok = nve[nve["avail"] <= d]
            if len(n_ok):
                last = n_ok.iloc[-1]
                same = n_ok[(n_ok["iso_week"] == last["iso_week"]) & (n_ok["iso_year"] < last["iso_year"])]
                hydro["hydro_no_nve_dev"] = last["filling_ratio"] - same["filling_ratio"].median() if len(same) >= 5 else np.nan
        # Weather anomalies vs the same calendar window in earlier years (ERA5, lag 7 days).
        wd = d - timedelta(days=7)
        def window_mean(end, days, col):
            s = wxd[col].loc[end - timedelta(days=days - 1):end]
            return s.mean() if len(s) >= days * 0.9 else np.nan
        t_now = window_mean(wd, 30, "temperature_2m_mean")
        t_norm = np.nanmean([window_mean(date(y, wd.month, min(wd.day, 28)), 30, "temperature_2m_mean")
                             for y in range(wd.year - 15, wd.year)])
        season_start = date(wd.year if wd.month >= 10 else wd.year - 1, 10, 1)
        def season_snow(y_offset):
            a = date(season_start.year - y_offset, 10, 1)
            b = date(wd.year - y_offset, wd.month, min(wd.day, 28))
            s = wxd["snowfall_water_equivalent_sum"].loc[a:b]
            return s.sum() if len(s) else np.nan
        snow_now = season_snow(0)
        snow_norm = np.nanmedian([season_snow(k) for k in range(1, 16)])

        for h in (1, 2, 3):
            ty, tm = month_add(d.year, d.month, h)
            # Seasonal prior: median log change from the 30 days before the same calendar date to the
            # same target month, in earlier years whose target month ended before D.
            prior = []  # (years back, log change), nearest year first
            for k in range(1, 16):
                py_ = d.year - k
                pd_ = date(py_, d.month, min(d.day, 28))
                pty, ptm = month_add(py_, d.month, h)
                if month_end(pty, ptm) >= d or (pty, ptm) not in full:
                    continue
                past30 = fi.loc[pd_ - timedelta(days=29):pd_]
                if len(past30) >= 28:
                    prior.append(lg(full[(pty, ptm)], past30.mean()))
            # M7: the same prior from the latest 3 and 5 usable years only (structure changed with
            # OL3 in 2023 and the wind build-out).
            prior3, prior5 = prior[:3], prior[:5]
            target = full.get((ty, tm)) if month_end(ty, tm) <= last_day else None
            rows.append({
                "issue": d, "h": h, "target_month": tm, "target_year": ty, "target_end": month_end(ty, tm),
                "ref30": ref30, "y_level": target, "y": lg(target, ref30) if target is not None else np.nan,
                "seasonal_prior": float(np.median(prior)) if len(prior) >= 3 else np.nan,
                "seasonal_prior_3y": float(np.median(prior3)) if len(prior3) >= 3 else np.nan,
                "seasonal_prior_5y": float(np.median(prior5)) if len(prior5) >= 3 else np.nan,
                "de_fi_log": lg(de30, ref30), "de_trend_log": lg(de30, de_prev), "fi_trend_log": lg(ref30, prev90),
                "fi_last7_log": lg(float(known.iloc[-7:].mean()), ref30),  # M17
                "fi_se3_spread_log": lg(ref30, se3_30),  # M19
                # M21: median log price of the same calendar month in completed earlier years.
                "month_log_median": float(np.median([np.log(max(v, FLOOR)) for (yy, mm), v in full.items()
                                                     if mm == tm and month_end(yy, mm) < d])) if any(
                    mm == tm and month_end(yy, mm) < d for (yy, mm) in full) else np.nan,
                "fi_se1_spread_log": lg(ref30, se1_30),
                "temp_anom_30": t_now - t_norm, "snow_anom_season": snow_now - snow_norm,
                "month_sin": np.sin(2 * np.pi * tm / 12), "month_cos": np.cos(2 * np.pi * tm / 12),
                "spring_target": float(tm in (4, 5, 6)), "winter_target": float(tm in (11, 12, 1, 2, 3)),
                **hydro,
            })
        d += timedelta(days=7)
    t = pd.DataFrame(rows)
    t["snow_spring"] = t["snow_anom_season"] * t["spring_target"]          # M12
    t["hydro_winter"] = t["hydro_nose_dev"] * t["winter_target"]           # M12
    t["nuclear_unavail_mw"] = nuclear_unavailability(t)                     # M25
    t["se3_nuc_unavail_mw"] = nuclear_unavailability(t, "outages_se3.csv", 1e6)  # M27
    return t, data.get("futures")


def futures_baseline(table, fut):
    out = np.full(len(table), np.nan)
    if fut is None or fut.empty:
        return out
    fut = fut.copy()
    fut["trade_date"] = pd.to_datetime(fut["trade_date"]).dt.date
    for i, r in enumerate(table.itertuples()):
        g = fut[(fut["maturity"] == r.target_year * 100 + r.target_month) & (fut["trade_date"] <= r.issue - timedelta(days=1))]
        if len(g):
            out[i] = float(g["settlement_price"].iloc[-1])
    return out


def backtest(table):
    table = table.sort_values(["issue", "h"]).reset_index(drop=True)
    for name in MODELS:
        for c in ("_p10", "_p50", "_p90"):
            table[name + c] = np.nan
    oos = {name: [] for name in MODELS}  # (issue, h, log error) of earlier scored issues
    for d in sorted(table["issue"].unique()):
        if d < FIRST_SCORED:
            continue
        now = table["issue"] == d
        train = table[(table["target_end"] < d) & table["y"].notna()]
        for name, cfg in MODELS.items():
            cols, off = cfg["features"], cfg["offset"]
            need = cols + ([off] if off else [])
            tr = train.dropna(subset=need)
            if cfg.get("per_h"):
                hs = table.loc[now, "h"].unique()
            if "exclude_targets" in cfg:
                a, b = cfg["exclude_targets"]
                tr = tr[(tr["target_end"] < a) | (tr["target_end"] > b)]
            if len(tr) < 60:
                continue
            te = table.loc[now, need]
            ok = te.notna().all(axis=1)
            pred = pd.Series(np.nan, index=te.index)
            if not ok.any():
                continue
            resid_in = pd.Series(np.nan, index=tr.index)
            groups = [(None, tr, te.index)] if not cfg.get("per_h") else [
                (h, tr[tr["h"] == h], te.index[table.loc[te.index, "h"] == h]) for h in (1, 2, 3)]
            for _h, trg, te_idx in groups:
                base_tr = trg[off] if off else 0.0
                mu, sd = trg[cols].mean(), trg[cols].std().replace(0, 1)
                wgt = None
                if "half_life_years" in cfg:  # M20
                    age = np.array([(d - e).days for e in trg["target_end"]], dtype=float) / 365.0
                    wgt = 0.5 ** (age / cfg["half_life_years"])
                if cfg.get("month_equal"):  # M28: each target month weighs the same in total
                    n = trg.groupby(["target_year", "target_month"])["y"].transform("size").to_numpy(dtype=float)
                    wgt = (wgt if wgt is not None else 1.0) / n
                    wgt = wgt * len(trg) / wgt.sum()  # mean weight 1, so the ridge alpha keeps its meaning
                if cfg.get("loss") == "median":  # M18: fit the log median (MALE) directly
                    model = QuantileRegressor(quantile=0.5, alpha=cfg["alpha"], solver="highs")
                else:
                    model = Ridge(alpha=cfg["alpha"])
                model.fit((trg[cols] - mu) / sd, trg["y"] - base_tr, sample_weight=wgt)
                sel = [i for i in te_idx if ok[i]]
                if sel:
                    pred[sel] = model.predict((te.loc[sel, cols] - mu) / sd) + (te.loc[sel, off] if off else 0.0)
                resid_in[trg.index] = trg["y"] - base_tr - model.predict((trg[cols] - mu) / sd)
            for idx in te.index:
                if pd.isna(pred[idx]):
                    continue
                h = table.at[idx, "h"]
                # Only errors whose target month was known before D, from the last 3 years.
                past = [e for (known, hh, e) in oos[name] if hh == h and d - timedelta(days=3 * 365) <= known <= d]
                errs = np.array(past) if len(past) >= 40 else resid_in[tr["h"] == h].to_numpy()
                ref = table.at[idx, "ref30"]
                lvl = lambda v: max(ref, FLOOR) * np.exp(v) if ref >= FLOOR else ref + (np.exp(v) - 1) * FLOOR
                table.at[idx, name + "_p50"] = lvl(pred[idx])
                table.at[idx, name + "_p10"] = lvl(pred[idx] + np.quantile(errs, 0.1))
                table.at[idx, name + "_p90"] = lvl(pred[idx] + np.quantile(errs, 0.9))
        # Out-of-sample errors become usable once the target month has ended (checked above via
        # the issue filter on later issues).
        for name in MODELS:
            for idx in table.index[now]:
                if not np.isnan(table.at[idx, name + "_p50"]) and not np.isnan(table.at[idx, "y"]):
                    e = table.at[idx, "y"] - np.log(max(table.at[idx, name + "_p50"], FLOOR) / max(table.at[idx, "ref30"], FLOOR))
                    oos[name].append((table.at[idx, "target_end"] + timedelta(days=1), table.at[idx, "h"], e))
    return table


def oos_range(table, name, years=None, min_n=40, by_season=False, q=(0.1, 0.9), out=None):
    """p10/p90 for any p50 column from its own earlier out-of-sample log errors (same h, target
    month ended before the issue; all history, or the last `years`)."""
    t = table
    err = np.log(np.maximum(t["y_level"], FLOOR) / np.maximum(t[f"{name}_p50"], FLOOR))
    known = t["target_end"].map(lambda e: e + timedelta(days=1))
    p10, p90 = np.full(len(t), np.nan), np.full(len(t), np.nan)
    for h in (1, 2, 3):
        idx = np.where(t["h"] == h)[0]
        for i in idx:
            d = t.at[i, "issue"]
            m = (t["h"] == h) & (known <= d) & err.notna()
            if by_season:  # R1: errors of target months in the same half-year (Apr-Sep vs Oct-Mar)
                summer = t["target_month"].between(4, 9)
                m &= summer == summer.iat[i]
            if years:
                m &= known >= d - timedelta(days=365 * years)
            e = err[m].to_numpy()
            if len(e) < min_n or pd.isna(t.at[i, f"{name}_p50"]):
                continue
            base = max(t.at[i, f"{name}_p50"], FLOOR)
            p10[i], p90[i] = base * np.exp(np.quantile(e, q[0])), base * np.exp(np.quantile(e, q[1]))
    out = out or name
    table[f"{out}_p10"], table[f"{out}_p90"] = p10, p90
    if out != name:
        table[f"{out}_p50"] = table[f"{name}_p50"]


def direction_rule(table, name, other):
    """M10: a direction class only where `name` and `other` agree; else 0 (about unchanged)."""
    def cls(col):
        x = table[col] - table["ref30"]
        return np.where(x > BAND * table["ref30"].abs(), 1, np.where(x < -BAND * table["ref30"].abs(), -1, 0))
    a, b = cls(f"{name}_p50"), cls(f"{other}_p50")
    return np.where(a == b, a, 0)


def score(table, models):
    t = table[(table["issue"] >= FIRST_SCORED) & table["y_level"].notna()].copy()
    out = []
    for m in models:
        for label, mask in (("all", np.ones(len(t), bool)), ("DEV", t["issue"] < DEV_END), ("HOLD", t["issue"] >= DEV_END)):
            for h in ("1-3", 1, 2, 3):
                s = t[mask] if h == "1-3" else t[mask & (t["h"] == h)]
                s = s[s[m + "_p50"].notna()]
                if s.empty:
                    continue
                real = s["y_level"] - s["ref30"]
                fc = s[m + "_p50"] - s["ref30"]
                cls = lambda x, ref: np.where(x > BAND * ref.abs(), 1, np.where(x < -BAND * ref.abs(), -1, 0))
                rc = cls(real, s["ref30"])
                fcl = s[m + "_dir"].to_numpy() if m + "_dir" in s else cls(fc, s["ref30"])
                row = {"model": m, "period": label, "h": str(h), "n": len(s), "issues": s["issue"].nunique(),
                       "mae": float((s[m + "_p50"] - s["y_level"]).abs().mean()),
                       # Main M metric (fixed 2026-11-02): mean |log(forecast / real)|, scale-free so
                       # that the 2022 crisis does not dominate.
                       "male": float(np.abs(np.log(np.maximum(s[m + "_p50"], FLOOR) / np.maximum(s["y_level"], FLOOR))).mean()),
                       "dir_hit": float((rc == fcl).mean()), "wrong_way": float(((rc * fcl) == -1).mean()),
                       # Codex review: report how often a direction is called, and wrong way among calls.
                       "calls": float((fcl != 0).mean()),
                       "wrong_in_calls": float(((rc * fcl) == -1).sum() / max((fcl != 0).sum(), 1))}
                if m + "_p10" in s and s[m + "_p10"].notna().any():
                    r = s[s[m + "_p10"].notna()]
                    row["cover80"] = float(((r["y_level"] >= r[m + "_p10"]) & (r["y_level"] <= r[m + "_p90"])).mean())
                    pin = lambda f, qq: np.maximum(qq * (r["y_level"] - f), (qq - 1) * (r["y_level"] - f))
                    row["pinball"] = float(((pin(r[m + "_p10"], 0.1) + pin(r[m + "_p90"], 0.9)) / 2).mean())
                out.append(row)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("stamp")
    ap.add_argument("--tag", default=None)
    args = ap.parse_args()
    pins, data = load(args.stamp)
    table, fut = build(data)
    table["b_unchanged_p50"] = table["ref30"]
    table["c_seasonal_p50"] = np.where(table["seasonal_prior"].notna(),
                                       np.maximum(table["ref30"], FLOOR) * np.exp(table["seasonal_prior"]), np.nan)
    table["a_futures_p50"] = futures_baseline(table, fut)
    for n in ("3y", "5y"):  # M7
        table[f"m7_seasonal_{n}_p50"] = np.where(table[f"seasonal_prior_{n}"].notna(),
                                                 np.maximum(table["ref30"], FLOOR) * np.exp(table[f"seasonal_prior_{n}"]), np.nan)
    table = backtest(table)
    for name, parts in BLENDS.items():
        logs = sum(w * np.log(np.maximum(table[f"{m}_p50"], FLOOR)) for m, w in parts.items())
        table[f"{name}_p50"] = np.exp(logs)
    table = table.reset_index(drop=True)
    for name in list(BLENDS) + ["c_seasonal"]:
        oos_range(table, name)
    table["m10_m4_agree_p50"] = table["m4_blend_m1_seas_p50"]
    table["m10_m4_agree_p10"], table["m10_m4_agree_p90"] = table["m4_blend_m1_seas_p10"], table["m4_blend_m1_seas_p90"]
    table["m10_m4_agree_dir"] = direction_rule(table, "m4_blend_m1_seas", "c_seasonal")
    # Range experiments on the m10 level (R1 season-conditioned errors, R2 wider error quantiles).
    oos_range(table, "m10_m4_agree", by_season=True, out="r1_season")
    oos_range(table, "m10_m4_agree", q=(0.05, 0.95), out="r2_q05")
    oos_range(table, "m10_m4_agree", by_season=True, q=(0.05, 0.95), out="r12_season_q05")
    oos_range(table, "m10_m4_agree", q=(0.025, 0.975), out="r3_q025")
    oos_range(table, "m10_m4_agree", q=(0.05, 0.95), years=3, out="r4_q05_3y")
    for r in ("r1_season", "r2_q05", "r12_season_q05", "r3_q025", "r4_q05_3y"):
        table[f"{r}_dir"] = table["m10_m4_agree_dir"]
    recipe_names = []
    for name, corr in M10_RECIPE.items():
        logs = 0.5 * np.log(np.maximum(table[f"{corr}_p50"], FLOOR)) + 0.5 * np.log(np.maximum(table["c_seasonal_p50"], FLOOR))
        table[f"{name}_blend_p50"] = np.exp(logs)
        table[f"{name}_m10_p50"] = table[f"{name}_blend_p50"]
        table[f"{name}_m10_dir"] = direction_rule(table, f"{name}_blend", "c_seasonal")
        oos_range(table, f"{name}_m10", q=(0.025, 0.975))
        recipe_names.append(f"{name}_m10")
    for name, wgt in M16_WEIGHTS.items():
        logs = wgt * np.log(np.maximum(table["m1_seas_corr_p50"], FLOOR)) + (1 - wgt) * np.log(np.maximum(table["c_seasonal_p50"], FLOOR))
        table[f"{name}_blend_p50"] = np.exp(logs)
        table[f"{name}_p50"] = table[f"{name}_blend_p50"]
        table[f"{name}_dir"] = direction_rule(table, f"{name}_blend", "c_seasonal")
        oos_range(table, name, q=(0.025, 0.975))
        recipe_names.append(name)
    for name, wgt in M21_WEIGHTS.items():
        month_level = np.exp(table["month_log_median"])
        logs = (1 - wgt) * np.log(np.maximum(table["m16_w07_p50"], FLOOR)) + wgt * np.log(np.maximum(month_level, FLOOR))
        table[f"{name}_blend_p50"] = np.exp(logs)
        table[f"{name}_p50"] = table[f"{name}_blend_p50"]
        table[f"{name}_dir"] = direction_rule(table, f"{name}_blend", "c_seasonal")
        oos_range(table, name, q=(0.025, 0.975))
        recipe_names.append(name)
    for name, corr in BEST_RECIPE.items():
        logs = 0.7 * np.log(np.maximum(table[f"{corr}_p50"], FLOOR)) + 0.3 * np.log(np.maximum(table["c_seasonal_p50"], FLOOR))
        if name in BEST21:  # the current best recipe also blends the calendar-month level (M21, 0.15)
            logs = 0.85 * logs + 0.15 * np.log(np.maximum(np.exp(table["month_log_median"]), FLOOR))
        table[f"{name}_blend_p50"] = np.exp(logs)
        table[f"{name}_p50"] = table[f"{name}_blend_p50"]
        table[f"{name}_dir"] = direction_rule(table, f"{name}_blend", "c_seasonal")
        oos_range(table, name, q=(0.025, 0.975))
        recipe_names.append(name)
    # m_best: the current best M model (experiments.md, "Current best M model") under a fixed name.
    for c in ("_p10", "_p50", "_p90", "_dir"):
        table["m_best" + c] = table["m21_015" + c]
    models = (["m_best"] + list(MODELS) + list(BLENDS) + ["m10_m4_agree", "r1_season", "r2_q05", "r12_season_q05", "r3_q025", "r4_q05_3y", *recipe_names, "m7_seasonal_3y", "m7_seasonal_5y", "b_unchanged",
                                             "c_seasonal", "a_futures"])
    results = score(table, models)
    suffix = f"{args.stamp}-{args.tag}" if args.tag else args.stamp
    out_dir = HERE / "data" / ("experiments" if args.tag else "")
    out_dir.mkdir(parents=True, exist_ok=True)
    table.to_csv(HERE / "data" / f"m-predictions-{suffix}.csv", index=False)
    lines = [f"M backtest {suffix}: issues {table['issue'].min()}..{table['issue'].max()}, scored from {FIRST_SCORED};"
             f" DEV < {DEV_END} <= HOLD. MAE of the monthly mean FI price, EUR/MWh VAT 0.",
             f"{'model':18} {'period':6} {'h':>3} {'n':>5} {'MAE':>7} {'MALE':>6} {'dir':>5} {'wrong':>6} {'calls':>6} {'w/call':>6} {'cov80':>6} {'pinb':>6}"]
    for r in results:
        lines.append(f"{r['model']:18} {r['period']:6} {r['h']:>3} {r['n']:5} {r['mae']:7.2f} {r['male']:6.3f} {r['dir_hit']:5.2f} "
                     f"{r['wrong_way']:6.2f} {r['calls']:6.2f} {r['wrong_in_calls']:6.2f} {r.get('cover80', float('nan')):6.2f} {r.get('pinball', float('nan')):6.2f}")
    report = (out_dir if args.tag else HERE) / f"m-backtest-{suffix}.txt"
    report.write_text("\n".join(lines) + "\n")
    (out_dir if args.tag else HERE).joinpath(f"m-backtest-results-{suffix}.json").write_text(
        json.dumps({"inputs": pins, "models": MODELS, "results": results}, indent=1, default=str) + "\n")
    print("\n".join(lines))


if __name__ == "__main__":
    main()
