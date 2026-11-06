"""Prototype metrics for one monthly market review.

Reads the local SQLite copy of production (scripts/sync-production-database.sh) and writes
metrics-YYYY-MM.json. Read-only. Usage:

    python3 -I compute.py ../../../laravel/database/database.sqlite 2026-09 2026-10-07
"""

import json
import sqlite3
import statistics
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
VAT = 0.255  # spot_prices_hour.vat_rate for FI since 2024-09; all prices here are c/kWh


def month_bounds_utc(year, month):
    start = datetime(year, month, 1, tzinfo=HEL)
    end = datetime(year + (month == 12), month % 12 + 1, 1, tzinfo=HEL)
    fmt = "%Y-%m-%d %H:%M:%S"
    return start.astimezone(timezone.utc).strftime(fmt), end.astimezone(timezone.utc).strftime(fmt)


def to_local(utc_text):
    return datetime.strptime(utc_text, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc).astimezone(HEL)


def percentile(values, p):
    values = sorted(values)
    k = (len(values) - 1) * p
    lo, hi = int(k), min(int(k) + 1, len(values) - 1)
    return values[lo] + (values[hi] - values[lo]) * (k - lo)


def r(x, n=2):
    return None if x is None else round(x, n)


def spot_month(db, year, month, table="spot_prices_hour", region="FI"):
    start, end = month_bounds_utc(year, month)
    rows = db.execute(
        f"select utc_datetime, price_without_tax from {table} where region=? and utc_datetime>=? and utc_datetime<? order by utc_datetime",
        (region, start, end),
    ).fetchall()
    return [(to_local(u), p) for u, p in rows]


def spot_metrics(hours, quarters):
    vat0 = [p for _, p in hours]
    inc = [p * (1 + VAT) for p in vat0]
    by_day = {}
    for t, p in hours:
        by_day.setdefault(t.date(), []).append(p * (1 + VAT))
    day_means = {d: statistics.mean(v) for d, v in by_day.items()}
    cheapest_day = min(day_means, key=day_means.get)
    dearest_day = max(day_means, key=day_means.get)
    max_h = max(hours, key=lambda x: x[1])
    min_h = min(hours, key=lambda x: x[1])
    max_q = max(quarters, key=lambda x: x[1]) if quarters else None
    spreads = [max(v) - min(v) for v in by_day.values()]
    day = [p * (1 + VAT) for t, p in hours if 7 <= t.hour < 22]
    night = [p * (1 + VAT) for t, p in hours if not 7 <= t.hour < 22]
    weekday = [p * (1 + VAT) for t, p in hours if t.weekday() < 5]
    weekend = [p * (1 + VAT) for t, p in hours if t.weekday() >= 5]
    heat = {}
    for t, p in hours:
        heat.setdefault((t.weekday(), t.hour), []).append(p * (1 + VAT))
    return {
        "hours": len(hours),
        "quarters": len(quarters),
        "mean_c_kwh_vat": r(statistics.mean(inc)),
        "mean_eur_mwh_vat0": r(statistics.mean(vat0) * 10, 1),
        "median_c_kwh_vat": r(statistics.median(inc)),
        "p10_c_kwh_vat": r(percentile(inc, 0.10)),
        "p90_c_kwh_vat": r(percentile(inc, 0.90)),
        "cheapest_day": [cheapest_day.isoformat(), r(day_means[cheapest_day])],
        "dearest_day": [dearest_day.isoformat(), r(day_means[dearest_day])],
        "max_hour": [max_h[0].isoformat(), r(max_h[1] * (1 + VAT))],
        "min_hour": [min_h[0].isoformat(), r(min_h[1] * (1 + VAT))],
        "max_quarter": [max_q[0].isoformat(), r(max_q[1] * (1 + VAT))] if max_q else None,
        "negative_hours": sum(1 for p in vat0 if p < 0),
        "zero_or_negative_hours": sum(1 for p in vat0 if p <= 0),
        "hours_below_1c_vat": sum(1 for p in inc if p < 1),
        "hours_above_20c_vat": sum(1 for p in inc if p > 20),
        "mean_daily_spread_c_kwh_vat": r(statistics.mean(spreads)),
        "day_07_22_c_kwh_vat": r(statistics.mean(day)),
        "night_22_07_c_kwh_vat": r(statistics.mean(night)),
        "weekday_c_kwh_vat": r(statistics.mean(weekday)),
        "weekend_c_kwh_vat": r(statistics.mean(weekend)),
        "daily_means_c_kwh_vat": {d.isoformat(): r(v) for d, v in sorted(day_means.items())},
        "heatmap_weekday_hour_c_kwh_vat": {f"{w}-{h}": r(statistics.mean(v)) for (w, h), v in sorted(heat.items())},
    }


