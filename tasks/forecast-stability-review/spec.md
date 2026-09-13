# Forecast stability review

Assess forecast and futures stability from 2026-06-13 through 2026-09-13 with production evidence. The export work is complete. The follow-on work builds a reproducible local three-month timeline and stability review from the verified export only.

## Export scope
- All fixed_contract_price_forecasts rows for forecast_date 2026-06-13 through 2026-09-13, with all quantiles, model versions, horizons, and full source_metadata.
- FI Base electricity_futures_eod_prices for trade_date 2026-06-01 through 2026-09-13, with delivery instrument metadata.
- energy_price contract_price_daily_statistics for fixed_term_6, fixed_term_12, and fixed_term_24 from 2026-06-01 through 2026-09-13.
- Entire forecast table count and minimum/maximum forecast dates, database clock, relevant column schemas, exact queries, export times, row counts, and SHA-256 hashes.

## Local analysis scope
- Use only `export-20260913T073334Z`; verify its manifest, hashes, bytes, and counts.
- Build standard-library Python analysis, full original and selected daily CSVs, weekly timeline, fixed-delivery futures returns, outlook-change decomposition, summary JSON, standalone SVG, and a plain-English report.
- Select median/30-day v1 through 26 July and v2 from 27 July; preserve the dual-model date separately. Do not claim stored history proves HTML impressions.
- Reconstruct every stored median hedge cost, separate delivery-window rolls from curve/source changes, and use stored evaluation facts for accuracy.
- Explain missing dates, changing retail product mix, basis changes, low confidence, and limited v2 evaluation. Recommendations are proposals only.
- Analysis work must stay inside this task directory. No production access or application changes.

## Export safety (completed phase)
Use the explicit production Railway project, environment, and MySQL service. Use the standalone ProductionMySqlConnection helper, not Laravel bootstrap. Execute only SELECT queries within one consistent READ ONLY transaction; always roll back. Limit query time, rows, and export size. Do not print secrets or exception details. Do not access authentication tables, change application code, or replace the local database.
