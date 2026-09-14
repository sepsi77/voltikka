# Contract import services

This directory owns the authoritative contract import and its post-import work.

## Primary classes

- `ContractAcquisitionResult` carries deduplicated API contracts, failed postcodes, and completeness.
- `ContractImporter` owns all database mutations for one contract import.
- `ContractImportResult` carries import counts, replacement data, active IDs, company names, changed observation IDs, and all pointed observation IDs observed in the run.
- `ContractPostImportCoordinator` captures exact dispatcher-returned target IDs and runs optional work after the import transaction commits.
- `ContractImportCompletion` owns required statistics/cache work and bounded same-day completion in the existing checkpoint JSON. This domain-local helper is needed because both the initial command and later ticks must use the same ownership and statistics fence.
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
- for complete imports after successful statistics, build and verify all eight annual presets plus candidate company/5,000, then activate one shared generation. Verified evidence/generation conflicts permit one full retry after durable failed-candidate retirement. Recovery succeeds without an import alert. A proven complete full import can defer after exhaustion; scoped/partial imports and genuine failures cannot enqueue completion. Failure preserves the active pointer unless an independent safety invalidation changed it. No failure restores a previous pointer

Optional work:

- send each observed pointed episode through the idempotent interpretation dispatcher
- dispatch `WarmContractPriceStatisticsCache` for weekly and 5,000 kWh
- calculate contract percentiles

One interpretation failure must not stop a later observation. Required work stops on a genuine failure and makes `contracts:fetch` fail; optional percentile work still runs. Statistics-cache warming remains optional after a successful statistics calculation. The command combines required stage codes, acquisition failures, and checkpoint failures into one explicit Sentry Issue and safe aggregate Laravel log at its outcome boundary. It never sends the result's failure message text to Log or Sentry. The reporter extracts only the class and closed cache-conflict/storage reason from `requiredExceptions`; unknown classes get `unexpected`. See `../../Support/AGENTS.md`. Partial acquisition keeps its existing success exit and reports warning severity; terminal failures report error severity. Optional stages do not create import Issues.

No cache store is flushed or truncated. Price-cache replacement uses `ContractListCacheService::refresh()` and `Caching/ContractPriceCacheLifecycle`; see `../Caching/AGENTS.md`. Partial imports and statistics failures never start replacement. Interpretation dispatch remains before statistics and can independently invalidate unsafe prices immediately; a failed refresh does not undo those safety invalidations. The coordinator does not call nested Artisan commands and does not use `app()` service location.

`contracts:fetch` installs a new full-run UUID in a failed start marker before acquisition. All later writes compare that UUID under the same checkpoint row lock. A superseded command cannot replace the newer fact. EEX checkpoint recording is unchanged. Acquisition/import/required-stage failures stay failed; partial acquisition stays incomplete. Scoped imports neither insert nor overwrite global facts. A scoped statistics fence locks the same-date row if it exists; if it is absent, it remains absent. Only a full import can create this checkpoint.

## Durable deferred completion

`contracts:complete-import` runs each minute, with `onOneServer` and 35-minute overlap expiry. It never calls Azure, dispatches interpretation work, sleeps between polls, or recalculates a historical date. `--dry-run` reads only: no checkpoint insertion/claim, attempt increment, statistics, cache writes or read-through bootstrap. Stage-only old failed records are inert. A separately approved fresh full import is needed to create proof.

Only successful full acquisition/import plus successful initial statistics and sitemap invalidation can create `pending_completion`. Waiting exact targets skip the wasted initial cache build. Exhausted typed cache conflicts also defer; storage/readback/retirement, unknown, statistics and invalidation errors remain terminal. Output explicitly states that completion is deferred. Waiting sends no import Issue. A terminal tick sends one safe aggregate with controlled reasons/classes and no source output, exception messages or tokens.

