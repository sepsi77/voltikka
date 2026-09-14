# Contract import services

This directory owns the authoritative contract import and its post-import work.

## Primary classes

- `ContractAcquisitionResult` carries deduplicated API contracts, failed postcodes, and completeness.
- `ContractImporter` owns all database mutations for one contract import.
- `ContractImportResult` carries import counts, replacement data, active IDs, company names, changed observation IDs, and all pointed observation IDs observed in the run.
- `ContractPostImportCoordinator` runs required and optional work after the transaction commits.
- `ContractPostImportResult` separates required failures from optional failures and carries the exact nullable statistics start and completion timestamps. Its `requiredExceptions` map also retains each caught required-stage Throwable for safe class/reason extraction in the command. The compatibility message maps must never enter Log or Sentry context.

## Import rules

- `ContractImporter::import()` is the testable entry point. It does not use Artisan.
- The importer uses one `DB::transaction()` closure. Do not catch a mutation failure inside this closure.
- Source snapshots are in the same transaction as contract rows and price rows.
- Relational pricing model, contract type, and target group values pass through their tolerant enums. Verified aliases normalize to canonical values, and unsupported or malformed values store explicit `Unknown`. The immutable source payload keeps the exact upstream values. Metering remains source-compatible in this slice.
- After company upserts and before `processContracts()`, all already-existing imported contract rows are locked in stable contract-ID order so updates cannot precede their locks. New rows are included in the later stable lock before episode mutation.
- Snapshot fingerprints stay unique and immutable. Their first/last timestamps are aggregate evidence only.
- A payload transition creates a point `ContractSourceObservation`, adds it to `changedObservationIds`, and atomically moves `current_source_observation_id`. An unchanged payload extends only that pointed episode. Every import returns its pointed ID in `observedObservationIds`.
- The importer verifies that every non-null pointer resolves to an observation owned by that contract. A→B→A produces two snapshots and three episodes.
- The post-import coordinator loads and dispatches each observed observation. Fingerprint idempotency prevents duplicate jobs and lets a transient pre-dispatch failure recover on the next import.
- Immediate relational publication compares the published interpretation snapshot with the pointed observation snapshot. No max-ID or snapshot-date map can select currentness.
- Company logo network and storage work must run after the transaction.
- A partial postcode acquisition imports available contracts with `complete=false`. It preserves active rows that are absent from the partial response, skips replacement linking, and calculates statistics from the full preserved active set.

## Post-import rules

Required work:

- capture `statisticsStartedAt` immediately before the daily statistics call, calculate from active contract IDs with overwrite enabled, then capture `statisticsCompletedAt` immediately after the call succeeds
- forget the owned sitemap cache key without clearing unrelated application entries or locks
- for complete imports after successful statistics, build and verify all eight annual presets plus candidate company/5,000, then activate one shared generation. Verified evidence/generation conflicts permit one full retry after durable failed-candidate retirement. Recovery succeeds without an import alert; exhaustion reports the required `price_cache_refresh` stage. Failure preserves the active pointer unless an independent safety invalidation changed it. No failure restores a previous pointer

Optional work:

- send each observed pointed episode through the idempotent interpretation dispatcher
- dispatch `WarmContractPriceStatisticsCache` for weekly and 5,000 kWh
- calculate contract percentiles

Each stage has its own failure boundary. One interpretation failure must not stop a later snapshot. A required failure makes `contracts:fetch` fail, but safe later stages still run. The command combines required stage codes, acquisition failures, and checkpoint failures into one explicit Sentry Issue and safe aggregate Laravel log at its outcome boundary. It never sends the result's failure message text to Log or Sentry. The reporter extracts only the class and closed cache-conflict/storage reason from `requiredExceptions`; unknown classes get `unexpected`. See `../../Support/AGENTS.md`. Partial acquisition keeps its existing success exit and reports warning severity; terminal failures report error severity. Optional stages do not create import Issues.

No cache store is flushed or truncated. Price-cache replacement uses `ContractListCacheService::refresh()` and `Caching/ContractPriceCacheLifecycle`; see `../Caching/AGENTS.md`. Partial imports and statistics failures never start replacement. Interpretation dispatch remains before statistics and can independently invalidate unsafe prices immediately; a failed refresh does not undo those safety invalidations. The coordinator does not call nested Artisan commands and does not use `app()` service location.

`contracts:fetch` writes the global `contract_import` freshness checkpoint only when `--postcodes` is absent. It first overwrites the same-date fact with `failed` before acquisition, so a crash cannot leave an older ready fact; failure of this first write stops the command. Full acquisition/import/required-stage failures are `failed`, a partial acquisition is `incomplete`, and complete successful post-import work is `ready`. Ready metadata uses `observed_source_observation_ids`, active IDs, and both statistics timestamps. Old `observed_snapshot_ids` metadata fails closed. A postcode-scoped run must never overwrite this global fact.
