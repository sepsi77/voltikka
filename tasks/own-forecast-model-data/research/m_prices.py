#!/usr/bin/env python3
"""Daily day-ahead price history 2015-2022 for the medium-term model (M), from ENTSO-E.

The local snapshot has FI prices from 2022 and SE1/SE3/DE-LU from 2023 only; M needs more years
to learn seasonal changes and the hydro relation. This script fetches the ENTSO-E Transparency
day-ahead prices (A44) once, one request per zone and calendar year (32 requests), with the key
from laravel/.env (never printed), caches the raw XML in research/data/entsoe-a44/ (git-ignored,
untrusted data) and writes daily means per Helsinki day.

Curve type A03 omits a point when the price does not change; the point then repeats until the
next given position (same rule as laravel/app/Services/EntsoeService.php). Germany is DE-AT-LU
until 2018-09-30 and DE-LU from 2018-10-01 (the bidding-zone split); both are stored as "DE".

Output: research/data/m-export-<stamp>/prices_daily.csv (zone, date, eur_mwh, hours), 2015-01-01
.. 2022-12-31 from ENTSO-E, then the local SQLite snapshot from 2023-01-01 (FI from 2022 is
compared with the fetch as a check). Pins in research/m-inputs-<stamp>.json.

Usage: python3 -I m_prices.py <sqlite path> <YYYYMMDD>
"""
import csv
import hashlib
import json
import sqlite3
import sys
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

HERE = Path(__file__).resolve().parent
ENV = HERE.parents[2] / "laravel" / ".env"
CACHE = HERE / "data" / "entsoe-a44"
API = "https://web-api.tp.entsoe.eu/api"
HEL = ZoneInfo("Europe/Helsinki")
ZONES = {"FI": ["10YFI-1--------U"], "SE1": ["10Y1001A1001A44P"], "SE3": ["10Y1001A1001A46L"],
         "DE": ["10Y1001A1001A63L", "10Y1001A1001A82H"]}  # DE-AT-LU, then DE-LU
DE_SPLIT = datetime(2018, 9, 30, 22, tzinfo=timezone.utc)  # first DE-LU delivery hour (2018-10-01 00 CET)
YEARS = range(2015, 2023)
LOCAL_REGION = {"FI": "FI", "SE1": "SE1", "SE3": "SE3", "DE": "DE-LU"}


def api_key():
    for line in ENV.read_text().splitlines():
        if line.startswith("ENTSOE_API_KEY="):
            return line.split("=", 1)[1].strip().strip('"')
    sys.exit("ENTSOE_API_KEY is not in laravel/.env")


def fetch(zone, eic, year, key):
    target = CACHE / f"{zone}_{eic}_{year}.xml"
    if target.exists():
        return target.read_bytes()
    start = datetime(year - 1, 12, 31, 23, tzinfo=timezone.utc)
    end = datetime(year, 12, 31, 23, tzinfo=timezone.utc)
    query = urllib.parse.urlencode({"securityToken": key, "documentType": "A44", "in_Domain": eic,
                                    "out_Domain": eic, "periodStart": start.strftime("%Y%m%d%H%M"),
                                    "periodEnd": end.strftime("%Y%m%d%H%M")})
    for attempt in range(3):
        try:
            with urllib.request.urlopen(f"{API}?{query}", timeout=120) as resp:
                body = resp.read()
            break
        except Exception as e:  # the URL holds the key: print the class only
            print(f"{zone} {year}: {type(e).__name__} {getattr(e, 'code', '')} (try {attempt + 1})", flush=True)
            time.sleep(10)
    else:
        return None
    CACHE.mkdir(parents=True, exist_ok=True)
    tmp = target.with_suffix(".part")
    tmp.write_bytes(body)
    tmp.replace(target)
    time.sleep(3)
    return body


