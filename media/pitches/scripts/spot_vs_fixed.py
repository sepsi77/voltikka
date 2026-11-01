"""Spot against fixed contracts, for media pitches.

Usage (from the repository root, read-only):
    python3 -I media/pitches/scripts/spot_vs_fixed.py laravel/database/database.sqlite [CHOICE_DATE]

CHOICE_DATE (default 2026-01-21, the first Voltikka statistics date) is the date on which
a household chooses spot or a 12/24-month fixed contract in section 1.

Assumptions:
- Monthly consumption shares are the same as app/Services/BillComparison/ConsumptionProfile.php
  (no heating for 2,000 and 5,000 kWh, heating for 18,000 kWh). Consumption is flat over the
  hours of each month, so the household pays the plain monthly spot average.
- Spot prices include VAT (spot_prices_hour.price_without_tax * (1 + vat_rate)).
- Contract prices are medians from contract_price_daily_statistics (unit_statistics_v1):
  energy_price and monthly_fee for fixed_term_12 / fixed_term_24, spot_margin and monthly_fee
  for spot. They include VAT (household contracts).
- Futures: the latest FI Base curve. Months use month futures, other months use the quarter.
  A month inside a quarter that has some month futures gets the remainder of that quarter.
"""

import sqlite3
import sys
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
UTC = ZoneInfo("UTC")

db = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
CHOICE = date.fromisoformat(sys.argv[2]) if len(sys.argv) > 2 else date(2026, 1, 21)

WINTER = {1, 2, 3, 11, 12}
_sf = 12 / (7 + 5 * 1.30)
NOHEAT = {m: (_sf * 1.30 if m in WINTER else _sf) / 12 for m in range(1, 13)}
_NEED = [754, 768, 623, 426, 202, 0, 0, 36, 277, 336, 537, 812]
HEAT = {m: 0.45 / 12 + 0.55 * _NEED[m - 1] / sum(_NEED) for m in range(1, 13)}
PROFILES = {
    "kerrostalo 2000": (2000, NOHEAT),
    "omakotitalo 5000": (5000, NOHEAT),
    "sähkölämmitys 18000": (18000, HEAT),
}


def median(stat_date, segment, metric):
    row = db.execute(
        "select median_value from contract_price_daily_statistics "
        "where stat_date = ? and segment_key = ? and metric_key = ? "
        "and method_version = 'unit_statistics_v1'",
        (stat_date.isoformat(), segment, metric),
    ).fetchone()
    if row is None:
        sys.exit(f"No {segment}/{metric} median on {stat_date}")
    return float(row[0])


def offers(stat_date):
    return {
        "f12": (median(stat_date, "fixed_term_12", "energy_price"), median(stat_date, "fixed_term_12", "monthly_fee")),
        "f24": (median(stat_date, "fixed_term_24", "energy_price"), median(stat_date, "fixed_term_24", "monthly_fee")),
        "spot": (median(stat_date, "spot", "spot_margin"), median(stat_date, "spot", "monthly_fee")),
    }


hours = []
for ts, price in db.execute(
    "select utc_datetime, price_without_tax * (1 + vat_rate) from spot_prices_hour "
    "where region = 'FI' order by utc_datetime"
):
    hours.append((datetime.fromisoformat(ts).replace(tzinfo=UTC).astimezone(HEL), price))

LAST_FULL_DAY = (hours[-1][0] - timedelta(hours=23)).date()
END = LAST_FULL_DAY + timedelta(days=1)
LATEST_STAT = date.fromisoformat(db.execute("select max(stat_date) from contract_price_daily_statistics").fetchone()[0])


def hours_in_month(y, m):
    a = datetime(y, m, 1, tzinfo=HEL)
    b = datetime(y + (m == 12), m % 12 + 1, 1, tzinfo=HEL)
    return round((b.astimezone(UTC) - a.astimezone(UTC)).total_seconds() / 3600)


def period(start, end, kwh, shares, fee, energy_c=None, margin=0.0):
    """Cost in euros for [start, end). energy_c=None means spot + margin."""
    a = datetime(start.year, start.month, start.day, tzinfo=HEL)
    b = datetime(end.year, end.month, end.day, tzinfo=HEL)
    cons = cost = 0.0
    for local, price in hours:
        if a <= local < b:
            c = kwh * shares[local.month] / hours_in_month(local.year, local.month)
            cons += c
            cost += c * ((price + margin) if energy_c is None else energy_c) / 100
    return cost + fee * (end - start).days / 30.4375, cons


def spot_c(start, end, kwh, shares):
    cost, cons = period(start, end, kwh, shares, 0.0)
    return 100 * cost / cons


def annual(kwh, energy_c, fee, margin=0.0):
    return kwh * (energy_c + margin) / 100 + 12 * fee


now = offers(LATEST_STAT)
then = offers(CHOICE)
print(f"Spot data to {LAST_FULL_DAY}; latest statistics {LATEST_STAT}")
print(f"Medians {CHOICE}: {then}")
print(f"Medians {LATEST_STAT}: {now}")
print(f"12 kk kiinteä energy price change: {100 * (now['f12'][0] / then['f12'][0] - 1):+.0f} %")

