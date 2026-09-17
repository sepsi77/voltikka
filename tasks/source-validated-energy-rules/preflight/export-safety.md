# Private pricing preflight export

Implementation only. No Railway command or production query was run. The manager must inspect
this script before execution. No application, task-status, or shared context file was changed.

## Run after approval

From the repository root, with Railway MySQL credentials already injected into this process:

```sh
php tasks/source-validated-energy-rules/preflight/export.php --target=/tmp/voltikka-pricing-preflight-NEW
```

Use a new, single-level directory below `/tmp`. The script refuses existing files, directories,
and symbolic links. It makes the directory with mode 0700 and files with mode 0600. Requirements:
Composer dependencies, PDO MySQL, PDO SQLite, and PCNTL. It loads Composer only. It does not load
Laravel, `.env`, cached application configuration, or migrations. Do not copy these artifacts to Git.
It does not open or replace the active local database.

Success produces `snapshot.sqlite`, `summary.json`, and `manifest.json`. Accept the snapshot only
when the command exits 0 and the manifest says `complete`. A failure can leave partial private
files. Do not use them. Use another new target for a retry; the script does not delete artifacts.
The manifest records expected deployment context, not independently verified deployment identity.
Production context supplied for this work: commit `917de212fdc4ef4862903268c3d33ba5cd886e73`,
calculated-cost schema 17, profile V4/v19/v17. The local candidate uses calculated-cost schema 19.

## Source queries and scope

Every SELECT, including metadata and graph reads, starts after the connection helper establishes
REPEATABLE READ and one consistent READ ONLY transaction. Reads use unbuffered PDO rows.
`ROLLBACK` runs in `finally`. Source statements are SELECTs and connection-session settings only.
All selected business tables must use InnoDB. No provider, LLM, authentication, queue, cache,
session, user, or unrelated runtime table is queried.

Each query has a count query over a `LIMIT 250001` sentinel subquery, followed by an explicit
column SELECT with the same predicate and sentinel limit. Counts must agree. Limits apply to
metadata and the graph too: 250,000 rows per query, 1,000,000 selected rows in total, and 256 MiB
of serialized row data. The source graph counts again when scoped contracts are copied.
There is no silent truncation or automatic reduction of scope.

The script sets MySQL MAX_EXECUTION_TIME to 20 seconds and adds the same SELECT hint. Metadata
lock waits are limited to 20 seconds. A PCNTL alarm and per-row checks set a 240-second work
deadline. A native PDO operation can defer PHP signal handling until it returns; allow its bounded
query timeout and final rollback/file cleanup in addition to this deadline. SQLite has a separate
256 MiB page limit. PHP memory is limited to 512 MiB. Fatal process termination can leave an
incomplete target; disconnection ends the read-only source transaction. Errors expose only fixed
stage names, never exception text, credentials, or provider responses.

Queries are limited to:

- `electricity_contracts`: first, the complete `id,replaced_by_contract_id` graph. Then complete
  rows for active IDs and their transitive replacement ancestors. The direction is
  `old.replaced_by_contract_id -> new.id`. No product-name inference is used.
- `information_schema.TABLES`, `COLUMNS`, and `STATISTICS`: only the 12 tables below. These preserve
  table engines/collation, column definitions, and index metadata. Metadata is not application data;
  concurrent DDL must not occur during this export.
- `active_contracts`: all rows. Its `id` joins `electricity_contracts.id`.
- `companies`: all rows.
- `contract_interpretations`: scoped contract rows, pointed published rows, and rows tied to scoped
  source snapshots or scoped/current observations. The SELECT allowlist is only IDs, analysis
  fingerprint, status, schema/prompt/validator versions, output, validation errors, published fields,
  relational publication flag, and lifecycle dates. No `error`, `llm_attempts`, `usage`, raw provider
  response, provider response ID, or provider/model field is copied.
- `contract_source_observations`: scoped rows, current pointed rows, and analysis observations
  referenced by exported interpretations, including rows owned by another contract.
- `contract_source_snapshots`: scoped rows and every snapshot referenced by exported observations
  or interpretations. Public source payload JSON is necessary for independent source proof.
- `price_components`: all episodes for scoped `electricity_contract_id` values.
- `contract_price_snapshots`: all dates for scoped `contract_id` values.
- `electricity_futures_eod_prices`: all dates where `area='FI' AND product='Base'`.
- `spot_price_averages`: all FI rows, all period types and dates.
- `spot_prices_hour`: all FI rows and dates. This is the actual model table name, not
  `spot_price_hours`. No quarter-hour table is needed.