def change(new, old):
    return {"old": old, "abs": r(new - old), "pct": r((new - old) / old * 100, 1)} if old else None


def stat(db, day, segment, metric):
    row = db.execute(
        "select median_value, avg_value, p20_value, p80_value, contract_count from contract_price_daily_statistics "
        "where stat_date=? and segment_key=? and metric_key=? and consumption_kwh is null and pricing_basis='canonical_calculation'",
        (day, segment, metric),
    ).fetchone()
    if not row:
        return None
    med, avg, p20, p80, n = row
    return {"value": r(med if med is not None else avg, 3), "p20": r(p20, 3), "p80": r(p80, 3), "n": n}


def contract_metrics(db, first_day, last_day):
    # A day can lack statistics (2026-10-01 has none), so use the first/last day of the month with data.
    first_day, last_day = db.execute(
        "select min(stat_date), max(stat_date) from contract_price_daily_statistics "
        "where pricing_basis='canonical_calculation' and stat_date between ? and ?", (first_day, last_day)).fetchone()
    out = {"start_date": first_day, "end_date": last_day}
    for seg in ["fixed_term_6", "fixed_term_12", "fixed_term_24", "open_ended", "quarterly", "market_reset", "spot"]:
        for metric in ["energy_price", "monthly_fee"] + (["spot_margin"] if seg == "spot" else []):
            a, b = stat(db, first_day, seg, metric), stat(db, last_day, seg, metric)
            out[f"{seg}.{metric}"] = {"start": a, "end": b, "change": change(b["value"], a["value"]) if a and b else None}
    a = stat(db, first_day, "seller_set_overall", "seller_set_energy_price_index_v1")
    b = stat(db, last_day, "seller_set_overall", "seller_set_energy_price_index_v1")
    out["seller_set_index"] = {"start": a, "end": b, "change": change(b["value"], a["value"])}
    series = {}
    for seg in ["fixed_term_6", "fixed_term_12", "fixed_term_24"]:
        rows = db.execute(
            "select stat_date, median_value from contract_price_daily_statistics where segment_key=? and metric_key='energy_price' "
            "and consumption_kwh is null and pricing_basis='canonical_calculation' and stat_date between ? and ? order by stat_date",
            (seg, first_day, last_day),
        ).fetchall()
        series[seg] = {d: v for d, v in rows}
    out["daily_fixed_medians"] = series
    return out


def offer_movements(db, first_day, last_day):
    """New and withdrawn household offers by first/last observation (Helsinki dates)."""
    household = "(c.target_group in ('Household','Both') or c.target_group is null)"
    q = f"""
        select c.id, c.company_name, c.name, c.pricing_model, c.contract_type, c.replaced_by_contract_id,
               min(o.first_observed_at) first_seen, max(o.last_observed_at) last_seen
        from electricity_contracts c join contract_source_observations o on o.contract_id = c.id
        where {household} group by c.id
    """
    replacements = {row[0] for row in db.execute(
        "select distinct replaced_by_contract_id from electricity_contracts where replaced_by_contract_id is not null")}
    start_utc, end_utc = month_bounds_utc(int(first_day[:4]), int(first_day[5:7]))
    rows = list(db.execute(q))
    on_sale = lambda day: sum(1 for x in rows if x[6][:10] <= day <= x[7][:10])
    new_raw = [x for x in rows if start_utc <= x[6] < end_utc]
    gone_raw = [x for x in rows if start_utc <= x[7] < end_utc]
    # Sellers often republish the same product under a new ID. A trusted replacement link marks it.
    new = [x for x in new_raw if x[0] not in replacements]
    gone = [x for x in gone_raw if x[5] is None]
    by_model = lambda xs: {m: sum(1 for x in xs if x[3] == m) for m in sorted({x[3] for x in xs})}
    return {
        "on_sale_first_day": on_sale(first_day), "on_sale_last_day": on_sale(last_day),
        "first_seen_raw": len(new_raw), "last_seen_raw": len(gone_raw),
        "new_count": len(new), "new_by_pricing_model": by_model(new),
        "withdrawn_count": len(gone), "withdrawn_by_pricing_model": by_model(gone),
        "new_companies": sorted({x[1] for x in new}),
        "note": "Household offers. New/withdrawn exclude contracts with a trusted replacement link. "
                "Raw counts include ID churn and short-lived listings; observation dates are UTC text.",
    }


