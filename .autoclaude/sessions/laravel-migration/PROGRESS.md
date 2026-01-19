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

### Task: api-routes-calculation (COMPLETED)

**What was done:**
- Created two new API endpoints for price calculation and consumption estimation
- Implemented `CalculationController` with `calculatePrice` and `estimateConsumption` methods
- Integrated existing `ContractPriceCalculator` and `EnergyCalculator` services

**API Endpoints:**
1. `POST /api/calculate-price` - Calculate annual electricity cost for a contract
   - Request body:
     - `contract_id` (required) - ID of the electricity contract
     - `consumption` (int) - Total annual kWh consumption (simple mode)
     - `energy_usage` (object) - Detailed energy breakdown (advanced mode)
       - `total`, `basic_living`, `room_heating`, `water`, `sauna`, `electricity_vehicle`, `cooling`, etc.
     - `spot_price_day`, `spot_price_night` (optional) - For spot contracts
   - Returns:
     - `total_cost` - Annual electricity cost in EUR
     - `avg_monthly_cost` - Average monthly cost
     - `monthly_costs` - Array of 12 monthly costs
     - `monthly_fixed_fee` - Fixed monthly fee
     - Rate information (general, day/night, seasonal)

2. `POST /api/estimate-consumption` - Estimate annual electricity consumption
   - Request body:
     - `living_area` (required) - Floor area in m²
     - `num_people` (required) - Number of residents
     - `building_type` - detached_house, apartment, row_house
     - `heating_method` - electricity, air_to_water_heat_pump, ground_heat_pump, etc.
     - `supplementary_heating` - heat_pump, exhaust_air_heat_pump, fireplace
     - `building_energy_efficiency` - passive, low_energy, 2010, 2000, etc.
     - `building_region` - south, central, north
     - `electric_vehicle_kms_per_month`, `bathroom_heating_area`, `sauna_usage_per_week`
     - `sauna_is_always_on_type`, `external_heating`, `external_heating_water`, `cooling`
   - Returns:
     - `total` - Total annual consumption in kWh
     - `basic_living` - Basic living electricity
     - Component breakdown: `room_heating`, `water`, `sauna`, `electricity_vehicle`, etc.
     - Fuel consumption (if applicable): firewood, heating oil, pellets

**Validation:**
- Contract ID validation with 404 for non-existent contracts
- Required fields validation
- Enum value validation for heating methods, regions, etc.
- Positive integer validation for living_area and num_people

**Tests:**
- 18 comprehensive feature tests covering:
  - Basic consumption calculation
  - Detailed energy usage calculation
  - Time metering contracts
  - Seasonal metering contracts
  - Input validation (contract_id, consumption, enum values)
  - Minimal and full parameter estimates
  - Specific features (sauna, EV, heating breakdown)
- `php artisan test --filter=CalculationApiTest` - 18 tests, 75 assertions
- `php artisan test` - All 126 tests pass (494 assertions)

**Files created:**
- `laravel/app/Http/Controllers/Api/CalculationController.php` - API controller
- `laravel/tests/Feature/CalculationApiTest.php` - Feature tests

**Files modified:**
- `laravel/routes/api.php` - Added calculation routes

**Commit:** 80118e4 - "feat: Create API routes for price calculation and consumption estimation"

## 2026-01-20

### Task: fetch-contracts-job (COMPLETED)

**What was done:**
- Created `FetchContracts` Artisan command to fetch contracts from Azure Consumer API
- Ported logic from legacy Python Cloud Functions (`fetch-contracts`, `load-contracts-to-db`, `trigger-contract-fetch`)
- Created `AzureConsumerApiClient` service for API communication with retry logic

**Command features:**
- `php artisan contracts:fetch` - Fetch contracts from all 30 default postcodes
- `php artisan contracts:fetch --postcodes=00100,02230` - Fetch from specific postcodes
- Deduplicates contracts across postcodes
- Updates existing contracts (only pricing_has_discounts field per Python behavior)
- Clears and repopulates active_contracts table
- Inserts price components with composite key handling
- Inserts electricity sources with upsert logic
- Inserts contract-postcode relationships (only for valid postcodes)
- Inserts spot futures data
- Full database transaction support with rollback on error
- Comprehensive logging and console output

**Scheduled task:**
- Configured in `routes/console.php` using Laravel 11 Schedule facade
- Runs daily at 06:00 Europe/Helsinki timezone
- `withoutOverlapping()` to prevent concurrent runs
- `onOneServer()` for distributed environments
- Logs output to `storage/logs/contracts-fetch.log`

