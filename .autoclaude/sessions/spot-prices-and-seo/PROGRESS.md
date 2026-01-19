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

## 2026-01-20 - Iteration 4

### Completed: `fetch-historical-command` - Create artisan command to backfill historical spot prices

**Approach:**
- Followed TDD methodology: wrote 19 feature tests first, then implemented the command
- Created `BackfillSpot` command in `app/Console/Commands/BackfillSpot.php`
- Created feature tests in `tests/Feature/BackfillSpotCommandTest.php`

**Implementation Details:**
- Command signature: `spot:backfill --start-date= --end-date= --force`
- Defaults to fetching 1 year of historical data when no dates specified
- Fetches prices in monthly chunks to avoid API limits
- Shows progress bar during long operations
- Skips dates that already have data (unless --force flag)
- Handles ENTSO-E API rate limits with 150ms delay between requests
- Continues processing after individual chunk failures
- Uses same VAT rate logic as FetchSpot command (10%, 24%, 25.5% depending on date)
- Uses `insertOrIgnore` to avoid duplicates

**Command Options:**
- `--start-date`: Start date for backfill (YYYY-MM-DD, defaults to 1 year ago)
- `--end-date`: End date for backfill (YYYY-MM-DD, defaults to today)
- `--force`: Force fetch even if data already exists for the period

**Tests:**
- 19 feature tests covering:
  - Command signature and options
  - Default date range (1 year back)
  - Custom date range validation
  - Monthly chunking logic
  - Data persistence to database
  - Skip existing data behavior
  - Force flag behavior
  - Progress output
  - API error handling (continues after errors)
  - VAT rate calculations for all periods
  - Total records reporting
  - Empty response handling
  - Date format validation
  - Start before end validation
  - Rate limiting delay between API calls
  - InsertOrIgnore behavior
  - Negative price handling

**Commit:** `62fbb6d` - feat: Add spot:backfill command for historical price data

## 2026-01-20 - Iteration 5

### Completed: `spot-price-analytics` - Add analytics methods to SpotPrice component

**Approach:**
- Followed TDD methodology: wrote 20 feature tests first, then implemented the analytics methods
- Enhanced `App\Livewire\SpotPrice` with analytics functionality
- Extended existing tests in `tests/Feature/SpotPriceComponentTest.php`

**Implementation Details:**

**New Analytics Methods:**

1. **`getBestConsecutiveHours(int $hoursNeeded)`**
   - Finds the best N consecutive hours with the lowest average price
   - Useful for EV charging or high-consumption tasks
   - Returns: start_hour, end_hour, average_price, prices array
   - Returns null if not enough consecutive hours available

2. **`getPriceVolatility()`**
   - Calculates price variance and standard deviation for today
   - Useful for understanding price stability
   - Returns: variance, std_deviation, average, range

3. **`getHistoricalComparison()`**
   - Compares today's average with yesterday and weekly average
   - Fetches historical data from database (7 days back)
   - Calculates percentage change from yesterday and weekly average
   - Returns: today_average, yesterday_average, weekly_average, weekly_days_available, change_from_yesterday_percent, change_from_weekly_percent

4. **`getCheapestRemainingHours(int $count)`**
   - Finds cheapest future hours (excludes current and past hours)
   - Includes tomorrow's prices if available
   - Sorted by price ascending
   - Returns array of future hours

5. **`calculatePotentialSavings(int $hoursNeeded, float $kwhPerHour)`**
   - Calculates potential savings if using cheapest hours vs average
   - Useful for showing users the benefit of timing their consumption
   - Returns: cheapest_average, overall_average, savings_per_kwh, savings_cents, savings_euros, savings_percent, total_kwh

**View Integration:**
- All analytics data passed to view:
  - `priceVolatility` - price variance and standard deviation
  - `historicalComparison` - today vs yesterday vs weekly average
  - `cheapestRemainingHours` - 5 cheapest upcoming hours
  - `bestConsecutiveHours` - best 3 consecutive hours for EV charging
  - `potentialSavings` - savings estimate for 3 hours at 3.7 kW

