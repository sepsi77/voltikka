# Decisions

- 2026-10-23: the user rejected "the data is lost". Retail premium periods take their dates from
  source episodes, so the next collection covers 22–23 October. Forecasts accept `--as-of`.
- Read-only v3 rebuild preview: 21 October reproduces the stored 748 rows with 0.00 EUR median
  deltas; 22 October reproduces 655 and adds none, because a contract without a snapshot is excluded
  as `missing_historical_snapshot_identity`.
- Of 35 active household contracts covered on 22 October without a snapshot, 23 kept the same source
  and its published interpretation; 12 had a one-day payload whose interpretation failed (401) and
  was never retried, so they have no dated evidence until part B runs.

- Local proof on a production copy synced 2026-10-23 (Helsinki): `--compare-existing` on 2026-10-21
  rebuilds all 258 stored snapshots exactly from dated evidence and adds none (5 without a dated
  interpretation, 3 without a numeric price, 13 blocked by the source pricing gate, as in the stored day).
- 2026-10-22 dry run before part B: 19 snapshots to add; all 227 stored snapshots match; 712 v3 annual
  rows (655 stored). 20 contracts have no dated interpretation: 14 failed with 401 on a payload that is no
  longer current (part B), 1 failed with 401 on its current payload (normal `--retry-failed`), 6 failed with
  validation errors (also missing on other days; not part of this repair).
- Part B is new code: it needs a deploy before it can run in production.
- Production after part B (14 history-only analyses superseded + valid, 1 current contract published):
  dry run adds 32 snapshots; 211 stored snapshots match; 16 open-ended supplier-adjusted contracts get a
  higher v3 annual total (+49 to +165 EUR at 5,000 kWh). Reason: the stored 22 October comparable premium
  lacked the donors whose interpretations had failed. The stored open-ended v3 median was a dip
  (775.36 / 778.30 / 715.32 / 775.92 EUR on 20–23 October); the repair gives 774.41 EUR. Apply proceeds.
  Their stored snapshots stay unchanged (history rule), so their snapshot annual columns keep the old
  values; public annual readers use the v3 rows.
- Backup before apply: `Voltikka/voltikka-2026-10-23-18-32-42.zip`, 82,777,515 bytes, SHA-256
  b54094aaa6e3376f30303d09111efe3ea9bb79e64d589c961f57fd3c8de418ab; password read, full gzip read to EOF
  (1,503,537,469 SQL bytes), 39/39 schema tables in the dump, all InnoDB. Spatie skips dump comments, so
  there is no "Dump completed" marker.
- Production release: image `deploy-000003-591ec0a439fe` (deployment `3373ecf3`, SUCCESS 2026-10-23
  18:30 UTC). Applied 2026-10-23 18:39 UTC with explicit user approval: 32 snapshots added (227 -> 259),
  52 unit/legacy daily rows, 751 v3 annual rows, 30 v3 aggregates. 21 and 23 October unchanged (258/748,
  257/745). Then `forecasting:run-fixed-contracts --as-of=2026-10-22` saved 9 forecasts (futures trades
  2026-10-21/2026-10-14). Remaining gaps: the seller-set index row of 22 October is the stored one; 5
  contracts without a dated interpretation (validation errors) stay out, as on other days.
- Correction: the seller-set index CAN be rebuilt for a past date with the current writer
  (`writeForDate`), which takes rates and segments from the date's snapshots and only eligibility/family
  from the contract row. A read-only rebuild reproduced the stored 21 and 23 October rows exactly, with no
  pricing-model/contract-type/metering drift between dated snapshots and contract rows on 21–23 October.
  The historical path is not suitable: it uses v1 selection (completion by the target day) and stops at
  2026-08-11. With explicit approval, 2026-10-22 was rewritten at about 18:50 UTC: overall 13.2087 (n=83),
  fixed term 11.4064 (58), open ended 15.3340 (13), market reset 14.5442 (12), hybrid base 10.5832 (35).
