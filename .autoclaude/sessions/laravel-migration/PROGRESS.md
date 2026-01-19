# Progress Log

## 2026-01-19

### Task: scaffold-laravel (COMPLETED)

**What was done:**
- Created Laravel 11.47 project in `laravel/` directory using Composer
- Configured PostgreSQL as the default database connection in `.env` and `.env.example`
- Set application name to "Voltikka"
- Configured Finnish locale (fi_FI) and Europe/Helsinki timezone
- Created `app/Services` and `app/Enums` directories for future use
- Fixed PHP 8.5 deprecation warnings in `config/database.php` for MySQL/MariaDB SSL constants
- Created root `.gitignore` to exclude IDE files, Python artifacts, and embedded git repos in legacy/
- Initialized git repository and created initial commit

**Tests:**
- `php artisan test` passes (2 assertions, 1 deprecated due to vendor code)

**Files created/modified:**
- `laravel/` - Full Laravel 11 project scaffold
- `laravel/.env` - PostgreSQL connection configured
- `laravel/.env.example` - Template with PostgreSQL settings
- `laravel/config/database.php` - Fixed PHP 8.5 PDO constant deprecation
- `.gitignore` - Project-wide ignore rules

**Commit:** e084118 - "feat: Scaffold Laravel 11 project for Voltikka migration"

### Task: eloquent-company-model (COMPLETED)

**What was done:**
- Created `Company` Eloquent model ported from SQLAlchemy model
- Configured string primary key (`name`) instead of auto-incrementing ID
- Implemented `generateSlug()` method for Finnish character handling (ä→a, ö→o, å→a)
- Auto-generates `name_slug` on model save via `saving` event
- Added `hasMany` relationship to `ElectricityContract` (prepared for next task)
- Created migration documenting existing PostgreSQL schema (non-destructive)
- Created comprehensive unit tests (7 tests, 19 assertions)

**Tests:**
- `php artisan test --filter=CompanyTest` - 7 tests pass
- `php artisan test` - All tests pass (21 assertions)

**Files created:**
- `laravel/app/Models/Company.php` - Eloquent model
- `laravel/database/migrations/2026_01_19_150000_create_companies_table.php` - Schema documentation
- `laravel/tests/Unit/CompanyTest.php` - Unit tests

**Commit:** 00b76f0 - "feat: Add Company Eloquent model with Finnish slug generation"

### Task: eloquent-contract-model (COMPLETED)

**What was done:**
- Created `ElectricityContract` Eloquent model ported from SQLAlchemy model
- Configured string primary key (`id`) matching legacy PostgreSQL schema
- Implemented `generateSlug()` method for Finnish character handling (ä→a, ö→o, å→a)
- Auto-generates `name_slug` on model save via `saving` event
- Configured attribute casts for JSONB fields (`billing_frequency`, `time_period_definitions`, `transparency_index`)
- Configured boolean casts for all boolean fields
- Configured float casts for consumption limitation fields
- Set up all relationships:
  - `belongsTo Company` (via `company_name` → `name`)
  - `hasMany PriceComponent` (via `electricity_contract_id`)
  - `hasOne ElectricitySource` (via `contract_id`)
  - `belongsToMany Postcode` (via `contract_postcode` pivot table)
- Created migration documenting existing schema including `contract_postcode` pivot table
- Created comprehensive unit tests (12 tests, 65 assertions)

**Tests:**
- `php artisan test --filter=ElectricityContractTest` - 12 tests pass
- `php artisan test` - All 20 tests pass (86 assertions)

**Files created:**
- `laravel/app/Models/ElectricityContract.php` - Eloquent model
- `laravel/database/migrations/2026_01_19_160000_create_electricity_contracts_table.php` - Schema documentation
- `laravel/tests/Unit/ElectricityContractTest.php` - Unit tests

**Commit:** d705bfe - "feat: Add ElectricityContract Eloquent model with relationships"

### Task: eloquent-price-component-model (COMPLETED)

**What was done:**
- Created `PriceComponent` Eloquent model ported from SQLAlchemy model
- Handled composite primary key (`id`, `price_date`) using custom `setKeysForSaveQuery()` method
  - Laravel doesn't natively support composite keys, but this method ensures proper update/delete operations
