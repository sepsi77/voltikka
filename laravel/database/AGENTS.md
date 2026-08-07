# AGENTS.md

Database/migration notes for Voltikka.

## `contract_order_clicks`

Stores one durable first-party event for an accepted contract-detail seller CTA activation.

Important semantics:

- `event_uuid` is unique and makes Beacon or fetch retries idempotent
- contract and company fields are event-time snapshots; `contract_id` has no cascading foreign key
- annual price, price rank, rank total, rank consumption, and pricing basis are nullable and stay null when unavailable
- the table uses typed columns and indexes for time, company, contract identity/name, source, medium, campaign, and CTA location
- it does not store raw IP addresses, user agents, full referrers, full URLs, query strings, visitor IDs, session IDs, or generic event-property JSON
- durable rows have indefinite retention at the initial release; there is no analytics cleanup command, job, or schedule

The short-lived browser attribution period is separate. Its logical inactivity limit is 30 minutes. See `../app/Services/Analytics/AGENTS.md`.

## `users.is_admin`

The boolean defaults to false. Filament panel access requires it to be true. Valid credentials alone do not grant access. Admin-user creation and role changes are explicit operational data mutations; they do not run in migrations, seeders, or deployment code.

## Electricity contract consumption ranges

- `consumption_limitation_min_x_kwh_per_y` and `consumption_limitation_max_x_kwh_per_y` are nullable, but each stored value must be non-negative and a present minimum must not exceed a present maximum.
- Migration `2026_07_31_000001` fails before DDL if an existing row violates these rules. It does not clean or infer data.
- MySQL uses one named CHECK constraint. SQLite uses named BEFORE INSERT and BEFORE UPDATE triggers without rebuilding `electricity_contracts`. Other database drivers fail clearly.

## Contract test factories

- `ElectricityContract::factory()` requires an existing company through `forCompany()`; it never creates a company implicitly.
- The default contract is inactive, household, fixed-price, canonical-only, and has no consumption limits. Use named states for activation, other pricing classifications, legacy canonical nulls, limits, and relational price rows.
- `factories/Support/CanonicalPricingFixture.php` contains only parser-valid canonical scenarios and strict public builders for complete canonical attributes, boundaries, components, phases, schedules, consumption effects, and monthly packages. Builders use canonical enums where the domain has them and do not deep-merge overrides. Keep malformed payloads local to the test that needs them.
- `withRelationalPrices()` takes explicit component facts and creates deterministic `PriceComponent` rows after the contract exists. Do not make relational rows a default side effect. `withConsumptionLimits()` rejects negative bounds and inverted ranges.

## Local production-data snapshot

`database.sqlite` is ignored local development data. Refresh it from production only through the repository wrapper:

```bash
scripts/sync-production-database.sh
```

Run this command from the repository root. Stop all local Laravel, queue, SQLite, and database-tool processes first. The wrapper builds and migrates a separate `.production-sync-*` SQLite file, reads production MySQL in one read-only consistent transaction, validates row counts, foreign keys, and integrity, creates a timestamped SQLite backup in `/tmp`, removes stale sidecars, and atomically replaces the local file. A failure before replacement leaves the active local database unchanged.

Authentication and operational runtime tables remain empty. If production temporarily lacks the local-derived `contract_source_observations` table, the fresh target reconstructs observations and current pointers with migration `2026_07_30_000002`; all other application-table drift fails closed. Detailed implementation rules are in `../app/Services/DevelopmentDatabase/AGENTS.md` and `../../scripts/AGENTS.md`. Never run the internal Artisan adapter directly against `database.sqlite`, print Railway database variables, or change this workflow into a production write.

## `spot_social_publications`

Stores one durable daily Spot social publication identity per Helsinki `content_date`.

Important semantics:
- `content_date` is unique and is the date shown in the video
- statuses are `processing`, `published`, and `failed`; each explicit claim increments `attempt_count`
- the first claim stores exact `data_as_of`, and retries reuse it
- normal runs never retry failed or processing rows; explicit retry permits failed rows or processing rows that are at least 30 minutes old
- published rows are final; partial PostFast success stores `posted_count` and `skipped_platforms` as published metadata
- PostFast timeouts can have uncertain external results, so failure is durable and operators must inspect PostFast before explicit retry

## `data_freshness_checkpoints`

Stores one latest operational fact per `key` and `effective_date` for the scheduled morning gates. This is not a general workflow engine and does not preserve run history.

