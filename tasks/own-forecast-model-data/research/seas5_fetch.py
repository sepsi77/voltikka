#!/usr/bin/env python3
"""Fetch archived C3S SEAS5 (ECMWF) monthly forecasts from the Copernicus CDS and reduce them to
regional monthly means per ensemble member, for the as-of backtest of the medium-term model (M).

Why a local research script and not a Laravel collector: the CDS archive is permanent and never
revised, so the as-of rule is met by the release time alone (start month day 6, 12:00 UTC), and
nothing has to be captured live. Production already stores live SEAS5/EC46 vintages through
Open-Meteo. Where a production model reads SEAS5 is the open runtime decision of phase 5.

Data: dataset seasonal-monthly-single-levels, originating_centre ecmwf, product monthly_mean (every
member) and hindcast_climate_mean (model climate 1993-2016, for anomalies). Real-time starts use
system 5 until 2022-10 and system 51 from 2022-11 (CDS constraints, checked 2026-11-01). Licence
CC BY 4.0, "Copernicus Climate Change Service (C3S) Climate Data Store", DOI 10.24381/cds.68dd14c3.

Files (all under research/data/seas5/, git-ignored, rebuilt by this script):
  raw/fc_s{system}_{YYYYMM}.grib     one start, 4 variables, leads 1-6, all members
  raw/clim_s{system}_{MM}.grib       hindcast climate mean for one start month, leads 1-6
  seas5_regions.csv                  start, system, available_at_utc, lead, valid_month, region,
                                     variable, unit, member, value, clim, anomaly

The key comes from the environment variable ECMWF_API_KEY; it is sent only in the PRIVATE-TOKEN
header and is never printed.

Usage: ECMWF_API_KEY=... research/.venv/bin/python -I seas5_fetch.py [--from 2017-11] [--to YYYY-MM]
       [--workers 4] [--reduce-only]
"""
import argparse
import csv
import json
import math
import os
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import date
from pathlib import Path

API = "https://cds.climate.copernicus.eu/api/retrieve/v1"
DATASET = "seasonal-monthly-single-levels"
VARIABLES = ["2m_temperature", "10m_wind_speed", "total_precipitation", "runoff"]
# One request box (N, W, S, E) at 1 degree that covers every region below.
AREA = [71, 4, 57, 32]
# Regions are plain lat/lon boxes (inclusive grid points). No land mask: each box is chosen to be
# mostly land. fi = Finnish demand/temperature and wind; no_south = NO1/NO2/NO5 hydro;
# nordic_north = SE1/SE2/NO4 hydro and north Nordic wind.
REGIONS = {
    "fi": (60, 70, 21, 30),
    "no_south": (58, 63, 5, 11),
    "nordic_north": (63, 68, 13, 21),
}
SYSTEM_51_FROM = (2022, 11)
FIRST_REALTIME = (2017, 11)
HERE = Path(__file__).resolve().parent
DATA = HERE / "data" / "seas5"
RAW = DATA / "raw"


def system_for(year, month):
    return "51" if (year, month) >= SYSTEM_51_FROM else "5"


def months(start, end):
    y, m = start
    while (y, m) <= end:
        yield y, m
        y, m = (y + 1, 1) if m == 12 else (y, m + 1)


def parse_month(text):
    y, m = text.split("-")
    return int(y), int(m)


