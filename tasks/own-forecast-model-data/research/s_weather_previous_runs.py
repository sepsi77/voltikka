#!/usr/bin/env python3
"""Historical weather forecasts as issued 1-7 days ahead, for an honest S backtest (user decision
2026-11-02: Open-Meteo may be used for this; FMI keeps no forecast archive).

Source: Open-Meteo Previous Runs API, https://previous-runs-api.open-meteo.com/v1/forecast.
`<variable>_previous_dayN` is "the value predicted 24*N hours before valid time" (N = 1..7);
most models are archived from January 2024. Weather data by Open-Meteo.com (CC BY 4.0).

Points: the 29 FMI stations that production forecasts are stored for (laravel/config/fmi.php,
temperature and 10 m wind, as the FMI forecast parameters) and the 9 neighbour wind points
(laravel/config/open_meteo.php, 10 m wind only). Same points as the live collectors, so a phase 3
candidate can run on the stored live vintages.

Free-tier budget (10,000 calls/day, 5,000/hour, 600/minute). Open-Meteo counts a request with
more than 10 variables or more than 2 weeks of data as several calls (fractional). The estimate
below uses max(1, variables/10) * max(1, days/14) per request; it is printed before any request.
One request per point and calendar year; the pause between requests keeps the minute rate low.

Raw responses are cached in research/data/om-previous-runs/<model>/<point>_<year>.json (git-ignored,
untrusted data; atomic write; a cached file is not fetched again). Errors print the exception
class and HTTP status only.

Usage:
  python3 -I s_weather_previous_runs.py probe                 # 2 points x 2 short dates x models
  python3 -I s_weather_previous_runs.py fetch --model M [--dry-run] [--pause 15]
  python3 -I s_weather_previous_runs.py reduce --model M <YYYYMMDD of the export>
"""
import argparse
import csv
import hashlib
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
API = "https://previous-runs-api.open-meteo.com/v1/forecast"
CACHE = HERE / "data" / "om-previous-runs"
LEADS = range(1, 8)
START, END = date(2024, 1, 1), date(2026, 11, 1)
PROBE_MODELS = ("ecmwf_ifs", "ecmwf_ifs025", "best_match")

FMI_STATIONS = {  # laravel/config/fmi.php, forecast/observation stations
    100908: (59.77909, 21.37479), 100932: (59.77363, 22.94868), 101004: (60.20307, 24.96131),
    101061: (61.14475, 21.30273), 101065: (60.51565, 22.27916), 101118: (61.41940, 23.62256),
    101191: (60.89839, 26.94882), 101237: (61.04030, 28.12916), 101256: (62.33382, 21.19081),
    101267: (61.63042, 21.37620), 101268: (62.20324, 21.16983), 101462: (63.05858, 21.75460),
    101464: (63.43508, 21.06856), 101481: (62.97839, 20.74008), 101485: (63.09871, 21.63938),
    101537: (63.08225, 25.85862), 101570: (62.99842, 27.80817), 101608: (62.65695, 29.63608),
    101660: (63.75144, 22.52282), 101661: (63.95114, 22.84537), 101673: (64.33073, 23.44627),
    101783: (65.38508, 24.09568), 101784: (65.03975, 24.56118), 101785: (64.66589, 24.40695),
    101786: (64.93503, 25.33920), 101794: (65.00637, 25.39325), 101840: (65.78944, 24.58278),
    101846: (65.67318, 24.51519), 101986: (67.69168, 24.85538),
}
NEIGHBOUR_POINTS = {  # laravel/config/open_meteo.php neighbour_wind_points
    "ee01_hiiumaa": (58.8960, 22.5605), "ee02_sopi_tootsi": (58.6347, 25.1230),
    "dk01_vesterhav": (56.4260, 8.1281), "dk02_anholt": (56.6013, 11.1047),
    "de01_schleswig_holstein": (54.2194, 9.6961), "de02_niedersachsen": (52.6367, 9.8451),
    "se01_markbygden": (65.2536, 21.6020), "se02_blakliden": (64.5, 17.0),
    "se03_hajsberget": (59.7852, 13.0042),
}


