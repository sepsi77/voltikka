"""Inputs for the consumer layer prototype: hourly consumption weights per home type and an
hourly spot price path for the coming months. Read-only on the SQLite copy.

Usage: python3 -I consumer_inputs.py <sqlite> <out.json> [--review 2026-11 --past 2026-09 --trade-date 2026-10-30]

Home types (Fingrid Datahub 364, verified 2026-11-02 on data.fingrid.fi/en/datasets/364):
BE01 apartment in an apartment block, BE02 electrically heated small house, BE03 small house
without electric heating. Values are the average kWh of one accounting point in one hour.

Past month: the real Datahub hours of the month (the engine reads the real spot itself).

Coming months, analog method: for each past winter with Datahub data (2023-24, 2024-25,
2025-26) the target hour takes the source hour with the same month, day of month and local hour
(same day of month; the last source day repeats if the target month is longer). Consumption = the source hour's kWh. Price (c/kWh, VAT 0) = source spot
- mean of the mapped source hours + FI month future (EEX settlement on the trade date). So each analog keeps
its own weather pattern (cold-day consumption together with high prices) around the futures
level. The analogs are scenarios, not probabilities.
"""

import json
import sqlite3
import sys
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
GROUPS = {"BE01": "apartment", "BE02": "electric_heating_house", "BE03": "house_no_electric_heating"}
VAT = 1.255


def arg(name, default):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


def utc_text(dt):
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def month_hours(year, month):
    """UTC hour starts of one Helsinki calendar month."""
    start = datetime(year, month, 1, tzinfo=HEL)
    end = datetime(year + (month == 12), month % 12 + 1, 1, tzinfo=HEL)
    cur, out = start.astimezone(timezone.utc), []
    while cur < end.astimezone(timezone.utc):
        out.append(cur)
        cur += timedelta(hours=1)
    return out


def add_months(y, m, n):
    k = y * 12 + (m - 1) + n
    return k // 12, k % 12 + 1


def load(db, sql, params=()):
    return db.execute(sql, params).fetchall()