def request(method, url, key, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("PRIVATE-TOKEN", key)
    if data is not None:
        req.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read()


def retrieve(inputs, target, key):
    """Submit one CDS job, wait for it and download its GRIB file atomically."""
    if target.exists() and target.stat().st_size > 0:
        return "cached"
    job = json.loads(request("POST", f"{API}/processes/{DATASET}/execution", key, {"inputs": inputs}))
    job_id = job["jobID"]
    delay = 5
    while True:
        status = json.loads(request("GET", f"{API}/jobs/{job_id}", key))["status"]
        if status == "successful":
            break
        if status in ("failed", "rejected", "dismissed", "deleted"):
            raise RuntimeError(f"CDS job {job_id} ended with status {status}")
        time.sleep(delay)
        delay = min(delay * 2, 60)
    results = json.loads(request("GET", f"{API}/jobs/{job_id}/results", key))
    href = results["asset"]["value"]["href"]
    tmp = target.with_suffix(".part")
    with urllib.request.urlopen(href, timeout=600) as resp, open(tmp, "wb") as fh:
        while chunk := resp.read(1 << 20):
            fh.write(chunk)
    tmp.replace(target)
    return "fetched"


def base_inputs(system, product, year, month):
    return {
        "originating_centre": "ecmwf",
        "system": system,
        "variable": VARIABLES,
        "product_type": [product],
        "year": [f"{year}"],
        "month": [f"{month:02d}"],
        "leadtime_month": [str(i) for i in range(1, 7)],
        "area": AREA,
        "data_format": "grib",
    }


def jobs_for(start, end):
    jobs = []
    for y, m in months(start, end):
        s = system_for(y, m)
        jobs.append((base_inputs(s, "monthly_mean", y, m), RAW / f"fc_s{s}_{y}{m:02d}.grib"))
    # The hindcast climate mean depends on system and start month only; any valid year selects it.
    for s, year in (("5", 2018), ("51", 2024)):
        for m in range(1, 13):
            jobs.append((base_inputs(s, "hindcast_climate_mean", year, m), RAW / f"clim_s{s}_{m:02d}.grib"))
    return jobs


# --- reduction ---------------------------------------------------------------------------------

def region_means(path):
    """Yield (variable shortName, units, forecastMonth, member, {region: area-weighted mean})."""
    import eccodes  # imported here so that a fetch-only run does not need ecCodes

    with open(path, "rb") as fh:
        while (gid := eccodes.codes_grib_new_from_file(fh)) is not None:
            try:
                short = eccodes.codes_get(gid, "shortName")
                units = eccodes.codes_get(gid, "units")
                lead = eccodes.codes_get(gid, "forecastMonth")
                member = eccodes.codes_get(gid, "number") if eccodes.codes_is_defined(gid, "number") else 0
                lats = eccodes.codes_get_array(gid, "latitudes")
                lons = eccodes.codes_get_array(gid, "longitudes")
                vals = eccodes.codes_get_values(gid)
                missing = eccodes.codes_get(gid, "missingValue")
            finally:
                eccodes.codes_release(gid)
            out = {}
            for name, (s, n, w, e) in REGIONS.items():
                tot = wsum = 0.0
                for la, lo, v in zip(lats, lons, vals):
                    if s <= la <= n and w <= lo <= e and v != missing:
                        wt = math.cos(math.radians(la))
                        tot += wt * v
                        wsum += wt
                out[name] = tot / wsum if wsum else None
            yield short, units, lead, member, out


def reduce_all(start, end):
    clim = {}
    for path in sorted(RAW.glob("clim_s*_*.grib")):
        s, m = path.stem.split("_")[1][1:], int(path.stem.split("_")[2])
        for short, _units, lead, _member, out in region_means(path):
            for region, v in out.items():
                clim[(s, m, lead, short, region)] = v
    rows = 0
    target = DATA / "seas5_regions.csv"
    tmp = target.with_suffix(".part")
    with open(tmp, "w", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["start", "system", "available_at_utc", "lead", "valid_month", "region", "variable",
                    "unit", "member", "value", "clim", "anomaly"])
        for y, m in months(start, end):
            s = system_for(y, m)
            path = RAW / f"fc_s{s}_{y}{m:02d}.grib"
            if not path.exists():
                continue
            # Released on day 6 at 12 UTC (C3S schedule): the earliest as-of time for this start.
            available = f"{y}-{m:02d}-06T12:00:00Z"
            for short, units, lead, member, out in region_means(path):
                vy, vm = divmod((y * 12 + m - 1) + (lead - 1), 12)
                for region, v in out.items():
                    c = clim.get((s, m, lead, short, region))
                    w.writerow([f"{y}-{m:02d}", s, available, lead, f"{vy}-{vm + 1:02d}", region, short, units,
                                member, _fmt(v), _fmt(c), _fmt(v - c if v is not None and c is not None else None)])
                    rows += 1
    tmp.replace(target)
    return target, rows


def _fmt(v):
    return "" if v is None else f"{v:.6g}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="start", default=f"{FIRST_REALTIME[0]}-{FIRST_REALTIME[1]:02d}")
    ap.add_argument("--to", dest="end", default=None, help="last start month (default: last released)")
    ap.add_argument("--workers", type=int, default=4)
    ap.add_argument("--reduce-only", action="store_true")
    args = ap.parse_args()

    start = parse_month(args.start)
    if args.end:
        end = parse_month(args.end)
    else:
        today = date.today()
        # A start month is released on day 6 at 12 UTC; before day 7 use the previous month (safe).
        y, m = (today.year, today.month) if today.day >= 7 else ((today.year, today.month - 1) if today.month > 1 else (today.year - 1, 12))
        end = (y, m)
    if start < FIRST_REALTIME:
        sys.exit("Real-time SEAS5 starts are used from 2017-11 only; earlier starts are hindcasts.")

    if not args.reduce_only:
        key = os.environ.get("ECMWF_API_KEY", "").strip()
        if not key:
            sys.exit("ECMWF_API_KEY is not set.")
        RAW.mkdir(parents=True, exist_ok=True)
        jobs = jobs_for(start, end)
        failed = 0
        with ThreadPoolExecutor(max_workers=args.workers) as pool:
            futures = {pool.submit(retrieve, inputs, target, key): target for inputs, target in jobs}
            for fut in as_completed(futures):
                target = futures[fut]
                try:
                    print(f"{target.name}: {fut.result()}", flush=True)
                except (urllib.error.URLError, RuntimeError, KeyError, TimeoutError) as e:
                    failed += 1
                    # Print the class and the HTTP status only; never a header or a response body.
                    print(f"{target.name}: FAILED {type(e).__name__} {getattr(e, 'code', '')}".rstrip(), flush=True)
        if failed:
            sys.exit(f"{failed} of {len(jobs)} requests failed; rerun to fetch only the missing files.")

    target, rows = reduce_all(start, end)
    print(f"{target}: {rows} rows")


if __name__ == "__main__":
    main()
