"""Shared S model code: as-of feature table, model parts, range calibration (s_v1).

Moved verbatim from tasks/own-forecast-model-data/research/s_backtest.py (commit 237a9e4, SHA-256
4e5e8fc2...), so that the backtest and the production forecast run the same functions. The
backtest imports everything from here; a change here is a change of the frozen model and needs a
new model version and a new replay proof (tasks/own-forecast-model-data/s-model-freeze.md).

Module-level dicts (ISSUE, WEATHER_SOURCE, NETLOAD_WIND, REFIT, CANDIDATES, ...) are settings that
the caller sets before it builds a table or fits; they are mutated in place, never rebound.
"""
import bisect
import json
import math
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.linear_model import LinearRegression, Ridge
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

def build_table(data, end_issue, first_issue=None, targets_known_before=None):
    """As-of feature table, one row per (issue, target hour). Defaults = the backtest.

    first_issue: first issue day (default FIRST_ISSUE). targets_known_before (production,
    2026-11-07): only target days before this date must have complete prices; later target days
    are kept with y = NaN, so a live issue (all targets in the future) and the last issues before a
    refit (some targets after it) get their rows. Their hours must be in data["spot"] (FI rows
    with an empty price for future hours). The backtest leaves it None: every target complete."""
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
    # Through 2100: the research range ended in 2027, so a live issue in 2028 would have lost its
    # holidays without an error (2026-11-07; same values for every earlier date).
    for y in range(2022, 2101):
        holidays.update(finnish_holidays(y))

    hour_index = px.index.to_numpy()
    by_day_rows = px.groupby("ldate").indices

    rows = []
    d = FIRST_ISSUE if first_issue is None else first_issue
    while d <= end_issue:
        kd = d + timedelta(days=1) if ISSUE["after_prices"] else d  # last day with known prices
        targets = [kd + timedelta(days=k) for k in range(1, 8)]
        must_know = targets if targets_known_before is None else [t for t in targets if t < targets_known_before]
        if d not in complete or kd not in complete or any(t not in complete for t in must_know):
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
    return apply_block(fit_block(train, start), test)


def fit_block(train, start):
    """Fit every part of one block on its training rows (target day before start). The test rows
    are not needed: apply_block(state, test) predicts them. predict_block() is exactly
    apply_block(fit_block()), so the backtest also proves the production fit/predict split
    (2026-11-07; the order of the fits is the one of the earlier one-pass predict_block).
    train gets the stage-1 columns written in place."""
    state = {"start": start, "candidates": {}, "baselines": {}, "ls": {}, "linear": {}}
    uses_netload = any(set(cols) & set(NETLOAD_FEATURES)
                       for cols in list(CANDIDATES.values()) + list(LINEAR_CANDIDATES.values()))
    state["wind"] = wind_fit(train, start) if NETLOAD_WIND["mode"] == "stage1" else None
    early = train[train["tdate"] < start - timedelta(days=CALIBRATION_DAYS)].copy()
    calib = train[train["tdate"] >= start - timedelta(days=CALIBRATION_DAYS)].copy()
    state["netload"] = None
    if uses_netload:
        state["netload"] = netload_fit(train)
        # The CQR fits must not see stage-1 values fitted on the calibration rows (Codex review
        # 2026-11-02): refit stage 1 on the early rows only for them.
        netload_apply(netload_fit(early), calib, start, record=False)
    for name, cols in CANDIDATES.items():
        opts = CANDIDATE_OPTS.get(name, {})
        # A column without two distinct training values (fut_month before 2026-04) cannot split;
        # leave it out of that block's fit.
        cols = [c for c in cols if early[c].nunique(dropna=True) >= 2]
        fitted = {q: quantile_fit(train, cols, q, opts, start) for q in QUANTILES}
        # Calibrated range (name_cal), conformalized quantile regression: fit the same models on the
        # training rows before the last CALIBRATION_DAYS, predict those last days, and take per
        # horizon the 0.9 quantile of the lower miss (p10 - y) and of the upper miss (y - p90).
        # Those shifts move the full-training p10 down and p90 up (or inward, if negative) for the
        # test block. A first version scaled the range by a ratio; tiny raw widths made the factor
        # explode (x30 after the 2023 autumn spikes), so the additive form is used.
        cal_start = start - timedelta(days=CALIBRATION_DAYS)
        cq = {q: quantile_predict(quantile_fit(early, cols, q, opts, cal_start), calib) for q in QUANTILES}
        yc = calib["y"].to_numpy()
        miss = pd.DataFrame({"k": calib["k"].to_numpy(), "lo": cq[0.1] - yc, "hi": yc - cq[0.9]})
        shifts = miss.groupby("k")[["lo", "hi"]].quantile(0.9)
        for side in ("lo", "hi"):
            CAL_FACTORS.setdefault(name, []).append({"block": str(start), "side": side, **shifts[side].round(1).to_dict()})
        state["candidates"][name] = {"fitted": fitted, "shifts": shifts}
    for name, col in (("a_last_week", "p_last_week"), ("b_last_day", "p_same_hour_d"), ("c_thirdparty", "p_thirdparty")):
        state["baselines"][name] = (col, {q: (train["y"] - train[col]).groupby(train["k"]).quantile(q) for q in (0.1, 0.9)})
    for name, (level_cols, shape_cols) in LS_CANDIDATES.items():
        state["ls"][name] = level_shape_fit(train, level_cols, shape_cols)
    for name, cols in LINEAR_CANDIDATES.items():
        state["linear"][name] = linear_fit(train, name, cols, per_hour=name in LINEAR_PER_HOUR)
    return state


