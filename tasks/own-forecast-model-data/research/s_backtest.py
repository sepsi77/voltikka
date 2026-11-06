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
import time
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.linear_model import LinearRegression, Ridge

HERE = Path(__file__).resolve().parent
HEL = ZoneInfo("Europe/Helsinki")
WINTER = {11, 12, 1, 2, 3}
QUANTILES = (0.1, 0.5, 0.9)
NUCLEAR_NOMINAL_MW = 4372.0  # Loviisa 1+2 (2 x 496) + Olkiluoto 1+2 (2 x 890) + Olkiluoto 3 (1600)
FIRST_ISSUE = date(2023, 1, 15)  # Fingrid from 2023-01-01, and 14 days of price history
FIRST_BLOCK = date(2024, 1, 1)
FUTURES_FROM = date(2026, 4, 9)  # first issue with a FI futures trade date (2026-04-08) before it
MODEL_PARAMS = dict(max_iter=300, learning_rate=0.05, max_leaf_nodes=31, min_samples_leaf=100,
                    l2_regularization=1.0, random_state=0)

H_FEATURES = [
    "k", "hour", "dup", "dow", "month", "doy", "holiday",
    "p_last_week", "p_same_hour_d", "p_same_hour_d1",
    "fi_mean_d", "fi_min_d", "fi_max_d", "fi_mean_7", "fi_std_7", "fi_mean_28",
    "se1_mean_d", "se3_mean_d", "ee_mean_d", "de_mean_d", "se3_mean_7", "de_mean_7",
    "fg_wind_24", "fg_cons_24", "fg_nuclear_24", "fg_hydro_24", "fg_net_import_24", "fg_wind_cap",
    "nuclear_plan_mw", "cap_se1_plan", "cap_se3_plan", "cap_ee_plan", "fut_month",
]
P_FEATURES = H_FEATURES + ["wind_fc", "wind_fc_day", "wind_fc_share", "temp_day"]
W_FEATURES = ["om_fi_temp", "om_fi_wind", "om_fi_windp", "om_se_wind", "om_ee_wind", "om_dkde_wind",
              "om_fi_temp_day", "om_fi_windp_day", "om_lead"]
# Added 2026-11-02 after external modelling advice (decisions.md): a southern Finland demand
# temperature, a structural net-load estimate (add_netload) and a linear LEAR-type baseline.
SOUTH_FEATURES = ["om_fi_temp_south", "om_fi_temp_south_day"]
NETLOAD_FEATURES = ["netload_exp", "resid_load", "resid_load_day", "import_need", "import_need_day"]
# Border capacity (s_border_capacity.py; user request 2026-11-02): as-of normal day-ahead capacity
# per border (median of the last 7 known days), import borders limited by the A78 outage plans.
CAPACITY_FEATURES = ["imp_cap", "exp_cap", "imp_se1", "imp_se3", "imp_ee", "flow_based"]
IMPORT_BORDERS = ("SE1_FI", "SE3_FI", "EE_FI")
EXPORT_BORDERS = ("FI_SE1", "FI_SE3", "FI_EE")
FLOW_BASED_FROM = date(2024, 10, 30)  # first flow-based delivery day; JAO maxBorderFlow, not NTC
WX_COLS = ("fi_temp", "fi_temp_south", "fi_wind", "fi_windp", "se_wind", "ee_wind", "dkde_wind")
# Price-like inputs of the linear model: asinh(x / 10) like the LEAR model (Lago et al. 2021),
# so that spikes do not dominate a squared loss.
PRICE_COLS = {"p_last_week", "p_same_hour_d", "p_same_hour_d1", "fi_mean_d", "fi_min_d", "fi_max_d",
              "fi_mean_7", "fi_std_7", "fi_mean_28", "se1_mean_d", "se3_mean_d", "ee_mean_d", "de_mean_d",
              "se3_mean_7", "de_mean_7", "fut_month"}
LINEAR_CANDIDATES = {}
LINEAR_PER_HOUR = {"l_lear_hour", "l_lear_k"}  # E15b
# E27 (Codex 2026-11-06): horizon-group slopes in the ridge: these inputs also enter multiplied by
# the D+2..D+4 and D+5..D+7 indicators, so their effect can change with the horizon.
LINEAR_INTERACT = {"l_lear_k": ["resid_load", "import_need", "om_fi_temp_south", "om_se_wind", "om_ee_wind",
                                "om_dkde_wind", "om_fi_windp"]}
CANDIDATE_OPTS = {}  # per-candidate fit options: k_groups, half_life_days (fit_predict)
# E7: wind in the stage-1 net load: "proxy" (10 m wind power proxy x capacity) or "stage1" (a
# capacity-factor model from the 100 m wind forecast at the 29 FI stations, add_wind).
NETLOAD_WIND = {"mode": "proxy"}
# E8: "previous" = Open-Meteo Previous Runs (a run 24*N h before each valid hour); "single" = the
# 00 UTC ECMWF HRES run of the issue day D (weather_single_runs.csv, from 2024-03-14), which
# replaces the om_* values of every issue day it covers (older issues keep "previous").
WEATHER_SOURCE = {"mode": "previous"}
NETLOAD_DESIGN = {"interact": False}  # E29
WIND_SHRINK = {"on": False}  # E28
WIND_FEATURES = ["wind_mw_fc", "wind_mw_fc_day", "solar_proxy", "om_ghi_fi"]
NETLOAD_FIT = []
CANDIDATES = {"h_asof": H_FEATURES, "p_standin": P_FEATURES}
# w_forecast (added when the export has weather_previous_runs.csv): h_asof + historical Open-Meteo
# forecasts as issued (Previous Runs API, from 2024-01). Honest: per target hour the smallest lead
# day N whose forecast was public at the issue time (run time = valid time - 24 N h, plus
# WEATHER_PUBLISH_LAG); no such N <= 7 leaves the features empty (most of D+7).
# Level/shape candidates (differentiator 3): a daily-level model on one row per target day and an
# hourly-shape model on the deviation from the day mean. p50 = level p50 + shape p50; the range
# combines both spreads as independent errors and is then calibrated with *_scal only.
LEVEL_EXTRA = ["lw_day_mean", "nuclear_plan_day", "cap_se1_day", "cap_se3_day", "cap_ee_day"]
LS_LEVEL = [c for c in H_FEATURES if c not in ("hour", "dup", "p_last_week", "p_same_hour_d", "p_same_hour_d1",
                                               "nuclear_plan_mw", "cap_se1_plan", "cap_se3_plan", "cap_ee_plan")] + LEVEL_EXTRA
