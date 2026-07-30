# AGENTS.md

Context for queued jobs under `laravel/app/Jobs`.

## `AnalyzeContractSourceSnapshot`

Purpose:
- runs one fingerprinted source snapshot through the strict OpenRouter contract interpretation pipeline
- stores output, automatic validation errors, usage, latency, and failure details in `contract_interpretations`
- automatically publishes valid compatible classifications; it has no human review state

Important semantics:
- implements `ShouldBeUnique` by interpretation ID and uses three bounded queue attempts for transport/runtime failures
- each queue execution makes one initial model call and at most two model correction calls for deterministic validation errors
- every model attempt, output, validation result, usage, provider response ID, and latency is retained in `llm_attempts`; aggregate usage remains in `usage`
- `ContractPostImportCoordinator` calls the fingerprint-idempotent dispatcher only after the source transaction commits when interpretation is enabled; each observed snapshot has its own failure boundary. Unchanged snapshots revisit the dispatcher so a transient failure before interpretation creation can recover, but an existing fingerprint does not create a duplicate job
- stale results cannot publish over a newer source snapshot; they become `superseded`
- job timeout is 400 seconds; the Supervisor worker timeout is 420 seconds and database queue `retry_after` must remain above both at 450 seconds or more
- output becomes a permanent failed interpretation only after the allowed correction calls still fail; deterministic validation failure does not use a queue retry
- transport/provider failures throw so the queue can retry them

## `WarmContractPriceStatisticsCache`

Purpose:
- warms the prepared Livewire view-data cache for `/sahkosopimus/tilastot`
- uses `ContractPriceStatistics::warmPreparedViewDataCache()` so it fills the same cache entry a public request would use
- is queued by `contracts:warm-price-statistics-cache` by default and implements `ShouldBeUnique` per period + consumption to avoid duplicate expensive rebuilds during nearby imports

Important semantics:
- the default warmed public state is weekly period + 5 000 kWh/year (`/sahkosopimus/tilastot?kulutus=5000` equivalent; the URL-bound default omits `kulutus` in generated URLs)
- `contracts:calculate-price-statistics` queues this after recalculating daily statistics
- `ContractPostImportCoordinator` dispatches this job directly for weekly/5 000 after its direct daily statistics call succeeds; it does not use a nested Artisan command
- `spot:fetch` queues this after recalculating spot averages because spot data participates in the statistics page cache fingerprint
- do not move this back to synchronous warming in import commands unless product explicitly accepts longer import runtimes; user-facing UX should not depend on the first low-traffic visitor hitting a cold cache
