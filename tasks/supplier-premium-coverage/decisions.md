# Decisions

- Scope is evidence only. Existing forecasting edits in the working tree belong to another task and are not deployed.
- Read the RetailPremium constants and metadata builders. Current floors are retail-premium-v2 and retail-premium-history-v2. Inspect production versions before filtering the export.
- Period overlap with the requested dates selects premiums; original period endpoints stay unchanged. Forecast bounds apply to forecast_date, not target_date.
- Store local artifacts only in this task directory. All 1,897 requested-term premium rows have stored duration metadata. No current contract mapping was read. Historical metadata keeps its active-tip template provenance; it does not claim direct dated duration disclosure.
- Production version metadata selected exactly retail-premium-v2 and retail-premium-history-v2. All other method rows remain out of the premium export.
- The first export (export-20260913T093827Z) selected snapshot fixed-price segments only. Keep it as superseded evidence. The complete second export (export-20260913T094138Z) selects FixedTerm plus Fixed6/Fixed12/Fixed24, including 6,801 Hybrid snapshots. This prevents segment selection from hiding fixed-duration variants. Do not pool the exports.
- Final data has 25,620 snapshots, 2,239 futures, 477 statistics, 1,314 forecasts and 1,949 current-pair term_strip premium rows. All count-first queries and hash checks passed. All writes were local; both production transactions were read-only and rolled back.
- Report 411 numerically compatible General premium rows in 20 cells from 10 suppliers. VAT stays separate. These are descriptive rows, not independent repricings or validated forecasts. Unknown VAT affects 1,249 requested-term rows, but not all rows.
- Drop 29 method seams from independent period-key counts. Preserve source recurrences and flag unchanged-signature and overlapping pairs instead of inventing merged periods. Component-period keys, lineage counts and pair audits remain distinct metrics.
- No model, supplier profit claim, quarterly beta inference, deployment, or application/context-file edit belongs to this unit. The task report holds the analysis rules because the scope explicitly excludes application AGENTS changes.
- Verification: PHP lint passed; two explicit-ID Railway runs completed with exit 0; final local analysis verified all file/query/manifest hashes, expected counts, byte counts and snapshot duration values. Application tests and asset build were not run because application files did not change.
