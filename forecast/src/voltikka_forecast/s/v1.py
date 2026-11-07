"""s_v1, the frozen S model (tasks/own-forecast-model-data/s-model-freeze.md).

One run per issue day D after the D+1 day-ahead result. Output: p10/p50/p90 for every hour of
D+2..D+7. Two views of the same stored data:
  - afternoon view (as of D 14:30, prices through D+1, Fingrid before 14:00; k=1 is D+2):
    part best_d1, used for D+2;
  - morning view (as of D 11:30, prices through D, Fingrid before 10:00; k=3..7 are D+3..D+7):
    best_d24 (D+3, D+4) and best_d57 (D+5..D+7), each with half the gap to l_lear_hour added.
Both views use the ECMWF 00 UTC run of D. The range is seasonal_calibration (*_scal): p10/p90
moved by the 0.9 quantile of earlier out-of-sample misses (same season and k, targets in the
365 days before the refit Monday, at least 500 rows), else the conformal *_cal range.

The settings below repeat what research/s_backtest.py main() set for E30 (morning view) and E32
(afternoon view, --issue-after-prices). The replay test compares this module with those
backtest files, so a mismatch fails there.

This module works on the data dict of core.build_table (the shape of the research export); the
MySQL and archive readers build that dict.
"""
import hashlib
import platform
from contextlib import contextmanager
from datetime import date, timedelta
from pathlib import Path

import numpy as np
import pandas as pd
import sklearn

from . import core as C

MODEL = "s_v1"
VIEWS = ("morning", "afternoon")
PARTS = {"afternoon": ("best_d1",), "morning": ("best_d24", "best_d57", "l_lear_hour")}
# Parts whose out-of-sample misses calibrate the range (l_lear_hour only moves the p50).
MISS_PARTS = {"afternoon": ("best_d1",), "morning": ("best_d24", "best_d57")}
# Output target day D+j -> (view, k).
OUTPUT = {2: ("afternoon", 1), 3: ("morning", 3), 4: ("morning", 4), 5: ("morning", 5),
          6: ("morning", 6), 7: ("morning", 7)}
SEAL_MIN_ROWS = 500  # seasonal_calibration default
# Everything that computes a feature or a forecast value; a change here is a new model version.
CODE_FILES = ("core.py", "v1.py", "data.py", "weather.py")


class InputMissing(RuntimeError):
    """A live issue lacks an input (the readiness facts and the model disagree)."""


class WrongVersion(RuntimeError):
    """The model version cannot serve the issue day."""


def code_sha256():
    """SHA-256 over the model code files (the vintage and model version record it)."""
    h = hashlib.sha256()
    for name in CODE_FILES:
        h.update((Path(__file__).parent / name).read_bytes())
    return h.hexdigest()


def runtime():
    return {"python": platform.python_version(), "sklearn": sklearn.__version__,
            "pandas": pd.__version__, "numpy": np.__version__}


@contextmanager
def view(name):
    """Set the module settings of core for one view (the backtest main() settings)."""
    if name not in VIEWS:
        raise ValueError(name)
    C.ISSUE["after_prices"] = name == "afternoon"
    C.NETLOAD_WIND["mode"] = "stage1"
    C.WEATHER_SOURCE["mode"] = "single"
    C.REFIT["months"], C.REFIT["days"] = 1, 7
    C.NETLOAD_DESIGN["interact"] = False
    C.WIND_SHRINK["on"] = False
    for group in (C.CANDIDATES, C.CANDIDATE_OPTS, C.LS_CANDIDATES, C.LINEAR_CANDIDATES):
        group.clear()
    w_netload = C.H_FEATURES + C.W_FEATURES + C.SOUTH_FEATURES + C.CAPACITY_FEATURES + C.NETLOAD_FEATURES
    if name == "afternoon":
        C.CANDIDATES["best_d1"] = w_netload + C.WIND_FEATURES
        C.CANDIDATE_OPTS["best_d1"] = {"k_groups": [(1, 1)]}
    else:
        C.CANDIDATES["best_d24"] = w_netload + ["se3_nuc_unavail", "se3_nuc_unavail_day"]
        C.CANDIDATE_OPTS["best_d24"] = {"k_groups": [(2, 4)]}
        C.CANDIDATES["best_d57"] = w_netload
        C.CANDIDATE_OPTS["best_d57"] = {"params": {"max_leaf_nodes": 15, "min_samples_leaf": 300,
                                                   "l2_regularization": 3.0}}
        C.LINEAR_CANDIDATES["l_lear_hour"] = w_netload
    try:
        yield
    finally:
        C.ISSUE["after_prices"] = False


