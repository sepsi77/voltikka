#!/usr/bin/env python3
"""SEAS5 skill check for the Finland region: forecast vs observed monthly temperature and
precipitation. Read-only on the SQLite snapshot and on data/seas5/seas5_regions.csv.

Observed: the mean of the 6 Finnish ERA5 points in weather_daily_observations (Helsinki, Turku,
Tampere, Jyväskylä, Kuopio, Oulu), monthly mean of daily values. Months with fewer than 28 days
on any point are skipped.

Anomalies on both sides are against the calendar-month mean of this sample (starts 2017-11 and
later), per lead for the forecast. This removes the warming offset against the 1993-2016 model
climate (decisions.md, 2026-11-02) and any model bias. It is an in-sample reference; it does not
change correlations, and it slightly favours the coverage figure.

Reported per lead: n, correlation of the ensemble-mean anomaly with the observed anomaly (all
months and Nov-Mar), and p10-p90 coverage (share of months whose observed anomaly lies inside the
10th-90th percentile of the member anomalies; 0.80 is a calibrated ensemble).

Lead 1 is the start month itself: at the as-of time (day 6) about 5 days of it are already past.

Usage: python3 -I seas5_skill.py <sqlite path> [csv path]
"""
import csv
import sqlite3
import statistics as st
import sys
from collections import defaultdict
from pathlib import Path

FI_POINTS = ("helsinki", "turku", "tampere", "jyvaskyla", "kuopio", "oulu")
WINTER = {11, 12, 1, 2, 3}
# variable in the CSV -> (observed column, factor from the SEAS5 unit to the observed unit)
VARIABLES = {
    "2t": ("temperature_2m_mean", None),        # K -> °C is an offset; anomalies make it vanish
    "tprate": ("precipitation_sum", 86400e3),   # m/s -> mm/day
}


def observed(db):
    con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    out = {}
    for var, (col, _f) in VARIABLES.items():
        per = defaultdict(lambda: defaultdict(list))
        q = f"select point, date, {col} from weather_daily_observations where point in ({','.join('?' * len(FI_POINTS))}) and date >= '2017-11-01' and {col} is not null"
        for point, d, v in con.execute(q, FI_POINTS):
            per[d[:7]][point].append(v)
        out[var] = {
            m: st.mean(st.mean(v) for v in pts.values())
            for m, pts in per.items()
            if len(pts) == len(FI_POINTS) and min(len(v) for v in pts.values()) >= 28
        }
    con.close()
    return out


def forecasts(path):
    fc = defaultdict(list)  # (var, lead, valid_month) -> member values (observed unit, no offset)
    for r in csv.DictReader(open(path)):
        if r["region"] != "fi" or r["variable"] not in VARIABLES:
            continue
        f = VARIABLES[r["variable"]][1] or 1.0
        fc[(r["variable"], int(r["lead"]), r["valid_month"])].append(float(r["value"]) * f)
    return fc


def corr(x, y):
    mx, my = st.mean(x), st.mean(y)
    sx = sum((a - mx) ** 2 for a in x) ** 0.5
    sy = sum((b - my) ** 2 for b in y) ** 0.5
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (sx * sy)


def month_means(d):
    by = defaultdict(list)
    for k, v in d.items():
        by[k[-2:]].append(v)
    return {m: st.mean(v) for m, v in by.items()}


def main():
    db = sys.argv[1]
    path = sys.argv[2] if len(sys.argv) > 2 else str(Path(__file__).resolve().parent / "data/seas5/seas5_regions.csv")
    obs = observed(db)
    fc = forecasts(path)
    print("SEAS5 region fi vs mean of 6 Finnish ERA5 points; anomalies vs sample calendar-month mean")
    print(f"{'var':7} {'lead':>4} {'n':>4} {'r_all':>6} {'n_win':>5} {'r_win':>6} {'p10-p90':>8}")
    for var in VARIABLES:
        o = obs[var]
        for lead in range(1, 7):
            members = {vm: v for (vv, l, vm), v in fc.items() if vv == var and l == lead and vm in o}
            if len(members) < 12:
                continue
            em = {vm: st.mean(v) for vm, v in members.items()}
            fmm, omm = month_means(em), month_means({k: o[k] for k in em})
            fa = {k: em[k] - fmm[k[-2:]] for k in em}
            oa = {k: o[k] - omm[k[-2:]] for k in em}
            ks = sorted(em)
            win = [k for k in ks if int(k[-2:]) in WINTER]
            inside = 0
            for k in ks:
                q = sorted(v - fmm[k[-2:]] for v in members[k])
                lo, hi = q[int(0.1 * (len(q) - 1))], q[int(round(0.9 * (len(q) - 1)))]
                inside += lo <= oa[k] <= hi
            print(f"{var:7} {lead:>4} {len(ks):>4} {corr([fa[k] for k in ks], [oa[k] for k in ks]):6.2f} "
                  f"{len(win):>5} {corr([fa[k] for k in win], [oa[k] for k in win]):6.2f} {inside / len(ks):8.2f}")


if __name__ == "__main__":
    main()