def apply_block(state, test):
    """Predictions of every fitted part for the test rows (adds the columns to test, returns it)."""
    start = state["start"]
    if state["wind"] is not None:
        wind_apply(state["wind"], test, start)
    if state["netload"] is not None:
        netload_apply(state["netload"], test, start)
    for name, part in state["candidates"].items():
        for q in QUANTILES:
            test[f"{name}_p{int(q * 100)}"] = quantile_predict(part["fitted"][q], test)
        s = part["shifts"]
        test[f"{name}_cal_p50"] = test[f"{name}_p50"]
        test[f"{name}_cal_p10"] = np.minimum(test[f"{name}_p10"] - test["k"].map(s["lo"]), test[f"{name}_p50"])
        test[f"{name}_cal_p90"] = np.maximum(test[f"{name}_p90"] + test["k"].map(s["hi"]), test[f"{name}_p50"])
    for name, (col, resid) in state["baselines"].items():
        test[f"{name}_p50"] = test[col]
        for q in (0.1, 0.9):
            test[f"{name}_p{int(q * 100)}"] = test[col] + test["k"].map(resid[q])
    for name, fitted in state["ls"].items():
        level_shape_apply(fitted, test, name)
    for name, fitted in state["linear"].items():
        linear_apply(fitted, test, name)
    return test


def fit_predict(train, test, cols, q, opts, start):
    """One quantile model, or one per horizon group (opts k_groups), optionally with recency
    weights (opts half_life_days: weight 0.5 ** (age of the target day / half-life))."""
    return quantile_predict(quantile_fit(train, cols, q, opts, start), test)


def quantile_fit(train, cols, q, opts, start):
    """Fitted (k range, columns, model) per horizon group; see fit_predict. Every group is fitted
    (the one-pass version skipped a group without test rows; each backtest block has all k)."""
    fitted = []
    for lo, hi in opts.get("k_groups", [(1, 7)]):
        tr = train[train["k"].between(lo, hi)]
        weight = None
        if "half_life_days" in opts:
            age = np.array([(start - d).days for d in tr["tdate"]], dtype=float)
            weight = 0.5 ** (age / opts["half_life_days"])
        # A column with no value in the training rows carries no information and makes the
        # sklearn binning fail (E30 weekly blocks, 2026-11-06); leave it out of this fit.
        use = [c for c in cols if tr[c].notna().any()]
        model = HistGradientBoostingRegressor(loss="quantile", quantile=q, **{**MODEL_PARAMS, **opts.get("params", {})})
        model.fit(tr[use], tr["y"], sample_weight=weight)
        fitted.append((lo, hi, use, model))
    return fitted


