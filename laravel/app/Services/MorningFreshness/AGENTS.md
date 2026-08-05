# Morning job freshness

This directory owns the small fail-closed freshness gate for scheduled retail-premium collection and fixed-term forecast generation.

## Primary files

- `MorningJobFreshnessService.php` records checkpoints, checks prerequisites, and writes one structured warning when a job is deferred.
- `MorningFreshnessResult.php` carries deterministic failure keys and messages.
- `../../Models/DataFreshnessCheckpoint.php` defines checkpoint keys and statuses.
- `../../../database/migrations/2026_07_28_000001_create_data_freshness_checkpoints_table.php` defines durable storage.

## Rules

- A checkpoint is the latest fact for one key and effective date. It is not a workflow engine or a run-history table.
- The effective date is the command `asOf` date in Europe/Helsinki.
- Both gated jobs require same-date ready `contract_import` and `eex_futures` checkpoints plus a prior-date FI EEX Base row no more than the configured calendar-day age.
- Contract facts must contain `observed_source_observation_ids`, active contract IDs, and the exact statistics start and completion timestamps. Old snapshot-ID metadata and other missing or malformed facts fail closed.
- When interpretation is enabled, retail-premium freshness checks every active checkpoint contract. Each must have exactly one observed episode in the checkpoint. That episode must equal the contract pointer, and its snapshot must equal the currently published interpretation snapshot. Observed pending contracts outside the active ID set do not block the current market.
- Fixed-term forecast interpretation checks start from the checkpoint active IDs, then use current contract facts and `ContractStatisticsSegmentClassifier` in the expected pricing basis. They require publication only for `Household`, `Both`, or null-target contracts classified as `fixed_term_6`, `fixed_term_12`, or `fixed_term_24`. Spot, Hybrid, OpenEnded, business-only, and other segment activity cannot make fixed-term statistics stale.
- Forecast checks additionally require at least one current-date fixed-term 6/12/24 `energy_price` statistic for the pricing basis selected by the canonical-pricing flag. The forecast builder handles each available duration independently, and gated zero output still fails.
- A required relevant fixed-term interpretation published after statistics started makes forecast statistics stale, including publication during the calculation.
- A ready EEX fact must contain the latest prior-date FI Base point extracted in that current run. The separate database query remains the data-presence and age check.
- Full upstream commands first overwrite the same-date checkpoint with a failed start marker before acquisition. A checkpoint-write failure stops the command, and scoped runs stay unchanged.
- Scheduled commands opt in with `--require-freshness`. Manual runs keep their prior behavior, and historical retail backfills bypass this gate.
- Keep failure messages concise and deterministic. Do not put exception messages, source payloads, credentials, or other secrets in checkpoint metadata or warning context.