# Southern Finland demand temperature: the FMI stations in the largest load regions, weighted by
# the approximate region population in millions (Uusimaa, Pirkanmaa, Varsinais-Suomi,
# Kymenlaakso, South Karelia). Most of the 29 stations are west-coast wind sites, so their plain
# mean describes wind areas, not heating demand.
SOUTH_WEIGHTS = {"fmi_101004": 1.75, "fmi_101118": 0.53, "fmi_101065": 0.49, "fmi_101191": 0.16,
                 "fmi_101237": 0.12}


# Variable sets. "base": the first fetch (2 m temperature, 10 m wind). "extra" (experiment E3,
# 2026-11-02): 100 m wind (turbine hub height) at every point and global horizontal radiation at
# the FMI stations, kept per point by reduce-extra so a stage-1 model can learn wind power.
VARSETS = {"base": (("temperature_2m", "wind_speed_10m"), ("wind_speed_10m",)),
           "extra": (("wind_speed_100m", "shortwave_radiation"), ("wind_speed_100m",))}


def points(varset="base"):
    fmi_vars, nb_vars = VARSETS[varset]
    out = {f"fmi_{k}": (lat, lon, fmi_vars) for k, (lat, lon) in FMI_STATIONS.items()}
    out.update({k: (lat, lon, nb_vars) for k, (lat, lon) in NEIGHBOUR_POINTS.items()})
    return out


def cache_dir(model, varset):
    return CACHE / (model if varset == "base" else f"{model}-{varset}")


def hourly_vars(base):
    return [f"{v}_previous_day{n}" for v in base for n in LEADS]


def weight(n_vars, days):
    return max(1.0, n_vars / 10) * max(1.0, days / 14)


def get(params):
    url = f"{API}?{urllib.parse.urlencode(params)}"
    req = urllib.request.Request(url, headers={"User-Agent": "voltikka-research"})
    with urllib.request.urlopen(req, timeout=180) as resp:
        return json.loads(resp.read())


def params_for(lat, lon, base, model, start, end):
    return {"latitude": lat, "longitude": lon, "hourly": ",".join(hourly_vars(base)), "models": model,
            "start_date": start.isoformat(), "end_date": end.isoformat(), "wind_speed_unit": "ms",
            "timezone": "GMT", "timeformat": "unixtime"}


def fail(e):
    return f"FAILED {type(e).__name__} {getattr(e, 'code', '')}".rstrip()


def probe(_args):
    """Which model has previous_day1..7 values at the start and the end of the period."""
    plan = [("fmi_101267", date(2024, 1, 10)), ("fmi_101267", date(2026, 10, 20))]
    pts = points()
    for model in PROBE_MODELS:
        for name, day in plan:
            lat, lon, base = pts[name]
            try:
                data = get(params_for(lat, lon, base, model, day, day))
            except (urllib.error.URLError, TimeoutError, ValueError) as e:
                print(model, name, day, fail(e))
                continue
            hourly = data.get("hourly", {})
            filled = {v: sum(x is not None for x in hourly.get(v, [])) for v in hourly_vars(base)}
            print(model, name, day, "grid", data.get("latitude"), data.get("longitude"),
                  "non-null hours per lead:", [filled[f"wind_speed_10m_previous_day{n}"] for n in LEADS])
            time.sleep(2)


def year_chunks():
    for y in range(START.year, END.year + 1):
        yield y, max(START, date(y, 1, 1)), min(END, date(y, 12, 31))