- Configured string primary key (`id`) with non-incrementing
- Configured attribute casts:
  - `price_date` → date
  - `has_discount`, `discount_is_percentage` → boolean
  - `discount_value`, `discount_discount_n_first_kwh`, `price` → float
  - `discount_discount_n_first_months` → integer
  - `discount_discount_until_date` → datetime
- Set up `belongsTo` relationship with `ElectricityContract` (via `electricity_contract_id`)
- Added useful query scopes:
  - `ofType($type)` - Filter by price_component_type
  - `forDate($date)` - Filter by price_date
  - `latest()` - Order by price_date descending
  - `withDiscount()` - Filter to only discounted components
- No timestamps (matching legacy schema)

**Files created:**
- `laravel/app/Models/PriceComponent.php` - Eloquent model

**Commit:** eed2d34 - "feat: Add PriceComponent Eloquent model with composite key support"

### Task: eloquent-postcode-model (COMPLETED)

**What was done:**
- Created `Postcode` Eloquent model ported from SQLAlchemy model
- Configured string primary key (`postcode`) matching legacy PostgreSQL schema
- Implemented `generateSlug()` method for Finnish character handling (ä→a, ö→o, å→a)
- Configured all fillable fields grouped by category:
  - Finnish name fields: `postcode_fi_name`, `postcode_fi_name_slug`, `postcode_abbr_fi`
  - Swedish name fields: `postcode_sv_name`, `postcode_sv_name_slug`, `postcode_abbr_sv`
  - Area fields: `type_code`, `ad_area_code`, `ad_area_fi`, `ad_area_fi_slug`, `ad_area_sv`, `ad_area_sv_slug`
  - Municipality fields: `municipal_code`, `municipal_name_fi`, `municipal_name_fi_slug`, `municipal_name_sv`, `municipal_name_sv_slug`, `municipal_language_ratio_code`
- Set up `belongsToMany` relationship with `ElectricityContract` (via `contract_postcode` pivot table)
- Added useful query scopes:
  - `inMunicipality($name)` - Filter by Finnish municipality name
  - `search($term)` - Search by postcode, Finnish/Swedish name, or municipality
- No timestamps (matching legacy schema)
- Created comprehensive unit tests following TDD (13 tests, 40 assertions)

**Tests:**
- `php artisan test --filter=PostcodeTest` - 13 tests pass
- `php artisan test tests/Unit/` - All 33 tests pass (125 assertions)

**Files created:**
- `laravel/app/Models/Postcode.php` - Eloquent model
- `laravel/tests/Unit/PostcodeTest.php` - Unit tests

### Task: eloquent-remaining-models (COMPLETED)

**What was done:**
- Created four remaining Eloquent models ported from SQLAlchemy:

1. **ElectricitySource** - Energy source percentages for each contract
   - Primary key: `contract_id` (one-to-one with ElectricityContract)
   - All renewable, fossil, and nuclear percentage fields
   - `belongsTo` relationship with `ElectricityContract`
   - Helper methods: `isFullyRenewable()`, `isFossilFree()`, `hasNuclear()`

2. **ActiveContract** - Simple reference table for active contracts
   - Primary key: `id` (foreign key to electricity_contracts)
   - `belongsTo` relationship with `ElectricityContract`

3. **SpotFutures** - Futures pricing data
   - Composite primary key (`date`, `price`) handled via `setKeysForSaveQuery()`
   - Date and float casts

4. **SpotPriceHour** - Hourly spot prices
   - Composite primary key (`region`, `timestamp`) handled via `setKeysForSaveQuery()`
   - Computed accessors for `vat` and `price_with_tax` (matching database computed columns)
   - Query scopes: `forRegion()`, `forDate()`
   - Null-safe VAT calculations

All models follow TDD approach with comprehensive unit tests.

**Tests:**
- 37 new tests with 72 assertions
- `php artisan test tests/Unit/` - All 70 tests pass (197 assertions)

**Files created:**
- `laravel/app/Models/ElectricitySource.php`
- `laravel/app/Models/ActiveContract.php`
- `laravel/app/Models/SpotFutures.php`
- `laravel/app/Models/SpotPriceHour.php`
- `laravel/tests/Unit/ElectricitySourceTest.php`
- `laravel/tests/Unit/ActiveContractTest.php`
- `laravel/tests/Unit/SpotFuturesTest.php`
- `laravel/tests/Unit/SpotPriceHourTest.php`