def parse(body):
    """{utc hour start (epoch s): price} from an A44 document; 15-min values become hourly means."""
    root = ET.fromstring(body)
    ns = {"n": root.tag.split("}")[0][1:]} if root.tag.startswith("{") else {}
    p = (lambda t: f"n:{t}") if ns else (lambda t: t)
    quarter = defaultdict(list)
    for ts in root.findall(p("TimeSeries"), ns):
        curve = (ts.findtext(p("curveType"), default="A01", namespaces=ns) or "A01").strip()
        for period in ts.findall(p("Period"), ns):
            interval = period.find(p("timeInterval"), ns)
            t0 = datetime.fromisoformat(interval.findtext(p("start"), namespaces=ns).replace("Z", "+00:00"))
            t1 = datetime.fromisoformat(interval.findtext(p("end"), namespaces=ns).replace("Z", "+00:00"))
            res = period.findtext(p("resolution"), namespaces=ns)
            minutes = {"PT60M": 60, "PT15M": 15, "PT30M": 30}[res]
            n = int((t1 - t0).total_seconds() // (minutes * 60))
            pts = {int(x.findtext(p("position"), namespaces=ns)): float(x.findtext(p("price.amount"), namespaces=ns))
                   for x in period.findall(p("Point"), ns)}
            last = None
            for pos in range(1, n + 1):
                if pos in pts:
                    last = pts[pos]
                elif curve != "A03":
                    continue
                if last is None:
                    continue
                t = t0 + timedelta(minutes=minutes * (pos - 1))
                quarter[int(t.timestamp()) // 3600 * 3600].append(last)
    return {h: sum(v) / len(v) for h, v in quarter.items()}


def daily(hours):
    acc = defaultdict(list)
    for h, v in hours.items():
        acc[datetime.fromtimestamp(h, timezone.utc).astimezone(HEL).date()].append(v)
    return {d: (sum(v) / len(v), len(v)) for d, v in acc.items()}


def main():
    sqlite_path, stamp = sys.argv[1], sys.argv[2]
    key = api_key()
    out_dir = HERE / "data" / f"m-export-{stamp}"
    out_dir.mkdir(parents=True, exist_ok=True)
    rows = []
    for zone, eics in ZONES.items():
        hours = {}
        for year in YEARS:
            for eic in eics:
                body = fetch(zone, eic, year, key)
                if body is None or b"<Acknowledgement_MarketDocument" in body:
                    continue
                for h, v in parse(body).items():
                    t = datetime.fromtimestamp(h, timezone.utc)
                    if zone == "DE" and ((eic == eics[0]) != (t < DE_SPLIT)):
                        continue  # keep each German zone only for its own period
                    hours[h] = v
        for d, (v, n) in daily(hours).items():
            if date(2015, 1, 1) <= d <= date(2022, 12, 31):
                rows.append((zone, d, v, n))
    fetched = {(z, d): v for z, d, v, _ in rows}
    con = sqlite3.connect(f"file:{sqlite_path}?mode=ro", uri=True)
    local = defaultdict(dict)
    for region, ts, price in con.execute(
            "select region, timestamp, price_without_tax from spot_prices_hour where region in ('FI','SE1','SE3','DE-LU')"):
        local[region][int(ts)] = price * 10.0  # c/kWh -> EUR/MWh
    for zone, region in LOCAL_REGION.items():
        for d, (v, n) in daily(local[region]).items():
            if d >= date(2023, 1, 1):
                rows.append((zone, d, v, n))
    # Check: FI 2022 fetched vs local (local FI starts 2022).
    diffs = [abs(fetched[("FI", d)] - v) for d, (v, n) in daily(local["FI"]).items()
             if d.year == 2022 and ("FI", d) in fetched and n >= 23]
    print(f"FI 2022 check: {len(diffs)} days, mean |fetched - local| {sum(diffs) / max(len(diffs), 1):.3f} EUR/MWh, max {max(diffs, default=0):.3f}")
    rows.sort(key=lambda r: (r[0], r[1]))
    path = out_dir / "prices_daily.csv"
    with open(path, "w", newline="") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["zone", "date", "eur_mwh", "hours"])
        for z, d, v, n in rows:
            w.writerow([z, d.isoformat(), f"{v:.3f}", n])
    pins_path = HERE / f"m-inputs-{stamp}.json"
    pins = json.loads(pins_path.read_text()) if pins_path.exists() else {"export": f"data/m-export-{stamp}", "files": {}}
    pins["files"]["prices_daily.csv"] = {
        "rows": len(rows), "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "source": "ENTSO-E Transparency A44 2015-2022 (FI, SE1, SE3, DE-AT-LU/DE-LU); local snapshot from 2023",
        "fetched": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
    }
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")
    counts = defaultdict(int)
    for z, d, v, n in rows:
        counts[(z, d.year)] += 1
    print(f"{path}: {len(rows)} rows; days per zone-year: " + ", ".join(
        f"{z}{y % 100}:{c}" for (z, y), c in sorted(counts.items()) if c < 360))


if __name__ == "__main__":
    main()
