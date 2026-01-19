# Progress Log

## 2026-01-19

### Session: database-setup

**Objective:** Set up SQLite database for local development and seed with real electricity contract data

---

### Task 1: Configure Laravel for SQLite local development
**Status:** Completed

Updated `.env` configuration:
- Changed `DB_CONNECTION` from `pgsql` to `sqlite`
- Set `DB_DATABASE` to full path: `/Users/seppo/Documents/python/voltikka/laravel/database/database.sqlite`
- Changed `SESSION_DRIVER` from `database` to `file`
- Changed `QUEUE_CONNECTION` from `database` to `sync`
- Changed `CACHE_STORE` from `database` to `file`
- Ran `php artisan config:clear` to clear configuration cache

---

### Task 2: Create PostcodeSeeder with real Finnish postcode data
**Status:** Completed

Created `database/seeders/PostcodeSeeder.php` that:
- Fetches postcode data from Posti.fi (`https://www.posti.fi/webpcode/unzip/`)
- Parses the PCF_*.dat file using fixed-width format (Latin-1 encoded)
- Extracts all fields: postcode, Finnish/Swedish names, abbreviations, area codes, municipality codes, etc.
- Generates slugs for name fields using `Postcode::generateSlug()`
- Inserts records in batches of 500 using upsert (handles duplicates)

Ported from Python implementation at `legacy/python/shared/voltikka/utils/postcodes.py`.

---

### Task 3: Run database migrations
**Status:** Completed

Ran `php artisan migrate:fresh` which created all tables:
- users, cache, jobs (Laravel defaults)
- companies
- electricity_contracts
- price_components
- postcodes
- electricity_sources
- active_contracts
- spot_futures
- spot_price_hours
- contract_postcode (pivot table)

All 11 migrations completed successfully.

---

### Task 4: Seed postcodes from Posti.fi
**Status:** Completed

Ran `php artisan db:seed --class=PostcodeSeeder`:
- Downloaded PCF_20260119.dat from Posti.fi
- Parsed 3786 postcode records
- Inserted all records in 8 batches

---

### Task 5: Fetch electricity contracts from Azure API
**Status:** Completed

Ran `php artisan contracts:fetch`:
- Fetched contracts for 30 representative postcodes across Finland
- Retrieved 488 unique electricity contracts
- Processed and saved:
  - 37 companies
  - 488 contracts
  - 488 active contract entries
  - 488 electricity sources
  - 22,195 contract-postcode relationships
  - 1 spot futures price (5.602)

---

### Task 6: Verify database has contract data
**Status:** Completed

Final database counts:
| Table | Records |
|-------|---------|
| Postcodes | 3,786 |
| Companies | 37 |
| Electricity Contracts | 488 |
| Electricity Sources | 488 |
| Active Contracts | 488 |
| Price Components | 1 |

**Note:** Price components count is lower than expected (1173 processed, 1 saved) due to the Azure API returning null UUIDs (`00000000-0000-0000-0000-000000000000`) for most price component IDs. This causes composite primary key conflicts when inserting. This is a data quality issue from the API that needs to be addressed separately - not a database setup issue.

---

### Task 7: Fix PriceComponent insertion with null UUIDs
**Status:** Completed

Fixed the issue where the Azure API returns null UUIDs (`00000000-0000-0000-0000-000000000000`) for most price component IDs, causing composite key conflicts.

**Approach (TDD):**
1. Wrote failing tests first:
   - `test_command_handles_null_uuid_price_components` - Tests two contracts with null UUID price components
   - `test_command_handles_multiple_null_uuid_components_on_same_contract` - Tests a single contract with multiple null UUID components

2. Implemented fix in `FetchContracts.php`:
   - Added `NULL_UUID` constant
   - Modified `processPriceComponents()` to check if component ID is null UUID
   - If null UUID, generate a unique UUID using `Str::uuid()`

3. Verified fix:
   - Both new tests pass
   - All 16 FetchContractsCommandTest tests pass
   - Re-ran `contracts:fetch` and verified PriceComponents: **1,173 records** (was 1)

**Final database counts after fix:**
| Table | Records |
|-------|---------|
| Postcodes | 3,786 |
| Companies | 37 |
| Electricity Contracts | 488 |
| Price Components | **1,173** |
| Electricity Sources | 488 |
| Active Contracts | 488 |
| Contract-Postcode | 22,195 |
| Spot Futures | 1 |

---

### Task 8: Fix memory exhaustion on contracts list page
**Status:** Completed

Fixed the memory exhaustion issue in the ContractsList Livewire component that was causing the page to exceed 128MB memory limit.

**Problem:**
- The `getContractsProperty()` method was eager loading `availabilityPostcodes` relationship
- The `contract_postcode` pivot table has **22,195 records**
- Loading all these records for 488 contracts exceeded the memory limit

**Approach (TDD):**
1. Wrote failing tests first:
   - `test_postcode_filter_works_without_eager_loading_all_postcodes` - Tests postcode filtering for local vs national contracts
   - `test_contracts_with_many_postcodes_load_efficiently` - Verifies `availabilityPostcodes` is NOT eager loaded
   - `test_only_latest_price_components_are_used` - Ensures correct price components are used for calculations

2. Implemented fix in `ContractsList.php`:
   - Removed `availabilityPostcodes` from eager loading
   - Applied postcode filter at database level using EXISTS subquery instead of filtering in PHP
   - This way, pivot table records are never loaded into memory

3. Verified fix:
   - All 3 new tests pass
   - All 126 total tests pass (777 assertions)
   - Memory usage reduced from >128MB to **~12MB**
   - Postcode filtering still works correctly (437 contracts available in Helsinki)

**Code Change:**
```php
// Before (memory exhaustion)
->with(['company', 'priceComponents', 'electricitySource', 'availabilityPostcodes'])
// ... filter in PHP after loading all pivot records

// After (optimized)
->with(['company', 'priceComponents', 'electricitySource'])
// ... filter at database level using EXISTS subquery
```

---

### Summary

All database setup tasks completed successfully:
1. SQLite configured for local development
2. PostcodeSeeder created and tested
3. All migrations run
4. Real Finnish postcode data seeded (3,786 records)
5. Real electricity contracts fetched and saved (488 contracts from 37 companies)
6. Data verification completed
7. **Fixed null UUID issue for price components (now 1,173 records)**
8. **Fixed memory exhaustion on contracts list page (reduced from >128MB to ~12MB)**

The local development environment is now ready with real data for testing.