**Commit:** 11ee1d3 - "feat: Add remaining Eloquent models (ElectricitySource, ActiveContract, SpotFutures, SpotPriceHour)"

### Task: contract-price-calculator (COMPLETED)

**What was done:**
- Created `ContractPriceCalculator` service class ported from Python `contract_price_calculator.py`
- Implemented all three metering types:
  - **General** - Single rate for all hours
  - **Time** - Day (07-22) and night (22-07) rates with configurable night share
  - **Seasonal** - Winter day (Nov-Mar, 07-22) and other times rates
- Implemented spot price contract handling with margin detection
- Handles abnormally low prices (< 0.8 c/kWh) as spot contract margins
- Calculates costs for all usage components with appropriate night-time shares:
  - Basic living (15% night)
  - Bathroom underfloor heating (15% night)
  - Water heating (90% night)
  - Sauna (0% night - daytime only)
  - Electric vehicle (90% night)
  - Room heating (33% night)
  - Cooling (only June-August, daytime only)
- Supports monthly heating breakdown for accurate seasonal calculations
- Returns comprehensive pricing result with all rate information

**Supporting code:**
- `MeteringType` enum - General, Time, Season metering types
- `EnergyUsage` DTO - Energy consumption data structure
- `ContractPricingResult` DTO - Calculation results with monthly breakdown

**Tests:**
- 20 comprehensive tests covering all metering types and edge cases
- `php artisan test --filter=ContractPriceCalculatorTest` - 20 tests, 58 assertions
- `php artisan test` - All 91 tests pass (256 assertions)

**Files created:**
- `laravel/app/Services/ContractPriceCalculator.php` - Main calculation service
- `laravel/app/Enums/MeteringType.php` - Metering type enum
- `laravel/app/Services/DTO/EnergyUsage.php` - Energy usage data class
- `laravel/app/Services/DTO/ContractPricingResult.php` - Pricing result data class
- `laravel/tests/Unit/ContractPriceCalculatorTest.php` - Comprehensive unit tests

**Commit:** dc244d4 - "feat: Port contract price calculator to PHP"

### Task: energy-calculator (COMPLETED)

**What was done:**
- Created `EnergyCalculator` service class ported from Python `energy_calculator/calculator.py`
- Estimates annual electricity consumption based on building characteristics:
  - Building type, size, energy efficiency rating
  - Geographic region (south, central, north Finland)
  - Number of residents
  - Heating method and supplementary heating

**Calculation modules implemented:**
1. **Basic living electricity** - Per person (400 kWh) + per floor area (30 kWh/m²)
2. **Building heating energy need** - Regional factors based on building age and energy rating
3. **Primary heating methods:**
   - Electric (efficiency 1.0)
   - Air-to-water heat pump (efficiency 2.2)
   - Ground heat pump (efficiency 2.9)
   - District heating (fixed 700 kWh)
   - Oil heating (calculates oil consumption)
   - Fireplace (calculates firewood consumption)
   - Pellets (calculates pellet consumption)
4. **Supplementary heating:**
   - Heat pump (40% share, 2.2 efficiency)
   - Exhaust air heat pump (60% share, 3.0 efficiency)
   - Fireplace (40% share)
5. **Water heating** - Formula-based with boiler energy loss lookup
6. **Bathroom underfloor heating** - 200 kWh per m²/year
7. **Sauna** - Regular (7.5 kWh/session × weekly × 52) or always-on (2750 kWh/year)
8. **Electric vehicle charging** - 0.199 kWh/km × monthly km × 12
9. **Cooling** - Fixed 240 kWh/year

**Supporting code:**
- `HeatingMethod` enum - All primary heating methods
- `SupplementaryHeatingMethod` enum - Heat pump, exhaust air, fireplace
- `BuildingEnergyRating` enum - Passive to older buildings
- `BuildingRegion` enum - South, central, north Finland
- `BuildingType` enum - Detached house, apartment, row house
- `EnergyCalculatorRequest` DTO - Input parameters
- `EnergyCalculatorResult` DTO - Output with fuel consumption and costs

