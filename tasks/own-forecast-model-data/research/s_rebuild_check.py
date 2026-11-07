#!/usr/bin/env python3
"""Rebuild check (s-model-freeze.md gap 2, point 3): the feature tables that production builds
from the database and the archive must equal the research tables built from the export.

For both s_v1 views it builds the table of every issue up to --end from (a) the research export
and (b) the database (voltikka_forecast.s.data.load with the pinned archive), and compares the
model input columns row by row. p_thirdparty (scoring only) and temp_day (p_standin only) are
left out: production does not read them into the model. Every difference is listed by column
and month, so it can be explained (for example a later correction of a Fingrid hour).

Database: --sqlite <path> (a local copy) or the DB_* variables (MySQL, read-only use).
Usage: nice .venv/bin/python -I s_rebuild_check.py 20261102 --archive <dir> [--sqlite <path>] [--end 2026-10-24]
"""
import os

os.environ.setdefault("OMP_NUM_THREADS", "2")

import argparse
import sys
import time
from datetime import date
from pathlib import Path

import numpy as np
import pandas as pd

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parents[2] / "forecast" / "src"))
sys.path.insert(0, str(HERE))  # python -I does not add the script folder
from voltikka_forecast.s import core as C  # noqa: E402
from voltikka_forecast.s import data as D  # noqa: E402
from voltikka_forecast.s import v1  # noqa: E402

import s_backtest as sb  # noqa: E402

SKIP = {"p_thirdparty", "temp_day"}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("stamp")
    ap.add_argument("--archive", required=True)
    ap.add_argument("--sqlite")
    ap.add_argument("--end", default="2026-10-24")
    args = ap.parse_args()
    t0 = time.time()
    end = date.fromisoformat(args.end)
    _pins, research = sb.load_export(args.stamp)
    db = D.Db.from_sqlite(args.sqlite) if args.sqlite else D.Db.from_env()
    prod = D.load(db, first_issue=C.FIRST_ISSUE, last_issue=end, archive=D.read_archive(args.archive))
    print(f"inputs read, {time.time() - t0:.0f} s", flush=True)
    bad = 0
    for name in v1.VIEWS:
        with v1.view(name):
            a = C.build_table(research, end)
            b = C.build_table(prod, end)  # same rule as the research table: every target known
        keys = ["issue", "target_ts"]
        cols = [c for c in a.columns if c not in SKIP and c not in keys]
        missing = [c for c in cols if c not in b.columns]
        m = a.merge(b, on=keys, how="outer", suffixes=("_r", "_p"), indicator=True)
        rows = m["_merge"].value_counts().to_dict()
        print(f"{name}: research {len(a)} rows, production {len(b)} rows, {rows}; missing columns {missing}", flush=True)
        both = m[m["_merge"] == "both"]
        for c in cols:
            if c in missing:
                continue
            x, y = both[f"{c}_r"], both[f"{c}_p"]
            if x.dtype == object or y.dtype == object:
                diff = ~((x == y) | (x.isna() & y.isna()))
            else:
                diff = ~(np.isclose(x.astype(float), y.astype(float), rtol=0, atol=1e-9, equal_nan=True))
            if diff.any():
                months = pd.Series([str(d)[:7] for d in both.loc[diff, "issue"]]).value_counts().sort_index()
                print(f"  {c}: {int(diff.sum())} rows differ; by issue month {months.to_dict()}")
                bad += int(diff.sum())
        bad += rows.get("left_only", 0) + rows.get("right_only", 0) + len(missing)
    print(f"{'PASS' if bad == 0 else 'DIFFERENCES'}: {bad}; {time.time() - t0:.0f} s")
    sys.exit(0 if bad == 0 else 1)


if __name__ == "__main__":
    main()
