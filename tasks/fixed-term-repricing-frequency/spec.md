# Fixed-term repricing frequency

Research only. Measure same-ID and supported lineage energy-price changes for fixed-price 6/12/24-month offers, supplier offered medians, and exact-date 7/14/30/45/60/90-day windows. Separate monthly fees, promotions, missing evidence, method seams and censoring. Test whether slow repricing supports (not proves) a longer forecast horizon. Use the complete verified supplier-premium export. Do not change application code, existing tasks, production, or the local database. Do not commit or push.

Deliver reproducible Python, CSV, JSON and report. Report observed dates, not exact seller decision dates. No-change endpoints do not prove no intermediate changes.

## Continuation

Use only the same verified export, April 8–September 13. Add `horizon-lag-analysis.py`, independent checks, CSV/JSON and `horizon-lag-report.md`. Preserve all cadence artifacts. No application change, production access, database replacement, dependency, commit or push.

Predeclared horizons: 14/30/45/60 days. Median market unit_statistics_v1 for 6/12/24 months; exact same-basis current/target. Compare unchanged, repaired v3 EWMA gap (alpha .25, lambda .30, minimum 10, observed prefix then canonical only) and untuned 14-day retail momentum on identical model cohorts. Non-30-day gap forecasts are sensitivity only. Report individual coverage, 14/30/45 intersection, and a separate previous 30-day replay cohort. No cross-basis targets to fill 60 days. No horizon winner from different target cohorts. Optional coefficient fitting is not required; any fit must use strictly matured prior targets and the user-specified grid and 20 prior issue-day minimum.

Predeclared delay grid: 0/7/14/21/30/45/60 days. Correlate seven-day retail changes with lagged seven-day hedge changes, not price levels. Keep public market statistics (eligible Time/Season included) separate from supplier General medians. Require complete same-basis retail windows. Primary analysis removes delivery-basket rolls; retain unscreened comparison, removed counts, distinct daily retail change events and common-date lag comparisons. No causal or calibrated lag claim. Disclose revised export-time data, overlapping windows, common shocks and offer-mix/survivor bias.
