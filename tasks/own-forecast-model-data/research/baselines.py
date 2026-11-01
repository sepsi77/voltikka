#!/usr/bin/env python3
"""Short-term model (S) baselines on stored FI day-ahead prices. Read-only.

Issue rule: a run on Helsinki day D at 11:30 knows every hour up to the end of D (the day-ahead
result for D was published on D-1). It forecasts every hour of the Helsinki days D+1..D+7.

Baselines (spec.md, "Short-term model (S)"):
  a  same_hour_last_week: the price of the same Helsinki clock hour 7 days before the target day
  b  last_day_repeated:   the price of the same Helsinki clock hour on day D

Metrics per horizon day k = 1..7 (EUR/MWh, VAT 0):
  hourly MAE and RMSE, MAE of the daily mean, absolute error of the daily maximum,
  and the hit rate of the 3 cheapest / 3 most expensive hours of the target day
  (share of the true 3 hours that the forecast also puts in its 3).

Usage: python3 -I baselines.py <sqlite path> [--from YYYY-MM-DD] [--to YYYY-MM-DD]
"""
import argparse
import math
import sqlite3
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
WINTER = {11, 12, 1, 2, 3}


def load(path):
    # Read-only URI: never write to the database.
    con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    rows = con.execute(
        "select timestamp, price_without_tax from spot_prices_hour where region = 'FI' order by timestamp"
    ).fetchall()
    con.close()
    # day -> {local hour label -> EUR/MWh}. On DST days a label can repeat or be absent; keying
    # by (hour, fold) keeps the 25th hour separate and skips the missing one.
    days = defaultdict(dict)
    for ts, price in rows:
        local = datetime.fromtimestamp(ts, timezone.utc).astimezone(HEL)
        days[local.date()][(local.hour, local.fold)] = price * 10.0
    return days


def top3(values, reverse):
    order = sorted(values, key=lambda k: values[k], reverse=reverse)
    return set(order[:3])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("db")
    ap.add_argument("--from", dest="start", default="2023-01-08")
    ap.add_argument("--to", dest="end", default=None)
    args = ap.parse_args()

    days = load(args.db)
    complete = {d for d, h in days.items() if len(h) >= 23}
    start = date.fromisoformat(args.start)
    end = date.fromisoformat(args.end) if args.end else max(complete) - timedelta(days=7)

    # acc[(baseline, k, season)] -> lists
    acc = defaultdict(lambda: defaultdict(list))
    d = start
    while d <= end:
        if d in complete:
            for k in range(1, 8):
                target = d + timedelta(days=k)
                if target not in complete:
                    continue
                actual = days[target]
                sources = {"a_same_hour_last_week": days.get(target - timedelta(days=7)), "b_last_day_repeated": days[d]}
                for name, src in sources.items():
                    if not src:
                        continue
                    keys = [h for h in actual if h in src]
                    if len(keys) < 23:
                        continue
                    fc = {h: src[h] for h in keys}
                    act = {h: actual[h] for h in keys}
                    season = "winter" if target.month in WINTER else "summer"
                    for bucket in (season, "all"):
                        a = acc[(name, k, bucket)]
                        a["abs"].extend(abs(fc[h] - act[h]) for h in keys)
                        a["sq"].extend((fc[h] - act[h]) ** 2 for h in keys)
                        a["daymean"].append(abs(sum(fc.values()) / len(fc) - sum(act.values()) / len(act)))
                        a["daymax"].append(abs(max(fc.values()) - max(act.values())))
                        a["cheap"].append(len(top3(fc, False) & top3(act, False)) / 3)
                        a["dear"].append(len(top3(fc, True) & top3(act, True)) / 3)
        d += timedelta(days=1)

    print(f"FI day-ahead, issue days {start}..{end}, EUR/MWh VAT 0")
    print(f"{'baseline':24} {'k':>2} {'season':7} {'days':>5} {'MAE':>7} {'RMSE':>7} {'dayMAE':>7} {'maxAE':>7} {'cheap3':>7} {'dear3':>7}")
    for (name, k, bucket), a in sorted(acc.items()):
        n = len(a["daymean"])
        print(
            f"{name:24} {k:>2} {bucket:7} {n:>5} {sum(a['abs'])/len(a['abs']):7.2f} "
            f"{math.sqrt(sum(a['sq'])/len(a['sq'])):7.2f} {sum(a['daymean'])/n:7.2f} "
            f"{sum(a['daymax'])/n:7.2f} {sum(a['cheap'])/n:7.2f} {sum(a['dear'])/n:7.2f}"
        )


if __name__ == "__main__":
    main()
