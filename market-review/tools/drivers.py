"""Prototype driver data for the journalist layer of one monthly market review.

Reads the local SQLite copy of production (read-only) and writes drivers-YYYY-MM.json. Each item
covers the review month, the same month one year earlier and the previous month where data exists.
Month boundaries are Europe/Helsinki. Usage:

    python3 -I drivers.py ../../../laravel/database/database.sqlite 2026-10 2026-11-02
"""

import json
import math
import sqlite3
import statistics
import sys
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
VAT = 0.255  # FI spot VAT since 2024-09, applied to every hour (also negative), as compute.py does
FMT = "%Y-%m-%d %H:%M:%S"
FI_POINTS = ["helsinki", "turku", "tampere", "jyvaskyla", "kuopio", "oulu"]  # config/open_meteo.php group 'fi'
NUCLEAR_MW = {"OL1": 890, "OL2": 890, "OL3": 1600, "LO1": 507, "LO2": 507}  # assumed net capacities
NORMAL_YEARS = (1991, 2020)
HDD_BASE = 17.0
NEIGHBOURS = ["FI", "SE1", "SE3", "EE", "DE-LU"]
COUPLED_TOL_C_KWH = 0.001


def r(x, n=2):
    return None if x is None else round(x, n) + 0  # + 0 turns -0.0 into 0.0


def month_start(year, month):
    return date(year + (month > 12), (month - 1) % 12 + 1, 1)


def month_days(first):
    nxt = month_start(first.year, first.month + 1)
    return [first + timedelta(days=i) for i in range((nxt - first).days)]


def utc_bounds(first):
    nxt = month_start(first.year, first.month + 1)
    a = datetime(first.year, first.month, 1, tzinfo=HEL).astimezone(timezone.utc)
    b = datetime(nxt.year, nxt.month, 1, tzinfo=HEL).astimezone(timezone.utc)
    return a.strftime(FMT), b.strftime(FMT)


def to_local(utc_text):
    return datetime.strptime(utc_text[:19], FMT).replace(tzinfo=timezone.utc).astimezone(HEL)


def hel_to_utc_text(day, end_of_day=False):
    t = datetime(day.year, day.month, day.day, tzinfo=HEL) + (timedelta(days=1) if end_of_day else timedelta())
    return t.astimezone(timezone.utc).strftime(FMT)


def fingrid(db, dataset, first, series_key=""):
    a, b = utc_bounds(first)
    return db.execute(
        "select utc_datetime, value from fingrid_hourly_values where dataset_id=? and series_key=? "
        "and utc_datetime>=? and utc_datetime<? order by utc_datetime", (dataset, series_key, a, b)).fetchall()


