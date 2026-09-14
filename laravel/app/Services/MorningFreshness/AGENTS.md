# Morning job freshness

This directory owns the small fail-closed freshness gate for scheduled retail-premium collection and fixed-term forecast generation.

## Primary files

- `MorningJobFreshnessService.php` records checkpoints, checks prerequisites, and writes one structured warning when a job is deferred.
- `MorningFreshnessResult.php` carries deterministic failure keys and messages.
- `../../Models/DataFreshnessCheckpoint.php` defines checkpoint keys and statuses.
- `../../../database/migrations/2026_07_28_000001_create_data_freshness_checkpoints_table.php` defines durable storage.

## Rules

- A checkpoint is the latest fact for one key and effective date, not a run-history table. Contract-import version 1 also stores bounded pending completion proof in its JSON; `ContractImport/ContractImportCompletion` owns that local protocol.
- The effective date is the command `asOf` date in Europe/Helsinki.
- Both gated jobs require same-date ready `contract_import` and `eex_futures` checkpoints, current-run prior-date FI Base proof, and prior-date FI EEX Base database data within the configured age. The futures-adjusted forecast restores these EEX checks; retail-premium behavior is unchanged. Contract, unit-statistic, source-episode and publication recovery checks remain unchanged. A missing EEX requirement blocks publication-only recovery.
- Contract facts must contain `observed_source_observation_ids`, active contract IDs, and the exact statistics start and completion timestamps. Old snapshot-ID metadata and other missing or malformed facts fail closed.
- Versioned completion proof certifies the successful full completion at its write boundary; it does not freeze the market or cache generation for later readers. `ContractImportCompletion::readyCurrent()` belongs only at completion write/final-CAS guards, not in this service. New-manifest and legacy ready checkpoints use the same scoped rules below. EEX/cache invalidation and unrelated Spot, business-only or non-fixed publication must not add a global `contract_completion` failure. Relevant fixed-term publication after statistics remains the single recoverable `statistics_publication_order` failure when the other prerequisites pass. `pending_completion` never passes.
- When interpretation is enabled, retail-premium freshness checks every active checkpoint contract. Each must have exactly one observed episode in the checkpoint. That episode must equal the contract pointer, and its snapshot must equal the currently published interpretation snapshot. Observed pending contracts outside the active ID set do not block the current market.
- Fixed-term forecast interpretation checks start from the checkpoint active IDs, then use current contract facts and `ContractStatisticsSegmentClassifier` in the expected pricing basis. They require publication only for `Household`, `Both`, or null-target contracts classified as `fixed_term_6`, `fixed_term_12`, or `fixed_term_24`. Spot, Hybrid, OpenEnded, business-only, and other segment activity cannot make fixed-term statistics stale.
- Forecast checks additionally require at least one current-date fixed-term 6/12/24 `energy_price` statistic from `unitStatistics()` (`unit_statistics_v1`) for the PricingMode basis. Annual-method unit-shaped rows cannot satisfy this gate. The forecast builder handles each available duration independently, and gated zero output still fails.
- A required relevant fixed-term interpretation published after statistics started makes forecast statistics stale, including publication during the calculation.
- When this publication-order failure is the only forecast failure, the scheduled non-dry command recalculates the date's statistics from every current `active_contracts` ID with overwrite enabled. It then runs the complete gate again. No other failure and no dry run can start this recovery.
- The recovery captures a new Helsinki statistics-start time immediately before the direct `ContractPriceStatisticsService` call. The second check uses this override only for publication order; it still validates the stored checkpoint and every other fact. A relevant interpretation published after this new start keeps the gate closed.
- A successful recovery queues `WarmContractPriceStatisticsCache('weekly', 5000)` before forecast generation. An empty active-contract set fails instead of accepting an empty refresh.
- A ready EEX fact must contain the latest prior-date FI Base point extracted in that current run. The separate database query remains the data-presence and age check.
- Full upstream commands first overwrite the same-date checkpoint with a failed start marker before acquisition. Contract runs install a UUID; later updates compare ownership, and required statistics hold the checkpoint row lock inside their existing transaction. A checkpoint-write failure stops the command. Scoped contract statistics never insert or replace global readiness. They lock an existing same-date row inside the statistics transaction; a missing row stays absent. EEX recording stays unchanged. See `../ContractImport/AGENTS.md`.
- Scheduled commands opt in with `--require-freshness`. Manual runs keep their prior behavior, and historical retail backfills bypass this gate.
- Keep failure messages concise and deterministic. Do not put exception messages, source payloads, credentials, or other secrets in checkpoint metadata or warning context.
