# AGENTS.md

Database/migration notes for Voltikka.

## `contract_source_snapshots`

Stores immutable, complete upstream contract payloads for auditability and later interpretation.

Important semantics:
- unique rows are keyed by contract and canonical SHA-256 source fingerprint
- unchanged imports update only `last_observed_at`
- snapshots are deleted with their contract
- the source payload is evidence only and does not directly affect calculations or public behavior

## `contract_interpretations`

Stores one versioned automated analysis for each source + schema + prompt + provider + model fingerprint.

Important semantics:
- statuses are `pending`, `processing`, `published`, `failed`, and race-protection `superseded`
- output and validation errors are JSON; model/prompt/schema/validator metadata and execution metrics provide provenance
- `validator_version` participates in the analysis fingerprint so a stricter deterministic validator can create a new interpretation for the same source and model output contract
- `llm_attempts` retains the initial call and up to two model correction calls, including each complete output, validation errors, usage, provider response ID, and latency
- there are no reviewer, approval, or manual-override columns
- `electricity_contracts.published_interpretation_id` points to the current version, and each interpretation stores its exact `published_fields`
- the pointer is intentionally indexed without a foreign key to avoid a circular delete path with interpretations that already cascade from contracts; application publication manages it atomically
- `electricity_contracts.canonical_pricing`, `canonical_source_consistency`, and `canonical_calculation` materialize the current validated rich JSON
- versioned interpretation output is the canonical interpretation/pricing history
- `relational_pricing_published` is the durable gate used by later imports for activation and relational price writes
- canonical phase-aware calculators consume the published interpretation phases for annual and exact-period pricing; unsafe source pricing is not copied to relational `price_components`

## Contract price statistics provenance

`contract_price_snapshots.pricing_basis` and `contract_price_daily_statistics.pricing_basis`
distinguish `canonical_calculation` forward values from `observed_seller_data` historical or
feature-off values. Existing rows default to observed. These columns are necessary because the
old tables could mix canonical annual totals with relational unit metrics and had no way for the
public page or CSV to state provenance. They do not change the date/contract or aggregate unique
keys. The existing unique keys still allow only one row per date+contract and per aggregate key.
Before a calculation writes snapshots, it removes opposite-basis rows for that target date and
replaces its own contract set inside the same transaction. Thus one run and one basis own a newly
calculated date, including when canonical mode now excludes a contract. Other dates remain intact.

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
