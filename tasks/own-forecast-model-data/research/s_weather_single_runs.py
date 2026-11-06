#!/usr/bin/env python3
"""Issue-time weather forecasts for the S backtest: the full ECMWF IFS HRES 9 km run of 00 UTC on
each issue day D, from the Open-Meteo Single Runs API (experiment E8, 2026-11-02).

Why: the Previous Runs API only gives runs 24*N h before each valid hour, so most D+1 hours used a
run about 2 days old and late D+7 hours had none (decisions.md). Production reads the newest FMI
ECMWF run (FMI's 11:05 fetch gets the 00 UTC run, result time about 08:00 UTC). The 00 UTC run of D
is therefore the honest input for an issue at D 11:30 Helsinki (08:30-09:30 UTC) and matches
production. Archive: ECMWF IFS HRES from 2024-03-14 (Open-Meteo documentation, 2026-11-02).

One request per issue day with all 38 points (29 FMI stations, 9 neighbour points) and 4 hourly
variables (2 m temperature, 10 m and 100 m wind, shortwave radiation), 9 forecast days. Budget:
counted here as one call per point (38 per request; Open-Meteo counts several locations as several
calls). The free tier allows 10,000 calls per day; the run calls until the API answers HTTP 429
and then waits for the limit window to reset. It runs until every day is cached. Raw responses are cached in research/data/om-single-runs/<model>/<D>.json
(git-ignored, untrusted data, atomic write). Errors print the exception class and HTTP status only.

Usage:
  python3 -I s_weather_single_runs.py fetch [--pause 3] [--dry-run]
  python3 -I s_weather_single_runs.py reduce <YYYYMMDD of the S export>
"""
import argparse
import csv
import hashlib
import http.client
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from s_weather_previous_runs import FMI_STATIONS, NEIGHBOUR_POINTS, SOUTH_WEIGHTS  # noqa: E402

HERE = Path(__file__).resolve().parent
API = "https://single-runs-api.open-meteo.com/v1/forecast"
MODEL = "ecmwf_ifs"
CACHE = HERE / "data" / "om-single-runs" / MODEL
START, END = date(2024, 3, 14), date(2026, 10, 31)
VARS = ("temperature_2m", "wind_speed_10m", "wind_speed_100m", "shortwave_radiation")
POINTS = {**{f"fmi_{k}": v for k, v in FMI_STATIONS.items()}, **NEIGHBOUR_POINTS}


def request(day):
    names = list(POINTS)
    params = {"latitude": ",".join(f"{POINTS[n][0]}" for n in names),
              "longitude": ",".join(f"{POINTS[n][1]}" for n in names),
              "models": MODEL, "run": f"{day.isoformat()}T00:00", "hourly": ",".join(VARS), "forecast_days": 9,
              "wind_speed_unit": "ms", "timezone": "GMT", "timeformat": "unixtime"}
    req = urllib.request.Request(f"{API}?{urllib.parse.urlencode(params)}", headers={"User-Agent": "voltikka-research"})
    with urllib.request.urlopen(req, timeout=300) as resp:
        data = json.loads(resp.read())
    if not isinstance(data, list) or len(data) != len(names):
        raise ValueError("unexpected payload")
    return {n: x["hourly"] for n, x in zip(names, data)}


BUDGET_FILE = CACHE.parent / f"{MODEL}-calls.json"


def calls_today():
    day = datetime.now(timezone.utc).date().isoformat()
    used = json.loads(BUDGET_FILE.read_text()) if BUDGET_FILE.exists() else {}
    return day, used.get(day, 0)


def add_calls(n):
    used = json.loads(BUDGET_FILE.read_text()) if BUDGET_FILE.exists() else {}
    day = datetime.now(timezone.utc).date().isoformat()
    used[day] = used.get(day, 0) + n
    BUDGET_FILE.write_text(json.dumps(used))


def sleep_to_next_utc_day():
    now = datetime.now(timezone.utc)
    nxt = datetime.combine(now.date() + timedelta(days=1), datetime.min.time(), timezone.utc) + timedelta(minutes=5)
    print(f"{now:%F %H:%M} UTC: daily API limit reached; wait until {nxt:%F %H:%M} UTC", flush=True)
    time.sleep((nxt - now).total_seconds())


