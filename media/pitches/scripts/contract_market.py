"""Contract-market figures for media pitches (other than spot against fixed).

Usage (from the repository root, read-only):
    python3 -I media/pitches/scripts/contract_market.py laravel/database/database.sqlite

Sections:
1. Monthly table: plain spot average and averages of the daily medians of 12/24-month fixed
   and open-ended energy prices. All c/kWh incl. VAT (household contracts).
2. 12-month fixed median against the next-12-month FI Base futures strip, both excl. VAT.
   The difference is the "retail premium" (spread over wholesale). Never call it margin or profit.
3. Open-ended general-tariff (Yleissähkö) contracts on the latest statistics date, with a
   simple annual cost at today's price (energy price * kWh + 12 * monthly fee).
4. 15-minute prices since 2025-10-01: spread inside the hour and negative prices.
"""

import sqlite3
import sys
from collections import defaultdict
from datetime import date, datetime, timedelta
from statistics import mean
from zoneinfo import ZoneInfo

HEL = ZoneInfo("Europe/Helsinki")
UTC = ZoneInfo("UTC")
db = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)

LATEST = db.execute("select max(stat_date) from contract_price_daily_statistics").fetchone()[0]


def local(ts):
    return datetime.fromisoformat(ts).replace(tzinfo=UTC).astimezone(HEL)


def medians(segment, metric="energy_price"):
    return {
        d: float(v)
        for d, v in db.execute(
            "select stat_date, median_value from contract_price_daily_statistics "
            "where segment_key = ? and metric_key = ? and method_version = 'unit_statistics_v1' "
            "and median_value is not null",
            (segment, metric),
        )
    }


f12, f24, oe = medians("fixed_term_12"), medians("fixed_term_24"), medians("open_ended")

print(f"Latest statistics date {LATEST}")
print("\n== 1. Monthly averages, c/kWh incl. VAT ==")
spot_month = defaultdict(list)
for ts, p in db.execute(
    "select utc_datetime, price_without_tax * (1 + vat_rate) from spot_prices_hour "
    "where region = 'FI' and utc_datetime >= '2025-12-31'"
):
    t = local(ts)
    if t.year == 2026:
        spot_month[t.month].append(p)
print("month | spot | 12 kk | 24 kk | toistaiseksi")
for m in sorted(spot_month):
    row = [mean(v for d, v in s.items() if int(d[5:7]) == m and d[:4] == "2026") for s in (f12, f24, oe)]
    print(f"2026-{m:02d} | {mean(spot_month[m]):.2f} | " + " | ".join(f"{x:.2f}" for x in row))

print("\n== 2. 12 kk fixed median against the next-12-month futures strip, c/kWh excl. VAT ==")
curves = defaultdict(list)
for td, t, y, m, p in db.execute(
    "select trade_date, maturity_type, display_year, display_month, settlement_price "
    "from electricity_futures_eod_prices where area = 'FI' and product = 'Base'"
):
    curves[td].append((t, y, m, float(p)))