def fetch(args):
    pts = points(args.set)
    folder = cache_dir(args.model, args.set)
    todo, total = [], 0.0
    for name, (lat, lon, base) in pts.items():
        for y, a, b in year_chunks():
            target = folder / f"{name}_{y}.json"
            w = weight(len(hourly_vars(base)), (b - a).days + 1)
            if not target.exists():
                todo.append((name, lat, lon, base, y, a, b, target, w))
                total += w
    print(f"{len(todo)} requests to fetch, estimated {total:.0f} weighted calls "
          f"(free tier: 10,000/day, 5,000/hour, 600/minute); pause {args.pause} s")
    if args.dry_run or not todo:
        return
    if total > args.max_calls:
        sys.exit(f"estimate {total:.0f} is over --max-calls {args.max_calls}; stop")
    folder.mkdir(parents=True, exist_ok=True)
    failed = 0
    for i, (name, lat, lon, base, y, a, b, target, w) in enumerate(todo, 1):
        try:
            data = get(params_for(lat, lon, base, args.model, a, b))
        except (urllib.error.URLError, TimeoutError, ValueError) as e:
            failed += 1
            print(f"{i}/{len(todo)} {name} {y}: {fail(e)}", flush=True)
            if getattr(e, "code", None) == 429:
                sys.exit("HTTP 429 (rate limit): stop; rerun later fetches only the missing files")
            continue
        tmp = target.with_suffix(".part")
        tmp.write_text(json.dumps(data))
        tmp.replace(target)
        print(f"{i}/{len(todo)} {name} {y}: ok ({w:.0f} calls)", flush=True)
        time.sleep(args.pause)
    if failed:
        sys.exit(f"{failed} requests failed; rerun to fetch only the missing files")


def reduce(args):
    """Group means per UTC hour and lead day, pinned into s-inputs-<stamp>.json.

    Columns: target_ts, lead_day, fi_temp (mean 2 m temperature of the FMI stations), fi_temp_south
    (population-weighted temperature of SOUTH_WEIGHTS, needs 80 % of the weight), fi_wind
    (mean 10 m wind), fi_windp (mean of min(v, 12)^3 / 12^3, a simple power-curve proxy),
    se_wind, ee_wind, dkde_wind (mean 10 m wind of the neighbour points of each group), and n_fi
    (FMI stations with a value). A group mean needs at least 80 % of its points, else it is empty.
    """
    groups = {"fi": [p for p in points() if p.startswith("fmi_")],
              "se": [p for p in NEIGHBOUR_POINTS if p.startswith("se")],
              "ee": [p for p in NEIGHBOUR_POINTS if p.startswith("ee")],
              "dkde": [p for p in NEIGHBOUR_POINTS if p.startswith(("dk", "de"))]}
    values = {}  # (point, variable, lead) -> {ts: value}
    for path in sorted((CACHE / args.model).glob("*.json")):
        name = path.stem.rsplit("_", 1)[0]
        hourly = json.loads(path.read_text())["hourly"]
        for key, series in hourly.items():
            if "_previous_day" not in key:
                continue
            var, lead = key.split("_previous_day")
            d = values.setdefault((name, var, int(lead)), {})
            d.update({ts: v for ts, v in zip(hourly["time"], series) if v is not None})

    def mean(group, var, lead, ts, f=lambda v: v):
        vals = [values.get((p, var, lead), {}).get(ts) for p in groups[group]]
        vals = [f(v) for v in vals if v is not None]
        return (sum(vals) / len(vals), len(vals)) if len(vals) >= 0.8 * len(groups[group]) else (None, len(vals))

    def south(lead, ts):
        got = [(w, values.get((p, "temperature_2m", lead), {}).get(ts)) for p, w in SOUTH_WEIGHTS.items()]
        got = [(w, v) for w, v in got if v is not None]
        total = sum(w for w, _ in got)
        return sum(w * v for w, v in got) / total if total >= 0.8 * sum(SOUTH_WEIGHTS.values()) else None

    all_ts = sorted({ts for d in values.values() for ts in d})
    out = HERE / "data" / f"s-export-{args.stamp}" / "weather_previous_runs.csv"
    rows = 0
    with open(out, "w", newline="") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["target_ts", "lead_day", "fi_temp", "fi_temp_south", "fi_wind", "fi_windp", "se_wind", "ee_wind",
                    "dkde_wind", "n_fi"])
        fmt = lambda v: "" if v is None else f"{v:.3f}"
        for lead in LEADS:
            for ts in all_ts:
                fi_temp, n_fi = mean("fi", "temperature_2m", lead, ts)
                fi_wind, _ = mean("fi", "wind_speed_10m", lead, ts)
                fi_windp, _ = mean("fi", "wind_speed_10m", lead, ts, lambda v: min(v, 12.0) ** 3 / 1728.0)
                rest = [mean(g, "wind_speed_10m", lead, ts)[0] for g in ("se", "ee", "dkde")]
                if fi_temp is None and fi_wind is None and all(r is None for r in rest):
                    continue
                w.writerow([ts, lead, fmt(fi_temp), fmt(south(lead, ts)), fmt(fi_wind), fmt(fi_windp), *map(fmt, rest), n_fi])
                rows += 1
    pins_path = HERE / f"s-inputs-{args.stamp}.json"
    pins = json.loads(pins_path.read_text())
    pins["files"]["weather_previous_runs.csv"] = {
        "rows": rows, "sha256": hashlib.sha256(out.read_bytes()).hexdigest(),
        "source": f"Open-Meteo Previous Runs API, model {args.model}, {START}..{END}",
        "fetched": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
    }
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")
    print(f"{out}: {rows} rows")