def fetch(args):
    """Runs until every issue day is cached (2026-11-03 rewrite, user feedback: an early stop left
    most of the free daily quota unused). The server is often slow (about 2-6 min per request) and
    sometimes sends an incomplete body or times out; the same day is valid later. So: one attempt per
    day per pass, failed days return in the next pass; 5 failed days in sequence pause the run for
    30 min. The API enforces the quota (user decision 2026-11-03: no client-side guess): HTTP 429
    with a daily-limit reason waits for the next UTC day, an hourly one for the next hour, a minutely
    one 60 s, any other 429 1 h. BUDGET_FILE only logs the calls sent per UTC day."""
    CACHE.mkdir(parents=True, exist_ok=True)
    per = len(POINTS)
    all_days = [START + timedelta(days=i) for i in range((END - START).days + 1)]
    missing = lambda: [d for d in all_days if not (CACHE / f"{d.isoformat()}.json").exists()]
    print(f"{len(missing())} issue days missing, {per} calls each; the API enforces the quota", flush=True)
    if args.dry_run:
        return
    passes = 0
    while missing():
        passes += 1
        streak = 0
        for d in missing():
            add_calls(per)
            try:
                data = request(d)
            except (urllib.error.URLError, TimeoutError, ValueError, OSError, http.client.HTTPException) as e:
                code = getattr(e, "code", None)
                print(f"{datetime.now(timezone.utc):%H:%M} {d}: FAILED {type(e).__name__} {code or ''}".rstrip(), flush=True)
                if code == 429:
                    try:
                        reason = str(json.loads(e.read()).get("reason", ""))[:200]
                    except (ValueError, OSError, AttributeError):
                        reason = ""
                    print(f"HTTP 429: {reason or 'no reason'}", flush=True)
                    low = reason.lower()
                    if "daily" in low:
                        sleep_to_next_utc_day()
                    elif "hourly" in low:
                        now = datetime.now(timezone.utc)
                        time.sleep(3600 - now.minute * 60 - now.second + 60)
                    elif "minutely" in low:
                        time.sleep(60)
                    else:
                        time.sleep(3600)
                    continue
                streak += 1
                if streak >= 5:
                    print("5 failed days in sequence: wait 30 min", flush=True)
                    time.sleep(1800)
                    streak = 0
                else:
                    time.sleep(30)
                continue
            streak = 0
            target = CACHE / f"{d.isoformat()}.json"
            tmp = target.with_suffix(".part")
            tmp.write_text(json.dumps(data))
            tmp.replace(target)
            time.sleep(args.pause)
        left = len(missing())
        print(f"{datetime.now(timezone.utc):%F %H:%M} UTC pass {passes} done: {len(all_days) - left} of {len(all_days)} cached, today {calls_today()[1]} calls", flush=True)
        if left:
            time.sleep(600)
    print("all issue days cached", flush=True)


def reduce(args):
    """One row per (issue day, valid UTC hour): FI group means as in the Previous Runs reduce
    (fi_temp, fi_temp_south, fi_wind, fi_windp, se_wind, ee_wind, dkde_wind), the FI radiation mean
    (ghi_fi) and the 100 m wind per point (ws100_<point>), for valid hours from the issue day on."""
    fmi = [n for n in POINTS if n.startswith("fmi_")]
    groups = {"se": [n for n in NEIGHBOUR_POINTS if n.startswith("se")],
              "ee": [n for n in NEIGHBOUR_POINTS if n.startswith("ee")],
              "dkde": [n for n in NEIGHBOUR_POINTS if n.startswith(("dk", "de"))]}
    out = HERE / "data" / f"s-export-{args.stamp}" / "weather_single_runs.csv"
    rows = 0
    with open(out, "w", newline="") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["issue_date", "target_ts", "fi_temp", "fi_temp_south", "fi_wind", "fi_windp", "se_wind", "ee_wind",
                    "dkde_wind", "ghi_fi"] + [f"ws100_{n}" for n in POINTS])
        fmt = lambda v: "" if v is None else f"{v:.3f}"
        for path in sorted(CACHE.glob("*.json")):
            data = json.loads(path.read_text())
            times = data[fmi[0]]["time"]
            for i, ts in enumerate(times):
                val = lambda n, v: data[n][v][i] if data[n][v][i] is not None else None
                def mean(names, var, f=lambda x: x):
                    xs = [f(val(n, var)) for n in names if val(n, var) is not None]
                    return sum(xs) / len(xs) if len(xs) >= 0.8 * len(names) else None
                south = [(wgt, val(n, "temperature_2m")) for n, wgt in SOUTH_WEIGHTS.items()]
                south = [(wgt, v) for wgt, v in south if v is not None]
                tot = sum(wgt for wgt, _ in south)
                w.writerow([path.stem, ts, fmt(mean(fmi, "temperature_2m")),
                            fmt(sum(wgt * v for wgt, v in south) / tot if tot >= 0.8 * sum(SOUTH_WEIGHTS.values()) else None),
                            fmt(mean(fmi, "wind_speed_10m")), fmt(mean(fmi, "wind_speed_10m", lambda v: min(v, 12.0) ** 3 / 1728.0)),
                            *[fmt(mean(groups[g], "wind_speed_10m")) for g in ("se", "ee", "dkde")],
                            fmt(mean(fmi, "shortwave_radiation"))] + [fmt(val(n, "wind_speed_100m")) for n in POINTS])
                rows += 1
    pins_path = HERE / f"s-inputs-{args.stamp}.json"
    pins = json.loads(pins_path.read_text())
    pins["files"]["weather_single_runs.csv"] = {
        "rows": rows, "sha256": hashlib.sha256(out.read_bytes()).hexdigest(),
        "source": f"Open-Meteo Single Runs API, {MODEL} 00 UTC run of each issue day, {START}..{END}",
        "fetched": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
    }
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")
    print(f"{out}: {rows} rows")


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)
    f = sub.add_parser("fetch")
    f.add_argument("--pause", type=float, default=3.0)
    f.add_argument("--dry-run", action="store_true")
    r = sub.add_parser("reduce")
    r.add_argument("stamp")
    args = ap.parse_args()
    {"fetch": fetch, "reduce": reduce}[args.cmd](args)


if __name__ == "__main__":
    main()