def strip(td):
    """Flat average of the 12 months after the trade month, in c/kWh excl. VAT."""
    mf = {(y, m): p for t, y, m, p in curves[td] if t == "month"}
    qf = {(y, m): p for t, y, m, p in curves[td] if t == "quarter"}
    d = date.fromisoformat(td)
    vals = []
    for i in range(1, 13):
        y, m = d.year + (d.month - 1 + i) // 12, (d.month - 1 + i) % 12 + 1
        if (y, m) in mf:
            vals.append(mf[(y, m)])
            continue
        qs = 3 * ((m - 1) // 3) + 1
        if (y, qs) not in qf:
            return None
        known = [mf[(y, qs + k)] for k in range(3) if (y, qs + k) in mf]
        vals.append((qf[(y, qs)] * 3 - sum(known)) / (3 - len(known)))
    return mean(vals) / 10


first_of_month = {}
for td in sorted(curves):
    first_of_month.setdefault(td[:7], td)
picks = sorted(set(first_of_month.values()) | {max(curves)})
print("trade date | strip | 12 kk fixed (next stat day) | premium")
for td in picks:
    s = strip(td)
    nxt = min((d for d in f12 if d > td), default=None)
    if s is None or nxt is None:
        continue
    fx = f12[nxt] / 1.255
    print(f"{td} | {s:.2f} | {fx:.2f} ({nxt}) | {fx - s:+.2f}")

print(f"\n== 3. Open-ended Yleissähkö contracts on {LATEST}, annual cost at today's price ==")
print("company | contract | national | c/kWh | €/kk | 2000 kWh | 5000 kWh")
for co, name, nat, e, fee in db.execute(
    "select s.company_name, s.contract_name, c.availability_is_national, s.energy_price_cents_per_kwh, "
    "s.monthly_fee_eur from contract_price_snapshots s join electricity_contracts c on c.id = s.contract_id "
    "where s.snapshot_date = ? and s.segment_key = 'open_ended' and s.metering = 'General' "
    "and s.energy_price_cents_per_kwh is not null order by s.energy_price_cents_per_kwh",
    (LATEST,),
):
    e, fee = float(e), float(fee)
    a2, a5 = 20 * e + 12 * fee, 50 * e + 12 * fee
    print(f"{co} | {name} | {'yes' if nat else 'no'} | {e:.2f} | {fee:.2f} | {a2:.0f} € | {a5:.0f} €")

print("\n== 4. 15-minute prices, c/kWh incl. VAT ==")
hours = defaultdict(list)
for ts, p in db.execute(
    "select utc_datetime, price_without_tax * (1 + vat_rate) from spot_prices_quarter "
    "where region = 'FI' and utc_datetime >= '2025-09-30 21:00:00'"
):
    t = local(ts)
    hours[t.replace(minute=0)].append(p)
full = {h: q for h, q in hours.items() if len(q) == 4}
spreads = [max(q) - min(q) for q in full.values()]
above = [max(q) - mean(q) for q in full.values()]
first, last = min(full), max(full)
print(f"Hours with 4 quarters: {len(full)} ({first:%Y-%m-%d} .. {last:%Y-%m-%d})")
print(f"Max-min spread inside the hour: mean {mean(spreads):.2f} c, hours with spread > 5 c: "
      f"{sum(s > 5 for s in spreads)}, > 10 c: {sum(s > 10 for s in spreads)}, max {max(spreads):.1f} c")
print(f"Most expensive quarter above its hourly average: mean {mean(above):.2f} c, max {max(above):.1f} c")
worst = max(full, key=lambda h: max(full[h]) - min(full[h]))
print(f"Largest spread: {worst:%Y-%m-%d %H:00} quarters " + ", ".join(f"{x:.1f}" for x in full[worst]))
neg_q = sum(1 for q in full.values() for x in q if x < 0)
print(f"Negative quarters: {neg_q} of {4 * len(full)}")

# Same calendar window one year earlier, hourly data, for negative-price comparison.
a, b = first - timedelta(days=365), last - timedelta(days=365)
prev = [
    p for ts, p in db.execute(
        "select utc_datetime, price_without_tax from spot_prices_hour where region = 'FI' "
        "and utc_datetime >= ? and utc_datetime <= ?",
        (a.astimezone(UTC).strftime("%Y-%m-%d %H:%M:%S"), b.astimezone(UTC).strftime("%Y-%m-%d %H:%M:%S")),
    )
]
cur = [
    p for ts, p in db.execute(
        "select utc_datetime, price_without_tax from spot_prices_hour where region = 'FI' "
        "and utc_datetime >= ? and utc_datetime <= ?",
        (first.astimezone(UTC).strftime("%Y-%m-%d %H:%M:%S"), last.astimezone(UTC).strftime("%Y-%m-%d %H:%M:%S")),
    )
]
for label, v in (("previous year", prev), ("15-min year", cur)):
    neg = [x for x in v if x < 0]
    print(f"Hourly, {label}: {len(neg)} negative hours of {len(v)}, "
          f"mean negative {mean(neg) if neg else 0:.2f} c, lowest {min(v):.2f} c (excl. VAT)")