# Inputs that a live issue must have for every target hour. core.build_table does not fail on a
# missing input: it leaves a feature empty (a missing Fingrid window, weather run, futures price
# or border capacity) and the trees still predict. The freeze says the model fails instead
# (s-model-freeze.md section 4), so predict() checks them. Columns that are empty by design stay
# out: cap_*_plan (empty without an A78 limit), p_thirdparty, the training targets.
LIVE_REQUIRED = ("p_last_week", "p_same_hour_d", "p_same_hour_d1", "fi_mean_d", "fi_mean_7", "fi_mean_28",
                 "se1_mean_d", "se3_mean_d", "ee_mean_d", "de_mean_d", "se3_mean_7", "de_mean_7",
                 "fg_wind_24", "fg_cons_24", "fg_nuclear_24", "fg_hydro_24", "fg_net_import_24", "fg_wind_cap",
                 "temp_south_7", "solar_cap", "cons_hod_7", "nuclear_plan_mw", "se3_nuc_unavail", "fut_month",
                 "imp_cap", "exp_cap", "om_fi_temp", "om_fi_temp_south", "om_fi_wind", "om_fi_windp", "om_se_wind",
                 "om_ee_wind", "om_dkde_wind", "om_ghi_fi", "om_lead")


SAME_HOUR = ("p_last_week", "p_same_hour_d", "p_same_hour_d1")


def _near_clock_change(issue, days=8):
    """A Helsinki day with 23 or 25 hours lies within days of the issue day (the same-hour
    lookups reach back 7 days from the target, and targets end at D+7)."""
    days_ = pd.date_range(pd.Timestamp(issue) - pd.Timedelta(days=days), periods=2 * days + 2, freq="D",
                          tz="Europe/Helsinki")
    return bool((pd.Series(days_).diff().dropna() != pd.Timedelta(days=1)).any())


def _check_table(table, name, live=False):
    # The parts need these columns; without them core silently drops a part or a feature group
    # (the backtest main() only registered best_d1 when om_ghi_fi existed).
    for col in ("om_ghi_fi", "om_fi_temp_south", "imp_cap", "se3_nuc_unavail"):
        if col not in table:
            raise RuntimeError(f"{name} view table has no column {col}")
    if live:
        ws100 = [c for c in table.columns if c.startswith("om_ws100_fmi_")]
        if len(ws100) != 29:
            raise InputMissing(f"{name} view: {len(ws100)} of 29 station 100 m wind columns")
        empty = [c for c in (*LIVE_REQUIRED, *ws100) if table[c].isna().any()]
        if _near_clock_change(table["issue"].iloc[0]):
            # The same-hour lookups key on (Helsinki day, hour, dup): the second 03:00 of the
            # 25-hour day and the absent 03:00 of the 23-hour day have no match on a normal day.
            # The backtest trained with these values empty (found by the production replay of
            # 2026-10-21); only local hour 3 is affected.
            other = table["hour"] != 3
            empty = [c for c in empty if c not in SAME_HOUR or table.loc[other, c].isna().any()]
        if empty:
            raise InputMissing(f"{name} view: missing input for issue {table['issue'].iloc[0]}: {', '.join(empty)}")


def refit_monday(d):
    """The refit Monday whose fit serves issue day d (backtest blocks: 7 days from 2024-01-01)."""
    return d - timedelta(days=d.weekday())


def train(data, monday, misses):
    """Fit both views on every row with a target day before monday (as the backtest block that
    starts on monday). misses: DataFrame of earlier out-of-sample misses (columns part, tdate, k,
    month, y, p10, p90). Returns the model dict that predict() needs."""
    if monday.weekday() != 0:
        raise ValueError(f"{monday} is not a Monday")
    model = {"model": MODEL, "monday": monday.isoformat(), "code_sha256": code_sha256(),
             "runtime": runtime(), "views": {}, "facts": {}}
    for name in VIEWS:
        with view(name):
            table = C.build_table(data, monday - timedelta(days=1), targets_known_before=monday)
            _check_table(table, name)
            rows = table[table["tdate"] < monday].copy()
            if rows["y"].isna().any():
                raise RuntimeError(f"{name}: training rows without a price")
            model["views"][name] = C.fit_block(rows, monday)
            model["facts"][name] = {"rows": int(len(rows)), "issues": int(rows["issue"].nunique()),
                                    "first_issue": str(rows["issue"].min()), "last_target": str(rows["tdate"].max())}
    model["scal"] = seasonal_shifts(misses, monday)
    return model


def validate(model):
    """Technical validity of a refit (the frozen method has no accuracy gate): every part, the
    stage-1 models and the linear part were fitted, on enough rows."""
    problems = []
    for name in VIEWS:
        state = model["views"][name]
        if model["facts"][name]["rows"] < 100_000:
            problems.append(f"{name}: only {model['facts'][name]['rows']} training rows")
        if state["wind"] is None or state["wind"]["model"] is None:
            problems.append(f"{name}: no stage-1 wind model")
        if state["netload"] is None or state["netload"]["model"] is None:
            problems.append(f"{name}: no stage-1 net load model")
        for part in PARTS[name]:
            if part in state["linear"]:
                if state["linear"][part]["guard"] or len(state["linear"][part]["models"]) != 24:
                    problems.append(f"{name}: linear part {part} not fitted for every hour")
            elif part not in state["candidates"]:
                problems.append(f"{name}: part {part} missing")
    if not model["scal"]:
        problems.append("no seasonal range shifts (empty miss store)")
    return problems


