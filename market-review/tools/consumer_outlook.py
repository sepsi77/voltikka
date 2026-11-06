"""Consumer layer items that need a month-by-month split: the bill outlook, the cold-and-calm bad
case and the cheap hours for spot customers. Read-only. Usage:

    python3 -I consumer_outlook.py <sqlite> <consumer-inputs.json> <out.json> --date 2026-11-02 [--months 6]

Typical contracts (one formula for every home type, the same hourly paths and weights as the
engine run in run_consumer.php):
- spot: the median spot margin and the median monthly fee of the spot offers on --date
  (contract_price_daily_statistics, canonical basis); each hour costs max(0, spot incl. VAT +
  margin), the same floor as the period engine;
- fixed: the median 12-month fixed-term energy price and monthly fee on --date.
Electricity tax class I (TAX_C_KWH, incl. VAT) is added per kWh to both. Network fees are not
included.

Bad case: per home type the analog winter with the highest spot cost for January, and the most
expensive 7 consecutive days for a spot customer in any analog (the week's bill on spot and on
the typical fixed contract). The analog prices keep each past winter's hourly pattern around
the futures level, so the bad case is a weather pattern at today's price level, not a forecast.

Cheap hours: the source months of the first target month (their own weekdays), shifted to its
futures level, mean price by Helsinki local hour on weekdays and weekends; the saving of moving 100 kWh from the 4 dearest to the 4 cheapest hours.
"""

import json
import sqlite3
import statistics as st
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
TAX_C_KWH = 2.918  # class I incl. VAT 25.5 % from 2026-04-01 (verify on vero.fi)
SHIFT_KWH = 100
FLEX_HOURS = 4
FLEX_KWH_PER_HOUR = 1.0


def arg(name, default):
    return sys.argv[sys.argv.index(name) + 1] if name in sys.argv else default


def stat(db, day, segment, metric):
    row = db.execute(
        "select median_value, contract_count from contract_price_daily_statistics where stat_date=? and segment_key=? "
        "and metric_key=? and consumption_kwh is null and pricing_basis='canonical_calculation'",
        (day, segment, metric),
    ).fetchone()
    if not row or row[0] is None:
        sys.exit(f"no statistic {segment}.{metric} on {day}")
    return row[0], row[1]


def local(ts):
    return datetime.fromtimestamp(int(ts), timezone.utc).astimezone(HEL)


def month_key(ts):
    return local(ts).strftime("%Y-%m")


