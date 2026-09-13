# Decisions

- Export public application evidence only. Keep the database unchanged.
- Read forecasts without a model, quantile, or horizon filter. Preserve full source_metadata.
- Include the early June futures and statistics dates as comparison evidence.
- The original export unit excluded analysis. The manager then assigned local stability calculations and code-math analysis in this same task directory.
- Initial working tree was clean.

## Export result

Completed export: `export-20260913T073334Z/`. Use this directory only for analysis. Its manifest has status `complete`.

- Database clock: 2026-09-13 07:33:36; UTC clock is identical. Session time zone: SYSTEM. Isolation: REPEATABLE-READ.
- Entire forecast table: 1,314 rows; forecast dates 2026-04-18 through 2026-09-13.
- Selected forecasts: 810 rows across 89 dates, 2026-06-13 through 2026-09-13. Models fixed_term_ewma_gap_v1 and fixed_term_ewma_gap_v2 each have 405 rows. All stored horizons are 30 days. Durations 6/12/24 and quantiles median/p20/p80 each have 270 rows per value.
- FI Base futures: 1,499 rows across 75 trade dates, 2026-06-01 through 2026-09-11. Month: 524; quarter: 525; year: 450. All instrument columns are preserved.
- Retail statistics: 315 rows across 105 dates, 2026-06-01 through 2026-09-13. Each selected segment has 105 rows. All use unit_statistics_v1. Basis counts: observed_seller_data 168; canonical_calculation 147.
- Relevant schemas: 80 column records.
- JSON columns remain database JSON strings. All 810 forecast source_metadata values passed local JSON validation.
- Each data file has exact SQL, UTC times, row count, byte count, and SHA-256 in manifest.json. The manifest has a separate manifest.sha256 file.
- Queries use a 20-second MySQL execution hint and a 20,001-row sentinel that fails above 20,000 rows. The export data limit is 100 MiB. PDO is unbuffered. The helper starts the consistent READ ONLY transaction and rolls it back in finally. Laravel is not booted.

## Verification and failures

- `php -l tasks/forecast-stability-review/export-production.php`: passed on each run.
- The exact requested Railway command was run in the foreground with the explicit IDs and stable caller/session. The fourth run completed all six SELECT queries.
- Three earlier attempts failed safely: one at connection setup and two at the database_clock query. Their failed manifests remain in export-20260913T073229Z, export-20260913T073257Z, and export-20260913T073314Z. No application rows were exported in these attempts. Exception details were suppressed.
- The local error handler now suppresses deprecation notices from the existing connection helper. All date/time SQL aliases that match built-in names are quoted. No helper or application code was changed.
- Local Python validation passed for complete status, every file hash, manifest hash, row counts, byte counts, JSON syntax, and forecast source_metadata JSON syntax.
- `git diff --check`: passed. Final status contains only the new task directory. No application test suite or asset build was needed because application code was not changed.
- The export phase performed no production writes, local database replacement, stability calculations, or code-math analysis.

## Local analysis result

- Added `analyze.py`, standard library only. It reads only the complete export and validates manifest status/hash plus all six data-file hashes, byte counts, and row counts before generation. Failed attempts are not inputs.
- Generated seven CSVs, `analysis-summary.json`, `forecast-timeline.svg`, and `report.md`. All original forecast columns and full JSON metadata are preserved. The headline has 267 median rows across 89 dates; v1 ends 26 July and v2 starts 27 July. Six dual-model median rows remain separate.
- Adjacent comparisons measure successive 30-day-outlook vintages, not revisions for a fixed target. They include gap-spanning pairs and mark day gaps and the model boundary. Chart paths stop at missing dates and the boundary.
- All 270 stored median hedge costs reconstruct within 0.0000498066 c/kWh (limit 0.000051). Month boundaries compare old/new delivery windows on the new curve; the old-window remainder can include source-selection changes. The August 6m roll share is about 92% on the new curve, or 85% on the old curve. These are accounting orderings, not unique causal shares.
- V2 normal premiums remain fixed, with observed history ending 26 July at 109 observations. The report proposes learning-path and same-basis evaluation work; it makes no app change.
- Accuracy uses stored evaluation facts only. Direction-category correctness maps slightly rising/falling to flat; it is not raw sign accuracy. There are 15 evaluated v1 median rows per term and 16 matured, unevaluated v2 median rows per term as of 13 September.
- The report explains retail sample-size/product-mix changes, canonical/observed basis changes, energy-only scope, low confidence, and why p20/p80 are not confidence bounds.
- Correction to the delegated volume assumption: the verified raw futures export has 1,475 null volume values and 24 non-null values, not all null. An initial assertion caught this. The final script asserts the correct counts, and the report avoids market-wide activity or liquidity claims.
- Fixed-maturity returns use 12 June–11 September: 66 observations, 65 adjacent pairs, and 61 exact-seven-calendar-day pairs per instrument. Five endpoints per instrument lack an in-sample exact-seven-day predecessor. No carry forward is used.

## Local analysis verification

- `python3 tasks/forecast-stability-review/analyze.py`: passed, including hash/count checks, 270 hedge reconstructions, signal counts/reversals, threshold sensitivity, fixed v2 premiums, sample counts, and decomposition assertions.
- Repeated generation with Python SHA-256 comparison: all 10 generated artifact hashes unchanged.
- Python `xml.etree.ElementTree.parse`: standalone SVG XML passed. No browser visual review was performed.
- Python `csv.DictReader` row checks passed: original 810; daily 267; dual-model 6; weekly 14; revisions 264; month boundaries 9; fixed futures 198.
- `git diff --check`: passed. The task directory is untracked, so this command alone does not check new-file whitespace; a separate local whitespace check is also used.
- Final working tree contains only `?? tasks/forecast-stability-review/`. No application tests or asset build were needed: no app code, CSS, or JavaScript changed. No production access, commit, or push occurred in this analysis unit.
