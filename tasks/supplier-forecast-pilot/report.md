# Supplier offered-energy-price index diagnostic

## Result

**The supplier-specific model does not improve on unchanged prices in this sample.** Its result against the pooled General baseline is effectively tied and changes sign with the weighting rule. This is not evidence to adopt a supplier forecast.

All errors and changes below are in c/kWh. Positive improvement means a lower supplier-model MAE. Coefficients were fixed before evaluation: alpha = 0.25, lambda = 0.30. No parameter was fitted to the targets.

| Weighting | Supplier-term cells | Forecast pairs | Supplier MAE | Unchanged MAE | Pooled-change MAE | Improvement vs unchanged | Improvement vs pooled |
|---|---:|---:|---:|---:|---:|---:|---:|
| Equal supplier-term cell | 51 | 891 | 0.545631 | 0.513255 | 0.545929 | -0.032376 (-6.31%) | +0.000298 (+0.05%) |
| Pooled forecast pair | 51 | 891 | 0.529232 | 0.496953 | 0.528835 | -0.032279 (-6.50%) | -0.000397 (-0.08%) |

There are 23 evaluated suppliers. Equal-cell weighting gives each included supplier-term cell one equal weight. It does not give each supplier one equal weight across all terms. Pooled-pair weighting gives each included forecast pair one equal weight.

| Term | Cells | Pairs | Supplier MAE | Unchanged MAE | Pooled-change MAE |
|---|---:|---:|---:|---:|---:|
| 6 months | 8 | 129 | 1.162639 | 1.139341 | 1.174806 |
| 12 months | 21 | 363 | 0.523039 | 0.476116 | 0.519694 |
| 24 months | 22 | 399 | 0.330080 | 0.308221 | 0.328304 |

These term rows use pooled-pair weighting. `aggregate-results.csv` also gives equal-cell term results and both absolute and percentage improvements. No supplier is selected as a winner after looking at these results.

## Supplier-specific results

All 84 supplier-term cells, including zero-pair cells, are in `supplier-term-results.csv`. It contains both baseline improvements, exact available pair counts, exclusions, date coverage, daily history counts, distinct median price levels, and median changes. The table below contains all 51 evaluated cells, in supplier order. It is descriptive, not a supplier ranking.