Version 1 metadata binds the run UUID, Helsinki effective date, full completeness, each exact contract/observation/snapshot and dispatcher-returned interpretation ID, initial active IDs, original/latest statistics timestamps, deadline and check count. It permits at most 60 claimed checks within two hours. Each check has a durable UUID claim and a 30-minute execution lease. A live claim blocks duplicates. An expired claim is terminal because writes might have been interrupted; it is never blindly retried. Terminal and ready rows are inert. JSON object-key order does not affect claim or cache-descriptor equality (MySQL normalizes object keys).

Settlement reads bounded identity batches, not global queue counts or job payloads. Pending/processing targets and transport-failed targets without validation-error proof wait. A deterministic rejected inactive target can settle, but it never counts as published. Each active row requires its exact current published target with output, no validation errors and a publication timestamp. Relational publication permission is not a canonical gate. Missing/superseded ownership or pointer changes invalidate the manifest. A missing optional dispatcher result cannot prove active publication. An empty active market fails closed rather than retaining old statistics as current. Interpretation-disabled imports keep legacy publication requirements.

Before completion, reload current active IDs, including new activations from the manifest; reset request pricing state; calculate current-date statistics; invalidate sitemap; build all eight presets plus company/5,000. Recheck exact active/publication evidence around statistics and in the cache candidate guard. Only typed evidence/generation conflicts can defer again. Every failed candidate still requires durable retirement; the existing two-total-attempt budget and atomic promotion stay authoritative. Final ready facts include current active IDs, exact statistics times and verified cache generation/fingerprint. `readyCurrent()` checks this complete proof at completion writes and final CAS only. It is not a permanent all-market/cache-UUID constraint on later consumers. Morning readers use the same existing scoped checks for new and legacy metadata: relevant fixed-term publication order can trigger the existing statistics-only recovery, while unrelated publication or EEX/cache invalidation does not block a fixed-term forecast. Retail checks retain their existing all-checkpoint-active interpretation coverage.

## Statistics ordering fence

A shared finite cache execution lock serializes normal required work, but its TTL is **not** the correctness proof. `ContractPriceStatisticsService::calculateForDate()` accepts an optional caller fence. Import callers take `lockForUpdate()` on the same-date contract checkpoint **inside the existing statistics transaction, before its first read/delete**, and retain that lock through statistics commit. Deferred calls verify fresh UUID, pending status, claim token/lease, current date, manifest and active IDs there. Initial full calls verify UUID/date and active IDs. Scoped calls lock the existing row and verify current active IDs without inserting or claiming global readiness. The missing-key `SELECT ... FOR UPDATE` stays inside the statistics transaction. Under InnoDB's default REPEATABLE READ semantics, that locking read holds the missing index-key gap until commit, so a concurrent full-start insertion waits. If the full start wins first, the scoped fence sees and locks its row instead. If it completes before an old scoped fence executes, changed active IDs reject the old call before deletion; otherwise statistics read current source data. No fake global fact or new date-lock table is required.

If old A pauses before the transaction and B becomes ready, A's fresh locked check rejects B's UUID or a changed same-run claim before deletion. If A already holds the row lock, B's start/update waits for A's statistics commit; B's later statistics then supersede A. No publisher lock, new global revision checkpoint, process alarm, extra statistics transaction, or network/cache-build work under the checkpoint lock is added. Cache candidates separately verify owner/evidence before the existing atomic generation promotion. A relevant fixed-term publication in the tiny final-read window is caught by the existing recoverable publication-order check. Completion is not an invented source snapshot guarantee, and unrelated later market changes do not permanently invalidate its checkpoint.

SQLite tests verify callback placement, rollback, stale-owner/claim ordering, scoped writes, JSON order and MySQL `FOR UPDATE` compilation. SQLite cannot prove MySQL blocking; the ordering argument relies on existing InnoDB row locks and default REPEATABLE READ gap locking for an absent key. The tests do not establish the same missing-key guarantee under a different database engine or isolation level. No production database was used for tests.
