# Offline supplier forecast diagnostic

Use only the verified export `../supplier-premium-coverage/export-20260913T094138Z` and Python standard library. Do not change application code, databases, settings, or production.

Forecast the household General fixed-term 6/12/24-month offered-energy-price index. Use exact stored segments, FixedPrice, and producer-clean unit rates. Aggregate supplier/date/term medians. Compare fixed alpha 0.25, lambda 0.30 supplier EWMA gap closure with unchanged supplier prices and a pooled General contract-weighted market-change baseline on identical pairs.

Issue dates: 2026-07-27 through 2026-08-14. Targets: exact 30 calendar days later, 2026-08-26 through 2026-09-13. Require canonical current and target evidence, complete latest strict-prior FI Base curves, and at least ten prior usable daily observations. Preserve observed history only before each term's first canonical statistic. No interpolation, carry-forward, parameter fitting, or observed fallback after the boundary.

Report all exclusions, daily history and price-level counts, median changes, curve gaps, supplier-term and aggregate errors, equal-cell and pooled-pair weights. State assumptions and formal premium VAT/lineage limits. No reliable forecast or consumer policy claim follows from this short overlapping sample.