LS_SHAPE = ["k", "hour", "dup", "dow", "month", "doy", "holiday", "fi_mean_d", "fi_std_7", "shape_d", "shape_d1", "shape_lw"]
LS_CANDIDATES = {"ls_asof": (LS_LEVEL, LS_SHAPE)}
WEATHER_PUBLISH_LAG = 7 * 3600
WEATHER_FROM = date(2024, 7, 1)  # first issue scored in the "weather_period" bucket (6 months of training data)
CALIBRATION_DAYS = 91  # last training days held back to calibrate the p10-p90 range
CAL_FACTORS = {}


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


def finnish_holidays(year):
    """Same rules as laravel/app/Support/FinnishHolidays.php: 1 = public holiday, 2 = eve."""
    a, b, c = year % 19, year // 100, year % 100
    d, e = b // 4, b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i, kk = c // 4, c % 4
    l = (32 + 2 * e + 2 * i - h - kk) % 7
    m = (a + 11 * h + 22 * l) // 451
    month = (h + l - 7 * m + 114) // 31
    day = (h + l - 7 * m + 114) % 31 + 1
    easter = date(year, month, day)

    def saturday_from(mo, da):
        x = date(year, mo, da)
        return x + timedelta(days=(5 - x.weekday()) % 7)

    midsummer = saturday_from(6, 20)
    days = {date(year, 1, 1): 1, date(year, 1, 6): 1, easter - timedelta(days=2): 1, easter: 1,
            easter + timedelta(days=1): 1, date(year, 5, 1): 1, easter + timedelta(days=39): 1,
            easter + timedelta(days=49): 1, midsummer - timedelta(days=1): 2, midsummer: 1,
            saturday_from(10, 31): 1, date(year, 12, 6): 1, date(year, 12, 24): 2,
            date(year, 12, 25): 1, date(year, 12, 26): 1}
    return days


def hourly_prices(spot):
    spot = spot.assign(eur=spot["price_without_tax"] * 10.0)  # c/kWh -> EUR/MWh
    wide = spot.pivot(index="timestamp", columns="region", values="eur").sort_index()
    local = pd.to_datetime(wide.index, unit="s", utc=True).tz_convert(HEL)
    wide["ldate"] = [x.date() for x in local]
    wide["hour"] = local.hour
    # dup = 1 for the repeated hour of the autumn DST change, so that hour keeps its own key.
    wide["dup"] = wide.groupby(["ldate", "hour"]).cumcount()
    return wide


def parse_outages(outages):
    """Return per document a list of revisions (revision, created_utc, cancelled, rows)."""
    docs = defaultdict(dict)
    for r in outages.itertuples(index=False):
        created = pd.Timestamp(r.document_created_at, tz="UTC").timestamp()
        periods = [(pd.Timestamp(p["start"], tz="UTC").timestamp(), pd.Timestamp(p["end"], tz="UTC").timestamp(),
                    float(p["mw"])) for p in json.loads(r.available_periods)]
        rev = docs[r.document_mrid].setdefault(r.revision_number, {
            "created": created, "cancelled": bool(r.is_cancelled) or r.doc_status == "A09", "rows": []})
        rev["created"] = min(rev["created"], created)
        rev["cancelled"] = rev["cancelled"] or bool(r.is_cancelled) or r.doc_status == "A09"
        rev["rows"].append({"type": r.document_type, "zone": r.zone, "resource": r.resource_name,
                            "unit": r.unit_name if isinstance(r.unit_name, str) else "",
                            "nominal": float(r.nominal_mw) if not pd.isna(r.nominal_mw) else None,
                            "periods": periods})
    return {k: sorted(v.items()) for k, v in docs.items()}


def outage_features(docs, issue_ts, hour_ts, nominal=NUCLEAR_NOMINAL_MW):
    """As-of planned nuclear MW and import border capacities for the given UTC hour starts."""
    mids = hour_ts + 1800.0
    lo, hi = mids.min(), mids.max()
    unit_unavail = defaultdict(lambda: np.zeros(len(mids)))
    plant_unavail = defaultdict(lambda: np.zeros(len(mids)))
    border = {z: np.full(len(mids), np.nan) for z in ("SE1_FI", "SE3_FI", "EE_FI")}
    for revisions in docs.values():
        current = None
        for _num, rev in revisions:
            if rev["created"] <= issue_ts:
                current = rev
        if current is None or current["cancelled"]:
            continue
        for row in current["rows"]:
            for start, end, mw in row["periods"]:
                if end <= lo or start > hi:
                    continue
                hit = (mids >= start) & (mids < end)
                if not hit.any():
                    continue
                if row["type"] == "A78":
                    b = border[row["zone"]]
                    b[hit] = np.fmin(b[hit], mw)
                elif row["nominal"] is not None:
                    loss = max(row["nominal"] - mw, 0.0)
                    if row["type"] == "A80":
                        arr = unit_unavail[(row["resource"], row["unit"])]
                    else:
                        arr = plant_unavail[row["resource"]]
                    arr[hit] = np.maximum(arr[hit], loss)
    by_plant = defaultdict(lambda: np.zeros(len(mids)))
    for (plant, _unit), arr in unit_unavail.items():
        by_plant[plant] = by_plant[plant] + arr
    total = np.zeros(len(mids))
    for plant in set(by_plant) | set(plant_unavail):
        total += np.maximum(by_plant.get(plant, 0.0), plant_unavail.get(plant, 0.0))
    return np.clip(nominal - total, 0, None), border


# --- feature table ------------------------------------------------------------------------------

