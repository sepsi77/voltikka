# Progress Log

## 2026-01-20 - Iteration 1

### Completed: `entsoe-service` - Create ENTSO-E API service for fetching spot prices

**Approach:**
- Followed TDD methodology: wrote comprehensive tests first, then implemented the service
- Created `EntsoeService` class in `app/Services/EntsoeService.php`
- Created unit tests in `tests/Unit/EntsoeServiceTest.php`

**Implementation Details:**
- Service fetches day-ahead prices from ENTSO-E Transparency Platform API
- Uses config values from `config/services.php` for API key, base URL, and Finland EIC code
- Parses XML response using SimpleXML with proper namespace handling
- Converts prices from EUR/MWh to c/kWh (divide by 10)
- Handles 15-minute resolution data by averaging to hourly values
- Returns array of price data with: region, timestamp, utc_datetime (Carbon), price_without_tax
- Includes retry logic for server errors (3 attempts with 1s delay)
- Handles "no data" responses gracefully (acknowledgement documents and empty responses)
- Handles multiple TimeSeries elements in a single response

**Tests:**
- 19 unit tests covering:
  - Basic fetch and price conversion
  - Timestamp and datetime handling
  - 15-minute resolution averaging
  - Config usage (API key, EIC code, document type)
  - Error handling (API errors, no data responses)
  - Retry logic on server errors
  - Multiple TimeSeries handling
  - Result sorting by timestamp

**Commit:** `eab606e` - feat: Add EntsoeService for fetching spot prices from ENTSO-E API

## 2026-01-20 - Iteration 2

### Completed: `fetch-spot-command` - Create artisan command to fetch and store spot prices

**Approach:**
- Followed TDD methodology: wrote 16 feature tests first, then implemented the command
- Created `FetchSpot` command in `app/Console/Commands/FetchSpot.php`
- Created feature tests in `tests/Feature/FetchSpotCommandTest.php`

**Implementation Details:**
- Command signature: `spot:fetch`
- Fetches today's and tomorrow's prices using the EntsoeService
- Stores prices in `spot_prices_hour` table using the SpotPriceHour model
- Uses `insertOrIgnore` to avoid duplicates (composite key: region, timestamp)
- Sets VAT rate based on price date:
  - 10% for Dec 2022 - Apr 2023 (temporary reduced rate)
  - 24% for May 2023 - Aug 2024 (standard rate)
  - 25.5% for Sep 2024 onwards (current increased rate)
- Logs success/failure with record count
- Handles API errors gracefully with informative error messages
- Processes prices in chunks of 500 to avoid memory issues

**VAT Rate History (Finland electricity):**
- The command correctly handles all Finnish VAT rate changes for electricity
- VAT is determined based on Helsinki timezone

**Tests:**
- 16 feature tests covering:
  - Basic fetch and save functionality
  - VAT rate calculations for all periods
  - VAT rate boundary dates (Aug 31/Sep 1 2024)
  - Upsert behavior (ignores existing records)
  - Date range (today and tomorrow)
  - Error handling (API errors, empty responses)
  - Output messages and logging
  - Negative prices (can occur during high renewable production)
  - UTC datetime storage
  - Multiple hours processing

**Commit:** `50d9500` - feat: Add spot:fetch command using ENTSO-E API

## 2026-01-20 - Iteration 3

### Completed: `spot-price-component-refactor` - Refactor SpotPrice Livewire component to use database

**Approach:**
- Followed TDD methodology: wrote 28 feature tests first, then implemented the refactored component
- Updated `App\Livewire\SpotPrice` to query database instead of legacy FastAPI
- Created feature tests in `tests/Feature/SpotPriceComponentTest.php`

**Implementation Details:**
- Removed HTTP calls to legacy FastAPI (`voltikka-fastapi-vrpilk3sbq-lz.a.run.app`)
- Query SpotPriceHour model with proper region filter (Finland only)
- Loads today's and tomorrow's prices from database
- Proper timezone handling:
  - Stores UTC timestamps in database
  - Converts to Helsinki time for display
  - Uses `Carbon::setTestNow()` for consistent testing
- Calculates current hour's price with proper Helsinki timezone
- Finds cheapest and most expensive hours (for today only)
- Calculates daily statistics: min, max, average, median
- Added `mostExpensiveHour` to view data
- Added `todayStatistics` to view data

**View Updates (spot-price.blade.php):**
- Updated to use `helsinki_hour` field instead of manual timezone calculations
- Added statistics section showing: average, median, min, max
- Shows dynamic VAT rate per hour (10%, 24%, or 25.5%)
- Added "tomorrow" badge for next day's prices
- Added message when no data is available
- Updated VAT information text to reflect current 25.5% rate

**Tests:**
- 28 feature tests covering:
  - Basic component rendering
  - Price loading from database
  - Current hour price detection with Helsinki timezone
  - Min/max price calculations (including negative prices)
  - Cheapest and most expensive hour finding
  - Daily statistics (average, median calculation)
  - Price with tax calculations
  - Region filtering (Finland only)
  - Sorting by time
  - Today vs tomorrow price separation
  - HTTP request removal verification

**Commit:** `ec20b58` - feat: Refactor SpotPrice component to use database instead of legacy FastAPI