def futures(db, area, short_code, maturity, before_date):
    row = db.execute(
        "select trade_date, settlement_price from electricity_futures_eod_prices where area=? and short_code=? and maturity=? "
        "and trade_date<? order by trade_date desc limit 1",
        (area, short_code, maturity, before_date),
    ).fetchone()
    return {"trade_date": row[0], "eur_mwh_vat0": row[1], "c_kwh_vat": r(row[1] / 10 * (1 + VAT))} if row else None


def main():
    db_path, month, publish = sys.argv[1], sys.argv[2], sys.argv[3]
    year, mon = int(month[:4]), int(month[5:7])
    db = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    first_day = date(year, mon, 1)
    last_day = (date(year + (mon == 12), mon % 12 + 1, 1) - timedelta(days=1))
    prev = (first_day - timedelta(days=1)).replace(day=1)

    cur = spot_metrics(spot_month(db, year, mon), spot_month(db, year, mon, "spot_prices_quarter"))
    prev_m = spot_metrics(spot_month(db, prev.year, prev.month), [])
    yoy_m = spot_metrics(spot_month(db, year - 1, mon), [])

    nxt = date(year + (mon == 12), mon % 12 + 1, 1)
    nxt2 = date(nxt.year + (nxt.month == 12), nxt.month % 12 + 1, 1)
    q_next = date(nxt.year + (nxt.month > 9), ((nxt.month - 1) // 3 * 3 + 3) % 12 + 1, 1)
    metrics = {
        "month": month,
        "publish_date_assumed": publish,
        "spot": cur,
        "spot_prev_month": {k: prev_m[k] for k in ("mean_c_kwh_vat", "mean_eur_mwh_vat0", "negative_hours")},
        "spot_same_month_last_year": {k: yoy_m[k] for k in ("mean_c_kwh_vat", "mean_eur_mwh_vat0", "negative_hours")},
        "spot_change_mom": change(cur["mean_c_kwh_vat"], prev_m["mean_c_kwh_vat"]),
        "spot_change_yoy": change(cur["mean_c_kwh_vat"], yoy_m["mean_c_kwh_vat"]),
        "contracts": contract_metrics(db, first_day.isoformat(), last_day.isoformat()),
        "offers": offer_movements(db, first_day.isoformat(), last_day.isoformat()),
        "futures": {
            # Accountability: what the month future said on the last trade day before delivery.
            "this_month_before_delivery": futures(db, "FI", "FNBM", f"{year}{mon:02d}", first_day.isoformat()),
            "next_month": futures(db, "FI", "FNBM", nxt.strftime("%Y%m"), publish),
            "month_after": futures(db, "FI", "FNBM", nxt2.strftime("%Y%m"), publish),
            "next_quarter": futures(db, "FI", "FNBQ", q_next.strftime("%Y%m"), publish),
            "next_year": futures(db, "FI", "FNBY", f"{year + 1}01", publish),
            "next_month_at_month_start": futures(db, "FI", "FNBM", nxt.strftime("%Y%m"), first_day.isoformat()),
            # Why fixed prices moved: the contracts price the coming winter and year.
            "next_quarter_at_month_start": futures(db, "FI", "FNBQ", q_next.strftime("%Y%m"), first_day.isoformat()),
            "next_quarter_at_month_end": futures(db, "FI", "FNBQ", q_next.strftime("%Y%m"), nxt.isoformat()),
            "next_year_at_month_start": futures(db, "FI", "FNBY", f"{year + 1}01", first_day.isoformat()),
            "next_year_at_month_end": futures(db, "FI", "FNBY", f"{year + 1}01", nxt.isoformat()),
        },
    }
    out = Path(__file__).resolve().parents[1] / "editions" / month / f"metrics-{month}.json"  # market-review/editions/<month>/
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(metrics, ensure_ascii=False, indent=1) + "\n")
    print(out)


if __name__ == "__main__":
    main()