| Supplier | Term | n | Supplier MAE | Unchanged MAE | Pooled MAE |
|---|---:|---:|---:|---:|---:|
| Alajärven Sähkö Oy | 12 | 19 | 0.7147 | 0.6368 | 0.6901 |
| Alajärven Sähkö Oy | 24 | 19 | 0.2929 | 0.2605 | 0.2831 |
| Cheap Energy Finland Oy | 24 | 19 | 0.2003 | 0.1832 | 0.2037 |
| Fortum Markets Oy | 24 | 19 | 0.0381 | 0.0000 | 0.0324 |
| Hehku Energia Oy | 12 | 18 | 0.8658 | 0.8150 | 0.8667 |
| Hehku Energia Oy | 24 | 18 | 0.1918 | 0.1694 | 0.1934 |
| Helen Oy | 6 | 17 | 0.9680 | 0.9976 | 1.0399 |
| Helen Oy | 12 | 17 | 0.4750 | 0.5041 | 0.4662 |
| Helen Oy | 24 | 17 | 0.3769 | 0.3971 | 0.3813 |
| Imatran Seudun Sähkö Oy | 6 | 17 | 0.7673 | 0.6894 | 0.7707 |
| Imatran Seudun Sähkö Oy | 12 | 18 | 0.2314 | 0.1833 | 0.2389 |
| Imatran Seudun Sähkö Oy | 24 | 17 | 0.3942 | 0.3918 | 0.4106 |
| Keravan Energia Oy | 6 | 18 | 0.9516 | 0.9583 | 0.9758 |
| Keravan Energia Oy | 12 | 19 | 0.7727 | 0.7332 | 0.7852 |
| Keravan Energia Oy | 24 | 17 | 0.7248 | 0.7100 | 0.7295 |
| Koillis-Satakunnan Sähkö Oy | 12 | 17 | 0.5441 | 0.5024 | 0.5570 |
| Koillis-Satakunnan Sähkö Oy | 24 | 16 | 0.4123 | 0.3706 | 0.3921 |
| Kokkolan Energia Oy | 6 | 19 | 1.4920 | 1.4816 | 1.5066 |
| Kokkolan Energia Oy | 12 | 19 | 0.5841 | 0.5532 | 0.6052 |
| Kokkolan Energia Oy | 24 | 19 | 0.5236 | 0.5079 | 0.5372 |
| Korpelan Energia Oy | 12 | 17 | 0.9010 | 0.8294 | 0.8840 |
| Köyliön-Säkylän Sähkö Oy | 12 | 19 | 0.5516 | 0.5158 | 0.5690 |
| Köyliön-Säkylän Sähkö Oy | 24 | 19 | 0.4115 | 0.3895 | 0.4113 |
| Lammaisten Energia Oy | 12 | 18 | 0.6048 | 0.4911 | 0.5467 |
| Lammaisten Energia Oy | 24 | 18 | 0.3015 | 0.2800 | 0.3123 |
| Nurmijärven Sähkö Oy | 12 | 19 | 0.6054 | 0.5474 | 0.6006 |
| Nurmijärven Sähkö Oy | 24 | 19 | 0.5990 | 0.5474 | 0.5700 |
| Omavoima Oy | 12 | 18 | 0.3721 | 0.3167 | 0.3676 |
| Omavoima Oy | 24 | 18 | 0.1867 | 0.1500 | 0.1777 |
| Oomi Oy | 12 | 19 | 0.3955 | 0.3684 | 0.3942 |
| Oomi Oy | 24 | 18 | 0.1803 | 0.1844 | 0.1946 |
| Paneliankosken Voima Oy | 12 | 17 | 0.7724 | 0.7059 | 0.7585 |
| Paneliankosken Voima Oy | 24 | 18 | 0.6997 | 0.6750 | 0.6962 |
| Pohjois-Karjalan Sähkö Oy | 6 | 19 | 1.7574 | 1.6634 | 1.6884 |
| Pohjois-Karjalan Sähkö Oy | 12 | 19 | 0.2265 | 0.1405 | 0.1937 |
| Pohjois-Karjalan Sähkö Oy | 24 | 19 | 0.0202 | 0.0000 | 0.0324 |
| Porvoon Energia Oy | 12 | 19 | 0.3281 | 0.2895 | 0.3416 |
| Porvoon Energia Oy | 24 | 19 | 0.2171 | 0.1947 | 0.2251 |
| Turku Energia Oy | 6 | 18 | 0.6364 | 0.6217 | 0.6439 |
| Turku Energia Oy | 12 | 18 | 0.3745 | 0.3550 | 0.3546 |
| Turku Energia Oy | 24 | 18 | 0.1586 | 0.1428 | 0.1403 |
| Vaasan Sähkö Myynti Oy | 6 | 18 | 1.3801 | 1.3889 | 1.4188 |
| Vaasan Sähkö Myynti Oy | 12 | 6 | 0.2636 | 0.2200 | 0.2629 |
| Vaasan Sähkö Myynti Oy | 24 | 18 | 0.3315 | 0.3061 | 0.3301 |
| Vattenfall Oy | 12 | 11 | 0.4314 | 0.4491 | 0.4593 |
| Vattenfall Oy | 24 | 17 | 0.2517 | 0.2588 | 0.2564 |
| Vihreä Älyenergia Oy | 12 | 19 | 0.4900 | 0.4342 | 0.4874 |
| Vihreä Älyenergia Oy | 24 | 19 | 0.4876 | 0.4342 | 0.4568 |
| Äänekosken Energia Oy | 6 | 3 | 1.7717 | 1.7000 | 1.7911 |
| Äänekosken Energia Oy | 12 | 17 | 0.2931 | 0.2529 | 0.3111 |
| Äänekosken Energia Oy | 24 | 18 | 0.3044 | 0.2778 | 0.3006 |

## Inputs and model

The only input is the complete, verified `../supplier-premium-coverage/export-20260913T094138Z/`. Its manifest SHA-256 is `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`.

Use stored exact `fixed_term_6/12/24`, `General`, and `FixedPrice` snapshots. The producer's energy filter accepts 0.005 through 50.0 inclusive; this diagnostic also requires finite values. The producer admits Household/Both/null targets. The export lacks target group, so this audience rule is trusted from the producer, not re-proved per historical row. Canonical General rates are household VAT-normalized offered unit prices. Historical observed rates use relational General prices. Their comparable scale and population are an explicit continuity assumption, not historical VAT evidence.

For each term, the first canonical unit statistic is **2026-07-27**. Use observed daily medians before that boundary, then canonical medians only. There is no observed fallback after it. The September 12 annual-statistics method switch does not define this unit-price transition. At the first issue date, canonical history count is zero: the model initializes from the assumed comparable observed prefix.

For supplier c, term t, and date d, R is the median across eligible supplier offers. Market M is the median of **all the same eligible General offers**, not a median of supplier medians. Each stored contract offer has one weight in M. Supplier duplicate/variant mix remains inside R; contract IDs are not replacement lineages.

