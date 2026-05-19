# AGENTS.md

Context for queued jobs under `laravel/app/Jobs`.

## `WarmContractPriceStatisticsCache`

Purpose:
- warms the prepared Livewire view-data cache for `/sahkosopimus/tilastot`
- uses `ContractPriceStatistics::warmPreparedViewDataCache()` so it fills the same cache entry a public request would use
- is queued by `contracts:warm-price-statistics-cache` by default and implements `ShouldBeUnique` per period + consumption to avoid duplicate expensive rebuilds during nearby imports

Important semantics:
- the default warmed public state is weekly period + 5 000 kWh/year (`/sahkosopimus/tilastot?kulutus=5000` equivalent; the URL-bound default omits `kulutus` in generated URLs)
- `contracts:calculate-price-statistics` queues this after recalculating daily statistics; `contracts:fetch` gets this behavior because it calls that command
- `spot:fetch` queues this after recalculating spot averages because spot data participates in the statistics page cache fingerprint
- do not move this back to synchronous warming in import commands unless product explicitly accepts longer import runtimes; user-facing UX should not depend on the first low-traffic visitor hitting a cold cache
