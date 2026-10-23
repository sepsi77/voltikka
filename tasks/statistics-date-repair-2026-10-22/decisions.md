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
