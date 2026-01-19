# Decisions Log

## Session: fix-model-gaps

### 1. Combined migrations into a single file
**Decision**: Create one migration for both `pricing_model` and `target_group` columns.
**Rationale**: Both columns are related (API metadata fields) and being added at the same time. A single migration is cleaner and reduces migration count.

### 2. Used nullable columns
**Decision**: Both new columns are nullable.
**Rationale**: Backwards compatibility - existing contracts can be updated incrementally, and the API may not provide these values for all contracts (confirmed by 2 NULL values after fetch).

### 3. Added indexes to both columns
**Decision**: Both columns have database indexes.
**Rationale**: These fields will be used for filtering in queries. Indexes will improve query performance when filtering by pricing_model or target_group.

### 4. Backfill logic in FetchContracts
**Decision**: Update existing contracts' new fields only when they're NULL.
**Rationale**: Preserves any future manual overrides while still allowing backfill of missing data. Follows the same pattern used for `pricing_has_discounts`.

### 5. Fixed spot_futures to use DB::table()
**Decision**: Changed `SpotFutures::updateOrCreate()` to `DB::table('spot_futures')->updateOrInsert()`.
**Rationale**: The SpotFutures model has a composite primary key (date, price) which Eloquent's updateOrCreate doesn't handle well. Direct DB query works correctly.

### 6. Metering type value: "Season" vs "Seasonal"
**Decision**: Use "Season" to match the API.
**Rationale**: The API returns "Season", not "Seasonal". The ContractsList filter should use the same value as the database. Updated the `meteringTypes` array accordingly.

### 7. Contract type filtering strategy
**Decision**:
- Spot and Hybrid use `pricing_model` field
- FixedTerm and OpenEnded use `contract_type` field
**Rationale**: The Azure API separates these concerns - `pricing_model` indicates the pricing approach (spot market vs fixed), while `contract_type` indicates the contract duration (open-ended vs fixed term). This provides more accurate filtering than the previous name-matching approach.
