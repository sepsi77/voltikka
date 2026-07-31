# Decisions

## Implementation

- Keep content-addressed `contract_source_snapshots` immutable and fingerprint-unique per contract.
- Store chronology in `contract_source_observations`. An unchanged pointed episode extends. Every payload transition creates a point episode, including A→B→A, which produces two snapshots and three episodes.
- Use `electricity_contracts.current_source_observation_id` as the only source-currentness rule. Snapshot IDs, row order, and snapshot aggregate `first_observed_at` / `last_observed_at` do not select currentness or day coverage.
- Return pointed source-observation IDs in import results and freshness checkpoint metadata. The old `observed_snapshot_ids` shape fails closed.
- Dispatch every observed pointed episode after commit. Analysis first uses the snapshot fingerprint. A published or superseded output can rematerialize without an API key or an LLM job only after it validates at the recurrent episode date; otherwise one date-scoped fallback is used.
- A matching `published_interpretation_id` permits an early dispatcher return only while the interpretation status is `published`. A late stale publish can mark that row `superseded`; a later recurrence must publish it again.
- Enforce application-level cross-contract integrity because observation and snapshot use separate foreign keys. The dispatcher rejects a snapshot owned by another contract. The publisher locks contract → pointed observation → pointed snapshot → interpretation, validates all ownership and source IDs before canonical writes, and uses the locked pointed snapshot payload.
- Select historical repair payloads only through observation episodes that cover the requested day. Proceed only when the covering episodes resolve to one distinct snapshot. Unknown or ambiguous intervals stay empty. Both repair commands have A→B→A regression coverage; snapshot aggregate ranges never fill the middle B interval as A.
- Current retail-premium periods take their dates from the pointed episode and record `source_metadata.source_observation_id`. The snapshot ID and fingerprint remain immutable payload provenance. Open-period identity reuse requires the same episode, so recurrent A does not merge with earlier A.
- Company `Päivitetty` returns the later of maximum pointed-episode `last_observed_at` and maximum relational price date among active null-pointer legacy contracts.

## Migration, rollback, and fail-closed limits

- Migration `2026_07_30_000001` creates the episode table and indexed, nullable contract pointer. The pointer has no foreign key because observations already cascade from contracts and a pointer FK would create a circular delete path.
- Migration `2026_07_30_000002` backfills full legacy ranges only when distinct snapshot ranges do not overlap. For overlapping ranges, hidden recurrence cannot be reconstructed. It writes only known first/last event points and leaves intervals unknown.
- Backfill locks contracts in stable ID order before snapshots, builds the complete plan, and fails before writes for an invalid range, different snapshots tied at the greatest timestamp, a non-empty target episode table, or existing non-null pointers. Reads and writes use one transaction.
- Separate snapshot and observation foreign keys cannot enforce same-contract ownership in the database. Dispatcher and publisher ownership checks fail closed. Repair and freshness consumers also require exact pointed/covering episode evidence.
- Rollback of the backfill clears pointers and episode rows. Rollback of the schema then removes the pointer column and episode table. Immutable snapshots are not changed. New application code is not compatible after those schema removals, so application and schema rollback must be coordinated. No production migration or rollback was run.
- Deploy operations must stop old import and interpretation workers while the separate schema and backfill migrations run. Local tests use SQLite. SQLite accepts `lockForUpdate()` but does not provide MySQL row-level `SELECT ... FOR UPDATE` behavior. The lock order is therefore verified by code review and test behavior only; MySQL lock contention was not tested.

## Independent-review correctness hardening

- Keep the original snapshot analysis fingerprint as the first lookup so a recurrent payload can still reuse valid output without a paid call. Revalidate reusable output with the recurrent episode's `first_observed_at`; if date-sensitive validation fails, supersede the old publication and use a fallback fingerprint that includes both the date and exact observation ID. The old output is never overwritten, and same-day recurrence episodes cannot share queued analysis work.
- A queued analysis reads the contract's pointed observation before any client call and uses its episode date. A missing, cross-contract, or different-snapshot pointer supersedes the work. Date-scoped fallback interpretations also store the exact `analysis_source_observation_id`; dispatcher reuse fails closed for another episode, and the job requires that ID to equal the exact pointer before a client call. Base snapshot fingerprint rows keep the column null and remain payload-reusable. This prevents queued A2 work from running with A3's date after A2→B→A3 on the same snapshot. Repeated date-scoped dispatch for one episode resolves one row and one job; a row can wait for an API key without becoming a failed retry.
- Gated historical repair treats each covering snapshot as an independent authorization boundary. It requires that snapshot's own published/superseded nonempty, validation-clean output to pass the current source-pricing gate, and caches this result by snapshot ID.
- The backfill reads, locks, plans, preflights, and writes in one transaction, with stable contract locks before snapshot locks. Deploy operations must stop old import and interpretation workers because schema DDL is separate and local SQLite cannot prove MySQL row locking.
- Import locks all existing imported contracts before updates. Forward retail keys include exact episode IDs while legacy key adoption stays available. Company freshness compares the pointed-episode maximum with relational dates only from null-pointer active contracts.

## Verification facts

- `./vendor/bin/pint` ran successfully on all 35 changed PHP files.
- Focused interpretation/gated-repair, backfill/import/coordinator/fetch, freshness/collision-repair, retail-premium/calibration/history, and company tests: 239 tests passed with 995 assertions. The canonical company query guard added 8 passing tests with 46 assertions.
- Final full Laravel suite: 1661 tests passed and 1 unrelated pre-existing test failed, with 5874 assertions in 76.52 seconds. The known failure is `ContractDetailPresenterTest::six month detail copy...`: strict float identity expected `60.0` but received `59.99999999999999` at line 378.
- The required `rg` check found snapshot aggregate timestamp use only in `ContractImporter`, which maintains aggregate evidence, and `ContractInterpretationInputBuilder`, which keeps immutable analysis provenance. No application currentness or historical coverage query uses snapshot aggregate dates.
- Documentation stale-wording search was reviewed. Currentness and chronology documentation now uses pointed episodes. Remaining `contract_price_snapshots` references describe the separate statistics table, not source snapshots.
- `git diff --check` completed with no output before task completion. No production, MySQL, or Railway command ran.

## Final exact-episode binding hardening

- Pint passed on the five touched PHP files.
- The A2→B→A3 date-scoped regression binds each fallback fingerprint to the exact observation ID. Separate recurrence episodes on the same analysis date get separate rows, and stale A2 work fails closed.
- Final `ContractInterpretationPipelineTest`: 66 tests passed with 255 assertions.
- Final manager regression set for interpretation, backfill, import, coordinator, fetch, freshness, both repair paths, retail history/current collection, and company details: 193 tests passed with 823 assertions.
- `ContractSourceObservationBackfillTest`: 3 tests passed with 14 assertions.
- Final Pint checks and `git diff --check` passed. No production, Railway, or MySQL command ran.