**Tests:**
- 20 new feature tests (total now 48) covering:
  - Best consecutive hours finding
  - Single hour edge case
  - Not enough data handling
  - Price array inclusion
  - Variance calculation
  - Standard deviation calculation
  - Volatility null handling
  - Price range calculation
  - Today vs yesterday comparison
  - Today vs weekly comparison
  - No yesterday data handling
  - Partial weekly data handling
  - Cheapest remaining hours (future only)
  - Current hour exclusion
  - Tomorrow inclusion
  - No future hours handling
  - Potential savings calculation
  - Savings with specific consumption
  - Insufficient data handling
  - View data assertions

**Commit:** `e18fa8e` - feat: Add analytics methods to SpotPrice component

## 2026-01-20 - Iteration 6

### Completed: `spot-price-view-enhanced` - Create enhanced Pörssisähkön hinta page view

**Approach:**
- Followed TDD methodology: wrote 18 view feature tests first, then implemented the enhanced view
- Updated `App\Livewire\SpotPrice` to include Chart.js compatible data
- Completely redesigned `resources/views/livewire/spot-price.blade.php`
- Updated layout to support script stacks for Chart.js

**Implementation Details:**

**New Component Method:**
- `getChartData()` - Generates Chart.js compatible data structure with color-coded bars (green=cheap, yellow=moderate, red=expensive)

**Enhanced View Features:**

1. **Hero Section with Current Price**
   - Large, prominent current price display
   - Shows price with and without VAT
   - Current hour indicator with "Nyt" badge

2. **Price Comparison Cards**
   - Today's average with change indicator from yesterday
   - Yesterday's average for reference
   - Weekly average with change indicator

3. **Interactive Bar Chart (Chart.js)**
   - Hourly prices displayed as color-coded bars
   - Green for cheap hours (bottom 33%)
   - Yellow for moderate hours (middle 33%)
   - Red for expensive hours (top 33%)
   - Finnish-formatted tooltips

4. **Statistics Section**
   - Average price (Keskihinta)
   - Median price (Mediaani)
   - Lowest price (Alin) - green highlighted
   - Highest price (Ylin) - red highlighted

5. **Best Hours Cards**
   - Cheapest hour card (green themed)
   - Most expensive hour card (red themed)
   - Price volatility indicator (yellow themed)

6. **Cheapest Remaining Hours Section**
   - Lists 5 cheapest upcoming hours
   - Includes tomorrow's prices if available
   - "Huomenna" badge for next-day hours

7. **EV Charging Section**
   - Best 3 consecutive hours for charging
   - Recommended charging time window
   - Potential savings in EUR
   - Typical EV consumption stats (3.7 kW)

8. **Enhanced Hourly Table**
   - Color indicators for each price
   - "Nyt" badge for current hour
   - "Huomenna" badge for next-day hours
   - Both ALV 0% and with-VAT prices

**Layout Updates:**
- Added `@stack('scripts')` to app.blade.php for Chart.js support

**Tests (18 new tests):**
- Hero section display
- Price comparison cards
- Statistics section
- Best hours section
- EV charging section
- Chart data availability
- Chart data structure
- Color coding in chart
- Volatility indicator
- Potential savings display
- Empty data handling
- Finnish number formatting
- Change indicators
- Tomorrow badges
- Current hour highlighting
- Cheapest/expensive hour display
- Mobile responsiveness

**Commit:** `3f90d5c` - feat: Create enhanced Pörssisähkön hinta page view

## 2026-01-20 - Iteration 7

### Completed: `spot-price-historical-view` - Add historical price trends to spot price page

**Approach:**
- Followed TDD methodology: wrote 28 feature tests first, then implemented the functionality
- Extended `App\Livewire\SpotPrice` with historical data methods
- Updated view with lazy-loaded historical data section
- Added CSV export functionality