**Migrations added:**
- `2026_01_19_200000_create_active_contracts_table.php` - Active contracts reference table
- `2026_01_19_210000_create_spot_futures_table.php` - Spot futures pricing data
- `2026_01_19_220000_create_spot_price_hours_table.php` - Hourly spot prices

**Other changes:**
- Fixed `jsonb` → `json` in electricity_contracts migration for SQLite test compatibility

**Tests:**
- 14 comprehensive feature tests covering:
  - Basic fetch and save to database
  - Multiple postcode handling
  - Default postcodes (30 total)
  - Contract deduplication
  - Updating existing contracts
  - Clearing active contracts
  - Postcode relationships
  - Spot futures
  - API error handling
  - Retry logic (3 retries with exponential backoff)
  - Discount data parsing
  - Time metering contracts
  - Seasonal metering contracts
  - Console output verification
- `php artisan test --filter=FetchContractsCommandTest` - 14 tests, 42 assertions
- `php artisan test` - All 126 tests pass (536 assertions)

**Files created:**
- `laravel/app/Console/Commands/FetchContracts.php` - Artisan command
- `laravel/app/Services/AzureConsumerApiClient.php` - API client service
- `laravel/tests/Feature/FetchContractsCommandTest.php` - Feature tests
- `laravel/database/migrations/2026_01_19_200000_create_active_contracts_table.php`
- `laravel/database/migrations/2026_01_19_210000_create_spot_futures_table.php`
- `laravel/database/migrations/2026_01_19_220000_create_spot_price_hours_table.php`

**Files modified:**
- `laravel/routes/console.php` - Added scheduled task
- `laravel/database/migrations/2026_01_19_160000_create_electricity_contracts_table.php` - jsonb → json

**Commit:** 3fa11dc - "feat: Create FetchContracts scheduled job for Azure Consumer API"

### Task: fetch-spot-prices-job (COMPLETED)

**What was done:**
- Created `FetchSpotPrices` Artisan command to fetch Nord Pool spot prices from Elering API
- Ported logic from legacy Python Cloud Function (`get-day-ahead-prices`)
- Created `EleringApiClient` service for API communication with retry logic

**Command features:**
- `php artisan spotprices:fetch` - Fetch spot prices for yesterday to tomorrow
- Fetches data only for Finland (FI) region (filters other Baltic countries)
- Converts prices from EUR/MWh to c/kWh (divide by 10)
- Applies correct VAT rates based on Finnish timezone:
  - 24% standard rate
  - 10% reduced rate (Dec 1, 2022 - Apr 30, 2023)
- Uses INSERT ... ON CONFLICT DO NOTHING for idempotent operation
- Stores: region, timestamp, utc_datetime, price_without_tax, vat_rate
- Computed columns (vat, price_with_tax) handled by model accessors
- 3 retries with 5-second delay on server errors

**Scheduled task:**
- Runs hourly (no specific time - every hour on the hour)
- Europe/Helsinki timezone
- `withoutOverlapping()` to prevent concurrent runs
- `onOneServer()` for distributed environments
- Logs output to `storage/logs/spotprices-fetch.log`

**VAT rate logic:**
- The temporary reduced VAT rate was in effect Dec 1, 2022 to Apr 30, 2023
- VAT determination uses Helsinki timezone (not UTC)
- This matches the Python implementation exactly

**Migration update:**
- Updated `spot_price_hours_table.php` migration to match Python model schema
- Table name: `spot_prices_hour` (matching legacy PostgreSQL)
- Columns: region, timestamp, utc_datetime, price_without_tax, vat_rate
- Composite primary key: (region, timestamp)

**Tests:**
- 15 unit tests for `EleringApiClient` service
- 18 feature tests for `FetchSpotPrices` command covering:
  - Basic fetch and save to database
  - Price unit conversion (EUR/MWh → c/kWh)
  - Standard VAT rate (24%)
  - Reduced VAT rate during temporary period (10%)
  - VAT boundary dates (with timezone awareness)
  - Only FI region saved
  - UTC datetime storage
  - Multiple hours handling
  - Skipping existing records (idempotency)
  - API error handling
  - Empty response handling
  - Missing FI region handling
  - Retry logic
  - Correct date range (yesterday to tomorrow)
  - Negative prices (can occur with high renewable production)