def quantile_predict(fitted, test):
    out = np.full(len(test), np.nan)
    for lo, hi, use, model in fitted:
        te = test["k"].between(lo, hi).to_numpy()
        if te.any():
            out[te] = model.predict(test.loc[te, use])
    return out


WIND_FIT = []


def add_wind(train, test, start):
    """E7 stage-1 wind: capacity factor (Fingrid 75 / installed capacity at the issue) from the
    100 m wind forecast at the 29 FI stations, the lead day, hour and month (HGB, squared loss).
    Training rows get out-of-fold values (4 folds of interleaved 30-day blocks) so that the price
    model does not learn from in-sample stage-1 values; test rows use a fit on all training rows."""
    wind_apply(wind_fit(train, start), test, start)


def wind_fit(train, start):
    """Fit part of add_wind: writes the out-of-fold values into train, returns the state."""
    cols = [c for c in train.columns if c.startswith("om_ws100_fmi_")] + ["om_lead", "hour", "month"]
    state = {"cols": cols, "model": None, "shrink": None}
    train["wind_mw_fc"] = np.nan
    train["wind_mw_fc_day"] = np.nan
    if len(cols) < 4:
        return state
    cf = train["wind_real"] / train["fg_wind_cap"]
    ok = cf.notna() & train["om_lead"].notna()
    if ok.sum() < 5000:
        return state
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
    state["model"] = HistGradientBoostingRegressor(**params).fit(train.loc[ok, cols], cf[ok])
    if WIND_SHRINK["on"]:
        # E28 (Codex idea 3): for D+5..D+7 move the capacity factor towards the training month x
        # hour mean by one coefficient, least squares on the out-of-fold training values.
        clim = cf[ok].groupby([train.loc[ok, "month"], train.loc[ok, "hour"]]).mean()
        c_tr = np.array([clim.get((a, b), np.nan) for a, b in zip(train["month"], train["hour"])])
        long_tr = (train["k"] >= 5).to_numpy()
        use = long_tr & ok.to_numpy() & ~np.isnan(oof) & ~np.isnan(c_tr)
        dx, dy = oof[use] - c_tr[use], cf.to_numpy()[use] - c_tr[use]
        lam = float(np.clip((dx * dy).sum() / (dx * dx).sum(), 0, 1)) if use.sum() > 1000 else 1.0
        sel = long_tr & ~np.isnan(oof) & ~np.isnan(c_tr)
        oof[sel] = c_tr[sel] + lam * (oof[sel] - c_tr[sel])
        state["shrink"] = (clim, lam)
        print(f"E28 wind shrink {start}: lambda {lam:.3f} ({int(use.sum())} rows)", flush=True)
    train["wind_mw_fc"] = oof * train["fg_wind_cap"].to_numpy()
    train["wind_mw_fc_day"] = train.groupby(["issue", "tdate"])["wind_mw_fc"].transform("mean")
    return state


def wind_apply(state, test, start):
    """Predict part of add_wind for the test rows (in place)."""
    test["wind_mw_fc"] = np.nan
    test["wind_mw_fc_day"] = np.nan
    if state["model"] is None:
        return
    te = test["om_lead"].notna().to_numpy()
    pred = np.full(len(test), np.nan)
    if te.any():
        pred[te] = np.clip(state["model"].predict(test.loc[te, state["cols"]]), 0, 1)
    if state["shrink"] is not None:
        clim, lam = state["shrink"]
        c_te = np.array([clim.get((a, b), np.nan) for a, b in zip(test["month"], test["hour"])])
        sel = (test["k"] >= 5).to_numpy() & ~np.isnan(pred) & ~np.isnan(c_te)
        pred[sel] = c_te[sel] + lam * (pred[sel] - c_te[sel])
    test["wind_mw_fc"] = pred * test["fg_wind_cap"].to_numpy()
    test["wind_mw_fc_day"] = test.groupby(["issue", "tdate"])["wind_mw_fc"].transform("mean")
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
    netload_apply(netload_fit(train, min_rows), test, start, record)