**Implementation Details:**

**New Component Properties:**
- `historicalDataLoaded` - Boolean flag for lazy loading state
- `weeklyDailyAverages` - Cached weekly data
- `weeklyChartData` - Cached chart data
- `monthlyComparison` - Cached monthly comparison
- `yearOverYearComparison` - Cached year-over-year data
- `FINNISH_MONTHS` - Constant array of Finnish month names

**New Component Methods:**

1. **`loadHistoricalData()`**
   - Lazy-loads all historical data on demand
   - Sets `historicalDataLoaded` to true when complete
   - Called via Livewire button click

2. **`getWeeklyDailyAverages()`**
   - Fetches daily averages for the past 7 days (excluding today)
   - Calculates min, max, and average per day
   - Groups by Helsinki date for proper timezone handling
   - Returns sorted array by date

3. **`getMonthlyComparison()`**
   - Compares current month average with last month
   - Uses Finnish month names (Tammikuu, Helmikuu, etc.)
   - Calculates percentage change
   - Returns both averages and day counts

4. **`getYearOverYearComparison()`**
   - Compares current month with same month last year
   - Includes `has_last_year_data` flag to conditionally show section
   - Calculates percentage change

5. **`getWeeklyChartData()`**
   - Generates Chart.js compatible data for weekly line chart
   - Includes daily averages dataset
   - Includes min/max range dataset
   - Uses Finnish date format (d.m.)

6. **`generateCsvData(int $days)`**
   - Generates CSV content for specified number of days
   - Uses semicolon as delimiter (Excel-friendly for European locales)
   - Includes: date, hour, price without tax, price with tax, VAT rate
   - Uses UTF-8 encoding with Finnish number formatting

7. **`downloadCsv()`**
   - Returns StreamedResponse for file download
   - Includes UTF-8 BOM for Excel compatibility
   - Default filename: spot-hinnat.csv
   - Exports last 7 days of data

**View Updates:**

1. **CSV Download Button**
   - Located after hourly prices table
   - Shows loading state during download
   - Finnish text: "Lataa CSV"

2. **Historical Data Section (Lazy Loaded)**
   - Initial state: "Lataa historiatiedot" button
   - Loading spinner during data fetch
   - Reveals full historical analysis when loaded

3. **Weekly Price Chart**
   - Line chart showing daily averages
   - Uses Chart.js with Finnish-formatted tooltips
   - Shows 7-day trend
   - Displays min/max range

4. **Monthly Comparison Cards**
   - Current month average (blue themed)
   - Last month average (gray themed)
   - Percentage change indicator (green for decrease, red for increase)
   - Day counts for context

5. **Year-over-Year Comparison**
   - Only shown when historical data is available
   - Current year vs last year same month
   - Percentage change indicator

**Tests (28 new tests):**
- Weekly daily averages calculation
- Partial weekly data handling
- Empty history handling
- Monthly comparison calculation
- No last month data handling
- Year-over-year comparison
- No last year data handling
- Weekly chart data structure
- Min/max range in chart
- CSV data generation
- CSV includes both price formats
- CSV includes historical data
- Lazy loading behavior
- Loading state display
- Data display after loading
- Weekly chart section display
- Monthly comparison section display
- Year-over-year display when data available
- Year-over-year hidden when no data
- CSV download button display
- CSV download response
- Finnish day names in chart
- Finnish month names
- View receives historical data after load

**Commit:** `04589ee` - feat: Add historical price trends to spot price page

## 2026-01-20 - Iteration 8

### Completed: `seo-contracts-base` - Create base SEO-optimized contracts listing component

**Approach:**
- Followed TDD methodology: wrote 29 feature tests first, then implemented the component
- Created `App\Livewire\SeoContractsList` that extends `ContractsList`
- Created feature tests in `tests/Feature/SeoContractsListTest.php`

**Implementation Details:**

**New Livewire Component:**
`App\Livewire\SeoContractsList` extends `ContractsList` with:

1. **Filter Parameters via Mount:**
   - `housingType` - omakotitalo, kerrostalo, rivitalo
   - `energySource` - tuulisahko, aurinkosahko, vihrea-sahko
   - `city` - Finnish city slug (e.g., helsinki, tampere)

2. **Housing Type Consumption Mapping:**
   - Omakotitalo: 18000 kWh
   - Kerrostalo: 5000 kWh
   - Rivitalo: 10000 kWh

3. **Energy Source Filtering:**
   - Tuulisähkö: filters contracts with renewable_wind > 0
   - Aurinkosähkö: filters contracts with renewable_solar > 0
   - Vihreä sähkö: filters contracts with renewable >= 50% AND fossil_peat = 0

4. **SEO Data Generation:**
   - `getSeoDataProperty()` - Returns array with title, description, canonical, jsonLd
   - `generateSeoTitle()` - Creates SEO-optimized page titles
   - `generateMetaDescription()` - Creates meta descriptions with consumption info
   - `generateCanonicalUrl()` - Creates canonical URLs for SEO pages
   - `generateJsonLd()` - Creates Schema.org ItemList structured data

5. **JSON-LD Structured Data:**
   - Schema.org ItemList with Product items
   - Includes product name, brand, offers with price specification
   - Proper price formatting for electricity contracts

6. **Finnish Localization:**
   - Housing type names in nominative and locative forms
   - Energy source display names
   - City names with proper locative endings (Helsingissä, Tampereella, etc.)
   - 20 major Finnish cities with pre-defined locative forms

7. **View Integration:**
   - Custom H1 heading based on filter context
   - SEO intro text explaining the filter
   - Breadcrumb navigation for SEO pages
   - Internal links section for related pages

**New View:**
`resources/views/livewire/seo-contracts-list.blade.php`:
- JSON-LD script block for structured data
- SEO-optimized hero section with custom H1 and intro text
- Breadcrumb navigation
- Full contracts listing (reused from parent)
- Internal links section for housing types, energy sources, and related pages

**Tests (29 tests, 59 assertions):**
- Component initialization and existence
- Filter parameter acceptance via mount
- Housing type consumption mapping (omakotitalo, kerrostalo, rivitalo)
- Energy source filtering (tuulisahko, aurinkosahko, vihrea-sahko)
- SEO title generation for each filter type
- Meta description generation
- Canonical URL generation
- JSON-LD structured data presence and structure
- ItemList type with correct itemListElement
- Product items with proper structure
- Parent filter logic inheritance
- Page heading display for each filter type
- Default behavior without filters
- View rendering with contracts
- SEO intro text display

**Commit:** `5edb22d` - feat: Add SeoContractsList component for SEO-optimized contract pages

## 2026-01-20 - Iteration 9

### Completed: `seo-housing-routes` - Create housing type SEO routes and pages

**Approach:**
- Followed TDD methodology: wrote 19 feature tests first, then implemented the routes
- Added routes to `routes/web.php` for housing type pages
- Enhanced `SeoContractsList` with detailed housing-type specific intro text

**Implementation Details:**

**New Routes Added:**
- `/sahkosopimus/omakotitalo` - Named `seo.housing.omakotitalo` (18000 kWh default)
- `/sahkosopimus/kerrostalo` - Named `seo.housing.kerrostalo` (5000 kWh default)
- `/sahkosopimus/rivitalo` - Named `seo.housing.rivitalo` (10000 kWh default)

**Route Configuration:**
- All routes use `SeoContractsList` Livewire component
- Routes use `defaults()` to pass `housingType` parameter to the component
- Each route has a named identifier for easy linking

**Enhanced Intro Text:**
New `getHousingTypeIntroText()` method provides detailed, SEO-optimized descriptions:
- **Omakotitalo**: Explains typical consumption (18 000 kWh), mentions electric heating can reach 20 000-25 000 kWh
- **Kerrostalo**: Explains typical consumption (5 000 kWh), mentions main consumption sources (appliances, lighting, electronics)
- **Rivitalo**: Explains typical consumption (10 000 kWh), compares to other housing types