- `php artisan test --filter=EleringApiClientTest` - 15 tests, 16 assertions
- `php artisan test --filter=FetchSpotPricesCommandTest` - 18 tests, 43 assertions
- `php artisan test` - All 126 tests pass (595 assertions)

**Files created:**
- `laravel/app/Console/Commands/FetchSpotPrices.php` - Artisan command
- `laravel/app/Services/EleringApiClient.php` - API client service
- `laravel/tests/Unit/EleringApiClientTest.php` - Unit tests
- `laravel/tests/Feature/FetchSpotPricesCommandTest.php` - Feature tests

**Files modified:**
- `laravel/routes/console.php` - Added scheduled task
- `laravel/database/migrations/2026_01_19_220000_create_spot_price_hours_table.php` - Schema fix

**Commit:** 70c5ae5 - "feat: Create FetchSpotPrices scheduled job for Elering API"

### Task: generate-descriptions-job (COMPLETED)

**What was done:**
- Created `GenerateDescriptions` Artisan command to generate contract descriptions using OpenAI API
- Ported logic from legacy Python Cloud Functions (`create-contract-description`, `trigger-short-description-update`)
- Created `OpenAiService` service for API communication with retry logic

**Command features:**
- `php artisan descriptions:generate` - Generate missing descriptions for active contracts
- Only processes active contracts (those in `active_contracts` table)
- Only processes contracts without existing `short_description`
- Uses latest price date for pricing information in prompts
- Builds comprehensive prompts with:
  - Company and contract name
  - Contract type (Fixed, OpenEnded, Spot)
  - Metering type (General, Time, Seasonal)
  - Price components (with spot margin handling)
  - Electricity source percentages
  - Consumption limits (if applicable)
- Generates Finnish language descriptions (2 sentences max)
- Gracefully handles API errors (continues with other contracts)
- 3 retries with 5-second delay on server errors

**Scheduled task:**
- Runs daily at 08:00 Europe/Helsinki timezone
- `withoutOverlapping()` to prevent concurrent runs
- `onOneServer()` for distributed environments
- Logs output to `storage/logs/descriptions-generate.log`

**OpenAI API integration:**
- Uses GPT-4 model
- API key configured via `OPENAI_API_KEY` environment variable
- Added to `config/services.php` under 'openai' key

**Prompt template:**
- Based exactly on the Python implementation
- Includes Finnish terminology translations:
  - OpenEnded = Toistaiseksi voimassaoleva sähkösopimus
  - Fixed = Määräaikainen sähkösopimus
  - Spot = Pörssisähkösopimus
  - Time = Aikasähkö
  - Seasonal = Kausisähkö
  - General = Yleissähkö
- Includes 9 example descriptions for style guidance

**Tests:**
- 11 unit tests for `OpenAiService` service
- 14 feature tests for `GenerateDescriptions` command covering:
  - Generate descriptions for contracts without descriptions
  - Skip contracts with existing descriptions
  - Only process active contracts
  - Use latest price date for pricing
  - Handle multiple contracts
  - Handle API errors gracefully
  - Console output verification
  - Handle contracts without electricity source
  - Handle spot contracts (margin labeling)
  - Include consumption limits in prompt
  - Handle no price components
  - Handle no contracts needing descriptions
  - Handle time metering contracts
  - Handle seasonal metering contracts
- `php artisan test --filter=OpenAiServiceTest` - 11 tests, 27 assertions
- `php artisan test --filter=GenerateDescriptionsCommandTest` - 14 tests, 36 assertions
- `php artisan test` - All 126 tests pass (658 assertions)

**Files created:**
- `laravel/app/Console/Commands/GenerateDescriptions.php` - Artisan command
- `laravel/app/Services/OpenAiService.php` - OpenAI API client service
- `laravel/tests/Unit/OpenAiServiceTest.php` - Unit tests
- `laravel/tests/Feature/GenerateDescriptionsCommandTest.php` - Feature tests

**Files modified:**
- `laravel/routes/console.php` - Added scheduled task
- `laravel/config/services.php` - Added OpenAI configuration

**Commit:** 9f67f70 - "feat: Create GenerateDescriptions scheduled job for OpenAI API"

### Task: frontend-contracts-list (COMPLETED)

