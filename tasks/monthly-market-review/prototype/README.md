# Prototype: September 2026 review

Purpose: test the review shape (decisions.md, 2026-11-01) on real data before we build the
snapshot table and the page. Not for publication.

## Files

- `compute.py` – reads the local SQLite copy of production (read-only) and writes
  `metrics-YYYY-MM.json`. `python3 -I compute.py ../../../laravel/database/database.sqlite 2026-09 2026-10-07`
  (month, assumed publication date; futures are read from trade dates before that date).
- `charts.py` – three SVG charts from the metrics, no dependencies. `python3 -I charts.py 2026-09`
- `2026-09-katsaus.md` – the Finnish draft. ⟦AVOIN⟧ = waits for backfilled data, ⟦TARKISTA⟧ = check
  before publication.

Data: production copy synced 2026-11-01 ~13:36 Helsinki. At that time the backfills (started
12:17) had not reached September for Fingrid, neighbour zones, outages or weather.

## What the prototype showed

1. **The mean alone misleads.** Mean 7.15 c/kWh, median 4.52, p10 0.55, p90 18.0. Six expensive
   weekdays made the mean. The key figures need the median or a share of cheap hours next to the
   mean, and the consumption-weighted price.
2. **Weekday vs weekend** (8.64 vs 3.06 c/kWh) is a strong, simple consumer angle.
3. **Quarterly resets** on 1 October: median 8.92 → 13.15 c/kWh (+47 %). An event after month end
   but before publication on about the 7th, so the review can include it. Strong media item.
4. **Futures accountability works** (FI futures from 2026-04-08): the September future on 31.8 was
   9.83 c/kWh incl. VAT, the result 7.15 (27 % lower).
5. **Fixed prices rose 7 %** while the coming months' futures fell. The explanation is the winter
   and the year: Q1 2027 +14 %, year 2027 +12 % during September. Percentages are our own derived
   values, so they do not add EEX quotes.
6. **New/withdrawn offers: the raw counts are wrong.** 392 "new" and 419 "withdrawn" household
   contracts, but about 300 are on sale on any day: sellers republish products under new IDs.
   Without contracts that have a trusted replacement link: 37 new, 37 withdrawn; on sale 293 → 278.
   Still experimental (short-lived listings, untrusted links). Keep only the on-sale count, or keep
   the filtered counts with a method note.
7. **Spot vs fixed for one month misleads,** because a fixed price also covers the winter. Use the
   year-to-date comparison. Flat-profile placeholder: spot Jan 21–Sep 30 7.17 + 0.50 margin =
   7.67 c/kWh vs the median 12-month fixed price on 2026-01-21 of 8.09 c/kWh. The difference is so
   small that the consumption weighting (Datahub 364) decides the result. Note: contract statistics
   use `observed_seller_data` until 2026-07-26 and `canonical_calculation` from 2026-07-27; a
   series across that date must state the seam.
8. **Heat map:** each weekday × hour cell holds only 4–5 days, so it is noisy. Keep the text to
   "in September" and do not present it as a rule.
9. **Max quarter-hour** 50.16 c/kWh on 15.9 at 01:00–01:15 (VAT0 39.97). Unusual at night: check
   against another source before publication.
10. **Charts:** price, wind and temperature go in three panels with one shared time axis, not in one
    chart with two y-axes. Site tokens lake/fox/violet pass the colour-vision checks but are below
    the validator's chroma floor (muted brand colours); direct labels on every line.

## Open points (fill after the backfills)

- Driver table: wind (75, 268), temperature vs 1991–2020 normal (ERA5), nuclear (188 + outages),
  net import (194) and FI = SE1/EE share, Norwegian reservoirs (NVE), gas storage (GIE),
  consumption (124).
- Consumption-weighted spot price per house type (Datahub 364, BE01–BE03), marked "preliminary".
- Spot vs fixed year-to-date, weighted, at 2,000 / 5,000 / 18,000 kWh.
- Wind and temperature panels under the daily price chart.
- Known outages in the next month (ENTSO-E outages).
- Weather outlook: ECMWF vintages are stored only from 2026-11-01, so the first real outlook comes
  with the October review (published about 7.11.).
- Voltikka's own fixed-term forecast direction: the September/October rows came from the drift
  model that was replaced on 2026-10-16. Include it from the October review.

## Decisions for the user

1. Key figures: mean + median (or share of hours below 1 c), or mean + consumption-weighted price?
2. New/withdrawn offers: keep the filtered counts as experimental, or show only the on-sale count?
3. EEX values: the draft quotes six settlement values (September future before delivery,
   October on 31.8 and 6.10, November, Q1 2027, year 2027) and two derived percentages. Is six
   still "a handful"?
4. VAT on negative hours: the site multiplies negative prices by 1.255 as well (`price_with_tax`).
   Keep that convention in the review or show negative hours without VAT?
