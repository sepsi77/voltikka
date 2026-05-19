# Decisions

- Added a queued `WarmContractPriceStatisticsCache` job instead of warming synchronously inside import commands, so imports only enqueue cache rebuild work and visitors do not trigger cold rebuilds.
- Added `contracts:warm-price-statistics-cache` with async default and `--sync` for manual validation/tests.
- Warm the default high-value state first: weekly period + 5 000 kWh/year. The command supports all periods/consumptions for future expansion.
- The job calls `ContractPriceStatistics::warmPreparedViewDataCache()` so the warmer uses exactly the same cache key and prepared payload as public page requests.
- Wired warming after `contracts:calculate-price-statistics` rather than only inside `contracts:fetch`, so manual recalculation also warms the page. `contracts:fetch` still benefits because it calls the calculate command.
- Wired warming after `spot:fetch` spot average recalculation, because spot source fingerprints can invalidate the statistics page cache.
- The job is unique per period + consumption for one hour to avoid duplicate expensive rebuilds if contract and spot imports enqueue nearby warm requests.