**What was done:**
- Created contracts listing page as the main homepage using Livewire
- Installed Livewire 4.x for reactive components
- Created base layout template with Finnish language support
- Implemented `ContractsList` Livewire component with:
  - Contract listing sorted by annual cost (ascending)
  - Consumption preset selector (2000, 5000, 10000, 18000 kWh)
  - Company logo display
  - Contract type badges (Fixed, Spot, OpenEnded)
  - Energy source badges (renewable %, nuclear %, fossil %)
  - Price breakdown (energy c/kWh, monthly fee EUR/kk)
  - Annual and monthly cost display

**Page features:**
- **Consumption presets:** Yksiö (2000 kWh), Kerrostalo (5000 kWh), Pieni talo (10000 kWh), Suuri talo (18000 kWh)
- **Contract cards showing:**
  - Company logo (or placeholder initials)
  - Company name
  - Contract name
  - Contract type badge
  - Energy source badges (color-coded)
  - Energy price (c/kWh)
  - Monthly fee (EUR/kk)
  - Annual cost (EUR)
  - Monthly cost (EUR/kk)
- **Responsive design** with Tailwind CSS
- **Real-time updates** when changing consumption preset

**Technical decisions:**
- Used Livewire instead of Inertia+Vue for simplicity and server-side rendering
- Integrated existing `ContractPriceCalculator` service for cost calculations
- Used Tailwind CDN fallback for tests (avoids Vite build requirement)
- Finnish language throughout the UI

**Tests:**
- 12 comprehensive feature tests covering:
  - Page accessibility
  - Livewire component rendering
  - Contract display
  - Company logo display
  - Consumption presets display
  - Consumption change updates costs
  - Contracts sorted by cost
  - Energy source badges
  - Price breakdown display
  - Contract type display
  - Preset button functionality
  - Page title
- `php artisan test --filter=ContractsListPageTest` - 12 tests, 21 assertions
- `php artisan test` - All 126 tests pass (679 assertions)

**Files created:**
- `laravel/app/Livewire/ContractsList.php` - Livewire component class
- `laravel/resources/views/layouts/app.blade.php` - Base layout template
- `laravel/resources/views/livewire/contracts-list.blade.php` - Component view
- `laravel/tests/Feature/ContractsListPageTest.php` - Feature tests

**Files modified:**
- `laravel/routes/web.php` - Updated root route to use ContractsList component
- `laravel/tests/Feature/ExampleTest.php` - Added RefreshDatabase trait
- `laravel/composer.json` - Added livewire/livewire dependency

**Commit:** 7045e57 - "feat: Create contracts listing page with Livewire"

### Task: frontend-contract-detail (COMPLETED)

**What was done:**
- Created contract detail page showing comprehensive information about individual contracts
- Implemented `ContractDetail` Livewire component with full pricing and cost calculation
- Created detailed Blade template with multiple information sections
- Added route `/sopimus/{contractId}` for accessing contract details
- Added clickable links from contracts list to detail pages

**Page features:**
1. **Header section:**
   - Company logo (or placeholder initials)
   - Contract name and company name
   - Contract type badge (Fixed/Spot/OpenEnded)
   - Metering type badge (General/Time/Seasonal)
   - Fixed time range badge (if applicable)
   - Short description
   - Action buttons: "Tilaa sopimus" (order) and "Lisätietoja" (more info)

2. **Consumption calculator:**
   - Same presets as listing page (2000, 5000, 10000, 18000 kWh)
   - Real-time cost calculation on consumption change
   - Annual and monthly cost display

3. **Pricing section:**
   - **General metering:** Single energy price + monthly fee
   - **Time metering:** Day price (07-22), night price (22-07) + monthly fee
   - **Seasonal metering:** Winter price (Nov-Mar day), other time price + monthly fee
   - All prices in Finnish format (comma as decimal separator)

4. **Price history section:**
   - Shows historical prices when multiple price dates exist
   - Grouped by price component type

5. **Long description section:**
   - Full contract description when available

6. **Microproduction section:**
   - Shows microproduction purchase info when contract supports it

7. **Electricity source breakdown (right sidebar):**
   - Visual progress bars for renewable, nuclear, fossil percentages
   - Detailed renewable breakdown (wind, hydro, solar, biomass)

8. **Company information (right sidebar):**
   - Company name
   - Address (street, postal code, city)
   - Website link

9. **Billing & terms (right sidebar):**
   - Billing frequency
   - National/regional availability
   - Availability for existing customers

**Navigation:**
- Back link to contracts list
- Contracts list now has clickable cards linking to detail pages