def build_table(data, end_issue):
    px = hourly_prices(data["spot"])
    key_price = {(d, h, u): v for d, h, u, v in zip(px["ldate"], px["hour"], px["dup"], px["FI"])}
    # A day is complete when it has every hour of its Helsinki day: 24, or 23/25 on the DST days
    # (Codex review 2026-11-02: ">= 23" also accepted an ordinary day with a missing hour).
    counts = px.groupby("ldate")["FI"].count()
    def day_hours(day):
        nxt = day + timedelta(days=1)
        return round((datetime(nxt.year, nxt.month, nxt.day, tzinfo=HEL).timestamp()
                      - datetime(day.year, day.month, day.day, tzinfo=HEL).timestamp()) / 3600)
    complete = {day for day, n in counts.items() if n == day_hours(day)}
    daily = px.groupby("ldate")[["FI", "SE1", "SE3", "EE", "DE-LU"]].agg(["mean", "min", "max"])
    days_sorted = sorted(daily.index)
    fi_by_day = px.groupby("ldate")["FI"].apply(np.array).to_dict()

    fg = data["fingrid"].pivot(index="timestamp", columns="dataset_id", values="value").sort_index()
    fg_ts = fg.index.to_numpy()

    docs = parse_outages(data["outages"])
    docs_se3 = parse_outages(data["outages_se3"]) if "outages_se3" in data else None  # E18

    fut = data["futures"]
    fut = fut[fut["maturity_type"] == "month"].copy()
    fut["trade_date"] = pd.to_datetime(fut["trade_date"]).dt.date
    fut_by_maturity = {m: g.sort_values("trade_date") for m, g in fut.groupby("maturity")}

    wx = data["weather_daily"].groupby("date")["temperature_2m_mean"].mean()
    temp_by_day = {date.fromisoformat(k): v for k, v in wx.items()}

    # Baseline (c): the nordpool-predict-fi vintage at the issue time (scoring only, not a feature).
    tp = data.get("thirdparty_history")
    tp_times, tp_values = [], []
    if tp is not None:
        epoch = pd.Timestamp("1970-01-01", tz="UTC")
        tp = tp.assign(vts=(pd.to_datetime(tp["vintage_utc"], utc=True) - epoch).dt.total_seconds().astype("int64"))
        for vts, g in tp.groupby("vts", sort=True):
            tp_times.append(int(vts))
            tp_values.append(dict(zip(g["target_ts"].to_numpy(), g["eur_mwh"].to_numpy())))

    wx_runs = {}
    if "weather_previous_runs" in data:
        wcols = [c for c in WX_COLS if c in data["weather_previous_runs"]]
        for lead, g in data["weather_previous_runs"].groupby("lead_day"):
            wx_runs[int(lead)] = g.set_index("target_ts")[wcols].to_dict("index")

    # Realised net load (Fingrid 124 consumption - 75 wind) per hour: the TARGET of the stage-1
    # estimate in add_netload, never a model input.
    netload_real = (fg[124] - fg[75]) if 124 in fg and 75 in fg else pd.Series(dtype=float)
    wind_real = fg[75] if 75 in fg else pd.Series(dtype=float)
    solar_cap = pd.Series(dtype=float)
    if "fingrid_extra" in data:
        fx = data["fingrid_extra"]
        solar_cap = fx[fx["dataset_id"] == 267].set_index("timestamp")["value"].sort_index()
    lead1_south = {ts: r.get("fi_temp_south") for ts, r in wx_runs.get(1, {}).items()}

    bc = data.get("border_capacity")
    if bc is not None:
        bc = bc.pivot_table(index="timestamp", columns="border", values="capacity_mw").sort_index()
        bc_ts = bc.index.to_numpy()

    holidays = {}
    for y in range(2022, 2028):
        holidays.update(finnish_holidays(y))

    hour_index = px.index.to_numpy()
    by_day_rows = px.groupby("ldate").indices

    rows = []
    d = FIRST_ISSUE
    while d <= end_issue:
        kd = d + timedelta(days=1) if ISSUE["after_prices"] else d  # last day with known prices
        targets = [kd + timedelta(days=k) for k in range(1, 8)]
        if d not in complete or kd not in complete or any(t not in complete for t in targets):
            d += timedelta(days=1)
            continue
        issue_ts, fg_cut = issue_time(d)
        week = [x for x in (kd - timedelta(days=i) for i in range(7)) if x in fi_by_day]
        month = [x for x in (kd - timedelta(days=i) for i in range(28)) if x in fi_by_day]
        last7 = np.concatenate([fi_by_day[x] for x in week])
        # E19: median price per Helsinki hour over the last 7 known days (robust daily shape).
        hour_med7 = {}
        for x in week:
            for hh, uu, v in zip(px["hour"].iloc[by_day_rows[x]], px["dup"].iloc[by_day_rows[x]], fi_by_day[x]):
                if uu == 0:
                    hour_med7.setdefault(hh, []).append(v)
        hour_med7 = {hh: float(np.median(v)) for hh, v in hour_med7.items()}
        se3_7 = np.nanmean([daily.loc[x, ("SE3", "mean")] for x in week])
        de_7 = np.nanmean([daily.loc[x, ("DE-LU", "mean")] for x in week])
        win = fg.loc[(fg_ts >= fg_cut - 24 * 3600) & (fg_ts < fg_cut)]
        cap = fg.loc[fg_ts < fg_cut, 268].dropna()
        # As-of demand level: Fingrid 124 per UTC hour of day over the 7 days before the cut, and
        # the southern temperature of the same days from the lead-1 forecasts (public >= 17 h
        # before each hour, so all known at the issue time).
        week_win = fg.loc[(fg_ts >= fg_cut - 7 * 86400) & (fg_ts < fg_cut)]
        cons_hod = week_win[124].groupby((week_win.index.to_numpy() // 3600) % 24).mean() if 124 in fg else pd.Series(dtype=float)
        south7 = [lead1_south.get(int(t)) for t in range(int(fg_cut) - 7 * 86400, int(fg_cut), 3600)]
        south7 = [v for v in south7 if v is not None and not pd.isna(v)]
        issue_feats = {
            "fi_mean_d": daily.loc[kd, ("FI", "mean")], "fi_min_d": daily.loc[kd, ("FI", "min")],
            "fi_max_d": daily.loc[kd, ("FI", "max")], "fi_mean_7": last7.mean(), "fi_std_7": last7.std(),
            "fi_mean_28": np.concatenate([fi_by_day[x] for x in month]).mean(),
            "se1_mean_d": daily.loc[kd, ("SE1", "mean")], "se3_mean_d": daily.loc[kd, ("SE3", "mean")],
            "ee_mean_d": daily.loc[kd, ("EE", "mean")], "de_mean_d": daily.loc[kd, ("DE-LU", "mean")],
            "se3_mean_7": se3_7, "de_mean_7": de_7,
            "fg_wind_24": win[75].mean() if len(win) >= 12 else np.nan,
            "fg_cons_24": win[124].mean() if len(win) >= 12 else np.nan,
            "fg_nuclear_24": win[188].mean() if len(win) >= 12 else np.nan,
            "fg_hydro_24": win[191].mean() if len(win) >= 12 else np.nan,
            "fg_net_import_24": win[194].mean() if len(win) >= 12 else np.nan,
            "fg_wind_cap": cap.iloc[-1] if len(cap) else np.nan,
            "temp_south_7": float(np.mean(south7)) if len(south7) >= 120 else np.nan,
            "solar_cap": float(solar_cap[solar_cap.index < fg_cut].iloc[-1]) if (solar_cap.index < fg_cut).any() else np.nan,
        }
        # Border capacity is published the day before delivery: known through the end of D.
        normal = {}
        if bc is not None:
            nxt = kd + timedelta(days=1)
            known_end = datetime(nxt.year, nxt.month, nxt.day, tzinfo=HEL).timestamp()
            hi = np.searchsorted(bc_ts, known_end)
            last = bc.iloc[np.searchsorted(bc_ts, known_end - 7 * 86400):hi]
            normal = last.median().to_dict()
        idx = np.concatenate([by_day_rows[t] for t in targets])
        hts = hour_index[idx].astype(float)
        nuclear, border = outage_features(docs, issue_ts, hts)
        # E18: planned SE3 nuclear unavailability (MW), as-of; computed against a large nominal so
        # that the result is the sum of losses.
        se3_unavail = (1e6 - outage_features(docs_se3, issue_ts, hts, nominal=1e6)[0]) if docs_se3 else np.full(len(hts), np.nan)
        imp = {b: np.fmin(np.full(len(hts), normal.get(b, np.nan)), border[b]) for b in IMPORT_BORDERS}
        exp_cap = sum(normal.get(b, np.nan) for b in EXPORT_BORDERS)
        pos = bisect.bisect_right(tp_times, issue_ts) - 1
        vintage = tp_values[pos] if pos >= 0 else {}
        tgt = px.iloc[idx]
        wind_fc = fg[246].reindex(hts.astype(int)).to_numpy() if 246 in fg else np.full(len(idx), np.nan)
        cap_now = issue_feats["fg_wind_cap"]
        day_of = np.array(tgt["ldate"])
        wind_day = pd.Series(wind_fc).groupby(day_of).transform("mean").to_numpy()
        fut_for = {}
        for t in targets:
            key = int(f"{t.year}{t.month:02d}")
            if key in fut_for:
                continue
            fut_for[key] = np.nan
            g = fut_by_maturity.get(key)
            if g is not None and d >= FUTURES_FROM:
                g = g[g["trade_date"] <= d - timedelta(days=1)]
                if len(g):
                    fut_for[key] = float(g["settlement_price"].iloc[-1])
        for j, (ts, row) in enumerate(zip(hts, tgt.itertuples())):
            t = row.ldate
            k = (t - kd).days
            fut_month = fut_for[int(f"{t.year}{t.month:02d}")]
            rows.append({
                "issue": d, "target_ts": int(ts), "tdate": t, "k": k, "hour": row.hour, "dup": row.dup,
                "dow": t.weekday(), "month": t.month, "doy": t.timetuple().tm_yday, "holiday": holidays.get(t, 0),
                "y": row.FI,
                "p_last_week": key_price.get((t - timedelta(days=7), row.hour, row.dup), np.nan),
                "p_same_hour_d": key_price.get((kd, row.hour, row.dup), np.nan),
                "p_same_hour_d1": key_price.get((kd - timedelta(days=1), row.hour, row.dup), np.nan),
                "p_hour_med7": hour_med7.get(row.hour, np.nan),
                **issue_feats,
                "nuclear_plan_mw": nuclear[j], "se3_nuc_unavail": se3_unavail[j], "cap_se1_plan": border["SE1_FI"][j],
                "cap_se3_plan": border["SE3_FI"][j], "cap_ee_plan": border["EE_FI"][j],
                "fut_month": fut_month,
                "wind_fc": wind_fc[j], "wind_fc_day": wind_day[j],
                "wind_fc_share": wind_fc[j] / cap_now if cap_now and not np.isnan(cap_now) else np.nan,
                "temp_day": temp_by_day.get(t, np.nan),
                "p_thirdparty": vintage.get(int(ts), np.nan),
                "cons_hod_7": cons_hod.get((int(ts) // 3600) % 24, np.nan),
                "netload_real": netload_real.get(int(ts), np.nan),
                "wind_real": wind_real.get(int(ts), np.nan),
                "imp_se1": imp["SE1_FI"][j], "imp_se3": imp["SE3_FI"][j], "imp_ee": imp["EE_FI"][j],
                "imp_cap": imp["SE1_FI"][j] + imp["SE3_FI"][j] + imp["EE_FI"][j], "exp_cap": exp_cap,
                "flow_based": float(d >= FLOW_BASED_FROM),
                **weather_forecast(wx_runs, int(ts), issue_ts),
            })
        d += timedelta(days=1)
    table = pd.DataFrame(rows)
    g = table.groupby(["issue", "tdate"])
    table["lw_day_mean"] = g["p_last_week"].transform("mean")
    for col, day in (("nuclear_plan_mw", "nuclear_plan_day"), ("cap_se1_plan", "cap_se1_day"),
                     ("cap_se3_plan", "cap_se3_day"), ("cap_ee_plan", "cap_ee_day")):
        table[day] = g[col].transform("mean")
    table["se3_nuc_unavail_day"] = g["se3_nuc_unavail"].transform("mean")
    table["shape_d"] = table["p_same_hour_d"] - table["fi_mean_d"]
    d1_mean = table.groupby("issue")["p_same_hour_d1"].transform("mean")  # same hours of day D-1
    table["shape_d1"] = table["p_same_hour_d1"] - d1_mean
    table["shape_lw"] = table["p_last_week"] - table["lw_day_mean"]
    if "weather_previous_runs_extra" in data:
        # E7: per-point 100 m wind and FI radiation of the same as-of lead as the base forecast.
        ex = data["weather_previous_runs_extra"].rename(columns=lambda c: c if c in ("target_ts", "lead_day") else f"om_{c}")
        table = table.merge(ex, left_on=["target_ts", "om_lead"], right_on=["target_ts", "lead_day"], how="left")
        table = table.drop(columns=["lead_day"])
        table["solar_proxy"] = table["om_ghi_fi"] * table["solar_cap"] / 1000.0
    if WEATHER_SOURCE["mode"] == "single":
        table = apply_single_runs(table, data["weather_single_runs"])
    if wx_runs:
        g = table.groupby(["issue", "tdate"])
        table["om_fi_temp_day"] = g["om_fi_temp"].transform("mean")
        table["om_fi_windp_day"] = g["om_fi_windp"].transform("mean")
        table["om_fi_temp_south_day"] = g["om_fi_temp_south"].transform("mean")
    return table


def apply_single_runs(table, sr):
    """E8: replace om_* weather with the issue-day 00 UTC run where one exists. om_lead keeps the
    Previous Runs formula (target-day distance), so it means the same in both sources."""
    sr = sr.rename(columns={c: f"om_{c}" for c in sr.columns if c not in ("issue_date", "target_ts")})
    sr["issue"] = [date.fromisoformat(x) for x in sr["issue_date"]]
    cols = [c for c in sr.columns if c.startswith("om_") and c in table.columns]
    m = table[["issue", "target_ts"]].merge(sr[["issue", "target_ts"] + cols], on=["issue", "target_ts"], how="left")
    have = m["om_fi_temp"].notna().to_numpy()
    for c in cols:
        vals = table[c].to_numpy(dtype=float).copy()
        vals[have] = m[c].to_numpy(dtype=float)[have]
        table[c] = vals
    issue_ts = np.array([issue_time(d)[0] for d in table["issue"]])
    lead = np.ceil((table["target_ts"].to_numpy() - issue_ts + WEATHER_PUBLISH_LAG) / 86400)
    om_lead = table["om_lead"].to_numpy(dtype=float).copy()
    om_lead[have] = np.clip(lead[have], 1, 8 if ISSUE["after_prices"] else 7)
    table["om_lead"] = om_lead
    if "solar_cap" in table and "om_ghi_fi" in table:
        table["solar_proxy"] = table["om_ghi_fi"] * table["solar_cap"] / 1000.0
    print(f"E8 single runs: {have.mean():.3f} of rows use the issue-day 00 UTC run", flush=True)
    return table


def weather_forecast(wx_runs, ts, issue_ts):
    """As-of Open-Meteo forecast values for one target hour (empty when none was public)."""
    out = {f"om_{c}": np.nan for c in WX_COLS}
    out["om_lead"] = np.nan
    if not wx_runs:
        return out
    lead = math.ceil((ts - issue_ts + WEATHER_PUBLISH_LAG) / 86400)
    rec = wx_runs.get(lead, {}).get(ts) if 1 <= lead <= 7 else None
    if rec:
        out.update({f"om_{k}": v for k, v in rec.items()})
        out["om_lead"] = lead
    return out


# --- models and metrics ---------------------------------------------------------------------------

# E32: issue after the day-ahead result (user question 2026-11-06). False: D 11:30, prices known
# through D, targets D+1..D+7. True: D 14:30, prices known through D+1 (SDAC result about 13:45
# Helsinki), Fingrid before D 14:00, targets D+2..D+8; k counts from the first unknown day (k=1 is
# D+2), so the model parts are unchanged. Weather stays the 00 UTC run of D; futures trade date <= D-1.
ISSUE = {"after_prices": False}


def issue_time(d):
    """Issue timestamp and Fingrid cut of the Helsinki issue day d."""
    h, fh = (14, 14) if ISSUE["after_prices"] else (11, 10)
    return (datetime(d.year, d.month, d.day, h, 30, tzinfo=HEL).timestamp(),
            datetime(d.year, d.month, d.day, fh, 0, tzinfo=HEL).timestamp())


REFIT = {"months": 3, "days": None}  # E26: refit interval in months; E30: in days (overrides months)


def blocks(last_issue):
    start = FIRST_BLOCK
    step = REFIT["months"]
    while start <= last_issue:
        if REFIT["days"]:
            nxt = start + timedelta(days=REFIT["days"])
        else:
            nxt = date(start.year + (start.month + step - 1) // 12, (start.month + step - 1) % 12 + 1, 1)
        yield start, nxt
        start = nxt


def predict_block(table, start, end):
    train = table[table["tdate"] < start].copy()
    test = table[(table["issue"] >= start) & (table["issue"] < end)].copy()
    if test.empty:
        return test
    uses_netload = any(set(cols) & set(NETLOAD_FEATURES)
                       for cols in list(CANDIDATES.values()) + list(LINEAR_CANDIDATES.values()))
    if NETLOAD_WIND["mode"] == "stage1":
        add_wind(train, test, start)
    early = train[train["tdate"] < start - timedelta(days=CALIBRATION_DAYS)].copy()
    calib = train[train["tdate"] >= start - timedelta(days=CALIBRATION_DAYS)].copy()
    if uses_netload:
        add_netload(train, test, start)
        # The CQR fits must not see stage-1 values fitted on the calibration rows (Codex review
        # 2026-11-02): refit stage 1 on the early rows only for them.
        add_netload(early, calib, start, record=False)
    for name, cols in CANDIDATES.items():
        opts = CANDIDATE_OPTS.get(name, {})
        # A column without two distinct training values (fut_month before 2026-04) cannot split;
        # leave it out of that block's fit.
        cols = [c for c in cols if early[c].nunique(dropna=True) >= 2]
        for q in QUANTILES:
            test[f"{name}_p{int(q * 100)}"] = fit_predict(train, test, cols, q, opts, start)
        # Calibrated range (name_cal), conformalized quantile regression: fit the same models on the
        # training rows before the last CALIBRATION_DAYS, predict those last days, and take per
        # horizon the 0.9 quantile of the lower miss (p10 - y) and of the upper miss (y - p90).
        # Those shifts move the full-training p10 down and p90 up (or inward, if negative) for the
        # test block. A first version scaled the range by a ratio; tiny raw widths made the factor
        # explode (x30 after the 2023 autumn spikes), so the additive form is used.
        cal_start = start - timedelta(days=CALIBRATION_DAYS)
        cq = {q: fit_predict(early, calib, cols, q, opts, cal_start) for q in QUANTILES}
        yc = calib["y"].to_numpy()
        miss = pd.DataFrame({"k": calib["k"].to_numpy(), "lo": cq[0.1] - yc, "hi": yc - cq[0.9]})
        s = miss.groupby("k")[["lo", "hi"]].quantile(0.9)
        test[f"{name}_cal_p50"] = test[f"{name}_p50"]
        test[f"{name}_cal_p10"] = np.minimum(test[f"{name}_p10"] - test["k"].map(s["lo"]), test[f"{name}_p50"])
        test[f"{name}_cal_p90"] = np.maximum(test[f"{name}_p90"] + test["k"].map(s["hi"]), test[f"{name}_p50"])
        for side in ("lo", "hi"):
            CAL_FACTORS.setdefault(name, []).append({"block": str(start), "side": side, **s[side].round(1).to_dict()})
    for name, col in (("a_last_week", "p_last_week"), ("b_last_day", "p_same_hour_d"), ("c_thirdparty", "p_thirdparty")):
        test[f"{name}_p50"] = test[col]
        for q in (0.1, 0.9):
            resid = (train["y"] - train[col]).groupby(train["k"]).quantile(q)
            test[f"{name}_p{int(q * 100)}"] = test[col] + test["k"].map(resid)
    for name, (level_cols, shape_cols) in LS_CANDIDATES.items():
        level_shape(train, test, name, level_cols, shape_cols)
    for name, cols in LINEAR_CANDIDATES.items():
        linear_quantile(train, test, name, cols, per_hour=name in LINEAR_PER_HOUR)
    return test


def fit_predict(train, test, cols, q, opts, start):
    """One quantile model, or one per horizon group (opts k_groups), optionally with recency
    weights (opts half_life_days: weight 0.5 ** (age of the target day / half-life))."""
    out = np.full(len(test), np.nan)
    for lo, hi in opts.get("k_groups", [(1, 7)]):
        tr = train[train["k"].between(lo, hi)]
        te = test["k"].between(lo, hi).to_numpy()
        if not te.any():
            continue
        weight = None
        if "half_life_days" in opts:
            age = np.array([(start - d).days for d in tr["tdate"]], dtype=float)
            weight = 0.5 ** (age / opts["half_life_days"])
        # A column with no value in the training rows carries no information and makes the
        # sklearn binning fail (E30 weekly blocks, 2026-11-06); leave it out of this fit.
        use = [c for c in cols if tr[c].notna().any()]
        model = HistGradientBoostingRegressor(loss="quantile", quantile=q, **{**MODEL_PARAMS, **opts.get("params", {})})
        model.fit(tr[use], tr["y"], sample_weight=weight)
        out[te] = model.predict(test.loc[te, use])
    return out


WIND_FIT = []


def add_wind(train, test, start):
    """E7 stage-1 wind: capacity factor (Fingrid 75 / installed capacity at the issue) from the
    100 m wind forecast at the 29 FI stations, the lead day, hour and month (HGB, squared loss).
    Training rows get out-of-fold values (4 folds of interleaved 30-day blocks) so that the price
    model does not learn from in-sample stage-1 values; test rows use a fit on all training rows."""
    cols = [c for c in train.columns if c.startswith("om_ws100_fmi_")] + ["om_lead", "hour", "month"]
    for df in (train, test):
        df["wind_mw_fc"] = np.nan
        df["wind_mw_fc_day"] = np.nan
    if len(cols) < 4:
        return
    cf = train["wind_real"] / train["fg_wind_cap"]
    ok = cf.notna() & train["om_lead"].notna()
    if ok.sum() < 5000:
        return
    params = dict(max_iter=200, learning_rate=0.05, max_leaf_nodes=31, min_samples_leaf=100, random_state=0)
    fold = np.array([(d - date(2020, 1, 1)).days // 30 % 4 for d in train["tdate"]])
    oof = np.full(len(train), np.nan)
    for f in range(4):
        fit = ok.to_numpy() & (fold != f)
        pred_rows = (fold == f) & train["om_lead"].notna().to_numpy()
        if fit.sum() < 2000 or not pred_rows.any():
            continue
        m = HistGradientBoostingRegressor(**params).fit(train.loc[fit, cols], cf[fit])
        oof[pred_rows] = m.predict(train.loc[pred_rows, cols])
    oof = np.clip(oof, 0, 1)
    m = HistGradientBoostingRegressor(**params).fit(train.loc[ok, cols], cf[ok])
    te = test["om_lead"].notna().to_numpy()
    pred = np.full(len(test), np.nan)
    if te.any():
        pred[te] = np.clip(m.predict(test.loc[te, cols]), 0, 1)
    if WIND_SHRINK["on"]:
        # E28 (Codex idea 3): for D+5..D+7 move the capacity factor towards the training month x
        # hour mean by one coefficient, least squares on the out-of-fold training values.
        clim = cf[ok].groupby([train.loc[ok, "month"], train.loc[ok, "hour"]]).mean()
        c_tr = np.array([clim.get((a, b), np.nan) for a, b in zip(train["month"], train["hour"])])
        c_te = np.array([clim.get((a, b), np.nan) for a, b in zip(test["month"], test["hour"])])
        long_tr, long_te = (train["k"] >= 5).to_numpy(), (test["k"] >= 5).to_numpy()
        use = long_tr & ok.to_numpy() & ~np.isnan(oof) & ~np.isnan(c_tr)
        dx, dy = oof[use] - c_tr[use], cf.to_numpy()[use] - c_tr[use]
        lam = float(np.clip((dx * dy).sum() / (dx * dx).sum(), 0, 1)) if use.sum() > 1000 else 1.0
        for v, c, lg in ((oof, c_tr, long_tr), (pred, c_te, long_te)):
            sel = lg & ~np.isnan(v) & ~np.isnan(c)
            v[sel] = c[sel] + lam * (v[sel] - c[sel])
        print(f"E28 wind shrink {start}: lambda {lam:.3f} ({int(use.sum())} rows)", flush=True)
    train["wind_mw_fc"] = oof * train["fg_wind_cap"].to_numpy()
    test["wind_mw_fc"] = pred * test["fg_wind_cap"].to_numpy()
    for df in (train, test):
        df["wind_mw_fc_day"] = df.groupby(["issue", "tdate"])["wind_mw_fc"].transform("mean")
    err = (test["wind_mw_fc"] - test["wind_real"]).abs()
    WIND_FIT.append({"block": str(start), "test_mae_mw": round(float(err.mean()), 1) if err.notna().any() else None,
                     "test_mae_mw_by_k": err.groupby(test["k"]).mean().round(1).to_dict()})


def _netload_design(df):
    t = df["om_fi_temp_south"]
    x = pd.DataFrame({
        "cons": df["cons_hod_7"], "temp": t, "dtemp": t - df["temp_south_7"],
        "cold": np.maximum(-10.0 - t, 0.0),  # extra demand below -10 C (heat pumps lose efficiency)
        "wind_mw": df["wind_mw_fc"] if NETLOAD_WIND["mode"] == "stage1" else df["om_fi_windp"] * df["fg_wind_cap"],
        "weekend": (df["dow"] >= 5).astype(float), "holiday": (df["holiday"] == 1).astype(float),
    }, index=df.index)
    hours = pd.get_dummies(df["hour"].astype(int), prefix="h", dtype=float)
    hours = hours.reindex(columns=[f"h_{i}" for i in range(24)], fill_value=0.0)
    parts = [x, hours]
    if NETLOAD_DESIGN["interact"]:  # E29 (Codex idea 4): temperature x hour, weekend x hour
        parts.append(hours.mul(t, axis=0).add_prefix("t_"))
        parts.append(hours.mul(x["weekend"], axis=0).add_prefix("we_"))
    return pd.concat(parts, axis=1)


def add_netload(train, test, start, min_rows=2000, record=True):
    """Stage-1 structural estimate (external advice 2026-11-02): expected net load = consumption
    minus wind, MW, for each target hour, from a linear model fitted on this block's TRAINING rows
    only: last week's consumption at the same hour, the southern Finland temperature forecast (and
    its change from last week), a cold term, forecast wind power (power-curve proxy x installed
    capacity), weekend, holiday and hour. resid_load = netload_exp - planned nuclear MW. Empty where
    a weather forecast is missing (before 2024, most of D+7). The CQR calibration rows are inside
    this fit (a small in-sample effect of one linear model)."""
    xtr = _netload_design(train)
    ok = xtr.notna().all(axis=1) & train["netload_real"].notna()
    for df in (train, test):
        for c in NETLOAD_FEATURES:
            df[c] = np.nan
    if ok.sum() < min_rows:
        return
    model = LinearRegression(fit_intercept=False).fit(xtr[ok], train.loc[ok, "netload_real"])
    for df, x in ((train, xtr), (test, _netload_design(test))):
        good = x.notna().all(axis=1).to_numpy()
        pred = np.full(len(df), np.nan)
        pred[good] = model.predict(x[good])
        df["netload_exp"] = pred
        df["resid_load"] = pred - df["nuclear_plan_mw"].to_numpy()
        df["resid_load_day"] = df.groupby(["issue", "tdate"])["resid_load"].transform("mean")
        # What domestic hydro, CHP and reserves must cover after the full import capacity: the
        # tightness signal (a positive value means import alone cannot balance the hour).
        df["import_need"] = df["resid_load"] - df["imp_cap"]
        df["import_need_day"] = df.groupby(["issue", "tdate"])["import_need"].transform("mean")
    if not record:
        return
    err = (test["netload_exp"] - test["netload_real"]).abs()
    NETLOAD_FIT.append({"block": str(start), "train_rows": int(ok.sum()), "test_rows": int(err.notna().sum()),
                        "test_mae_mw": round(float(err.mean()), 1) if err.notna().any() else None,
                        "test_mae_mw_by_k": err.groupby(test["k"]).mean().round(1).to_dict()})


def linear_quantile(train, test, name, cols, per_hour=False):
    """LEAR-type linear baseline (external advice 2026-11-02): one ridge model (alpha 1, fixed) on
    asinh(price / 10), price-like inputs also asinh(x / 10), one-hot hour, weekday, month and
    horizon, missing values = training median plus a missing flag, standardised on training rows.
    LEAR proper fits one LASSO per hour; this is a simpler single model. Range: training residual
    quantiles per horizon in the asinh space, transformed back."""
    cols = [c for c in cols if c not in ("hour", "dow", "month", "k") and train[c].nunique(dropna=True) >= 2]
    # Guard (2026-11-02): a linear model whose training rows mostly lack the weather forecast
    # extrapolates wildly (2024 Q1: MAE 389). Give no prediction then; s_best uses the tree alone.
    if "om_lead" in train and train["om_lead"].notna().mean() < 0.3:
        for q in (10, 50, 90):
            test[f"{name}_p{q}"] = np.nan
            test[f"{name}_cal_p{q}"] = np.nan
        return

    def frame(df):
        x = df[cols].astype(float).copy()
        for c in cols:
            if c in PRICE_COLS:
                x[c] = np.arcsinh(x[c] / 10.0)
        return x

    xtr, xte = frame(train), frame(test)
    med = xtr.median()
    flags = [c for c in cols if xtr[c].isna().any()]
    parts = []
    for x, df in ((xtr, train), (xte, test)):
        miss = x[flags].isna().astype(float).add_suffix("_na")
        cat = pd.concat([pd.get_dummies(df[c].astype(int), prefix=c, dtype=float) for c in ("hour", "dow", "month", "k")], axis=1)
        xf = x.fillna(med)
        inter = {}
        for c in LINEAR_INTERACT.get(name, []):
            if c in xf:
                for lo, hi in ((2, 4), (5, 7)):
                    inter[f"{c}_k{lo}{hi}"] = xf[c] * df["k"].between(lo, hi).astype(float)
        parts.append(pd.concat([xf, miss, cat, pd.DataFrame(inter, index=xf.index)], axis=1))
    xtr, xte = parts[0], parts[1].reindex(columns=parts[0].columns, fill_value=0.0)
    mu, sd = xtr.mean(), xtr.std().replace(0, 1.0)
    xtr, xte = (xtr - mu) / sd, (xte - mu) / sd
    ytr = np.arcsinh(train["y"].to_numpy() / 10.0)
    if per_hour:  # E15b: LEAR-like, one ridge per Helsinki hour of the day
        fit_tr, fit_te = np.zeros(len(train)), np.zeros(len(test))
        for h in range(24):
            a, b = (train["hour"] == h).to_numpy(), (test["hour"] == h).to_numpy()
            if a.sum() < 50:
                continue
            m = Ridge(alpha=1.0).fit(xtr[a], ytr[a])
            fit_tr[a] = m.predict(xtr[a])
            if b.any():
                fit_te[b] = m.predict(xte[b])
    else:
        model = Ridge(alpha=1.0).fit(xtr, ytr)
        fit_tr, fit_te = model.predict(xtr), model.predict(xte)
    # E12: keep predictions inside the training target range in asinh space (unbounded values
    # exploded after sinh in early blocks and winter).
    fit_te = np.clip(fit_te, ytr.min(), ytr.max())
    resid = pd.Series(ytr - fit_tr).groupby(train["k"].to_numpy())
    test[f"{name}_p50"] = 10.0 * np.sinh(fit_te)
    for q in (0.1, 0.9):
        test[f"{name}_p{int(q * 100)}"] = 10.0 * np.sinh(np.clip(fit_te + test["k"].map(resid.quantile(q)).to_numpy(),
                                                                 ytr.min(), ytr.max()))
    for q in (10, 50, 90):  # no hold-out calibration here: *_scal falls back to raw
        test[f"{name}_cal_p{q}"] = test[f"{name}_p{q}"]


def level_shape(train, test, name, level_cols, shape_cols):
    level_cols = [c for c in level_cols if train[c].nunique(dropna=True) >= 2]
    shape_cols = [c for c in shape_cols if train[c].nunique(dropna=True) >= 2]
    keys = ["issue", "tdate"]
    day_train = train.groupby(keys)[level_cols + ["y"]].mean()
    day_test = test.groupby(keys)[level_cols].mean()
    level = {}
    for q in QUANTILES:
        m = HistGradientBoostingRegressor(loss="quantile", quantile=q, **MODEL_PARAMS)
        m.fit(day_train[level_cols], day_train["y"])
        level[q] = pd.Series(m.predict(day_test[level_cols]), index=day_test.index)
    dev = train["y"] - train.groupby(keys)["y"].transform("mean")
    shape = {}
    for q in QUANTILES:
        m = HistGradientBoostingRegressor(loss="quantile", quantile=q, **MODEL_PARAMS)
        m.fit(train[shape_cols], dev)
        shape[q] = m.predict(test[shape_cols])
    idx = pd.MultiIndex.from_frame(test[keys])
    lv = {q: level[q].reindex(idx).to_numpy() for q in QUANTILES}
    p50 = lv[0.5] + shape[0.5]
    test[f"{name}_p50"] = p50
    test[f"{name}_p10"] = p50 - np.sqrt((lv[0.5] - lv[0.1]).clip(0) ** 2 + (shape[0.5] - shape[0.1]).clip(0) ** 2)
    test[f"{name}_p90"] = p50 + np.sqrt((lv[0.9] - lv[0.5]).clip(0) ** 2 + (shape[0.9] - shape[0.5]).clip(0) ** 2)
    for q in (10, 50, 90):  # no hold-out calibration for this candidate: *_scal falls back to raw
        test[f"{name}_cal_p{q}"] = test[f"{name}_p{q}"]


def seasonal_calibration(pred, name, min_rows=500):
    """name_scal: p10/p90 shifted by the 0.9 quantile of the out-of-sample misses of EARLIER
    blocks, same season and horizon, targets within the 365 days before the block start (all
    known at the block start). Falls back to name_cal where fewer than min_rows misses exist."""
    season = np.where(pred["month"].isin(list(WINTER)), "winter", "summer")
    lo_all = pred[f"{name}_p10"] - pred["y"]
    hi_all = pred["y"] - pred[f"{name}_p90"]
    p10 = pred[f"{name}_cal_p10"].copy()
    p90 = pred[f"{name}_cal_p90"].copy()
    for start, _end in blocks(pred["issue"].max()):
        known = (pred["tdate"] < start) & (pred["tdate"] >= start - timedelta(days=365))
        block = (pred["issue"] >= start) & (pred["issue"] < _end)
        for sea in ("winter", "summer"):
            for k in range(1, 8):
                src = known & (season == sea) & (pred["k"] == k)
                dst = block & (season == sea) & (pred["k"] == k)
                if src.sum() < min_rows or not dst.any():
                    continue
                p10[dst] = np.minimum(pred.loc[dst, f"{name}_p10"] - lo_all[src].quantile(0.9), pred.loc[dst, f"{name}_p50"])
                p90[dst] = np.maximum(pred.loc[dst, f"{name}_p90"] + hi_all[src].quantile(0.9), pred.loc[dst, f"{name}_p50"])
    pred[f"{name}_scal_p10"], pred[f"{name}_scal_p50"], pred[f"{name}_scal_p90"] = p10, pred[f"{name}_p50"], p90


def compose_best(pred):
    """s_best: D+1 = best_d1; D+2..D+4 = 0.5 best_d24 + 0.5 l_lear_hour (p50), the best_d24 range
    moved by the same change; D+5..D+7 likewise with best_d57 (E12b/E15b/E18/E22). E27 (l_lear_k
    for D+2..D+4) was kept with the quarterly refit, then removed: no gain with the monthly refit."""
    need = ["best_d1", "best_d24", "best_d57", "l_lear_hour"]
    if not all(f"{n}_p50" in pred for n in need):
        return False
    for suffix in ("_p50", "_scal_p10", "_scal_p90", "_p10", "_p90"):
        col = pd.Series(np.nan, index=pred.index)
        k1 = pred["k"] == 1
        col[k1] = pred.loc[k1, f"best_d1{suffix}"]
        for part, lin, (lo, hi) in (("best_d24", "l_lear_hour", (2, 4)), ("best_d57", "l_lear_hour", (5, 7))):
            m = pred["k"].between(lo, hi)
            shift = (0.5 * (pred.loc[m, f"{lin}_p50"] - pred.loc[m, f"{part}_p50"])).fillna(0.0)
            col[m] = pred.loc[m, f"{part}{suffix}"] + shift
        pred[f"s_best{suffix}"] = col
    pred["s_best_cal_p10"], pred["s_best_cal_p90"] = pred["s_best_p10"], pred["s_best_p90"]
    pred["s_best_scal_p50"] = pred["s_best_p50"]
    return True


def risk_calibration(pred, name, min_rows=300):
    """E14 (Codex #3): like seasonal_calibration, but the misses are also grouped by a risk band:
    the import-need tercile of the earlier misses (as-of: edges from those rows only) and a cold
    flag (southern temperature forecast below -5 C). A group with fewer than min_rows misses falls
    back to the season + horizon shift. Output: a twin model '<name>r' (same p50)."""
    season = np.where(pred["month"].isin(list(WINTER)), "winter", "summer")
    cold = (pred["om_fi_temp_south"] < -5).to_numpy() if "om_fi_temp_south" in pred else np.zeros(len(pred), bool)
    need = pred["import_need"].to_numpy() if "import_need" in pred else np.full(len(pred), np.nan)
    lo_all = pred[f"{name}_p10"] - pred["y"]
    hi_all = pred["y"] - pred[f"{name}_p90"]
    p10 = pred[f"{name}_scal_p10"].copy()
    p90 = pred[f"{name}_scal_p90"].copy()
    for start, _end in blocks(pred["issue"].max()):
        known = ((pred["tdate"] < start) & (pred["tdate"] >= start - timedelta(days=365))).to_numpy()
        block = ((pred["issue"] >= start) & (pred["issue"] < _end)).to_numpy()
        ref = need[known & ~np.isnan(need)]
        if len(ref) < 3 * min_rows:
            continue
        edges = np.quantile(ref, [1 / 3, 2 / 3])
        band = np.where(np.isnan(need), -1, np.digitize(need, edges))
        for sea in ("winter", "summer"):
            for k in range(1, 8):
                for b in (0, 1, 2):
                    for c in (False, True):
                        g = (season == sea) & (pred["k"] == k).to_numpy() & (band == b) & (cold == c)
                        src, dst = known & g, block & g
                        if src.sum() < min_rows or not dst.any():
                            continue
                        p10[dst] = np.minimum(pred.loc[dst, f"{name}_p10"] - lo_all[src].quantile(0.9), pred.loc[dst, f"{name}_p50"])
                        p90[dst] = np.maximum(pred.loc[dst, f"{name}_p90"] + hi_all[src].quantile(0.9), pred.loc[dst, f"{name}_p50"])
    pred[f"{name}r_p50"] = pred[f"{name}_p50"]
    pred[f"{name}r_scal_p10"], pred[f"{name}r_scal_p90"] = p10, p90
    pred[f"{name}r_p10"], pred[f"{name}r_p90"] = pred[f"{name}_p10"], pred[f"{name}_p90"]


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