def main():
    db = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
    out_path = sys.argv[2]
    ry, rm = map(int, arg("--review", "2026-11").split("-"))
    trade_date = arg("--trade-date", "2026-10-30")
    horizon = int(arg("--months", "12"))

    cons = {}
    for key, ts, val in load(db, "select series_key, utc_datetime, value from fingrid_hourly_values where dataset_id=364 and series_key in ('UserGroup=BE01','UserGroup=BE02','UserGroup=BE03')"):
        cons.setdefault(key.split("=")[1], {})[ts] = val
    spot = {ts: p for ts, p in load(db, "select utc_datetime, price_without_tax from spot_prices_hour where region='FI'")}

    result = {"review_month": f"{ry}-{rm:02d}", "trade_date": trade_date, "past": {}, "forward": {}}

    # Past month (default: the month before the review).
    py, pm = map(int, arg("--past", "%d-%02d" % add_months(ry, rm, -1)).split("-"))
    hours = month_hours(py, pm)
    for g in GROUPS:
        w = {int(h.timestamp()): cons[g].get(utc_text(h)) for h in hours}
        missing = [k for k, v in w.items() if v is None]
        result["past"][g] = {
            "month": f"{py}-{pm:02d}", "start": f"{py}-{pm:02d}-01", "end": (datetime(py + (pm == 12), pm % 12 + 1, 1) - timedelta(days=1)).strftime("%Y-%m-%d"),
            "kwh": round(sum(v for v in w.values() if v is not None), 3), "missing_hours": len(missing),
            "weights": {str(k): v for k, v in w.items() if v is not None},
        }

    # Coming months: futures level per month.
    months = [add_months(ry, rm, i) for i in range(horizon)]
    def settle(code, y, m):
        row = load(db, "select settlement_price from electricity_futures_eod_prices where area='FI' and short_code=? and maturity=? and trade_date=?", (code, f"{y}{m:02d}", trade_date))
        return row[0][0] / 10 if row else None  # EUR/MWh -> c/kWh, VAT 0

    # Month future where it exists; otherwise the quarter level after removing the quarter's
    # months that have their own month future (equal-weight months; an approximation).
    fut, fut_source = {}, {}
    for y, m in months:
        v = settle("FNBM", y, m)
        if v is not None:
            fut[(y, m)], fut_source[(y, m)] = v, "FNBM"
            continue
        qm = (m - 1) // 3 * 3 + 1
        q = settle("FNBQ", y, qm)
        if q is None:
            sys.exit(f"no FNBM {y}{m:02d} or FNBQ {y}{qm:02d} on {trade_date}")
        known = [settle("FNBM", y, k) for k in range(qm, qm + 3)]
        known = [k for k in known if k is not None]
        fut[(y, m)], fut_source[(y, m)] = (3 * q - sum(known)) / (3 - len(known)), "FNBQ" + ("-FNBM" if known else "")

    winter_start = ry if rm >= 7 else ry - 1
    for back in (3, 2, 1):  # winters 2023-24, 2024-25, 2025-26 for a 2026-27 target
        label = f"{winter_start - back}-{(winter_start - back + 1) % 100:02d}"
        scen = {"label": f"weather of winter {label}", "prices": {}, "groups": {g: {} for g in GROUPS}, "months": {}}
        for y, m in months:
            sy = y - back
            src_hours = month_hours(sy, m)
            src_by_local = {}
            for h in src_hours:
                loc = h.astimezone(HEL)
                src_by_local.setdefault(loc.date(), {})[loc.hour] = h
            src_prices = [spot.get(utc_text(h)) for h in src_hours]
            if any(p is None for p in src_prices):
                sys.exit(f"spot gap in source {sy}-{m:02d}")
            src_mean = sum(src_prices) / len(src_prices)
            # Only days with complete Datahub hours (Datahub arrives about 4 days late).
            src_days = sorted(d for d, hrs in src_by_local.items() if all(utc_text(h) in cons[g] for g in GROUPS for h in hrs.values()))
            month_kwh = {g: 0.0 for g in GROUPS}
            raw = {}
            for h in month_hours(y, m):
                loc = h.astimezone(HEL)
                d = src_days[min(loc.day, len(src_days)) - 1]
                hrs = src_by_local[d]
                sh = hrs.get(loc.hour) or hrs.get(loc.hour - 1) or hrs[min(hrs)]  # DST gap
                key = str(int(h.timestamp()))
                raw[key] = spot[utc_text(sh)]
                for g in GROUPS:
                    v = cons[g].get(utc_text(sh))
                    if v is None:
                        sys.exit(f"datahub gap {g} {utc_text(sh)}")
                    scen["groups"][g][key] = v
                    month_kwh[g] += v
            # Level = the mean of the mapped hours (Feb 29 is skipped; a longer month repeats a day).
            # Weekday matching was tried first: it repeated cold source days (Jan 2024 mean 10.6 ->
            # 14.2 c/kWh). The cost needs only the hour pairs of consumption and price.
            mapped_mean = sum(raw.values()) / len(raw)
            for key, v in raw.items():
                scen["prices"][key] = round(v - mapped_mean + fut[(y, m)], 4)
            scen["months"][f"{y}-{m:02d}"] = {"future_c_kwh_vat0": round(fut[(y, m)], 3), "future_source": fut_source[(y, m)], "source_month": f"{sy}-{m:02d}", "source_mean_c_kwh_vat0": round(src_mean, 3), "mapped_mean_c_kwh_vat0": round(mapped_mean, 3), "kwh": {g: round(v, 1) for g, v in month_kwh.items()}}
        result["forward"][str(back)] = scen

    first, last = months[0], months[-1]
    result["forward_period"] = {"start": f"{first[0]}-{first[1]:02d}-01", "end": (datetime(last[0] + (last[1] == 12), last[1] % 12 + 1, 1) - timedelta(days=1)).strftime("%Y-%m-%d")}
    result["vat"] = VAT
    with open(out_path, "w") as f:
        json.dump(result, f)

    print("past", {g: (v["kwh"], v["missing_hours"]) for g, v in result["past"].items()})
    for back, s in result["forward"].items():
        print(s["label"], {k: (v["source_month"], v["kwh"]) for k, v in s["months"].items()})


if __name__ == "__main__":
    main()
