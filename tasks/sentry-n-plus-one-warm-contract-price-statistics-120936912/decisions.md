# Decisions


## 2026-05-24

- The Sentry issues are duplicate reports for one `WarmContractPriceStatisticsCache` job. The trace shows the prepared statistics payload builder running the same daily spot-average window query once per spot statistic date, followed by one latest-row query per segment for `latestContractCount()` / consumption rows.
- `ContractPriceStatistics` now loads daily spot averages for the full needed market window once per component/job and slices the in-memory date-indexed array for each trailing-12-month summary. The hourly fallback is also loaded once if needed.
- Latest per-segment statistic rows now come from the already-hydrated `dailyStats` collection instead of issuing `contract_price_daily_statistics where segment_key = ... order by stat_date desc limit 1` per segment.
- Snapshot latest-date lookup is memoized so the data-version fingerprint and rendered snapshot metadata do not repeat the same `MAX(snapshot_date)` query in one warm job.
- Added regression coverage that direct queued warming performs at most one spot-average read for rolling spot windows and zero per-segment latest-row SQL lookups.
