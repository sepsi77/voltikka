"""Collects the figures of one review edition into data/<month>.js for the scenes (read-only).

Usage (from videos/monthly-market-review): python3 -I export-data.py 2026-10
Sources: market-review/editions/<month>/*.json (the frozen draft figures) and the local
SQLite snapshot (only the Voltikka price index series), plus the frozen chart data of the site page
(laravel/resources/market-reviews/<month>/kuva-*.json, read only) so that the video charts show the same data.
"""
import json
import sqlite3
import sys

month = sys.argv[1]
root = "../../"
proto = root + f"market-review/editions/{month}/"
m = json.load(open(proto + f"metrics-{month}.json"))
d = json.load(open(proto + f"drivers-{month}.json"))
o = json.load(open(proto + "consumer-outlook-2026-11.json"))

db = sqlite3.connect(f"file:{root}laravel/database/database.sqlite?mode=ro", uri=True)
index = {}
for seg in ["seller_set_overall", "seller_set_fixed_term", "seller_set_open_ended", "seller_set_market_reset"]:
    rows = db.execute(
        "select stat_date, avg_value from contract_price_daily_statistics where metric_key='seller_set_energy_price_index_v1' "
        "and segment_key=? and consumption_kwh is null and avg_value is not null and stat_date<=? order by stat_date",
        (seg, f"{month}-31"),
    ).fetchall()
    index[seg.replace("seller_set_", "")] = [[a, round(b, 3)] for a, b in rows]

groups = {}
for code, g in o["groups"].items():
    groups[code] = {
        "monthly": [[k, v["spot_eur_mean"], v["fixed_eur_mean"], v["kwh_mean"]] for k, v in g["monthly"].items()],
        "winter": g["seasons"]["winter"],
        "rest": g["seasons"]["rest"],
        "bad_january": g["bad_january"],
        "worst_week": g["worst_week"],
    }

site_dir = root + f"laravel/resources/market-reviews/{month}/"
site = {f"kuva{n}": json.load(open(site_dir + f"kuva-{n}.json")) for n in (1, 2, 3)}

out = {
    "month": month,
    "spot": {k: m["spot"][k] for k in ["mean_c_kwh_vat", "daily_means_c_kwh_vat", "dearest_day"]},
    "spot_prev": m["spot_prev_month"]["mean_c_kwh_vat"],
    "spot_last_year": m["spot_same_month_last_year"]["mean_c_kwh_vat"],
    "wind_daily_gwh": d["wind"]["daily_gwh"],
    "wind": {k: d["wind"][month][k] for k in ["production_twh", "capacity_mw_month_end"]},
    "temp_daily_anomaly": d["temperature"][month]["daily_anomaly_c"],
    "nuclear_availability": d["nuclear"][month]["availability_pct"],
    "neighbours_vat0": d["neighbour_prices"][month]["mean_c_kwh_vat0"],
    "hydro": d["nordic_hydro_norway"][month],
    "gas": {"now": d["gas_storage"][month]["eu_full_pct"], "last_year": d["gas_storage"]["2025-10"]["eu_full_pct"]},
    "index": index,
    "groups": groups,
    "site": site,
}
open(f"data/{month}.js", "w").write("// Made by export-data.py. Do not edit.\nwindow.DATA = " + json.dumps(out, ensure_ascii=False) + ";\n")
print(f"data/{month}.js", len(index["overall"]), "index days")
