#!/usr/bin/env python3
"""Export the medium-term model (M) inputs from the local SQLite snapshot and pin them. Read-only.

Files in research/data/m-export-<stamp>/ (git-ignored), pins in research/m-inputs-<stamp>.json:
  reservoirs.csv  ENTSO-E A72 weekly stored energy (MWh) for FI, NO, SE from 2015; the value of a
                  week is published after the week (as-of: a run uses weeks that ended at least
                  7 days before it; ENTSO-E publishes on the Wednesday after the week)
  weather.csv     ERA5 daily mean temperature and snowfall water equivalent at the 6 Finnish
                  points from 1991 (ERA5 is about 5-6 days late: as-of lag 7 days)
  futures.csv     FI Base month futures settlements (EEX, from 2026-04-08 only): baseline (a)
Prices come from m_prices.py (prices_daily.csv in the same folder).

Usage: python3 -I m_export.py <sqlite path> <YYYYMMDD>
"""
import csv
import hashlib
import json
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
FI_POINTS = ("helsinki", "turku", "tampere", "jyvaskyla", "kuopio", "oulu")
QUERIES = {
    "reservoirs.csv": """
        select zone, week_start_utc, iso_year, iso_week, stored_energy_mwh from entsoe_reservoir_weeks
        where zone in ('FI', 'NO', 'SE') order by zone, week_start_utc""",
    "weather.csv": f"""
        select point, date, temperature_2m_mean, snowfall_water_equivalent_sum from weather_daily_observations
        where point in ({','.join(repr(p) for p in FI_POINTS)}) order by point, date""",
    # M23: NVE Norway total filling ratio, weekly from 1995 (published on the Wednesday after the
    # week; as-of: week end + 4 days).
    "nve.csv": """
        select iso_year, iso_week, week_end_date, filling_ratio from nve_reservoir_weeks
        where area_type = 'NO' and area_no = 0 order by iso_year, iso_week""",
    "futures.csv": """
        select trade_date, maturity, settlement_price from electricity_futures_eod_prices
        where area = 'FI' and product = 'Base' and maturity_type = 'month' order by trade_date, maturity""",
}


def main():
    sqlite_path, stamp = sys.argv[1], sys.argv[2]
    out_dir = HERE / "data" / f"m-export-{stamp}"
    out_dir.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(f"file:{sqlite_path}?mode=ro", uri=True)
    pins_path = HERE / f"m-inputs-{stamp}.json"
    pins = json.loads(pins_path.read_text()) if pins_path.exists() else {"export": f"data/m-export-{stamp}", "files": {}}
    for name, sql in QUERIES.items():
        cur = con.execute(sql)
        path = out_dir / name
        with open(path, "w", newline="") as fh:
            w = csv.writer(fh, lineterminator="\n")
            w.writerow([c[0] for c in cur.description])
            n = 0
            for row in cur:
                w.writerow(row)
                n += 1
        pins["files"][name] = {"rows": n, "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
                               "source": "local SQLite snapshot", "exported": datetime.now(timezone.utc).strftime("%Y-%m-%d")}
        print(f"{name}: {n} rows")
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")


if __name__ == "__main__":
    main()