def _netload_features(df, model, x):
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


def netload_fit(train, min_rows=2000):
    """Fit part of add_netload: writes the in-sample features into train; returns the model
    (None when there are too few complete rows)."""
    xtr = _netload_design(train)
    ok = xtr.notna().all(axis=1) & train["netload_real"].notna()
    for c in NETLOAD_FEATURES:
        train[c] = np.nan
    if ok.sum() < min_rows:
        return {"model": None, "train_rows": int(ok.sum())}
    model = LinearRegression(fit_intercept=False).fit(xtr[ok], train.loc[ok, "netload_real"])
    _netload_features(train, model, xtr)
    return {"model": model, "train_rows": int(ok.sum())}


def netload_apply(state, test, start, record=True):
    """Predict part of add_netload for the test rows (in place)."""
    for c in NETLOAD_FEATURES:
        test[c] = np.nan
    if state["model"] is None:
        return
    _netload_features(test, state["model"], _netload_design(test))
    if not record:
        return
    err = (test["netload_exp"] - test["netload_real"]).abs()
    NETLOAD_FIT.append({"block": str(start), "train_rows": state["train_rows"], "test_rows": int(err.notna().sum()),
                        "test_mae_mw": round(float(err.mean()), 1) if err.notna().any() else None,
                        "test_mae_mw_by_k": err.groupby(test["k"]).mean().round(1).to_dict()})


def linear_quantile(train, test, name, cols, per_hour=False):
    """LEAR-type linear baseline (external advice 2026-11-02): one ridge model (alpha 1, fixed) on
    asinh(price / 10), price-like inputs also asinh(x / 10), one-hot hour, weekday, month and
    horizon, missing values = training median plus a missing flag, standardised on training rows.
    LEAR proper fits one LASSO per hour; this is a simpler single model. Range: training residual
    quantiles per horizon in the asinh space, transformed back."""
    linear_apply(linear_fit(train, name, cols, per_hour), test, name)


def _linear_design(state, df):
    x = df[state["cols"]].astype(float).copy()
    for c in state["cols"]:
        if c in PRICE_COLS:
            x[c] = np.arcsinh(x[c] / 10.0)
    miss = x[state["flags"]].isna().astype(float).add_suffix("_na")
    cat = pd.concat([pd.get_dummies(df[c].astype(int), prefix=c, dtype=float) for c in ("hour", "dow", "month", "k")], axis=1)
    xf = x.fillna(state["med"])
    inter = {}
    for c in LINEAR_INTERACT.get(state["name"], []):
        if c in xf:
            for lo, hi in ((2, 4), (5, 7)):
                inter[f"{c}_k{lo}{hi}"] = xf[c] * df["k"].between(lo, hi).astype(float)
    return pd.concat([xf, miss, cat, pd.DataFrame(inter, index=xf.index)], axis=1)


