"""Weather inputs of s_v1 from the stored Open-Meteo rows (hourly_forecast_runs).

The research reduced the raw API answers into CSV files (research/s_weather_single_runs.py
reduce, research/s_weather_previous_runs.py reduce); the backtest read those files back. These
functions give the same frames from the stored rows: same points, groups, weights, 80 % rules
and the same rounding (the CSV text had 3 decimals, so values are rounded the same way here).
The rebuild check compares them with the research files.
"""
import json

import numpy as np
import pandas as pd

# research/s_weather_previous_runs.py FMI_STATIONS (order = the column order of the training
# tables) and NEIGHBOUR_POINTS; production point keys are the FMISID strings and the same
# neighbour keys (laravel/config/fmi.php, laravel/config/open_meteo.php).
FMI_STATIONS = ("100908", "100932", "101004", "101061", "101065", "101118", "101191", "101237", "101256",
                "101267", "101268", "101462", "101464", "101481", "101485", "101537", "101570", "101608",
                "101660", "101661", "101673", "101783", "101784", "101785", "101786", "101794", "101840",
                "101846", "101986")
NEIGHBOURS = ("ee01_hiiumaa", "ee02_sopi_tootsi", "dk01_vesterhav", "dk02_anholt", "de01_schleswig_holstein",
              "de02_niedersachsen", "se01_markbygden", "se02_blakliden", "se03_hajsberget")
SOUTH_WEIGHTS = {"101004": 1.75, "101118": 0.53, "101065": 0.49, "101191": 0.16, "101237": 0.12}
GROUPS = {"se": [n for n in NEIGHBOURS if n.startswith("se")],
          "ee": [n for n in NEIGHBOURS if n.startswith("ee")],
          "dkde": [n for n in NEIGHBOURS if n.startswith(("dk", "de"))]}
SINGLE_PRODUCT, PREV_PRODUCT = "ecmwf_00z", "ecmwf_prev_day1"
SINGLE_VARS = ("temperature_2m", "wind_speed_10m", "wind_speed_100m", "shortwave_radiation")
PREV_VAR = "temperature_2m_previous_day1"

WS100_COLUMNS = [f"ws100_fmi_{p}" for p in FMI_STATIONS] + [f"ws100_{p}" for p in NEIGHBOURS]
SINGLE_COLUMNS = ["issue_date", "target_ts", "fi_temp", "fi_temp_south", "fi_wind", "fi_windp", "se_wind",
                  "ee_wind", "dkde_wind", "ghi_fi"] + WS100_COLUMNS
PREVIOUS_COLUMNS = ["target_ts", "lead_day", "fi_temp", "fi_temp_south", "fi_wind", "fi_windp", "se_wind",
                    "ee_wind", "dkde_wind", "n_fi"]
EXTRA_COLUMNS = ["target_ts", "lead_day", "ghi_fi"] + WS100_COLUMNS


def _r3(v):
    """The CSV round trip of the research files (f"{v:.3f}" read back as a float)."""
    return float(f"{v:.3f}") if v is not None else np.nan


def _series(row):
    values = row["values"] if isinstance(row["values"], dict) else json.loads(row["values"])
    # PHP stores 6.0 as the JSON integer 6. The research files had floats, and Python's sum()
    # adds a list that mixes int and float in another order (the last bit of a mean changes,
    # and with it the 3-decimal rounding; found by the rebuild check 2026-11-07). So: floats.
    values = {var: [None if x is None else float(x) for x in series] for var, series in values.items()}
    first = int(row["first_timestamp"])
    return first, values


def _mean(xs, n):
    xs = [x for x in xs if x is not None]
    return sum(xs) / len(xs) if len(xs) >= 0.8 * n else None


def _south(vals):
    got = [(w, vals.get(p)) for p, w in SOUTH_WEIGHTS.items()]
    got = [(w, v) for w, v in got if v is not None]
    total = sum(w for w, _ in got)
    return sum(w * v for w, v in got) / total if total >= 0.8 * sum(SOUTH_WEIGHTS.values()) else None


