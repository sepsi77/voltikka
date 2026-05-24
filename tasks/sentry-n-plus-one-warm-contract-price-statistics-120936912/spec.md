# Spec

Fix Sentry N+1 issues 120936912 / 120901555 / 120901554 in `App\Jobs\WarmContractPriceStatisticsCache`.

Goals:
- Identify repeated statistics-page warmer queries, especially repeated spot-price range reads and latest contract-stat lookups.
- Batch or memoize data used repeatedly while building `/sahkosopimus/tilastot` prepared view data.
- Preserve cached page semantics and displayed spot/contract-statistics values.
- Add focused regression coverage where practical.