**Tests:**
- 26 comprehensive feature tests covering:
  - Page accessibility
  - Livewire component rendering
  - Contract name, company name, logo display
  - Contract type and metering type display
  - Fixed time range display
  - Short and long description display
  - Energy price and monthly fee display
  - Electricity source breakdown
  - Renewable energy breakdown
  - Product and order links
  - Annual cost calculation
  - Consumption change updates cost
  - Consumption presets availability
  - Microproduction info display
  - Company address display
  - 404 for non-existent contracts
  - Price history display
  - Back to list link
  - Time metering day/night prices
  - Seasonal metering winter/other prices
- `php artisan test --filter=ContractDetailPageTest` - 26 tests, 37 assertions
- `php artisan test` - All 143 tests pass (716 assertions)

**Files created:**
- `laravel/app/Livewire/ContractDetail.php` - Livewire component class
- `laravel/resources/views/livewire/contract-detail.blade.php` - Component view
- `laravel/tests/Feature/ContractDetailPageTest.php` - Feature tests

**Files modified:**
- `laravel/routes/web.php` - Added contract detail route
- `laravel/resources/views/livewire/contracts-list.blade.php` - Added links to detail pages

**Commit:** 00067dc - "feat: Create contract detail page with Livewire"

### Task: frontend-filters (COMPLETED)

**What was done:**
- Added comprehensive filtering controls to the contracts listing page
- Implemented filters for contract type, metering type, postcode, and energy source preferences
- All filter states persist in URL via Livewire #[Url] attributes for shareable filtered views

**Filter controls implemented:**

1. **Contract type filter (Sopimustyyppi):**
   - Määräaikainen (Fixed)
   - Pörssisähkö (Spot)
   - Toistaiseksi voimassa (OpenEnded)
   - Click to select, click again to deselect

2. **Metering type filter (Mittarointi):**
   - Yleismittarointi (General)
   - Aikamittarointi (Time)
   - Kausimittarointi (Seasonal)

3. **Postcode search (Postinumero):**
   - Search input with autocomplete suggestions
   - Shows postcode and Finnish name
   - Supports search by postcode, Finnish/Swedish name, or municipality
   - Selected postcode displayed as removable badge
   - Filters contracts by availability (national contracts always visible)

4. **Energy source preferences (Energialähde):**
   - Uusiutuva (50%+) - Renewable energy filter
   - Sisältää ydinvoimaa - Nuclear energy filter
   - Fossiiliton - Fossil-free filter

**Additional features:**
- "Tyhjennä suodattimet" (Clear filters) button when any filter is active
- Results count showing number of matching contracts
- Visual indication of active filters (blue button background)
- Filter state persists when changing consumption preset
- Responsive grid layout for filter controls

**Technical changes:**
- Updated `ContractsList` Livewire component with filter properties and methods
- Updated Postcode model's `search` scope for SQLite compatibility (ILIKE → LIKE fallback)
- Updated postcodes migration to match model schema with all Finnish/Swedish name fields
- Filters applied in order: database query (contract_type, metering) → collection filter (postcode, energy source)

**Tests:**
- 20 comprehensive feature tests covering:
  - Filter controls displayed
  - Contract type filtering (Fixed, Spot, OpenEnded)
  - Clear contract type filter
  - Metering type filtering (General, Time, Seasonal)
  - Postcode filtering (national vs regional contracts)
  - Postcode search suggestions
  - Postcode search by municipality name
  - Renewable energy filter (>=50%)
  - Nuclear energy filter
  - Fossil-free filter
  - Combined filters (contract type + metering)
  - All filters combined
  - Reset all filters
  - Filter state persistence
  - Active filter visual indication
  - Filter result counts
- `php artisan test --filter=ContractsFilterTest` - 20 tests, 44 assertions
- `php artisan test` - All 137 tests pass (392 assertions)

**Files created:**
- `laravel/tests/Feature/ContractsFilterTest.php` - Comprehensive filter tests

**Files modified:**
- `laravel/app/Livewire/ContractsList.php` - Added filter properties and methods
- `laravel/app/Models/Postcode.php` - SQLite/PostgreSQL compatible search scope
- `laravel/database/migrations/2026_01_19_180000_create_postcodes_table.php` - Schema update
- `laravel/resources/views/livewire/contracts-list.blade.php` - Filter UI

**Commit:** b011f19 - "feat: Add filtering controls to contracts listing page"

