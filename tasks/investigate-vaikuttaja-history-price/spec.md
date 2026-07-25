# Investigate Vaikuttaja history price

Investigate why the Vaasan Sähkö Myynti Oy Vaikuttaja contract detail page shows a current energy price of 6.60 c/kWh, while the contract history summary graph reports a drop to 0.00 c/kWh.

Scope:
- Trace the contract-detail current-price and history-summary data paths.
- Inspect relevant production records with read-only operations if needed.
- Identify the root cause and report evidence.
- Resolve duplicate source rows that map to the same relational price-component key before upsert.
- Add focused regression tests and update nearby implementation documentation.
- Do not change production data or deploy without explicit confirmation.