print(f"\n== 1. Choice on {CHOICE}: costs {CHOICE}..{LAST_FULL_DAY} ==")
for name, (kwh, sh) in PROFILES.items():
    s, _ = period(CHOICE, END, kwh, sh, then["spot"][1], margin=then["spot"][0])
    f12, _ = period(CHOICE, END, kwh, sh, then["f12"][1], energy_c=then["f12"][0])
    f24, _ = period(CHOICE, END, kwh, sh, then["f24"][1], energy_c=then["f24"][0])
    print(f"{name}: spot {s:.0f} € | 12 kk {f12:.0f} € | 24 kk {f24:.0f} € | spot - 12 kk {s - f12:+.0f} €")

print("\n== 2. Rolling 12-month spot averages (monthly steps from 2023-01), c/kWh incl. VAT, no margin ==")
windows = {}
d = date(2023, 1, 1)
while date(d.year + 1, d.month, 1) <= END:
    e = date(d.year + 1, d.month, 1)
    windows[d] = {n: spot_c(d, e, k, sh) for n, (k, sh) in PROFILES.items()}
    d = date(d.year + (d.month == 12), d.month % 12 + 1, 1)
for y in (2023, 2024, 2025):
    v = [p for local, p in hours if local.year == y]
    print(f"{y} plain average {sum(v) / len(v):.2f}")

print(f"\n== 3. Today's offers ({LATEST_STAT}) against history ==")
last12 = (END - timedelta(days=365), END)
for name, (kwh, sh) in PROFILES.items():
    f12 = annual(kwh, *now["f12"])
    f24 = annual(kwh, *now["f24"])
    margin, fee = now["spot"]
    break_even12 = (f12 - 12 * fee) / kwh * 100 - margin
    break_even24 = (f24 - 12 * fee) / kwh * 100 - margin
    worst_start = max(windows, key=lambda w: windows[w][name])
    worst = windows[worst_start][name]
    above12 = sum(1 for w in windows.values() if w[name] > break_even12)
    s_last, _ = period(*last12, kwh, sh, fee, margin=margin)
    print(
        f"{name}: 12 kk {f12:.0f} €/v, 24 kk {f24:.0f} €/v | spot break-even 12 kk {break_even12:.2f} c, "
        f"24 kk {break_even24:.2f} c | worst window {worst_start} {worst:.2f} c "
        f"(+{100 * (break_even12 / worst - 1):.0f} % headroom) | windows above 12 kk: {above12}/{len(windows)} | "
        f"spot last 12 months {s_last:.0f} €"
    )

print("\n== 4. Futures-implied spot for the next 12 months ==")
trade = db.execute(
    "select max(trade_date) from electricity_futures_eod_prices where area = 'FI' and product = 'Base'"
).fetchone()[0]
curve = db.execute(
    "select maturity_type, display_year, display_month, settlement_price from electricity_futures_eod_prices "
    "where area = 'FI' and product = 'Base' and trade_date = ?",
    (trade,),
).fetchall()
month_f = {(y, m): float(p) for t, y, m, p in curve if t == "month"}
quarter_f = {(y, m): float(p) for t, y, m, p in curve if t == "quarter"}
first = date(LATEST_STAT.year + (LATEST_STAT.month == 12), LATEST_STAT.month % 12 + 1, 1)
months = [(first.year + (first.month - 1 + i) // 12, (first.month - 1 + i) % 12 + 1) for i in range(12)]
fut = {}
for y, m in months:
    if (y, m) in month_f:
        fut[(y, m)] = month_f[(y, m)]
        continue
    q_start = (y, 3 * ((m - 1) // 3) + 1)
    q_months = [(y, q_start[1] + i) for i in range(3)]
    known = [month_f[qm] for qm in q_months if qm in month_f]
    fut[(y, m)] = (quarter_f[q_start] * 3 - sum(known)) / (3 - len(known))
print(f"Curve {trade}: " + ", ".join(f"{y}-{m:02d} {v:.1f}" for (y, m), v in fut.items()))
for name, (kwh, sh) in PROFILES.items():
    eur_mwh = sum(sh[m] * fut[(y, m)] for y, m in months) / sum(sh[m] for _, m in months)
    c = eur_mwh / 10 * 1.255
    margin, fee = now["spot"]
    s = annual(kwh, c, fee, margin)
    f12 = annual(kwh, *now["f12"])
    f24 = annual(kwh, *now["f24"])
    be12 = (f12 - 12 * fee) / kwh * 100 - margin
    print(
        f"{name}: implied spot {c:.2f} c | spot {s:.0f} € | 12 kk {f12:.0f} € | 24 kk {f24:.0f} € | "
        f"spot - 12 kk {s - f12:+.0f} € | headroom +{100 * (be12 / c - 1):.0f} %"
    )
