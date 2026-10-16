# /sahkosopimus/tilastot annual-cost gaps, 21.9–12.10.2026

## Problem
The weekly annual-cost lead chart showed no value for open-ended contracts in the weeks of
28.9 and 5.10, and for spot in the week of 5.10. The stored v3 daily rows exist.

## Causes (verified 2026-10-16 on a production copy)
1. 7.10: the EEX run stored only quarter/year FI futures for trade date 6.10 (months came on 8.10).
   `EexMarketReferenceCurveProvider` chose that incomplete vintage, November was missing
   (`missing_forward_month_2026_11`), and all spot contracts fell back to rolling-365 and 37
   open-ended contracts to the seasonal index. The day became its own chart regime; the 5.10 week
   was mixed and null. Medians that day are wrong (spot 455 €, open-ended 621 € vs ~530/~750).
2. Open-ended: the dominant estimate method flips between `supplier_adjusted_forward_premium` and
   `supplier_adjusted_forward_curve_shift` on near ties (contract replacements on 1.10: 19/16 →
   18/18 → 18/19; also 8.9 and 10.9). `AnnualSeriesCompatibility::aggregateDisplayKey()` treated
   that as a regime change.
3. 1.10 has no price snapshots at all (separate investigation).

## Work
- Chart: one display family for the own-reference and premium forward variants.
- Curve: skip incomplete futures vintages.
- Production: recalculate 7.10 v3 (user approved on 2026-10-16).
- Investigate 1.10.
