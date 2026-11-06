"""Frozen chart data for the site review page (interactive charts), read-only.

Usage: python3 -I site_payloads.py <month YYYY-MM> <outlook-json> <sqlite> <out-dir> [--force]

The files are the frozen chart data of a review: the page never reads the database. The script
refuses to overwrite an existing file, because a later run can give other values (stored
statistics can be recalculated when a method changes). Use --force only before publication.

Writes into <out-dir> (laravel/resources/market-reviews/<month>/):
  kuva-1.json  monthly bill outlook per home (spot mean + three-winter range, 12-month fixed)
  kuva-2.json  daily spot mean, daily wind GWh and daily temperature anomaly of the month
  kuva-3.json  Voltikka price index, daily values of the review month plus the last day of the
               previous month (the start value of the monthly change; user decisions
               2026-11-06), in the payload shape of the uPlot renderer
               (resources/js/contract-price-statistics.js)

Daily index: one point per Helsinki day, the same rows and filters as
ContractPriceStatistics::sellerSetIndexChart() in its daily period. A day without a stored value
is null, so the line has a gap there (2026-10-01, the day the quarterly prices reset).
"""

import json
import sqlite3
import sys
from datetime import date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

EDITIONS = Path(__file__).resolve().parents[1] / "editions"  # market-review/editions/<month>/
MONTHS = ["tammi", "helmi", "maalis", "huhti", "touko", "kesä", "heinä", "elo", "syys", "loka", "marras", "joulu"]
HOMES = [("BE03", "Pientalo ilman sähkölämmitystä, noin 6 800 kWh vuodessa"),
         ("BE02", "Sähkölämmitteinen pientalo, noin 11 100 kWh vuodessa")]
INDEX = [  # label decides the colour in inkSeriesStyle(); index 0 is the fox lead line
    ("seller_set_overall", "Voltikan hintaindeksi"),
    ("seller_set_fixed_term", "Määräaikaiset"),
    ("seller_set_open_ended", "Toistaiseksi voimassa"),
    ("seller_set_market_reset", "Kvartaali- ja kuukausihintaiset"),
]
INDEX_FILTER = (
    "metric_key='seller_set_energy_price_index_v1' and method_version='unit_statistics_v1' "
    "and pricing_basis='canonical_calculation' and calculation_basis='canonical_direct_general_company_median_v1' "
    "and estimate_basis='fixed_supplier_family_weights_2026_08_11' "
    "and compatibility_key='seller_set_energy_price_index_v1:2026-08-11:company_median:fixed_weights' "
    "and consumption_kwh is null and avg_value > 0"
)


def bill_outlook(outlook):
    months = list(outlook["groups"]["BE02"]["monthly"])
    homes = []
    for key, title in HOMES:
        monthly = outlook["groups"][key]["monthly"]
        homes.append({
            "key": key,
            "title": title,
            "spot": [monthly[m]["spot_eur_mean"] for m in months],
            "spot_low": [monthly[m]["spot_eur_min"] for m in months],
            "spot_high": [monthly[m]["spot_eur_max"] for m in months],
            "fixed": [monthly[m]["fixed_eur_mean"] for m in months],
        })
    labels = [f"{MONTHS[int(m[5:]) - 1]} {m[2:4]}" if m[5:] in ("01", "11") else MONTHS[int(m[5:]) - 1] for m in months]
    return {"months": months, "labels": labels, "homes": homes}


def daily_drivers(month):
    metrics = json.loads((EDITIONS / month / f"metrics-{month}.json").read_text())
    drivers = json.loads((EDITIONS / month / f"drivers-{month}.json").read_text())
    price = metrics["spot"]["daily_means_c_kwh_vat"]
    days = list(price)
    wind = drivers["wind"]["daily_gwh"]
    temp = drivers["temperature"][month]["daily_anomaly_c"]
    return {
        "days": days,
        "labels": [f"{date.fromisoformat(d).day}.{date.fromisoformat(d).month}." for d in days],
        "price": [price[d] for d in days],
        "price_mean": metrics["spot"]["mean_c_kwh_vat"],
        "wind": [wind.get(d) for d in days],
        "temperature_anomaly": [temp.get(d) for d in days],  # null = no observation for that day
    }


def daily_index(db_path, month):
    db = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    first = date.fromisoformat(month + "-01")
    days = [(first - timedelta(days=1)).isoformat()]
    cursor = first
    while cursor.month == first.month:
        days.append(cursor.isoformat())
        cursor += timedelta(days=1)
    helsinki = ZoneInfo("Europe/Helsinki")
    series = []
    for seg, label in INDEX:
        rows = dict(db.execute(
            f"select substr(stat_date,1,10), avg_value from contract_price_daily_statistics where {INDEX_FILTER} "
            "and segment_key=? and substr(stat_date,1,10) between ? and ?",
            (seg, days[0], days[-1]),
        ).fetchall())
        series.append({"label": label, "values": [round(float(rows[d]), 4) if d in rows else None for d in days]})
    return {
        "x": [int(datetime.combine(date.fromisoformat(d), datetime.min.time(), helsinki).timestamp()) for d in days],
        "series": series,
        "unit": "cent",
        "decimals": 2,
        # Markers on every day: the start value (last day of the previous month) stands alone
        # before the 1.10. gap, and a lone point without a marker is invisible.
        "showPoints": True,
        "showLatestPoint": False,
        "leadStroke": "#f97316",
    }


def main():
    month, outlook_path, db_path, out = sys.argv[1], sys.argv[2], sys.argv[3], Path(sys.argv[4])
    payloads = {
        "kuva-1.json": bill_outlook(json.loads(Path(outlook_path).read_text())),
        "kuva-2.json": daily_drivers(month),
        "kuva-3.json": daily_index(db_path, month),
    }
    existing = [name for name in payloads if (out / name).exists()]
    if existing and "--force" not in sys.argv[5:]:
        sys.exit(f"Frozen chart data exists ({', '.join(existing)}); not overwritten. Use --force only before publication.")
    for name, payload in payloads.items():
        (out / name).write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n")
        print(out / name)


if __name__ == "__main__":
    main()