def expected_hours(first):
    a, b = utc_bounds(first)
    return int((datetime.strptime(b, FMT) - datetime.strptime(a, FMT)).total_seconds() // 3600)


def months_of(first):
    """The review month, the same month a year earlier, and the previous month."""
    return {
        first.strftime("%Y-%m"): first,
        month_start(first.year - 1, first.month).strftime("%Y-%m"): month_start(first.year - 1, first.month),
        month_start(first.year, first.month - 1).strftime("%Y-%m"): month_start(first.year, first.month - 1),
    }


# --- Fingrid system data ---------------------------------------------------------------------

def wind(db, first):
    rows = fingrid(db, 75, first)
    cap = dict(fingrid(db, 268, first))
    if not rows:
        return None
    vals = [v for _, v in rows]
    caps = [cap[u] for u, _ in rows if u in cap]
    return {
        "hours": len(rows), "hours_expected": expected_hours(first),
        "production_twh": r(sum(vals) / 1e6, 3),
        "mean_mw": r(statistics.mean(vals), 0),
        "capacity_mw_month_end": r(cap[max(cap)], 0) if cap else None,
        # Capacity factor = sum of hourly production / sum of hourly capacity (268) in the same hours.
        "capacity_factor_pct": r(sum(v for u, v in rows if u in cap) / sum(caps) * 100, 1) if caps else None,
        "max_hour_mw": r(max(vals), 0),
        "min_hour_mw": r(min(vals), 0),
    }


def daily_gwh(db, first, dataset=75):
    out = {}
    for u, v in fingrid(db, dataset, first):
        d = to_local(u).date().isoformat()
        out[d] = out.get(d, 0) + v / 1000
    return {d: r(v, 1) for d, v in sorted(out.items())}


def nuclear(db, first):
    rows = fingrid(db, 188, first)
    if not rows:
        return None
    vals = [v for _, v in rows]
    installed = sum(NUCLEAR_MW.values())
    return {
        "hours": len(rows), "hours_expected": expected_hours(first),
        "production_twh": r(sum(vals) / 1e6, 3),
        "mean_mw": r(statistics.mean(vals), 0),
        "installed_mw_assumed": installed,
        "availability_pct": r(statistics.mean(vals) / installed * 100, 1),
    }


def consumption(db, first):
    rows = fingrid(db, 124, first)
    if not rows:
        return None
    peak = max(rows, key=lambda x: x[1])
    return {
        "hours": len(rows), "hours_expected": expected_hours(first),
        "consumption_twh": r(sum(v for _, v in rows) / 1e6, 3),
        "mean_mw": r(statistics.mean(v for _, v in rows), 0),
        "peak_hour_mw": r(peak[1], 0),
        "peak_hour_local": to_local(peak[0]).isoformat(),
    }


def trade(db, first):
    net = dict(fingrid(db, 194, first))
    if not net:
        return None
    flows = {name: dict(fingrid(db, ds, first)) for name, ds in (("SE1", 60), ("SE3", 61), ("EE", 55))}
    cons, prod = dict(fingrid(db, 124, first)), dict(fingrid(db, 74, first))
    common = [u for u in net if all(u in f for f in flows.values())]
    flow_sum = {u: sum(f[u] for f in flows.values()) for u in common}
    # Sign check: 194 must follow the sum of the measured flows (positive = export) and the
    # opposite of consumption - production.
    bal = [u for u in net if u in cons and u in prod]
    return {
        "hours": len(net), "hours_expected": expected_hours(first),
        "net_export_twh": r(sum(net.values()) / 1e6, 3),
        "net_import_twh": r(-sum(net.values()) / 1e6, 3),
        "sign_note": "Fingrid 194 is positive for net export and negative for net import.",
        "flows_twh": {
            f"fi_to_{k.lower()}": {
                "net_twh": r(sum(f.values()) / 1e6, 3),
                "export_twh": r(sum(v for v in f.values() if v > 0) / 1e6, 3),
                "import_twh": r(-sum(v for v in f.values() if v < 0) / 1e6, 3),
            } for k, f in flows.items()
        },
        "sign_check": {
            "flows_55_60_61_net_twh": r(sum(flow_sum.values()) / 1e6, 3),
            "net_194_same_hours_twh": r(sum(net[u] for u in common) / 1e6, 3),
            "hours_same_sign_pct": r(sum(1 for u in common if (net[u] >= 0) == (flow_sum[u] >= 0)) / len(common) * 100, 1),
            "consumption_minus_production_twh": r(sum(cons[u] - prod[u] for u in bal) / 1e6, 3),
            "note": "Flows 55/60/61 omit the small Norway (and Russia) borders, so the sums differ a little.",
        },
    }


# --- Weather (ERA5 daily, Finnish points) ----------------------------------------------------

def load_era5(db):
    rows = db.execute(
        f"select point, date, temperature_2m_mean, wind_speed_100m_mean from weather_daily_observations "
        f"where model='era5' and point in ({','.join('?' * len(FI_POINTS))})", FI_POINTS).fetchall()
    obs = {}
    for p, d, t, w in rows:
        obs[(p, d)] = (t, w)
    return obs


def hdd(t, month):
    """Heating degree day, base 17 C, FMI rule: no heating in autumn (Aug-Dec) above +12, spring above +10."""
    if t is None:
        return None
    limit = 12.0 if month >= 8 else 10.0
    return 0.0 if t > limit else max(0.0, HDD_BASE - t)


def normals(obs):
    """Per point and calendar day (MM-DD): mean temperature, mean 100 m wind, mean HDD over 1991-2020."""
    acc = {}
    for (p, d), (t, w) in obs.items():
        y = int(d[:4])
        if not NORMAL_YEARS[0] <= y <= NORMAL_YEARS[1]:
            continue
        a = acc.setdefault((p, d[5:]), [[], [], []])
        if t is not None:
            a[0].append(t)
            a[2].append(hdd(t, int(d[5:7])))
        if w is not None:
            a[1].append(w)
    return {k: tuple(statistics.mean(x) if x else None for x in v) for k, v in acc.items()}


def point_mean(values):
    return statistics.mean(values) if values and None not in values else None


def temperature(obs, norm, first):
    days, anomalies, temps, norm_t, hdd_obs, hdd_norm, missing = [], {}, [], [], [], [], []
    for d in month_days(first):
        md, ds = d.strftime("%m-%d"), d.isoformat()
        t = point_mean([obs.get((p, ds), (None, None))[0] for p in FI_POINTS])
        if t is None:
            missing.append(ds)
            continue
        n = point_mean([norm[(p, md)][0] for p in FI_POINTS])
        days.append(ds)
        temps.append(t)
        norm_t.append(n)
        anomalies[ds] = r(t - n, 1)
        hdd_obs.append(statistics.mean(hdd(obs[(p, ds)][0], d.month) for p in FI_POINTS))
        hdd_norm.append(statistics.mean(norm[(p, md)][2] for p in FI_POINTS))
    if not days:
        return None
    full_norm_hdd = sum(statistics.mean(norm[(p, d.strftime("%m-%d"))][2] for p in FI_POINTS) for d in month_days(first))
    return {
        "days_with_data": len(days), "days_in_month": len(month_days(first)),
        "first_date": days[0], "last_date": days[-1], "missing_days": missing,
        "mean_c": r(statistics.mean(temps), 1),
        "normal_1991_2020_same_days_c": r(statistics.mean(norm_t), 1),
        "anomaly_c": r(statistics.mean(temps) - statistics.mean(norm_t), 1),
        "hdd17_kd": r(sum(hdd_obs), 0),
        "hdd17_normal_1991_2020_same_days_kd": r(sum(hdd_norm), 0),
        "hdd17_pct_of_normal": r(sum(hdd_obs) / sum(hdd_norm) * 100, 0) if sum(hdd_norm) else None,
        "hdd17_normal_1991_2020_full_month_kd": r(full_norm_hdd, 0),
        "daily_anomaly_c": anomalies,
    }


# --- Prices ---------------------------------------------------------------------------------

def prices(db, first, table="spot_prices_hour"):
    a, b = utc_bounds(first)
    out = {}
    for region in NEIGHBOURS:
        out[region] = dict(db.execute(
            f"select utc_datetime, price_without_tax from {table} where region=? and utc_datetime>=? and utc_datetime<?",
            (region, a, b)).fetchall())
    return out


def neighbour_prices(db, first):
    h, q = prices(db, first), prices(db, first, "spot_prices_quarter")
    if not h["FI"]:
        return None

    def share(series, other):
        common = [u for u in series["FI"] if u in series[other]]
        if not common:
            return None
        return r(sum(1 for u in common if abs(series["FI"][u] - series[other][u]) < COUPLED_TOL_C_KWH) / len(common) * 100, 1)

    return {
        "vat_basis": "VAT 0 (price_without_tax), c/kWh",
        "hours": {k: len(v) for k, v in h.items()},
        "mean_c_kwh_vat0": {k: r(statistics.mean(v.values())) if v else None for k, v in h.items()},
        "hours_fi_equal_se1_pct": share(h, "SE1"),
        "hours_fi_equal_ee_pct": share(h, "EE"),
        "hours_fi_equal_se3_pct": share(h, "SE3"),
        "quarters_fi_equal_se1_pct": share(q, "SE1"),
        "quarters_fi_equal_ee_pct": share(q, "EE"),
        "equal_tolerance_c_kwh": COUPLED_TOL_C_KWH,
    }


def datahub_weighted(db, first, publish):
    h = prices(db, first)["FI"]
    if not h:
        return None
    plain = statistics.mean(h.values()) * (1 + VAT)
    out = {"plain_mean_c_kwh_vat": r(plain), "vat_basis": "incl. VAT 25.5 %, also on negative hours"}
    last_correction = month_start(first.year, first.month + 1) + timedelta(days=10)
    out["preliminary"] = date.fromisoformat(publish) <= last_correction
    groups = {"BE01": "apartment in an apartment block", "BE02": "electrically heated small house",
              "BE03": "small house without electric heating"}
    for code, label in groups.items():
        rows = [(u, v) for u, v in fingrid(db, 364, first, f"UserGroup={code}") if u in h]
        if not rows:
            out[code] = None
            continue
        kwh = sum(v for _, v in rows)
        weighted = sum(v * h[u] * (1 + VAT) for u, v in rows) / kwh
        out[code] = {
            "label": label, "hours": len(rows), "hours_expected": expected_hours(first),
            "kwh_per_accounting_point": r(kwh, 0),
            "weighted_c_kwh_vat": r(weighted),
            "ratio_to_plain_mean": r(weighted / plain, 3),
            "energy_cost_eur_at_spot_vat": r(kwh * weighted / 100, 2),
        }
    return out


# --- Hydro and gas ---------------------------------------------------------------------------

def reservoir(db, first):
    last = month_start(first.year, first.month + 1) - timedelta(days=1)
    row = db.execute(
        "select iso_year, iso_week, week_end_date, filling_ratio, filling_twh from nve_reservoir_weeks "
        "where area_type='NO' and area_no=0 and week_end_date<=? and week_end_date>=? order by week_end_date desc limit 1",
        (last.isoformat(), first.isoformat())).fetchone()
    if not row:
        return None
    y, w, end, ratio, twh = row
    ref = db.execute(
        "select reference_from_year, reference_to_year, min_ratio, median_ratio, max_ratio from nve_reservoir_week_references "
        "where area_type='NO' and area_no=0 and iso_week=? order by reference_to_year desc limit 1", (w,)).fetchone()
    out = {"iso_year": y, "iso_week": w, "week_end_date": end, "filling_pct": r(ratio * 100, 1), "filling_twh": r(twh, 1)}
    if ref:
        out.update({
            "reference_years": f"{ref[0]}-{ref[1]}",
            "reference_min_pct": r(ref[2] * 100, 1), "reference_median_pct": r(ref[3] * 100, 1),
            "reference_max_pct": r(ref[4] * 100, 1),
            "vs_median_pct_points": r((ratio - ref[3]) * 100, 1),
        })
    return out


def gas(db, first):
    last = (month_start(first.year, first.month + 1) - timedelta(days=1)).isoformat()
    out = {"gas_day": last}
    for area in ("eu", "de"):
        row = db.execute("select full_pct, gas_in_storage_twh from gas_storage_daily_values where area_code=? and gas_day=?",
                         (area, last)).fetchone()
        out[f"{area}_full_pct"] = r(row[0], 1) if row else None
        out[f"{area}_storage_twh"] = r(row[1], 1) if row and row[1] is not None else None
    return out


# --- Outlook ---------------------------------------------------------------------------------

def outages(db, publish, window_end):
    """Latest known revision (document created by the end of the publication day) of each outage."""
    cutoff = hel_to_utc_text(date.fromisoformat(publish), end_of_day=True)
    w_start, w_end = hel_to_utc_text(date.fromisoformat(publish)), hel_to_utc_text(window_end, end_of_day=True)
    rows = db.execute(
        """select o.document_type, o.zone, o.business_type, o.psr_type, o.resource_name, o.unit_name, o.start_utc,
                  o.end_utc, o.nominal_mw, o.available_periods, o.document_mrid, o.time_series_mrid, o.revision_number,
                  o.document_created_at, o.is_cancelled
           from entsoe_outages o
           where o.document_created_at <= ? and o.revision_number = (
                 select max(o2.revision_number) from entsoe_outages o2 where o2.document_mrid = o.document_mrid
                 and o2.time_series_mrid = o.time_series_mrid and o2.document_created_at <= ?)
             and o.end_utc > ? and o.start_utc < ?
             and (o.zone = 'FI' or o.zone like 'FI\\_%' escape '\\' or o.zone like '%\\_FI' escape '\\')
           order by o.start_utc""", (cutoff, cutoff, w_start, w_end)).fetchall()
    gen, trans = [], []
    for (dt, zone, bt, psr, res, unit, s, e, nominal, periods, mrid, ts, rev, created, cancelled) in rows:
        if cancelled:
            continue
        segs = [p for p in json.loads(periods) if p["end"] > w_start and p["start"] < w_end]
        avail = min(p["mw"] for p in segs) if segs else None
        later = db.execute(
            "select max(revision_number) from entsoe_outages where document_mrid=? and time_series_mrid=? and revision_number>?",
            (mrid, ts, rev)).fetchone()[0]
        item = {
            "type": {"A53": "planned", "A54": "unplanned"}.get(bt, bt), "document_type": dt,
            "start_local": to_local(s).isoformat(), "end_local": to_local(e).isoformat(),
            "revision": rev, "revision_created_utc": created,
            "later_revision_in_db_after_publication": later,
        }
        if dt == "A78":
            item.update({"border": zone, "asset": res, "available_mw_min_in_window": avail,
                         "note": "A78 gives only the remaining capacity; ENTSO-E sends no nominal value."})
            trans.append(item)
        else:
            item.update({"zone": zone, "unit": unit or res, "psr_type": psr, "nuclear": psr == "B14",
                         "nominal_mw": nominal, "available_mw_min_in_window": avail,
                         "unavailable_mw_max_in_window": r(nominal - avail, 0) if nominal is not None and avail is not None else None})
            gen.append(item)
    return {
        "window_local": [publish, window_end.isoformat()], "known_by_utc": cutoff,
        "rule": "Latest revision per document_mrid + time_series_mrid created by the end of the publication day; "
                "cancelled versions dropped. Do not add A77 and A80 capacity of the same plant.",
        "generation_fi": gen, "transmission_fi_borders": trans,
    }


def ensemble_outlook(db, product, publish, norm, weeks):
    cutoff = hel_to_utc_text(date.fromisoformat(publish), end_of_day=True)
    runs = {}
    for p in FI_POINTS:
        row = db.execute(
            "select model_run_at, fetched_at, first_date, day_count, member_count, members from weather_outlook_runs "
            "where product=? and point=? and fetched_at<=? order by fetched_at desc limit 1", (product, p, cutoff)).fetchone()
        if not row:
            return None
        runs[p] = row
    first = {row[2] for row in runs.values()}
    if len(first) != 1:
        return {"error": "points have different first dates"}
    first_date = date.fromisoformat(first.pop())
    members = {p: json.loads(row[5]) for p, row in runs.items()}
    n_members = min(row[4] for row in runs.values())
    out = {
        "product": product, "model_run_at": sorted({row[0] for row in runs.values() if row[0]}),
        "fetched_at": sorted({row[1] for row in runs.values()}), "first_date": first_date.isoformat(),
        "members": n_members, "weeks": {},
        "method": "Share of members (control + perturbed) whose 7-day mean over the 6 Finnish points is above the "
                  "ERA5 1991-2020 normal of the same calendar days. No model bias correction.",
    }
    variables = {"temperature": ("temperature_2m_mean", 0), "wind_100m": ("wind_speed_100m_mean", 1)}
    for wk in range(weeks):
        days = [first_date + timedelta(days=wk * 7 + i) for i in range(7)]
        idx = [wk * 7 + i for i in range(7)]
        wk_out = {"from": days[0].isoformat(), "to": days[-1].isoformat()}
        for name, (var, ni) in variables.items():
            normal = statistics.mean(norm[(p, d.strftime("%m-%d"))][ni] for p in FI_POINTS for d in days)
            vals = []
            for m in range(n_members):
                xs = [members[p][var][m][i] for p in FI_POINTS for i in idx
                      if i < len(members[p][var][m]) and members[p][var][m][i] is not None]
                if len(xs) == len(FI_POINTS) * 7:
                    vals.append(statistics.mean(xs))
            if not vals:
                wk_out[name] = None
                continue
            unit = "c" if name == "temperature" else "ms"
            wk_out[name] = {
                f"normal_{unit}": r(normal, 1),
                "members_used": len(vals),
                "prob_above_normal_pct": r(sum(1 for v in vals if v > normal) / len(vals) * 100, 0),
                f"ensemble_median_anomaly_{unit}": r(statistics.median(vals) - normal, 1),
                f"ensemble_p10_anomaly_{unit}": r(sorted(vals)[max(0, int(0.1 * len(vals)))] - normal, 1),
                f"ensemble_p90_anomaly_{unit}": r(sorted(vals)[min(len(vals) - 1, int(0.9 * len(vals)))] - normal, 1),
            }
        out["weeks"][f"week_{wk + 1}"] = wk_out
    return out


def fixed_forecast(db, publish):
    model = "fixed_term_futures_only_v1"
    day = db.execute("select max(forecast_date) from fixed_contract_price_forecasts where model_version=? and forecast_date<=?",
                     (model, publish)).fetchone()[0]
    rows = db.execute(
        "select duration_months, target_quantile, horizon_days, target_date, current_price_cents_per_kwh, "
        "forecast_price_cents_per_kwh, expected_change_cents_per_kwh, direction, confidence from fixed_contract_price_forecasts "
        "where model_version=? and forecast_date=? order by duration_months, target_quantile", (model, day)).fetchall()
    terms = {}
    for term, q, hz, target, cur, fc, chg, direction, conf in rows:
        t = terms.setdefault(f"{term}_months", {"horizon_days": hz, "target_date": target,
                                                 "current_median_c_kwh_vat": r(float(cur), 2)})
        if q == "median":
            t.update({"forecast_median_c_kwh_vat": r(float(fc), 2), "expected_change_c_kwh": r(float(chg), 3),
                      "direction": direction, "confidence": conf})
        else:
            t[f"forecast_{q}_c_kwh_vat"] = r(float(fc), 2)
    return {"model_version": model, "forecast_date": day, "terms": terms,
            "note": "Median of offered fixed-term energy prices, incl. VAT. Directions with 'slightly_' are "
                    "about unchanged on the public page. p20/p80 are separate quantile forecasts, not uncertainty bounds."}


def outlook_vs_result(db, first):
    row = db.execute(
        "select trade_date, settlement_price from electricity_futures_eod_prices where area='FI' and short_code='FNBM' "
        "and maturity=? and trade_date<? order by trade_date desc limit 1",
        (first.strftime("%Y%m"), first.isoformat())).fetchone()
    h = prices(db, first)["FI"]
    if not row or not h:
        return None
    fut = float(row[1]) / 10 * (1 + VAT)
    real = statistics.mean(h.values()) * (1 + VAT)
    return {
        "future": f"EEX FI Base month future (FNBM) {first.strftime('%Y-%m')}",
        "trade_date": row[0], "future_c_kwh_vat": r(fut), "realised_mean_c_kwh_vat": r(real),
        "difference_c_kwh": r(real - fut), "difference_pct": r((real - fut) / fut * 100, 1),
        "note": "One single EEX value, quoted with source and date; never output EEX series.",
    }


def main():
    db_path, month, publish = sys.argv[1], sys.argv[2], sys.argv[3]
    first = date(int(month[:4]), int(month[5:7]), 1)
    db = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    obs = load_era5(db)
    norm = normals(obs)
    months = months_of(first)
    window_end = month_start(first.year, first.month + 2) + timedelta(days=14)  # e.g. 2026-12-15

    out = {
        "month": month, "publish_date_assumed": publish,
        "month_boundaries": "Europe/Helsinki",
        "wind": {k: wind(db, m) for k, m in months.items()} | {"daily_gwh": daily_gwh(db, first)},
        "temperature": {k: temperature(obs, norm, m) for k, m in months.items()},
        "nuclear": {k: nuclear(db, m) for k, m in months.items()},
        "consumption": {k: consumption(db, m) for k, m in months.items()},
        "trade": {k: trade(db, m) for k, m in months.items()},
        "neighbour_prices": {k: neighbour_prices(db, m) for k, m in months.items()},
        "nordic_hydro_norway": {k: reservoir(db, m) for k, m in months.items()},
        "gas_storage": {k: gas(db, m) for k, m in months.items()},
        "datahub_weighted_spot": {k: datahub_weighted(db, m, publish) for k, m in months.items()},
        "upcoming_outages": outages(db, publish, window_end),
        "weather_outlook": {
            "ecmwf_ens": ensemble_outlook(db, "ecmwf_ens", publish, norm, 2),
            "ecmwf_ec46": ensemble_outlook(db, "ecmwf_ec46", publish, norm, 4),
        },
        "fixed_term_forecast": fixed_forecast(db, publish),
        "last_outlook_vs_result": outlook_vs_result(db, first),
        "assumptions": [
            "Temperature and outlook use the plain mean of the 6 Finnish points (no population weights).",
            "Heating degree days: base 17 C per point and day, FMI rule (Aug-Dec days above +12 C and "
            "Jan-Jul days above +10 C count 0), then the mean over points.",
            f"Nuclear availability = mean MW of Fingrid 188 / {sum(NUCLEAR_MW.values())} MW "
            "(OL1 890, OL2 890, OL3 1,600, LO1 507, LO2 507).",
            "Wind capacity factor = sum of hourly production (75) / sum of hourly capacity (268).",
            "Spot prices incl. VAT multiply every hour by 1.255, also negative hours (site convention).",
            "Datahub 364 weighting uses the hourly kWh of one average accounting point of the group.",
            "Outlook probabilities compare raw ensemble values with the ERA5 normal; model bias is not corrected.",
            "Reservoir reference: NVE 20-year reference (2006-2025) is used for every year shown.",
        ],
        "sources": {
            "fingrid": "Fingrid open data (CC BY 4.0): 55, 60, 61, 74, 75, 124, 188, 194, 268, 364",
            "entsoe": "ENTSO-E Transparency Platform: day-ahead prices, outages A77/A78/A80",
            "weather": "Weather data by Open-Meteo.com (ERA5; ECMWF ENS and EC46)",
            "nve": "NVE magasinstatistikk",
            "gas": "GIE AGSI",
            "eex": "EEX (single quoted values only)",
            "voltikka": "Voltikka fixed-term forecast",
        },
    }
    bad = []

    def check(x, path):
        if isinstance(x, float) and not math.isfinite(x):
            bad.append(path)
        elif isinstance(x, dict):
            for k, v in x.items():
                check(v, f"{path}.{k}")
        elif isinstance(x, list):
            for i, v in enumerate(x):
                check(v, f"{path}[{i}]")

    check(out, "")
    if bad:
        raise SystemExit(f"non-finite values: {bad}")
    path = Path(__file__).resolve().parents[1] / "editions" / month / f"drivers-{month}.json"  # market-review/editions/<month>/
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n")
    print(path)


if __name__ == "__main__":
    main()
