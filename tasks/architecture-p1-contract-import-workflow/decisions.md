# Decisions

## Initial decisions

- Use a small import service and post-import coordinator. Do not introduce a general workflow framework.
- Keep the authoritative database import transactional.
- Treat cache warming as optional and statistics generation as required.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current workflow

- `FetchContracts` owns API acquisition, the complete database transaction, interpretation dispatch, cache work, statistics, percentiles, logo I/O, and console output.
- One failed postcode is tolerated when another postcode returns contracts, but the workflow does not retain or report the resulting incompleteness.
- Every observed source snapshot is sent to the dispatcher, including unchanged snapshots. One dispatch exception stops dispatch for all later snapshots.
- Cache clearing, cache version bumps, synchronous cache warming, required daily statistics, and optional percentiles share one catch block. A cache warm failure therefore prevents statistics.
- Nested statistics and percentile command exit codes are ignored. The fetch command can return success after required statistics fail.
- Existing focused command tests cover import data, transaction rollback, snapshots, interpretation queueing, relationship replacement, API retry, and logo behavior. They do not cover the P1 failure boundaries.

## Implementation design

- Add `app/Services/ContractImport/` as one cohesive domain directory. Do not add a general workflow framework.
- Add an Artisan-independent `ContractImporter` entry point for the authoritative transaction. It will accept fetched payloads, valid postcodes, the import date, and the acquisition-completeness flag.
- Return a readonly `ContractImportResult`. It will include completeness, counts, replacement statistics, newly created semantic snapshot IDs, and all snapshot IDs observed by the run. Unchanged snapshots update `last_observed_at` and revisit the fingerprint-idempotent dispatcher so a transient pre-dispatch failure can recover without creating duplicate jobs.
- Keep API acquisition in the Artisan adapter, but return a typed acquisition result so failed postcodes and completeness cannot be lost before import.
- Move logo download outside the database transaction. Stored files cannot participate in a database rollback.
- Add a small `ContractPostImportCoordinator` with direct constructor dependencies. Required statistics run through `ContractPriceStatisticsService`; optional interpretation dispatch, cache warming, statistics-cache queueing, and percentiles use separate failure boundaries.
- Treat daily statistics and cache invalidation/version bumps as required correctness work. Treat interpretation dispatch, logo download, synchronous cache warming, statistics-page cache queueing, and percentile calculation as optional follow-up work.
- Send each observed snapshot through the idempotent dispatcher in its own boundary and include its snapshot ID in any reported failure.
- Extract percentile calculation into a focused statistics service so the coordinator and percentile command do not call Artisan commands or resolve services with `app()`.
- Run required statistics before cache work. A later cache failure cannot prevent statistics from running.
- Import available data from a partial acquisition, set `complete=false`, report failed postcodes, and warn. Because a partial response is not an authoritative active set, preserve existing active rows that are absent from it and skip replacement linking until a complete acquisition. Daily statistics use the full preserved active set.

## Implemented result

- Added `app/Services/ContractImport/` with typed acquisition, import, and post-import results.
- `ContractImporter` now owns one authoritative `DB::transaction()` closure and all source database mutations. It injects the canonicalizer, safe component writer, and replacement linker.
- The importer separates newly created semantic snapshot IDs from all observed current snapshot IDs. An unchanged payload updates `last_observed_at`, does not return a changed ID, and still revisits the idempotent dispatcher for retry safety.
- Logo I/O now runs per company after commit. One logo failure does not stop another company or fail the command.
- `ContractPostImportCoordinator` calls daily statistics, cache services, interpretation dispatch, percentile calculation, and the statistics cache job directly. It does not call Artisan commands or use `app()`. The statistics warm job uses Laravel's normal pending dispatch path so its `ShouldBeUnique` lock remains effective.
- Daily statistics and stale-cache invalidation/version bumps are required. Interpretation, cache warming, statistics-cache dispatch, and percentile calculation are optional.
- `ContractPercentileService` now owns percentile calculation. `CalculateContractPercentiles` only prints the typed result.
- Focused tests prove direct importer use, completeness, changed and observed snapshots, isolated interpretation failures, stage order, required command failure, and partial acquisition import that preserves active rows missing from the partial response.

## Verification result

- `./vendor/bin/pint --test ...` passed for all changed PHP files.
- Focused importer, coordinator, and complete `FetchContractsCommandTest` run passed after final review fixes: 33 tests and 163 assertions.
- A review found and fixed three edge cases: unchanged snapshots now retry transient pre-dispatch failures, partial acquisitions preserve missing active rows and skip replacement linking, and the statistics warm job uses the normal unique-job dispatch path.
- The complete project test suite was not run. The regression item refers to the complete existing `FetchContractsCommandTest` plus the new focused service tests.