- `contract_price_daily_statistics`: all dates and segments where `metric_key='energy_price'`
  and `consumption_kwh IS NULL`.

The complete replacement graph and exact source SQL, query hashes, counts, byte counts, and
row-stream hashes stay in the private manifest. No date cutoff is applied to evidence episodes.
Cycles, missing active IDs, and missing replacement pointers are explicit graph facts. Broken
current/publication ownership is not repaired or filtered out.

## Reader evidence and local validation

`CanonicalContractPricingService` uses latest rolling-365 averages.
`EexMarketReferenceCurveProvider::spotSeasonalIndex` uses completed monthly averages with a
four-year lookback from the latest eligible month. All FI averages preserve that window even when
source data is stale. Its fixed-term context reader uses energy-price daily statistics with null
consumption; this is why that additional public input table is included.
`ContractPriceStatisticsService::rolling365EvidenceForDate` can fall back to FI hourly prices over
365 Helsinki dates converted to UTC. All stored FI hours preserve this fallback for historical
source dates too. If the hourly history or any other table exceeds a bound, stop for review; do
not assume a truncated history is sufficient. Missing source history remains missing evidence.

SQLite tables use production columns and numeric affinity for numeric fields. JSON and date
strings stay intact. Interpretation columns outside the allowlist are absent, not fabricated.
All source indexes become full-column NONUNIQUE SQLite lookup indexes, including primary and
unique keys. This is the original successful snapshot strategy, not a new relaxation of data checks.
These are lookup indexes, not mirrored MySQL constraints. Collated text primary/unique keys and
decimal unique indexes are supported without collation emulation or constraint-equivalence claims.
The exporter still rejects prefix, expression, partial, missing-column, incomplete, inconsistent
metadata and unsupported index types. Required metadata fields come from the actual COLUMNS and
STATISTICS SELECTs; optional expression/partial fields are rejected if supplied, not required.
`index_validation` records `lookup_index_created`; original source uniqueness metadata stays intact.
Rows are never deleted or merged. SQLite FK, NOT NULL, default and generated-column constraints
remain omitted. `ANALYZE` runs only on the new private file before sealing and hashing.

The measured ANALYZE-only improvement made optional unique-index preservation unnecessary; see
`../query-profile.md` for the manager decision and unchanged historical measurements. No dependency,
configuration or collation approximation was added. The sealed September 16 artifact, diagnostic
variants and old results are unchanged. Full production metadata stays in the manifest. Source primary-key duplicate groups, required evidence pointers, owner mismatches,
missing full-graph owners, and owners outside the copied contract scope are checked separately.
Every imported table count and the scope count are verified. SQLite `integrity_check` must pass.

Legacy NULL `analysis_source_observation_id` is recorded separately in
`current_publication_null_analysis_source_pointers`. NULL alone is not a source mismatch;
a differing snapshot or a differing non-NULL analysis pointer still is a mismatch.

The summary includes UTC/Helsinki times, counts, active audience/national availability,
interpretation and active publication profiles/states, source freshness, snapshot/statistics dates,
and futures/spot freshness. The manifest includes SQLite DDL, snapshot/summary file sizes and
SHA-256 hashes, plus the exporter hash. It cannot include its own recursive file hash.

The separate runner must freeze the comparison date from this summary and use its own isolated
application/cache setup. Existing cached calculation fields are source facts, not baseline results.
No known annual-pricing database input is omitted. Configuration and code are separate runner
inputs; this export does not attest their production values or invoke pricing readers.

## Offline verification

- `php -l tasks/source-validated-energy-rules/preflight/export.php`: passed.
- `php /tmp/voltikka-preflight-export-offline-test.php`: 13 checks passed. Fixtures cover reverse
  ancestry, unrelated rows, missing pointers, cycles (including numeric self-cycles), SQLite schema
  creation/import, JSON byte preservation, numeric affinity, dates/nulls, indexes, integrity, and
  invalid identifiers/missing schema. This private temporary test file is not a release artifact.
- CLI with `--target=/tmp`: refused, exit 1, generic initialization error.
- CLI with an existing `/tmp` test file: refused, exit 1, generic initialization error.
- `git diff --check`: passed for the new preflight files.

No MySQL integration test was run. Production schema, counts, query performance, and successful
export remain unverified until the manager approves and runs this command.
