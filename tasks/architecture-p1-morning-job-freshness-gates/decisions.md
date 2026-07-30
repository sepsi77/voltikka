# Decisions

## Initial decisions

- Use explicit freshness checks before adding a workflow engine.
- Do not infer success from schedule time alone.
- Keep Europe/Helsinki scheduling and existing overlap guards.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- `futures:fetch-eex` runs at 04:00, `contracts:fetch` at 06:00, `retail-premiums:collect` at 07:15, and `forecasting:run-fixed-contracts` at 07:30 in Europe/Helsinki.
- The dependent commands currently infer readiness from clock order. They do not check whether the same-day full contract acquisition completed or whether the same-day full EEX fetch succeeded.
- A partial contract acquisition can finish successfully with `ContractImportResult::complete=false`. That state is typed only in memory and is not durable for the later scheduled commands.
- Interpretation dispatch is asynchronous. A changed active contract can still point to its prior published interpretation when retail-premium collection and statistics run.
- Contract statistics run during contract post-import, before asynchronous interpretations can publish. A statistic generated before a new interpretation is not fresh for that interpretation.
- Forecast generation accepts the latest available prior futures curve and current-date statistics. It does not prove that the 04:00 EEX import ran, and an empty forecast set currently returns success.
- There is no existing durable pipeline-run or freshness-checkpoint model.

## Smallest compatible design

- Add one small `data_freshness_checkpoints` table. It stores one durable status and metadata payload per fact and effective date. This is evidence for gates, not a workflow engine.
- A full-scope contract import records `ready`, `incomplete`, or `failed`. Its ready metadata contains observed snapshot IDs, active contract IDs, and exact statistics start and completion times. A postcode-scoped diagnostic import must not claim global readiness.
- A full-scope EEX import records `ready` or `failed`. Selected-area, selected-tenor, selected-maturity, custom-date, and dry runs must not claim global readiness. Readiness requires a prior-date FI Base point extracted by the current run, not an old FI row already in the database.
- Both full-scope upstream commands first overwrite the same-date checkpoint with a failed start marker before acquisition work. Failure to write this marker stops the command, and an unexpected crash cannot leave an older ready fact. Scoped runs stay unchanged.
- Add one typed `MorningJobFreshnessService` result for both dependent commands. It checks the same-day contract checkpoint, exact one-snapshot coverage and current source publication for every active contract when interpretation is enabled, the required current statistics for forecasts, the same-day EEX checkpoint with current-run FI proof, and the latest prior FI futures trade date in the database.
- A published interpretation that is newer than the statistics start time makes forecast statistics stale. This blocks publication during the calculation. The command defers until a later complete import recalculates statistics.
- Use a configurable seven-calendar-day maximum age for the latest prior FI settlement. The same-day EEX checkpoint still proves that the scheduled fetch ran on weekends and exchange holidays.
- Add `--require-freshness` to the two dependent commands and use it in `routes/console.php`. This keeps deliberate historical/manual commands compatible while making the scheduled path fail closed.
- A blocked scheduled job prints a common `Morning job deferred` message, logs the structured reasons, returns failure, and writes no output rows.
- Historical retail-premium backfill does not use the morning gate. Existing overlap and timezone schedule rules stay unchanged.

## Implementation facts

- Added `data_freshness_checkpoints` with one unique fact per key/effective date, the `DataFreshnessCheckpoint` model, and `config/morning_freshness.php` with the env-backed seven-day default.
- Added `app/Services/MorningFreshness/` with a readonly typed result and one directly injected service for checkpoint writes, retail/forecast checks, and common structured deferred warnings. No service location or nested Artisan calls were added.
- Contract facts fail closed unless observed snapshot IDs, active contract IDs, and parseable statistics start and completion timestamps are present in order. With interpretation enabled, every active ID must have exactly one observed snapshot, and that snapshot must match the current published pointer. Forecast checks compare the newest required publication with the statistics start timestamp. Analysis-version fingerprints are not gated; a current same-source publication remains valid.
- Both gates require a same-date ready EEX checkpoint with a prior-date FI Base trade date extracted by that current run, plus a prior-date FI EEX Base database row within `morning_freshness.max_futures_age_days`. Forecast checks require at least one current fixed-term 6/12/24 `energy_price` statistic for the basis selected by canonical mode. The builder handles durations independently, and gated zero output still fails.
- `ContractPostImportResult::statisticsStartedAt` is set immediately before `calculateForDate()`, and `statisticsCompletedAt` immediately after it returns, before cache and optional work.
- `contracts:fetch` writes checkpoints only without `--postcodes`. A full run first writes `failed`; failure of that write stops before acquisition. Full-scope early failures remain `failed`, partial acquisition overwrites with `incomplete`, and complete required post-import success overwrites with `ready`. A full no-contract response fails. Scoped runs do not write the global fact.
- `futures:fetch-eex` determines full scope and writes the failed start marker before `dateRange()` and `selectedInstruments()`. It writes checkpoints only for the default non-dry scope. All date, maturity, area, tenor, count, and history-window options make the run targeted. Full readiness requires no failures and a prior-date FI Base point extracted from the current run; global fetched totals plus an old FI database row do not qualify.
- Scheduled retail collection and forecast generation now use `--require-freshness`. Manual runs stay ungated. Historical retail backfill bypasses the gate. Gated zero-output runs fail and report a deferred state.
- Added local MorningFreshness documentation and the required `CLAUDE.md -> AGENTS.md` symlink. Updated root, Laravel, database, ContractImport, RetailPremium, PriceForecasting, and service-index context.

## Verification facts

- Final focused/regression command: `php artisan test --filter='MorningJobFreshnessGateTest|ContractImporterTest|ContractPostImportCoordinatorTest|FetchContractsCommandTest|FetchEexFuturesCommandTest|RetailPremiumCollectionTest|InferredRetailPremiumCollectionTest|FixedContractPriceForecastingTest'` — 83 tests passed, 453 assertions.
- The freshness test covers incomplete contracts, delayed publication, publication after statistics start but before completion, missing and duplicate active snapshot coverage, contract start/completion metadata, current-run EEX proof, stale database futures, at-least-one fixed-term statistics, visible missing-checkpoint output, a valid gate, and gated zero output.
- Contract and EEX command tests cover full-scope checkpoint writes, initial-write failure before acquisition, and scoped non-writes. The EEX regression proves that non-FI current-run points plus an old FI database row produce a failed checkpoint and command failure. The coordinator test asserts exact `2026-08-01T06:12:34+03:00` start and `2026-08-01T06:12:39+03:00` completion timestamps.
- Final Pint check passed for 17 applicable PHP files. `git diff --check` passed.
- The full Laravel test suite was not run.