**Tests:**
- 36 comprehensive tests covering all calculation methods
- Tests for basic calculations, heating scenarios, appliances, and full estimates
- `php artisan test --filter=EnergyCalculatorTest` - 36 tests, 67 assertions
- `php artisan test` - All 126 tests pass (323 assertions)

**Files created:**
- `laravel/app/Services/EnergyCalculator.php` - Main calculation service
- `laravel/app/Enums/HeatingMethod.php` - Heating method enum
- `laravel/app/Enums/SupplementaryHeatingMethod.php` - Supplementary heating enum
- `laravel/app/Enums/BuildingEnergyRating.php` - Building energy rating enum
- `laravel/app/Enums/BuildingRegion.php` - Geographic region enum
- `laravel/app/Enums/BuildingType.php` - Building type enum
- `laravel/app/Services/DTO/EnergyCalculatorRequest.php` - Request DTO
- `laravel/app/Services/DTO/EnergyCalculatorResult.php` - Result DTO
- `laravel/tests/Unit/EnergyCalculatorTest.php` - Comprehensive unit tests

**Commit:** 7bef85a - "feat: Port energy consumption calculator to PHP"

### Task: api-routes-contracts (COMPLETED)

**What was done:**
- Created RESTful API routes for electricity contracts
- Implemented `ContractController` with `index` and `show` methods
- Created API Resource classes for consistent JSON formatting

**API Endpoints:**
1. `GET /api/contracts` - List contracts with optional filters and pagination
   - Query parameters:
     - `contract_type` - Filter by Fixed, Spot, OpenEnded
     - `metering` - Filter by General, Time, Seasonal
     - `postcode` - Filter by availability in specific postcode
     - `energy_source` - Filter by renewable (100%), nuclear, fossil_free
     - `consumption` - Annual kWh for cost calculation
     - `sort=cost` - Sort by calculated annual cost (ascending)
     - `per_page` - Items per page (default 20, max 100)
     - `page` - Page number
   - Returns paginated list with meta information

2. `GET /api/contracts/{id}` - Get single contract details
   - Includes company, price components, electricity source
   - Optional `consumption` parameter for cost calculation

**Supporting code:**
- Enabled API routing in `bootstrap/app.php`
- Created `routes/api.php` with contract routes
- Created API Resources:
  - `ContractResource` - Contract data formatting
  - `ContractCollection` - Paginated collection with meta
  - `CompanyResource` - Company data formatting
  - `PriceComponentResource` - Price component formatting
  - `ElectricitySourceResource` - Energy source formatting
- Enabled SQLite for testing in `phpunit.xml`
- Created additional migrations for testing:
  - `create_price_components_table.php`
  - `create_postcodes_table.php`
  - `create_electricity_sources_table.php`

**Tests:**
- 14 comprehensive feature tests covering all endpoints
- Tests for filtering, pagination, cost calculation, and sorting
- `php artisan test --filter=ContractApiTest` - 14 tests, 96 assertions
- `php artisan test` - All 126 tests pass (419 assertions)

**Files created:**
- `laravel/app/Http/Controllers/Api/ContractController.php` - API controller
- `laravel/app/Http/Resources/ContractResource.php` - Contract resource
- `laravel/app/Http/Resources/ContractCollection.php` - Paginated collection
- `laravel/app/Http/Resources/CompanyResource.php` - Company resource
- `laravel/app/Http/Resources/PriceComponentResource.php` - Price component resource
- `laravel/app/Http/Resources/ElectricitySourceResource.php` - Energy source resource
- `laravel/routes/api.php` - API route definitions
- `laravel/tests/Feature/ContractApiTest.php` - Feature tests
- `laravel/database/migrations/2026_01_19_170000_create_price_components_table.php`
- `laravel/database/migrations/2026_01_19_180000_create_postcodes_table.php`
- `laravel/database/migrations/2026_01_19_190000_create_electricity_sources_table.php`

**Files modified:**
- `laravel/bootstrap/app.php` - Added API routing
- `laravel/phpunit.xml` - Enabled SQLite for testing

**Commit:** c5b2ed6 - "feat: Create API routes for contracts listing and detail"