def reduce_extra(args):
    """Per-point 100 m wind (ws100_<point>) and the mean radiation of the FMI stations (ghi_fi,
    W/m2, needs 80 % of the stations) per UTC hour and lead day, pinned like reduce."""
    values = {}
    for path in sorted(cache_dir(args.model, "extra").glob("*.json")):
        name = path.stem.rsplit("_", 1)[0]
        hourly = json.loads(path.read_text())["hourly"]
        for key, series in hourly.items():
            if "_previous_day" not in key:
                continue
            var, lead = key.split("_previous_day")
            values.setdefault((name, var, int(lead)), {}).update(
                {ts: v for ts, v in zip(hourly["time"], series) if v is not None})
    names = list(points("extra"))
    fmi = [p for p in names if p.startswith("fmi_")]
    all_ts = sorted({ts for d in values.values() for ts in d})
    out = HERE / "data" / f"s-export-{args.stamp}" / "weather_previous_runs_extra.csv"
    rows = 0
    with open(out, "w", newline="") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["target_ts", "lead_day", "ghi_fi"] + [f"ws100_{p}" for p in names])
        for lead in LEADS:
            for ts in all_ts:
                ws = [values.get((p, "wind_speed_100m", lead), {}).get(ts) for p in names]
                ghi = [values.get((p, "shortwave_radiation", lead), {}).get(ts) for p in fmi]
                ghi = [v for v in ghi if v is not None]
                if all(v is None for v in ws) and not ghi:
                    continue
                g = f"{sum(ghi) / len(ghi):.1f}" if len(ghi) >= 0.8 * len(fmi) else ""
                w.writerow([ts, lead, g] + ["" if v is None else f"{v:.2f}" for v in ws])
                rows += 1
    pins_path = HERE / f"s-inputs-{args.stamp}.json"
    pins = json.loads(pins_path.read_text())
    pins["files"]["weather_previous_runs_extra.csv"] = {
        "rows": rows, "sha256": hashlib.sha256(out.read_bytes()).hexdigest(),
        "source": f"Open-Meteo Previous Runs API, model {args.model}, 100 m wind + shortwave radiation, {START}..{END}",
        "fetched": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
    }
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")
    print(f"{out}: {rows} rows")


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("probe")
    f = sub.add_parser("fetch")
    f.add_argument("--model", required=True)
    f.add_argument("--dry-run", action="store_true")
    f.add_argument("--pause", type=float, default=15.0)
    f.add_argument("--max-calls", type=float, default=4500.0)
    f.add_argument("--set", default="base", choices=sorted(VARSETS))
    r = sub.add_parser("reduce")
    r.add_argument("--model", required=True)
    r.add_argument("stamp")
    re_ = sub.add_parser("reduce-extra")
    re_.add_argument("--model", required=True)
    re_.add_argument("stamp")
    args = ap.parse_args()
    {"probe": probe, "fetch": fetch, "reduce": reduce, "reduce-extra": reduce_extra}[args.cmd](args)


if __name__ == "__main__":
    main()
