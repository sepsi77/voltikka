#!/usr/bin/env python3
"""Day-ahead border capacity of the Finnish borders, one series from 2023, for the S backtest.

Two sources, because the capacity method changed with the Nordic flow-based market coupling
(first delivery day 2024-10-30):
  - until 2024-10-29 22:00 UTC: Fingrid "Day-ahead transmission capacity ... - official" (NTC):
    24 SE1-FI, 25 SE3-FI, 26 FI-SE1, 27 FI-SE3, 112 EE-FI, 115 FI-EE. Since flow-based these
    datasets hold only zeros or nothing (laravel/app/Services/Fingrid/AGENTS.md). FI-> values are
    negative in the data; stored here as positive MW.
  - from 2024-10-29 23:00 UTC: JAO Nordic `maxBorderFlow` hourly mean (`jao_max_border_flows` in
    the local SQLite snapshot, laravel/app/Services/Jao/AGENTS.md). It is not an NTC: the largest
    flow the flow-based domain allows on that border, given the others. The backtest adds a
    flow-based flag so a model can see the change of method.
Both are published the day before delivery, so a run on day D knows the values through the end
of D only.

The Fingrid history is fetched once (about 6-12 requests, 7 s apart) with the key from
laravel/.env (never printed) and cached in research/data/fingrid-ntc/<id>.json (git-ignored,
untrusted data). JAO data is research use only (JAO terms; see Jao/AGENTS.md).

Output: research/data/s-export-<stamp>/border_capacity.csv (border, timestamp, capacity_mw,
source), and its pin in research/s-inputs-<stamp>.json.

Usage: python3 -I s_border_capacity.py <sqlite path> <YYYYMMDD of the export>
"""
import csv
import hashlib
import json
import sqlite3
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
ENV = HERE.parents[2] / "laravel" / ".env"
CACHE = HERE / "data" / "fingrid-ntc"
API = "https://data.fingrid.fi/api/datasets/{id}/data"
FINGRID = {24: "SE1_FI", 25: "SE3_FI", 26: "FI_SE1", 27: "FI_SE3", 112: "EE_FI", 115: "FI_EE"}
START, JAO_START = "2023-01-01T00:00:00Z", "2024-10-29T23:00:00Z"


def api_key():
    for line in ENV.read_text().splitlines():
        if line.startswith("FINGRID_API_KEY="):
            return line.split("=", 1)[1].strip().strip('"')
    sys.exit("FINGRID_API_KEY is not in laravel/.env")


def fetch(dataset_id, key):
    target = CACHE / f"{dataset_id}.json"
    if target.exists():
        return json.loads(target.read_text())
    rows, page = [], 1
    while page:
        query = urllib.parse.urlencode({"startTime": START, "endTime": JAO_START, "format": "json",
                                        "pageSize": 20000, "page": page, "sortOrder": "asc"})
        req = urllib.request.Request(f"{API.format(id=dataset_id)}?{query}", headers={"x-api-key": key})
        with urllib.request.urlopen(req, timeout=60) as resp:
            payload = json.loads(resp.read())
        rows += payload["data"]
        page = (payload.get("pagination") or {}).get("nextPage")
        time.sleep(7)
    CACHE.mkdir(parents=True, exist_ok=True)
    tmp = target.with_suffix(".part")
    tmp.write_text(json.dumps(rows))
    tmp.replace(target)
    return rows


def main():
    sqlite_path, stamp = sys.argv[1], sys.argv[2]
    key = api_key()
    jao_start = int(datetime.fromisoformat(JAO_START.replace("Z", "+00:00")).timestamp())
    out = HERE / "data" / f"s-export-{stamp}" / "border_capacity.csv"
    rows = 0
    with open(out, "w", newline="") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["border", "timestamp", "capacity_mw", "source"])
        for dataset_id, border in FINGRID.items():
            seen = set()
            for r in fetch(dataset_id, key):
                ts = int(datetime.fromisoformat(r["startTime"].replace("Z", "+00:00")).timestamp())
                if ts >= jao_start or ts in seen or r.get("value") is None:
                    continue
                seen.add(ts)
                w.writerow([border, ts, f"{abs(float(r['value'])):.1f}", f"fingrid_{dataset_id}"])
                rows += 1
        con = sqlite3.connect(f"file:{sqlite_path}?mode=ro", uri=True)
        for border, ts, mw in con.execute(
                "select border, timestamp, capacity_mw from jao_max_border_flows "
                "where border in (%s) and timestamp >= ? order by border, timestamp"
                % ",".join("?" * len(FINGRID)), (*FINGRID.values(), jao_start)):
            w.writerow([border, ts, f"{float(mw):.1f}", "jao"])
            rows += 1
    pins_path = HERE / f"s-inputs-{stamp}.json"
    pins = json.loads(pins_path.read_text())
    pins["files"]["border_capacity.csv"] = {
        "rows": rows, "sha256": hashlib.sha256(out.read_bytes()).hexdigest(),
        "source": "Fingrid open data 24/25/26/27/112/115 (CC BY 4.0) until 2024-10-29 22:00 UTC; "
                  "JAO Nordic publication tool maxBorderFlow (research use) from 2024-10-29 23:00 UTC",
        "fetched": datetime.now(timezone.utc).strftime("%Y-%m-%d"),
    }
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")
    print(f"{out}: {rows} rows")


if __name__ == "__main__":
    main()
