#!/usr/bin/env python3
"""Export the short-term model (S) inputs from a local SQLite snapshot and pin them. Read-only.

Same pattern as tasks/forecast-falling-market-study/export.py: plain CSV files, one SHA-256 and
row count per file. The CSV files go to research/data/s-export-<date>/ (git-ignored, about
12 MB); the pins go to research/s-inputs-<date>.json (committed), so a later run can prove that
it used the same inputs.

Units are kept as stored (spot c/kWh VAT 0, Fingrid MW, outages MW). s_backtest.py converts.

Usage: python3 -I s_export.py <sqlite path> <YYYYMMDD>
"""
import csv
import hashlib
import json
import sqlite3
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent

# Fingrid datasets used by the S model (laravel/config/fingrid.php):
# 74 production, 75 wind, 124 consumption, 188 nuclear, 191 hydro, 194 net import,
# 246 wind forecast (daily, upserted: only the last value per hour is stored), 268 wind capacity.
FINGRID = (74, 75, 124, 188, 191, 194, 246, 268)
FI_POINTS = ("helsinki", "turku", "tampere", "jyvaskyla", "kuopio", "oulu")

QUERIES = {
    "spot.csv": """
        select region, timestamp, price_without_tax from spot_prices_hour
        where region in ('FI', 'SE1', 'SE3', 'EE', 'DE-LU') and timestamp >= 1640995200
        order by region, timestamp""",
    "fingrid.csv": f"""
        select dataset_id, timestamp, value from fingrid_hourly_values
        where series_key = '' and dataset_id in ({','.join(str(d) for d in FINGRID)})
        order by dataset_id, timestamp""",
    # Nuclear unavailability (FI, B14: A77 production units, A80 generation units) and the
    # planned/forced import capacity of the three FI borders (A78). Every revision is kept;
    # the backtest selects the revision that existed at the issue time.
    "outages.csv": """
        select document_type, zone, document_mrid, revision_number, time_series_mrid,
               business_type, coalesce(doc_status, '') doc_status, is_cancelled,
               document_created_at, resource_name, coalesce(unit_name, '') unit_name,
               nominal_mw, available_periods
        from entsoe_outages
        where (zone = 'FI' and psr_type = 'B14' and document_type in ('A77', 'A80'))
           or (document_type = 'A78' and zone in ('SE1_FI', 'SE3_FI', 'EE_FI'))
        order by id""",
    "futures.csv": """
        select trade_date, maturity_type, maturity, settlement_price
        from electricity_futures_eod_prices
        where area = 'FI' and product = 'Base' and maturity_type in ('month', 'quarter', 'year')
        order by trade_date, maturity_type, maturity""",
    "weather_daily.csv": f"""
        select point, date, temperature_2m_mean, wind_speed_100m_mean
        from weather_daily_observations
        where point in ({','.join(repr(p) for p in FI_POINTS)}) and date >= '2022-01-01'
        order by point, date""",
    # Baseline (c), the third-party vintages: scored in phase 3 only (they start 2026-11-01).
    "thirdparty_runs.csv": """
        select id, source, fetched_at, first_utc_datetime, last_utc_datetime, points
        from spot_price_forecast_runs where region = 'FI' order by id""",
}


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    db, stamp = sys.argv[1], sys.argv[2]
    out = HERE / "data" / f"s-export-{stamp}"
    out.mkdir(parents=True, exist_ok=True)
    con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    pins = {}
    for name, sql in QUERIES.items():
        cur = con.execute(sql)
        path = out / name
        rows = 0
        with open(path, "w", newline="") as fh:
            w = csv.writer(fh, lineterminator="\n")
            w.writerow([d[0] for d in cur.description])
            for row in cur:
                w.writerow(row)
                rows += 1
        pins[name] = {"rows": rows, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}
    con.close()
    pin_path = HERE / f"s-inputs-{stamp}.json"
    pin_path.write_text(json.dumps({"export": f"data/s-export-{stamp}", "files": pins}, indent=2) + "\n")
    print(pin_path.read_text())


if __name__ == "__main__":
    main()