**Page Features (already implemented in base component):**
- Unique H1 with housing type ("Sähkösopimukset omakotitaloon")
- SEO-optimized intro text explaining electricity usage
- Contracts list with appropriate default consumption
- Internal links to other housing types
- Breadcrumb navigation

**New Test File:**
`tests/Feature/SeoHousingRoutesTest.php` with 19 tests covering:
- Route accessibility (all 3 housing types)
- Unique H1 content for each housing type
- Consumption info display in intro text
- Breadcrumb navigation presence
- Internal links to other housing types
- Contracts display on pages
- Named route functionality

**Tests Result:**
- All 19 tests pass (26 assertions)
- All 29 existing SeoContractsList tests still pass (59 assertions)

**Files Changed:**
- `routes/web.php` - Added 3 housing type routes
- `app/Livewire/SeoContractsList.php` - Added `getHousingTypeIntroText()` method
- `tests/Feature/SeoHousingRoutesTest.php` - New test file

## 2026-01-20 - Iteration 10

### Completed: `seo-energy-routes` - Create energy source SEO routes and pages

**Approach:**
- Followed TDD methodology: wrote 26 feature tests first, then implemented the routes
- Added routes to `routes/web.php` for energy source pages
- Enhanced `SeoContractsList` with energy source intro text, statistics, and environmental info

**Implementation Details:**

**New Routes Added:**
- `/sahkosopimus/tuulisahko` - Named `seo.energy.tuulisahko` (filter: renewable_wind > 0)
- `/sahkosopimus/aurinkosahko` - Named `seo.energy.aurinkosahko` (filter: renewable_solar > 0)
- `/sahkosopimus/vihrea-sahko` - Named `seo.energy.vihrea-sahko` (filter: renewable >= 50%, fossil_peat = 0)

**Route Configuration:**
- All routes use `SeoContractsList` Livewire component
- Routes use `defaults()` to pass `energySource` parameter to the component
- Each route has a named identifier for easy linking

**Enhanced Intro Text:**
New `getEnergySourceIntroText()` method provides detailed, SEO-optimized descriptions:
- **Tuulisähkö**: Explains wind power as one of the cleanest energy forms, mentions zero CO₂ emissions during operation, notes Finland's growing wind capacity
- **Aurinkosähkö**: Describes solar energy benefits, mentions no emissions/noise/waste during operation, notes seasonal availability in Finland
- **Vihreä sähkö**: Explains renewable energy benefits, emphasizes carbon footprint reduction

**Energy Source Statistics:**
New `getEnergySourceStatsProperty()` method calculates aggregated statistics:
- Average renewable percentage across contracts
- Average wind, solar, and hydro percentages
- Total number of contracts in category
- Fully renewable contract count
- Fossil-free contract count

**Environmental Impact Information:**
New `getEnvironmentalInfoProperty()` method provides CO₂ emission facts:
- **Tuulisähkö**: Lifecycle emissions 7-15 g/kWh vs 400-1000 g/kWh for fossil fuels
- **Aurinkosähkö**: Lifecycle emissions 20-50 g/kWh, no operational emissions
- **Vihreä sähkö**: Average lifecycle emissions under 50 g/kWh

**View Updates:**
- Added energy source statistics section with visual cards showing:
  - Average renewable percentage (green)
  - Average wind percentage (blue, if > 0)
  - Average solar percentage (yellow, if > 0)
  - Average hydro percentage (cyan, if > 0)
  - Total contracts count
  - Fossil-free contract count
- Added environmental impact section with gradient background and globe icon
- Sections only displayed on energy source pages

**New Test File:**
`tests/Feature/SeoEnergyRoutesTest.php` with 26 tests covering:
- Route accessibility (all 3 energy sources)
- Unique H1 content for each energy source
- Intro text display with energy source keywords
- Contract filtering by energy source
- Energy source statistics display
- Breadcrumb navigation presence
- Internal links to other energy sources
- Named route functionality
- Environmental impact information display