Important semantics:
- keys are `contract_import` and `eex_futures`; statuses are `ready`, `incomplete`, and `failed`
- the unique key is `(key, effective_date)`, and later full-scope runs replace that date's fact
- each full-scope upstream command first overwrites the same-date fact with a failed start marker; a crash therefore cannot preserve an older ready fact
- postcode-scoped contract runs and targeted/manual EEX runs never write global readiness
- contract ready metadata contains pointed source-observation IDs, active contract IDs, and exact statistics start and completion timestamps; the obsolete snapshot-ID shape fails closed
- EEX ready metadata contains the latest prior-date FI Base trade date extracted by that current run; database presence and age are checked separately
- dependent jobs fail closed when required metadata is absent or malformed

## `contract_source_snapshots` and `contract_source_observations`

Snapshots store immutable, complete upstream payloads. Observations store contiguous chronology episodes for those payloads.

Important semantics:
- snapshot rows are unique by contract and canonical SHA-256 source fingerprint; A can recur without a duplicate snapshot
- `electricity_contracts.current_source_observation_id` is the only current-source rule. It is indexed without a foreign key because observations already cascade from contracts and a pointer FK would create a circular delete path
- an unchanged import updates the snapshot's aggregate `last_observed_at` and extends only the pointed episode. A payload transition creates a new point episode and moves the pointer atomically
- no `(contract_id, source_snapshot_id)` uniqueness exists because A→B→A requires two A episodes
- observation coverage indexes support exact day selection. Consumers select covering episodes and proceed only when those episodes resolve to one distinct snapshot; they never order and pick an overlap
- snapshot first/last timestamps remain aggregate legacy evidence for audit and snapshot-based interpretation input. They do not select currentness or historical day coverage
- migration `2026_07_30_000002` uses full legacy ranges only when distinct ranges do not overlap. Overlap means hidden recurrence chronology is irrecoverable, so it stores first/last event points only and fails closed between them. Plan reads and writes run in one transaction: it locks contract rows in stable ID order, then locks snapshots and completes all preflight checks before inserts
- deploy operations must stop old import and interpretation workers while these two migrations run. The table/pointer DDL is a separate migration, and SQLite tests accept `lockForUpdate()` but cannot prove MySQL row-lock behavior
- rollback removes observation rows and pointers but does not modify immutable snapshots

## `contract_interpretations`

Stores one versioned automated analysis for each source + schema + prompt + provider + model fingerprint.

Important semantics:
- statuses are `pending`, `processing`, `published`, `failed`, and race-protection `superseded`
- output and validation errors are JSON; model/prompt/schema/validator metadata and execution metrics provide provenance
- `validator_version` participates in the analysis fingerprint so a stricter deterministic validator can create a new interpretation for the same source and model output contract
- `analysis_source_observation_id` is nullable and indexed. It is set only for date-scoped fallback analyses and binds them to the exact episode whose date produced the fingerprint; reusable base fingerprint rows remain null
- this binding has no foreign key because existence does not enforce exact current-episode identity. The dispatcher rejects reuse for a different observation, and the queued job verifies the exact pointed observation before a client call
- `llm_attempts` retains the initial call and up to two model correction calls, including each complete output, validation errors, usage, provider response ID, and latency
- there are no reviewer, approval, or manual-override columns
- `electricity_contracts.published_interpretation_id` points to the current version, and each interpretation stores its exact `published_fields`
- the pointer is intentionally indexed without a foreign key to avoid a circular delete path with interpretations that already cascade from contracts; application publication manages it atomically
- `electricity_contracts.canonical_pricing`, `canonical_source_consistency`, and `canonical_calculation` materialize the current validated rich JSON
- versioned interpretation output is the canonical interpretation/pricing history
- `relational_pricing_published` is the durable gate used by later imports for activation and relational price writes
- canonical phase-aware calculators consume the published interpretation phases for annual and exact-period pricing; unsafe source pricing is not copied to relational `price_components`

## Historical contract interpretation reconstruction

`contract_historical_interpretation_episodes` stores append-only consecutive exact-date evidence episodes. Its unique episode fingerprint covers the builder version, contract, full episode dates, and semantic evidence fingerprint. Flat validator input and an exact evidence manifest are immutable records. Builder v4 uses one `target_days` entry per date with the exact snapshot ID, sorted `id|price_date` component identities, and a normalized digest of the complete historical snapshot identity and component values. A separate `manifest_fingerprint` binds all exact row identities and values to the reviewed command plan without forcing a new LLM analysis for storage-ID-only changes. Redundant episode-wide lists are not stored.

`contract_historical_interpretations` stores only non-publishing work states: pending, processing, validated, and failed. Its unique analysis fingerprint covers all model, prompt/addendum, validator, parser, and provider versions. There are no publication fields or publication status values.

