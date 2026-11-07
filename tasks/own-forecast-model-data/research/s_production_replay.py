#!/usr/bin/env python3
"""Replay check of the production S code path (forecast/ package, voltikka_forecast.s.v1).

For each given issue day D: train s_v1 on the research export as the weekly refit of D's Monday
would (v1.train), predict D (v1.predict), and compare the D+2..D+7 output with the frozen
backtest rows: D+2 = E32 s_best (k=1), D+3..D+7 = the E30 parts composed with l_lear_hour
(k=3..7); p50 = s_best_p50, p10/p90 = s_best_scal_p10/p90. The E30 file's own s_best columns are
not used: for k=2..4 they were composed with l_lear_k (E27) before that blend was removed, so they
are not s_v1 (the frozen score was composed from the parts by s_compare.py). The range calibration
uses the misses of the same backtest files (best_d1 from E32, best_d24/best_d57 from E30), which is
what seasonal_calibration used.

Exit 1 when a value differs by more than --tol (default 1e-9 EUR/MWh).

Usage: OMP_NUM_THREADS=2 nice .venv/bin/python -I s_production_replay.py 20261102 2025-01-08 2026-04-15 ...
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
from voltikka_forecast.s import v1  # noqa: E402

import s_backtest as sb  # noqa: E402  (export reader only)


def _report_only(check):
    """v1.predict refuses a live issue with an empty input. History has empty inputs by design
    (no FI futures before 2026-04-08), and the backtest predicted them, so the replay reports the
    refusal and continues."""
    def wrapped(table, name, live=False):
        try:
            check(table, name, live=live)
        except v1.InputMissing as exc:
            print(f"  (a live run would refuse: {exc})", flush=True)
    return wrapped


v1._check_table = _report_only(v1._check_table)


def misses(e30, e32):
    out = []
    for frame, part, ks in ((e32, "best_d1", (1,)), (e30, "best_d24", (2, 3, 4)), (e30, "best_d57", tuple(range(1, 8)))):
        rows = frame[frame["k"].isin(ks) & frame[f"{part}_p50"].notna()]
        out.append(pd.DataFrame({"part": part, "tdate": rows["tdate"], "k": rows["k"], "month": rows["month"],
                                 "y": rows["y"], "p10": rows[f"{part}_p10"], "p90": rows[f"{part}_p90"]}))
    return pd.concat(out, ignore_index=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("stamp")
    ap.add_argument("issues", nargs="+")
    ap.add_argument("--tol", type=float, default=1e-9)
    args = ap.parse_args()
    t0 = time.time()
    _pins, data = sb.load_export(args.stamp)
    read = lambda tag: pd.read_csv(HERE / "data" / f"s-predictions-{args.stamp}-{tag}.csv.gz",
                                   parse_dates=False).assign(issue=lambda f: f["issue"].map(date.fromisoformat),
                                                             tdate=lambda f: f["tdate"].map(date.fromisoformat))
    e30, e32 = read("E30"), read("E32")
    sb.compose_best(e30)  # s_v1 blend (l_lear_hour) from the frozen E30 parts; see the docstring
    miss = misses(e30, e32)
    worst = 0.0
    models = {}
    for text in args.issues:
        issue = date.fromisoformat(text)
        monday = v1.refit_monday(issue)
        if monday not in models:
            models[monday] = v1.train(data, monday, miss)
            print(f"trained {monday}: {models[monday]['facts']}, {time.time() - t0:.0f} s", flush=True)
        out, _parts = v1.predict(data, models[monday], issue)
        ref = pd.concat([e32[(e32["issue"] == issue) & (e32["k"] == 1)],
                         e30[(e30["issue"] == issue) & e30["k"].between(3, 7)]])
        ref = ref.set_index("target_ts")[["s_best_scal_p10", "s_best_p50", "s_best_scal_p90"]]
        got = out.set_index("target_ts")[["p10", "p50", "p90"]]
        if len(ref) != len(got) or not ref.index.equals(got.index):
            print(f"{issue}: target hours differ ({len(got)} vs {len(ref)} backtest rows)")
            worst = np.inf
            continue
        diff = float(np.nanmax(np.abs(got.to_numpy() - ref.to_numpy())))
        worst = max(worst, diff)
        print(f"{issue}: {len(got)} hours, max |difference| {diff:.3g} EUR/MWh, {time.time() - t0:.0f} s", flush=True)
    print(f"worst {worst:.3g}; {'PASS' if worst <= args.tol else 'FAIL'} (tolerance {args.tol})")
    sys.exit(0 if worst <= args.tol else 1)


if __name__ == "__main__":
    main()