def seasonal_shifts(misses, monday, min_rows=SEAL_MIN_ROWS):
    """core.seasonal_calibration for one block: per (part, season, k) the 0.9 quantile of the lower
    (p10 - y) and upper (y - p90) misses with target days in the 365 days before monday."""
    known = misses[(misses["tdate"] < monday) & (misses["tdate"] >= monday - timedelta(days=365))]
    season = np.where(known["month"].isin(list(C.WINTER)), "winter", "summer")
    out = {}
    for (part, sea, k), g in known.assign(season=season).groupby(["part", "season", "k"]):
        if len(g) >= min_rows:
            out[(part, sea, int(k))] = (float((g["p10"] - g["y"]).quantile(0.9)),
                                        float((g["y"] - g["p90"]).quantile(0.9)))
    return out


def _scal(pred, part, shifts):
    """Add part_scal_p10/p50/p90 to one view's predictions (seasonal_calibration rule)."""
    season = np.where(pred["month"].isin(list(C.WINTER)), "winter", "summer")
    p10 = pred[f"{part}_cal_p10"].to_numpy(dtype=float).copy()
    p90 = pred[f"{part}_cal_p90"].to_numpy(dtype=float).copy()
    raw10, raw50, raw90 = (pred[f"{part}_p{q}"].to_numpy(dtype=float) for q in (10, 50, 90))
    for i, (sea, k) in enumerate(zip(season, pred["k"].to_numpy())):
        s = shifts.get((part, sea, int(k)))
        if s is not None:
            p10[i] = min(raw10[i] - s[0], raw50[i])
            p90[i] = max(raw90[i] + s[1], raw50[i])
    pred[f"{part}_scal_p10"], pred[f"{part}_scal_p50"], pred[f"{part}_scal_p90"] = p10, raw50, p90


def predict(data, model, issue):
    """Forecast of issue day D with a fitted model. Returns (output, parts):
    output: one row per target hour of D+2..D+7 (target_ts, tdate, day = j of D+j, p10, p50, p90);
    parts: the raw part predictions of both views (for the miss store after scoring)."""
    # The backtest always used the fit of the issue's own Monday. When that refit failed, the
    # active (older) version is used, never a newer one; the vintage records the version.
    if date.fromisoformat(model["monday"]) > refit_monday(issue):
        raise WrongVersion(f"model of {model['monday']} is newer than issue {issue}")
    frames, parts = {}, []
    for name in VIEWS:
        with view(name):
            kd = issue + timedelta(days=1) if name == "afternoon" else issue
            table = C.build_table(data, issue, first_issue=issue, targets_known_before=kd + timedelta(days=1))
            if table.empty:
                raise InputMissing(f"{name}: no complete input rows for issue {issue} (prices through {kd})")
            _check_table(table, name, live=True)
            pred = C.apply_block(model["views"][name], table.copy())
            empty = [c for c in ("wind_mw_fc", "netload_exp", "resid_load", "import_need") if pred[c].isna().any()]
            if empty:
                raise InputMissing(f"{name}: stage-1 values missing: {', '.join(empty)}")
            for part in MISS_PARTS[name]:
                _scal(pred, part, model["scal"])
                ok = pred[f"{part}_p50"].notna()
                parts.append(pd.DataFrame({
                    "view": name, "part": part, "issue": issue, "target_ts": pred.loc[ok, "target_ts"],
                    "tdate": pred.loc[ok, "tdate"], "k": pred.loc[ok, "k"], "month": pred.loc[ok, "month"],
                    "p10": pred.loc[ok, f"{part}_p10"], "p50": pred.loc[ok, f"{part}_p50"],
                    "p90": pred.loc[ok, f"{part}_p90"]}))
            frames[name] = pred
    out = []
    for j, (name, k) in OUTPUT.items():
        pred = frames[name]
        rows = pred[pred["k"] == k]
        if rows.empty or (rows["tdate"] != issue + timedelta(days=j)).any():
            raise InputMissing(f"D+{j}: no rows in the {name} view")
        if name == "afternoon":
            p50, p10, p90 = (rows[f"best_d1{s}"] for s in ("_p50", "_scal_p10", "_scal_p90"))
        else:
            part = "best_d24" if k <= 4 else "best_d57"
            # compose_best: half the gap to the linear part, the range moved by the same change.
            shift = (0.5 * (rows["l_lear_hour_p50"] - rows[f"{part}_p50"])).fillna(0.0)
            p50, p10, p90 = (rows[f"{part}{s}"] + shift for s in ("_p50", "_scal_p10", "_scal_p90"))
        out.append(pd.DataFrame({"target_ts": rows["target_ts"].to_numpy(), "tdate": rows["tdate"].to_numpy(),
                                 "day": j, "p10": p10.to_numpy(), "p50": p50.to_numpy(), "p90": p90.to_numpy()}))
    output = pd.concat(out, ignore_index=True)
    if output[["p10", "p50", "p90"]].isna().any().any():
        raise InputMissing("forecast has empty values")
    return output, pd.concat(parts, ignore_index=True)
