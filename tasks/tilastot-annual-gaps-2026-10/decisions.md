# Decisions

## Chart display families (2026-10-16)
- `AnnualSeriesCompatibility::aggregateDisplayKey()` sums the own-reference and premium forward
  variants into one family (`supplier_adjusted_forward`, `recurring_forward`) before it picks the
  dominant method. Real fallbacks (seasonal index, hold, rolling-365) are still their own family,
  so they still split a line. Stored `compatibility_key` is unchanged.
- Cache keys bumped: tilastot view-data v22, article comparison chart v6, homepage trend v9.
  Company comparison, spot win-rate and market insights read only the stored key; not bumped.
- Production-copy check (v3, 5000 kWh): open_ended weekly 2026-09-07 and 2026-09-28 and daily
  9.9/10.9/2.10/3.10 get values; no value became null. Remaining 5.10 week and 7.10/8.10 days
  are the incomplete-futures day and are repaired by the 7.10 recalculation.

## Complete futures vintages only (2026-10-16)
- `EexMarketReferenceCurveProvider::availableTradeDates()` lists a FI Base trade date only when it
  has at least one month, quarter and year row (`GROUP BY DATE(trade_date) HAVING COUNT(DISTINCT
  maturity_type) = 3`; checked on MySQL 8 with ONLY_FULL_GROUP_BY). Spot strip, supplier/reset
  forwards, references and premium curve checks all use it.
- All 135 stored FI trade dates pass, so history changes only where a run met a partial import (7.10).
  `CalculatedCostPayloadSchema::VERSION` stays 19: no field change and no current output change.
- Not changed, reported: `FixedTermHedgeCostService` (must not be refactored) and the retail-premium
  reference paths still accept an incomplete newest vintage; `MorningJobFreshnessService` and the
  EEX "current-run prior-date FI proof" accept a run with only quarter/year rows. On 7.10 no
  forecast was stored (9 on 5.10, 6.10, 8.10). Follow-up for the user to decide.
- Full suite: 3034 passed, 1 failed (`LayoutAssetLoadingTest`, caused by a local `public/hot` file).

## 7.10 recalculation: do not use the historical rebuild (2026-10-16)
Preview on a scratch copy of production (`contracts:rebuild-annual-cost-statistics --date=2026-10-07
--method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v3`), medians at 5000 kWh
(6.10 / stored 7.10 / rebuilt 7.10 / 8.10):
- spot 529 / 455 / 527 / 534 (repaired; 51 contracts, 2 lost)
- open_ended 748 / 621 / 675 / 753 (still ~75 € low)
- market_reset 714 / 847 / 742 / 740 (repaired)
- quarterly 610 / 599 / 692 / 614 (made worse; new display regime "none" → new gap)
Cause: the historical v3 path is not equal to the current writer for October dates. Open-ended has
no dated premium rung (premium 0, flags `historical_exact_contract_only`, `price_episode_left_censored`);
11 quarterly contracts fail exact-target validation and fall to relational hold-flat. The same
divergence appears in previews of the correct dates 6.10 and 8.10 (median deltas up to ±295 €).
The current writer cannot run for a past date (`CalculateContractPriceStatistics` refuses it).
The approved recalculation is therefore not applied. Options for the user: delete the bad 7.10 v3
rows (all, or spot/open_ended/market_reset only) after backup + JSON export, or leave them.

## Why 1.10 has no snapshots (production read-only evidence, 2026-10-16)
- `data_freshness_checkpoints` contract_import 2026-10-01: `failed`, stage `post_import`, checks 0,
  recorded 03:01:17 UTC. laravel.log 03:01:17: `Data fetch failed: contracts`, failures
  `daily_statistics: 1`, `ContractPriceCacheConflict`, reason `evidence_changed`; scheduler exit 1
  after 76.77 s. No later completion tick ran, because the row was terminal, not `pending_completion`.
- Cause: 62 new contract IDs appeared on 1.10 (October product replacements). New contracts stay
  inactive until first validation. Two of them were published/activated during the initial statistics
  (03:00:58 m7yecr, 03:01:12 s7gxeo). `ContractImportCompletion::statisticsFence()` compares
  `active_contracts` with the captured IDs and throws `evidenceChanged()`. In `required()` this conflict
  is deferred only when delayed or after statistics completed (stage `price_cache_refresh`), so in the
  first run it is a terminal `daily_statistics` failure. The morning retail/forecast consumers then
  waited all day (`dependencies_not_ready`).
- Fix (user approved, 2026-10-16): on a complete full import, an `evidence_changed` conflict in the
  initial `daily_statistics` stage now defers to `pending_completion`; the completion tick recalculates
  statistics after the interpretations settle. `generation_changed`, other errors, scoped and partial
  imports stay terminal. `validManifest()` accepts a pending row without statistics times (both absent),
  and a tick never writes `initial_statistics_*`. Tests in DeferredContractImportCompletionTest and
  FetchContractsCommandTest.
- Separate: eex_futures 2026-10-02 is stuck `running` (started 04:01:20 Helsinki). The deploy of
  6d43357 at about 04:18 stopped the container during the throttled run, so trade dates 1.10 and 2.10
  arrived only on 3.10.
- 1.10 statistics cannot be recreated: the current writer refuses past dates, and the historical
  rebuild diverges from the current writer after 22.9. One missing day does not break a chart line.

## EEX history check (production, 2026-10-16)
No FI Base trade date is missing or incomplete from 25.9 to 15.10 (each 7 month / 7 quarter / 6 year
rows; only 1.5.2026 is absent since 8.4, a market holiday). 1.10 and 2.10 arrived on 3.10; 6.10 months
on 8.10. No refetch is needed. The eex_futures checkpoints for 7.10 (`failed`, 2 failures) and 9.10
(`failed`, 1 failure) show that the fetcher reported its own partial runs; the 2.10 row stays
`running` because a deploy stopped that run.

## 7.10 removal (option a)
- Production counts before: spot 158, open_ended 125, market_reset 18 annual rows (301) and 3+3+3
  aggregates (9), all last written 2026-10-07 03:14:15 UTC.
- Today's 03:00 Spatie DB backup completed; monitor healthy.
- Exact export: `production-2026-10-07-removed-rows.json` (301 + 9 rows,
  SHA-256 fc365e1132f0b248abed07891d4b3ec59d6324ffdab2cc69957e6f5280920073).
- Applied 2026-10-16 about 21:40 Helsinki after the user's written approval: one transaction deleted
  exactly 301 annual rows and 9 aggregates (rollback guard on the counts). Remaining v3 on 7.10:
  447 annual rows, 21 aggregates (fixed-term, hybrid and quarterly segments unchanged).
- Restore: insert the rows of the export file unchanged (ids included) into the two tables.

## Release (2026-10-16)
Commit 3b95c81 pushed to main; Railway deployment 61dfaf9a-db61-4e7d-9370-35f441ae1a30 SUCCESS.
Public weekly lead chart (5000 kWh) now has values for spot and open-ended in the weeks of 28.9 and
5.10. Daily view: spot and open-ended have one null point on 7.10 (the removed rows; other segments
keep that date), 1.10 has no point at all. Full local suite: 3041 passed, 1 failed
(`LayoutAssetLoadingTest`, local `public/hot` file only).