def linear_fit(train, name, cols, per_hour=False):
    """Fit part of linear_quantile."""
    cols = [c for c in cols if c not in ("hour", "dow", "month", "k") and train[c].nunique(dropna=True) >= 2]
    # Guard (2026-11-02): a linear model whose training rows mostly lack the weather forecast
    # extrapolates wildly (2024 Q1: MAE 389). Give no prediction then; s_best uses the tree alone.
    if "om_lead" in train and train["om_lead"].notna().mean() < 0.3:
        return {"name": name, "guard": True}
    x = train[cols].astype(float).copy()
    for c in cols:
        if c in PRICE_COLS:
            x[c] = np.arcsinh(x[c] / 10.0)
    state = {"name": name, "guard": False, "cols": cols, "med": x.median(),
             "flags": [c for c in cols if x[c].isna().any()], "per_hour": per_hour}
    xtr = _linear_design(state, train)
    state["columns"] = xtr.columns
    mu, sd = xtr.mean(), xtr.std().replace(0, 1.0)
    state["mu"], state["sd"] = mu, sd
    xtr = (xtr - mu) / sd
    ytr = np.arcsinh(train["y"].to_numpy() / 10.0)
    state["ymin"], state["ymax"] = ytr.min(), ytr.max()
    if per_hour:  # E15b: LEAR-like, one ridge per Helsinki hour of the day
        fit_tr = np.zeros(len(train))
        state["models"] = {}
        for h in range(24):
            a = (train["hour"] == h).to_numpy()
            if a.sum() < 50:
                continue
            m = Ridge(alpha=1.0).fit(xtr[a], ytr[a])
            fit_tr[a] = m.predict(xtr[a])
            state["models"][h] = m
    else:
        state["model"] = Ridge(alpha=1.0).fit(xtr, ytr)
        fit_tr = state["model"].predict(xtr)
    resid = pd.Series(ytr - fit_tr).groupby(train["k"].to_numpy())
    state["resid_q"] = {q: resid.quantile(q) for q in (0.1, 0.9)}
    return state


def linear_apply(state, test, name):
    """Predict part of linear_quantile (in place)."""
    if state["guard"]:
        for q in (10, 50, 90):
            test[f"{name}_p{q}"] = np.nan
            test[f"{name}_cal_p{q}"] = np.nan
        return
    xte = _linear_design(state, test).reindex(columns=state["columns"], fill_value=0.0)
    xte = (xte - state["mu"]) / state["sd"]
    if state["per_hour"]:
        fit_te = np.zeros(len(test))
        for h, m in state["models"].items():
            b = (test["hour"] == h).to_numpy()
            if b.any():
                fit_te[b] = m.predict(xte[b])
    else:
        fit_te = state["model"].predict(xte)
    # E12: keep predictions inside the training target range in asinh space (unbounded values
    # exploded after sinh in early blocks and winter).
    fit_te = np.clip(fit_te, state["ymin"], state["ymax"])
    test[f"{name}_p50"] = 10.0 * np.sinh(fit_te)
    for q in (0.1, 0.9):
        test[f"{name}_p{int(q * 100)}"] = 10.0 * np.sinh(np.clip(fit_te + test["k"].map(state["resid_q"][q]).to_numpy(),
                                                                 state["ymin"], state["ymax"]))
    for q in (10, 50, 90):  # no hold-out calibration here: *_scal falls back to raw
        test[f"{name}_cal_p{q}"] = test[f"{name}_p{q}"]


def level_shape(train, test, name, level_cols, shape_cols):
    level_shape_apply(level_shape_fit(train, level_cols, shape_cols), test, name)


def level_shape_fit(train, level_cols, shape_cols):
    level_cols = [c for c in level_cols if train[c].nunique(dropna=True) >= 2]
    shape_cols = [c for c in shape_cols if train[c].nunique(dropna=True) >= 2]
    keys = ["issue", "tdate"]
    day_train = train.groupby(keys)[level_cols + ["y"]].mean()
    level = {}
    for q in QUANTILES:
        m = HistGradientBoostingRegressor(loss="quantile", quantile=q, **MODEL_PARAMS)
        level[q] = m.fit(day_train[level_cols], day_train["y"])
    dev = train["y"] - train.groupby(keys)["y"].transform("mean")
    shape = {}
    for q in QUANTILES:
        m = HistGradientBoostingRegressor(loss="quantile", quantile=q, **MODEL_PARAMS)
        shape[q] = m.fit(train[shape_cols], dev)
    return {"level_cols": level_cols, "shape_cols": shape_cols, "level": level, "shape": shape}


def level_shape_apply(state, test, name):
    keys = ["issue", "tdate"]
    day_test = test.groupby(keys)[state["level_cols"]].mean()
    level = {q: pd.Series(state["level"][q].predict(day_test[state["level_cols"]]), index=day_test.index) for q in QUANTILES}
    shape = {q: state["shape"][q].predict(test[state["shape_cols"]]) for q in QUANTILES}
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