H uses the latest FI Base trade date strictly before d. Delivery starts next full month. Resolve each delivery month from month, quarter, then year settlement; weight by calendar days; convert EUR/MWh to c/kWh with /10 × 1.255. A missing month makes the complete strip unavailable. Never use an older complete curve to replace an incomplete latest curve.

Learn N(c) = EWMA(R(c) − H) on all prior usable daily observations. Seed with the first usable difference and update with alpha 0.25. Forecast R(c) + 0.30 × [H + N(c) − R(c)]. The unchanged baseline is R(c). The pooled-change baseline is R(c) + 0.30 × [H + EWMA(M − H) − M], with the same history rule. This narrowed General baseline is **not** the exact public forecast, which also includes Time/Season offers. A separate PHP service replay owns that comparison.

## Evaluation and exclusions

Issue dates are July 27–August 14; targets are exact +30 calendar days, August 26–September 13. All three models use exactly the same supplier/date/term pairs and canonical current/target basis. No interpolation, carry-forward, same-day futures, or future retail history is used. Later rolling issues can use new history already observed by their issue date, but no target in this holdout has yet occurred.

- 25,620 source snapshots; 11,608 eligible; 14,012 excluded. Non-exclusive reasons: 8,140 non-General, 6,801 wrong segment and the same 6,801 non-FixedPrice, plus 9 failed unit-price filters. No row fails the selected history-basis rule.
- 28 source suppliers; 27 have some eligible snapshot history. The grid has 1,596 candidate pairs; 891 pass and 705 fail. Non-exclusive failures: 621 missing exact current, 645 missing exact target, 304 supplier histories below ten usable days. No accepted pair is given a different model denominator.
- The 477 calendar date/term market slots have no missing eligible market day. Three hedge slots fail: April 8 in each term has no prior curve. All other tested strips are complete. No issue-date curve fails.
- Accepted supplier histories have 51–127 usable daily observations, 2–18 distinct median levels, and 1–17 changes between successive accepted daily medians. They have 51–109 observed days and 0–18 canonical days. Market histories have 109–127 usable days. These counts are not proof of sufficient independent evidence. History gaps are not filled; supplier missing-date counts range from 0 to 59 before issue.

`pair-audit.csv` records every candidate, reason, and history count. `forecast-pairs.csv` holds all predictions and errors. `available-date-pairs.csv` shows each of the 57 issue/target/term combinations. `snapshot-exclusions.csv`, `date-gap-audit.csv`, `daily-indices.csv`, and `curve-audit.csv` preserve filter, basis, median, date, and curve evidence. Counts of exclusions overlap and must not be added.

## Limits and adoption decision

All 19 forecast windows overlap, and the sample covers only one market regime. Daily rows are not independent repricings. Even median changes can result from a changing offer mix, related product variants, or a method transition. The table does not identify genuine independent supplier price periods. No reliable supplier forecast, statistical significance, or optimum consumer policy follows from this sample.

Dateful full-table exports are not original as-of query archives. Some historical data was written later. Strict date filters prevent future-dated inputs, but do not prove that every exported historical row was available unchanged to a live query on that date. Results are conditional on the observed-prefix continuity and retained-evidence assumptions.

Formal premium coverage remains separate: 411 compatible General rows from ten suppliers, not 411 independent price changes. Requested-term formal VAT counts are 1,249 unknown, 494 included, and 154 excluded. This diagnostic does not fill unknown VAT, make all unknown-VAT rows usable, fix lineage timing, or normalize supplier variants. Formal VAT, lineage, chronology, and population limits still block adoption as a validated supplier-premium forecast.

Fees and fee-inclusive reference-consumption values are excluded. The wholesale curve is not actual supplier procurement cost. Grouped offered unit-price medians and their reference differences are not wholesale physical margins or profit.

## Verification

- `python3 tasks/supplier-forecast-pilot/analysis.py`: exit 0; all manifest, SQL/file hash, byte/row count, price-edge, fixed-weight, strict-date, duplicate-contract-date, complete-strip, and identical-denominator assertions passed.
- `python3 tasks/supplier-forecast-pilot/analysis.py --check`: exit 0. Separate checks re-read raw inputs and saved CSVs: 9,571 daily indices, 477 curves, 1,596 eligibility decisions, 891 matched pairs, and eight weighted aggregates passed. Checks use sorted middle values for medians, an independent calendar-day strip walk, and explicit EWMA weights.
- A second full run produced byte-identical output for all ten generated CSV/JSON artifacts. Python syntax, all 51 report table rows against the result CSV, and new-file Git whitespace checks passed. Final Git status was reviewed; unrelated application changes were not edited.
- No Laravel tests or asset build: no application, CSS, or JS change. No production command or database operation was used.