def main():
    db = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
    inputs = json.load(open(sys.argv[2]))
    out_path = sys.argv[3]
    day = arg("--date", "2026-11-02")
    n_months = int(arg("--months", "12"))
    vat = inputs["vat"]

    margin, n_spot = stat(db, day, "spot", "spot_margin")
    spot_fee, _ = stat(db, day, "spot", "monthly_fee")
    fixed_price, n_fixed = stat(db, day, "fixed_term_12", "energy_price")
    fixed_fee, _ = stat(db, day, "fixed_term_12", "monthly_fee")
    typical = {
        "date": day,
        "spot_margin_c_kwh": round(margin, 3), "spot_monthly_fee_eur": round(spot_fee, 2), "spot_offers": n_spot,
        "fixed12_energy_c_kwh": round(fixed_price, 3), "fixed12_monthly_fee_eur": round(fixed_fee, 2), "fixed12_offers": n_fixed,
        "tax_c_kwh": TAX_C_KWH,
    }

    analogs = inputs["forward"]
    months = list(next(iter(analogs.values()))["months"].keys())
    out = {"typical_contracts": typical, "months": months[:n_months], "groups": {}, "analog_labels": {b: a["label"] for b, a in analogs.items()}}

    for g in ("BE01", "BE03", "BE02"):
        per_analog = {}
        for back, a in analogs.items():
            prices, weights = a["prices"], a["groups"][g]
            bill = {}
            for ts, kwh in weights.items():
                m = month_key(ts)
                b = bill.setdefault(m, {"kwh": 0.0, "spot_energy": 0.0})
                b["kwh"] += kwh
                b["spot_energy"] += kwh * max(0.0, prices[ts] * vat + margin) / 100
            for m, b in bill.items():
                b["spot_eur"] = b["spot_energy"] + spot_fee + b["kwh"] * TAX_C_KWH / 100
                b["fixed_eur"] = b["kwh"] * fixed_price / 100 + fixed_fee + b["kwh"] * TAX_C_KWH / 100
            per_analog[back] = bill

        res = {"monthly": {}, "twelve_months": {}}
        for m in months[:n_months]:
            spot = [per_analog[b][m]["spot_eur"] for b in per_analog]
            fixed = [per_analog[b][m]["fixed_eur"] for b in per_analog]
            kwh = [per_analog[b][m]["kwh"] for b in per_analog]
            res["monthly"][m] = {
                "kwh_mean": round(st.mean(kwh)), "spot_eur_mean": round(st.mean(spot)), "spot_eur_min": round(min(spot)),
                "spot_eur_max": round(max(spot)), "fixed_eur_mean": round(st.mean(fixed)), "fixed_eur_min": round(min(fixed)),
                "fixed_eur_max": round(max(fixed)),
            }
        for back, bill in per_analog.items():
            res["twelve_months"][back] = {
                "kwh": round(sum(b["kwh"] for b in bill.values())),
                "spot_eur": round(sum(b["spot_eur"] for b in bill.values())),
                "fixed_eur": round(sum(b["fixed_eur"] for b in bill.values())),
            }

        # Coming winter (Nov-Mar) vs the rest of the 12 months, per analog and the mean.
        winter = lambda m: m[5:] in ("11", "12", "01", "02", "03")
        seasons = {}
        for part, test in (("winter", winter), ("rest", lambda m: not winter(m))):
            vals = {b: {k: sum(v[k] for m, v in bill.items() if test(m)) for k in ("spot_eur", "fixed_eur", "kwh")} for b, bill in per_analog.items()}
            seasons[part] = {
                "spot_eur_mean": round(st.mean(v["spot_eur"] for v in vals.values())),
                "fixed_eur_mean": round(st.mean(v["fixed_eur"] for v in vals.values())),
                "kwh_mean": round(st.mean(v["kwh"] for v in vals.values())),
                "by_analog": {b: {k: round(x) for k, x in v.items()} for b, v in vals.items()},
            }
        res["seasons"] = seasons

        # Bad case 1: the January of the analog with the highest spot cost.
        jan = next(m for m in months if m.endswith("-01"))
        worst = max(per_analog, key=lambda b: per_analog[b][jan]["spot_eur"])
        res["bad_january"] = {
            "month": jan, "analog": worst, "label": analogs[worst]["label"],
            "spot_eur": round(per_analog[worst][jan]["spot_eur"]), "fixed_eur": round(per_analog[worst][jan]["fixed_eur"]),
            "spot_eur_mean_of_analogs": res["monthly"].get(jan, {}).get("spot_eur_mean"),
        }

        # Bad case 2: the most expensive 7 consecutive local days for a spot customer.
        best = None
        for back, a in analogs.items():
            prices, weights = a["prices"], a["groups"][g]
            days = {}
            for ts, kwh in weights.items():
                d = local(ts).date()
                x = days.setdefault(d, [0.0, 0.0])
                x[0] += kwh
                x[1] += kwh * max(0.0, prices[ts] * vat + margin) / 100
            ds = sorted(days)
            for i in range(len(ds) - 6):
                kwh7 = sum(days[d][0] for d in ds[i:i + 7])
                cost7 = sum(days[d][1] for d in ds[i:i + 7])
                if best is None or cost7 > best["spot_energy_eur"]:
                    best = {"analog": back, "label": a["label"], "from": ds[i].isoformat(), "to": ds[i + 6].isoformat(),
                            "kwh": kwh7, "spot_energy_eur": cost7}
        best["spot_eur"] = round(best["spot_energy_eur"] + spot_fee * 7 / 30.4 + best["kwh"] * TAX_C_KWH / 100)
        best["fixed_eur"] = round(best["kwh"] * (fixed_price + TAX_C_KWH) / 100 + fixed_fee * 7 / 30.4)
        best["mean_c_kwh_spot_incl_margin"] = round(best["spot_energy_eur"] / best["kwh"] * 100, 2)
        best["kwh"] = round(best["kwh"])
        best["spot_energy_eur"] = round(best["spot_energy_eur"])
        res["worst_week"] = best
        out["groups"][g] = res

    # Flexible spot user: each day moves FLEX_KWH_PER_HOUR from each of its FLEX_HOURS dearest
    # hours to its FLEX_HOURS cheapest hours (at most half of an hour's use), for example a water
    # heater or car charging under automation. 12-month spot cost vs the average profile.
    for g in ("BE01", "BE03", "BE02"):
        flex = {}
        for back, a in analogs.items():
            prices, weights = a["prices"], a["groups"][g]
            days = {}
            for ts, kwh in weights.items():
                days.setdefault(local(ts).date(), []).append(ts)
            saving, moved = 0.0, 0.0
            for tss in days.values():
                order = sorted(tss, key=lambda t: prices[t])
                cheap, dear = order[:FLEX_HOURS], order[-FLEX_HOURS:]
                for d, c in zip(reversed(dear), cheap):
                    x = min(FLEX_KWH_PER_HOUR, weights[d] / 2)
                    pd = max(0.0, prices[d] * vat + margin)
                    pc = max(0.0, prices[c] * vat + margin)
                    saving += x * (pd - pc) / 100
                    moved += x
            flex[back] = {"moved_kwh": round(moved), "saving_eur": round(saving)}
        out["groups"][g]["flexible_spot"] = {
            "rule": f"{FLEX_KWH_PER_HOUR} kWh from each of the {FLEX_HOURS} dearest to the {FLEX_HOURS} cheapest hours of every day",
            "by_analog": flex, "saving_eur_mean": round(st.mean(v["saving_eur"] for v in flex.values())),
            "moved_kwh_mean": round(st.mean(v["moved_kwh"] for v in flex.values())),
        }

    # January reset estimate: P_new = P_now + beta * (F_new - F_reference), beta 1.0 and the
    # pricing vintage for the reference, as in CanonicalPricing/MarketReset (site method).
    def settle(code, maturity, before):
        row = db.execute("select trade_date, settlement_price from electricity_futures_eod_prices where area='FI' and short_code=? "
                         "and maturity=? and trade_date<? order by trade_date desc limit 1", (code, maturity, before)).fetchone()
        return row
    trade = arg("--trade-date", "2026-10-30")
    after = (datetime.strptime(trade, "%Y-%m-%d") + timedelta(days=1)).strftime("%Y-%m-%d")
    # Quarterly: the current Q4 price was set from the Q4 quarter (= day-weighted month average)
    # on the last trade date before 1.10.
    q4 = [(settle("FNBM", m, "2026-10-01"), d) for m, d in (("202610", 31), ("202611", 30), ("202612", 31))]
    q4_ref = sum(r[1] * d for r, d in q4) / 92
    q1_now = settle("FNBQ", "202701", after)
    q_delta = (q1_now[1] - q4_ref) / 10 * vat
    q_now, q_n = stat(db, day, "quarterly", "energy_price")
    nov_ref = settle("FNBM", "202611", "2026-11-01")
    m_delta = {}
    for mat in ("202612", "202701", "202702", "202703"):
        r = settle("FNBM", mat, after)
        m_delta[f"{mat[:4]}-{mat[4:]}"] = round((r[1] - nov_ref[1]) / 10 * vat, 2)
    resets = {"beta": 1.0, "trade_date": q1_now[0],
              "quarterly": {"median_now_c_kwh": round(q_now, 2), "offers": q_n, "q4_reference_eur_mwh": round(q4_ref, 2),
                            "q1_future_eur_mwh": q1_now[1], "change_c_kwh": round(q_delta, 2), "q1_estimate_c_kwh": round(q_now + q_delta, 2)},
              "monthly_change_from_november_c_kwh": m_delta, "groups": {}}
    for g in ("BE01", "BE03", "BE02"):
        q1 = [m for m in months if m[5:] in ("01", "02", "03")]
        kwh = {m: out["groups"][g]["monthly"][m]["kwh_mean"] if m in out["groups"][g]["monthly"] else None for m in q1}
        kwh = {m: v if v is not None else round(st.mean(sum(w for ts, w in a["groups"][g].items() if month_key(ts) == m) for a in analogs.values())) for m, v in kwh.items()}
        resets["groups"][g] = {
            "q1_kwh": sum(kwh.values()),
            "quarterly_q1_extra_eur": round(sum(kwh.values()) * q_delta / 100),
            "monthly_q1_extra_eur": round(sum(kwh[m] * m_delta[m] for m in q1) / 100),
        }
    out["reset_estimate"] = resets

    # Cheap hours in the first target month. The analog paths map days by day of month, so their
    # weekdays are not real; use the source months themselves (their own weekdays), each shifted to
    # the target month's futures level like the analog paths.
    first = months[0]
    by_hour = {"weekday": {h: [] for h in range(24)}, "weekend": {h: [] for h in range(24)}}
    sources = []
    for a in analogs.values():
        info = a["months"][first]
        sy, sm = map(int, info["source_month"].split("-"))
        start = datetime(sy, sm, 1, tzinfo=HEL).astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        end = datetime(sy + (sm == 12), sm % 12 + 1, 1, tzinfo=HEL).astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        rows = db.execute("select utc_datetime, price_without_tax from spot_prices_hour where region='FI' and utc_datetime>=? and utc_datetime<?", (start, end)).fetchall()
        mean = st.mean(p for _, p in rows)
        sources.append(info["source_month"])
        for u, p in rows:
            t = datetime.strptime(u, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc).astimezone(HEL)
            by_hour["weekend" if t.weekday() >= 5 else "weekday"][t.hour].append((p - mean + info["future_c_kwh_vat0"]) * vat)
    hours = {k: {h: round(st.mean(v), 2) for h, v in hrs.items() if v} for k, hrs in by_hour.items()}
    wd = hours["weekday"]
    order = sorted(wd, key=wd.get)
    cheap, dear = order[:4], order[-4:]
    gap = st.mean(wd[h] for h in dear) - st.mean(wd[h] for h in cheap)
    out["cheap_hours"] = {
        "month": first, "source_months": sources, "c_kwh_by_local_hour": hours,
        "weekday_cheapest_hours": sorted(cheap), "weekday_dearest_hours": sorted(dear),
        "weekday_gap_c_kwh": round(gap, 2), "saving_eur_per_100_kwh": round(gap * SHIFT_KWH / 100, 2),
        "weekend_vs_weekday_c_kwh": [round(st.mean(hours["weekend"].values()), 2), round(st.mean(wd.values()), 2)],
    }

    with open(out_path, "w") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
    print(json.dumps({"typical": typical, "cheap": {k: v for k, v in out["cheap_hours"].items() if k != "c_kwh_by_local_hour"}}, ensure_ascii=False))
    for g, res in out["groups"].items():
        print(g, {m: (v["spot_eur_mean"], v["fixed_eur_mean"]) for m, v in res["monthly"].items()}, res["bad_january"], res["worst_week"])


if __name__ == "__main__":
    main()