def single_runs_frame(rows):
    """weather_single_runs: one row per (issue day, valid UTC hour) of each stored 00 UTC run.
    rows: dicts with point, issued_at (datetime or 'YYYY-MM-DD 00:00:00'), first_timestamp, values,
    ordered by fetched_at.
    Every run must have all 38 points on the same hours (one request shape), else it fails."""
    runs = {}
    for row in rows:
        day = str(row["issued_at"])[:10]
        if str(row["issued_at"])[11:19] not in ("", "00:00:00"):
            raise ValueError(f"{SINGLE_PRODUCT} row with issued_at {row['issued_at']}")
        # Rows come ordered by fetched_at: keep the first stored answer of a point (the one that
        # existed at the issue time, if the API ever changed a stored run).
        runs.setdefault(day, {}).setdefault(str(row["point"]), _series(row))
    out = []
    points = list(FMI_STATIONS) + list(NEIGHBOURS)
    for day in sorted(runs):
        run = runs[day]
        missing = [p for p in points if p not in run]
        if missing:
            raise ValueError(f"run {day}: {len(missing)} of 38 points missing")
        first, ref = run[FMI_STATIONS[0]]
        n = len(ref[SINGLE_VARS[0]])
        for p in points:
            f, v = run[p]
            if f != first or any(len(v[var]) != n for var in SINGLE_VARS):
                raise ValueError(f"run {day}: point {p} has other hours")
        for i in range(n):
            val = lambda p, var: run[p][1][var][i]
            fmi = lambda var, f=lambda x: x: _mean([None if val(p, var) is None else f(val(p, var)) for p in FMI_STATIONS],
                                                   len(FMI_STATIONS))
            rec = [day, first + 3600 * i, _r3(fmi("temperature_2m")),
                   _r3(_south({p: val(p, "temperature_2m") for p in SOUTH_WEIGHTS})),
                   _r3(fmi("wind_speed_10m")), _r3(fmi("wind_speed_10m", lambda v: min(v, 12.0) ** 3 / 1728.0)),
                   *[_r3(_mean([val(p, "wind_speed_10m") for p in GROUPS[g]], len(GROUPS[g]))) for g in ("se", "ee", "dkde")],
                   _r3(fmi("shortwave_radiation"))]
            rec += [_r3(val(p, "wind_speed_100m")) for p in points]
            out.append(rec)
    frame = pd.DataFrame(out, columns=SINGLE_COLUMNS)
    frame["target_ts"] = frame["target_ts"].astype("int64")
    return frame


def previous_south_frame(rows):
    """weather_previous_runs rows of lead 1 with only fi_temp_south (the input of temp_south_7):
    the population-weighted lead-1 temperature of the five southern stations per valid hour.
    rows: stored ecmwf_prev_day1 rows (point, fetched_at, first_timestamp, values); a valid hour
    seen in several fetches takes the newest fetch (past values do not change)."""
    by_point = {}
    for row in sorted(rows, key=lambda r: str(r["fetched_at"])):
        first, values = _series(row)
        series = by_point.setdefault(str(row["point"]), {})
        for i, v in enumerate(values[PREV_VAR]):
            if v is not None:
                series[first + 3600 * i] = v
    hours = sorted({ts for s in by_point.values() for ts in s})
    out = []
    for ts in hours:
        south = _south({p: by_point.get(p, {}).get(ts) for p in SOUTH_WEIGHTS})
        if south is not None:
            out.append([ts, 1, np.nan, _r3(south), np.nan, np.nan, np.nan, np.nan, np.nan, np.nan])
    frame = pd.DataFrame(out, columns=PREVIOUS_COLUMNS)
    frame["target_ts"] = frame["target_ts"].astype("int64")
    frame["lead_day"] = frame["lead_day"].astype("int64")
    return frame


def empty_extra():
    """weather_previous_runs_extra without rows (the live run gets these columns from the single
    run; core.build_table creates the om_ws100_* columns from this frame's header)."""
    return pd.DataFrame({c: pd.Series(dtype="float64") for c in EXTRA_COLUMNS}).astype({"target_ts": "int64",
                                                                                        "lead_day": "int64"})