**Tests Result:**
- All 26 energy tests pass (37 assertions)
- All 19 housing tests still pass (26 assertions)
- Total: 74 SEO-related tests pass (122 assertions)

**Files Changed:**
- `routes/web.php` - Added 3 energy source routes
- `app/Livewire/SeoContractsList.php` - Added:
  - `getEnergySourceIntroText()` method
  - `getEnergySourceStatsProperty()` computed property
  - `getEnvironmentalInfoProperty()` computed property
  - Updated `render()` to pass new data to view
- `resources/views/livewire/seo-contracts-list.blade.php` - Added:
  - Energy source statistics section
  - Environmental impact section
- `tests/Feature/SeoEnergyRoutesTest.php` - New test file

**Commit:** `e71345b` - feat: Add energy source SEO routes for tuulisahko, aurinkosahko, vihrea-sahko

## 2026-01-20 - Iteration 11

### Completed: `seo-city-routes` - Create city-based SEO routes and pages

**Approach:**
- Followed TDD methodology: wrote 28 feature tests first, then implemented the functionality
- Added dynamic city route to `routes/web.php`
- Enhanced `SeoContractsList` with city filtering logic and city info properties

**Implementation Details:**

**New Route Added:**
- `/sahkosopimus/{city}` - Named `seo.city` (e.g., /sahkosopimus/helsinki)
- Route placed AFTER specific housing and energy routes to avoid overriding them
- Uses regex constraint `[a-z0-9-]+` to validate city slugs

**City Filtering Logic:**
New `filterByCity()` method filters contracts to show:
- All national contracts (availability_is_national = true)
- Contracts specifically available in the city's postcodes

New `getCityPostcodes()` method:
- Queries Postcode model by municipal_name_fi or municipal_name_fi_slug
- Returns array of postcodes for the city

**City Information:**
New `getCityInfoProperty()` computed property returns:
- City name and locative form
- Number of contracts available
- Number of unique providers
- List of provider names

**Finnish Locative Forms:**
The component already had 20 major Finnish cities with proper locative forms:
- Helsinki → Helsingissä
- Tampere → Tampereella
- Oulu → Oulussa
- Turku → Turussa
- Jyväskylä → Jyväskylässä
- Seinäjoki → Seinäjoella
- Rovaniemi → Rovaniemellä
- etc.

Unknown cities get generic `-ssa` ending (e.g., Testcity → Testcityssa)

**View Integration:**
Updated render method to pass:
- `isCityPage` - boolean flag for city pages
- `cityInfo` - city statistics and information

**New Test File:**
`tests/Feature/SeoCityRoutesTest.php` with 28 tests covering:
- Route accessibility (Helsinki, Tampere, Espoo)
- Unique H1 content with correct locative forms
- SEO intro text display
- Contract filtering (national + city-specific)
- City-specific contracts shown on correct city
- City-specific contracts NOT shown on other cities
- Breadcrumb navigation
- Internal links to other page types
- City statistics display
- Wildcard route functionality
- Named route (seo.city)
- Correct locative forms for various cities
- Unknown city fallback behavior
- JSON-LD structured data
- Housing and energy routes not overridden
- Top cities list query

**Tests Result:**
- All 28 city tests pass (38 assertions)
- All 19 housing tests still pass (26 assertions)
- All 26 energy tests still pass (37 assertions)
- Total: 73 SEO-related tests pass (101 assertions)

**Files Changed:**
- `routes/web.php` - Added city route with wildcard
- `app/Livewire/SeoContractsList.php` - Added:
  - `filterByCity()` method for contract filtering
  - `getCityPostcodes()` method to get city's postcodes
  - `getCityInfoProperty()` computed property
  - Updated `render()` to pass city data to view
- `tests/Feature/SeoCityRoutesTest.php` - New test file
