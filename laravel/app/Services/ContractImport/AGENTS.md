# Contract import services

This directory owns the authoritative contract import and its post-import work.

## Primary classes

- `ContractAcquisitionResult` carries deduplicated API contracts, failed postcodes, and completeness.
- `ContractImporter` owns all database mutations for one contract import.
- `ContractImportResult` carries import counts, replacement data, active IDs, company names, changed snapshot IDs, and all snapshot IDs observed in the run.
- `ContractPostImportCoordinator` runs required and optional work after the transaction commits.
- `ContractPostImportResult` separates required failures from optional failures and carries the exact nullable statistics start and completion timestamps.

## Import rules

- `ContractImporter::import()` is the testable entry point. It does not use Artisan.
- The importer uses one `DB::transaction()` closure. Do not catch a mutation failure inside this closure.
- Source snapshots are in the same transaction as contract rows and price rows.
- A new semantic fingerprint adds its snapshot ID to `changedSnapshotIds`.
- An unchanged payload updates `last_observed_at` and appears only in `observedSnapshotIds`.
- The post-import coordinator sends all observed snapshot IDs through the fingerprint-idempotent dispatcher. This does not create duplicate jobs, and it lets a transient failure before interpretation creation recover on the next import.
- Keep the latest snapshot ID map for every imported contract. The safe relational price publication gate uses this map.
- Company logo network and storage work must run after the transaction.
- A partial postcode acquisition imports available contracts with `complete=false`. It preserves active rows that are absent from the partial response, skips replacement linking, and calculates statistics from the full preserved active set.

## Post-import rules

Required work:

- capture `statisticsStartedAt` immediately before the daily statistics call, calculate from active contract IDs with overwrite enabled, then capture `statisticsCompletedAt` immediately after the call succeeds
- invalidate stale application cache data
- bump contract and company cache versions

Optional work:

- send each observed current snapshot through the idempotent interpretation dispatcher
- warm contract and company caches
- dispatch `WarmContractPriceStatisticsCache` for weekly and 5,000 kWh
- calculate contract percentiles

Each stage has its own failure boundary. One interpretation failure must not stop a later snapshot. A required failure makes `contracts:fetch` fail, but safe later stages still run.

Database cache invalidation uses `TRUNCATE TABLE`. Other cache stores use the cache repository API directly. The coordinator does not call nested Artisan commands and does not use `app()` service location.

`contracts:fetch` writes the global `contract_import` freshness checkpoint only when `--postcodes` is absent. It first overwrites the same-date fact with `failed` before acquisition, so a crash cannot leave an older ready fact; failure of this first write stops the command. Full acquisition/import/required-stage failures are `failed`, a partial acquisition is `incomplete`, and complete successful post-import work is `ready`. Ready metadata uses the import result's observed snapshot IDs and active IDs plus both statistics timestamps. A postcode-scoped run must never overwrite this global fact.