Migration up is retry-safe after MySQL partial DDL commits: each table, annual provenance column, and named index is guarded. Migration down refuses before any schema change when either historical audit table contains rows, but tolerates an empty partial schema. Historical audit data must never disappear through an implicit migration rollback.

`contract_price_annual_costs` has nullable indexed `historical_episode_id`, `historical_interpretation_id`, and `historical_evidence_grade` provenance columns. The AsOf writer fills all three only for a validated dedicated retrospective interpretation and keeps the normal source snapshot/interpretation fields null. Immutable-source and current-adapter rows keep the dedicated fields null. Full identities and flags remain in provenance JSON. This does not activate the public annual-cost method.

## Contract price statistics provenance

`contract_price_snapshots.pricing_basis` and `contract_price_daily_statistics.pricing_basis`
distinguish `canonical_calculation` forward values from `observed_seller_data` historical or
feature-off values. Existing rows default to observed. These columns are necessary because the
old tables could mix canonical annual totals with relational unit metrics and had no way for the
public page or CSV to state provenance. Before a calculation writes snapshots, it removes
opposite-basis rows for that target date and replaces its own contract set inside the same
transaction. Thus one run and one basis own a newly calculated date, including when canonical mode
now excludes a contract. Other dates remain intact.

`contract_price_annual_costs` is the versioned annual-only snapshot table. Its identity is date,
contract, consumption, and annual method. It keeps source IDs as indexed provenance without foreign
keys; only `contract_id` is a cascading foreign key. Do not put observed unit-price facts in this
table. `contract_price_daily_statistics.method_version` separates aggregate methods. Migration
`2026_08_06_000001` labels old `annual_cost` rows as `annual_cost_legacy_v1`, labels all old unit
rows as `unit_statistics_v1`, and replaces the old unique key with its method-aware form. The column
stays nullable at database level for application rollback compatibility. The Eloquent model and new
writers enforce a method value. The migration is retry-safe after partial MySQL DDL, always reruns the
backfill, and reports duplicate old identities before key replacement. The method-aware key does not
solve the residual nullable-key rule: MySQL and SQLite both permit duplicate unit rows when
`consumption_kwh` is NULL, and a rolled-back application can also write a NULL method. Date-scoped
application writers prevent these duplicates on rerun. The
migration down path refuses to remove the method key when rows would conflict under the old identity;
it never chooses one version or deletes it silently.

## `fixed_contract_price_forecasts` provenance

Forecast rows keep their input provenance in the existing `source_metadata` JSON. Model v2 records the current retail statistic's pricing basis, date, segment, metric, and contract count separately from historical observed basis counts/date bounds and futures coverage. Matured evaluation adds the actual retail basis/date/segment/metric without replacing forecast-input metadata.

`model_version` remains part of the unique identity. A semantics change inserts v2 rows beside immutable v1 rows; no replacement column or migration is needed. Public queries accept only the configured model version and expected current basis, while prior rows remain available for audit and evaluation.

## `electricity_futures_eod_prices`

Stores EEX electricity futures end-of-day settlement history collected by `php artisan futures:fetch-eex`.

Important semantics:
- unique rows are keyed by exchange/commodity/pricing/product/area/short_code/maturity/trade_date
- reruns update existing settlement prices and metadata because the EEX public endpoint may revise recent values
- `maturity` is stored in EEX request format `YYYYMM`; monthly maturities use the delivery month, quarterly maturities use the quarter start month, and yearly maturities use January (for example `202701`)
- keep `electricity_futures_eod_area_maturity_date_idx` for chart/query access by bidding zone and delivery year

## `price_components` latest-calculation lookup

`ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` uses a window-function query over `price_components` partitioned by `(electricity_contract_id, price_component_type)` and ordered by preferred non-zero price plus newest `price_date`.

`contracts:fetch` deletes the fetched contracts' rows for the current import date and inserts/upserts the complete current component set. This removes stale same-day components when an upstream component disappears or gets a new ID. Complete before/after payloads remain available through source snapshots.

The upstream API can return multiple null-UUID components that generate the same `(id, price_date)` key. `CanonicalPriceComponentWriter` must collapse those rows before `upsert()`: keep the first positive-price row, or the first row when all values are zero. Never let the final source row win implicitly through duplicate-key update order.

Keep `price_components_latest_calc_idx` on `(electricity_contract_id, price_component_type, price_date)` in place. It supports the large `WHERE electricity_contract_id IN (...)` bulk lookup without eager-loading full price history on listing/cache rebuilds. The `CASE WHEN price > 0` ordering expression is still computed by MySQL, but this composite index gives the optimizer the correct filtering and partition locality.
