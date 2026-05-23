# AGENTS.md

This file provides guidance to AI coding agents when working with code in this repository.

## Project Overview

Voltikka is a Finnish electricity contract comparison platform built with **Laravel 11 and Livewire 3**. The site helps consumers find and compare electricity contracts, view real-time spot prices, and calculate solar panel production estimates.

## Production site

- Production site URL https://voltikka.fi/
- Hosting: Railway with MySQL database

## Browser based testing

You can use agent browser skill to access a browser and do browser-based testing or access websites.

## Task tracking

Agents should use the task tracking system in `tasks/` when working on this code base. Read `tasks/AGENTS.md` before starting implementation work and keep the relevant task files updated as work progresses.

## Repository Structure

```
voltikka/
├── laravel/                 # Main Laravel 11 application
│   ├── app/
│   │   ├── Livewire/        # Livewire components
│   │   ├── Models/          # Eloquent models
│   │   ├── Services/        # Business logic and external API integrations
│   │   └── Console/Commands/ # Artisan commands
│   ├── resources/views/     # Blade templates
│   ├── database/migrations/ # Database migrations
│   └── data-investigation/  # API structure documentation
├── legacy/                  # Deprecated code (not in active use)
│   ├── python/              # Old Python services
│   └── voltikka/            # Old SvelteKit frontend
└── tasks/                   # Long-running agent task state; see tasks/AGENTS.md
```

## Build and Run Commands

### Laravel Application
```bash
cd laravel
composer install
npm install && npm run build   # Vite build for CSS/JS
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve              # Runs on http://127.0.0.1:8000
```

### Key Artisan Commands
```bash
# Contract data
php artisan contracts:fetch                # Fetch contracts from Azure API and auto-link high-confidence replacements
php artisan contracts:detect-replacements  # Report likely replacements for inactive contracts
php artisan contracts:link-replacements    # Persist high-confidence replacement links

# Spot prices
php artisan spot:fetch               # Fetch current spot prices from ENTSO-E; retries transient server/connection timeouts
php artisan spot:backfill            # Backfill historical spot prices; retries transient server/connection timeouts per chunk
php artisan spot:averages            # Calculate spot price averages

# Electricity futures
php artisan futures:fetch-eex        # Fetch EEX futures EOD settlement prices for configured Nordic month/quarter/year instruments
php artisan futures:backfill-eex     # Fetch all EEX futures history available from the public API (~45 days)

# Fixed-term price forecasts
php artisan forecasting:run-fixed-contracts       # Calculate and persist fixed-term contract price forecasts
php artisan forecasting:evaluate-fixed-contracts  # Compare matured stored forecasts with realized retail prices

# Utilities
php artisan logos:optimize           # Optimize company logos to WebP
php artisan sitemap:generate         # Generate sitemap.xml

# Testing
php artisan test
php artisan test --filter="ContractsFilterTest"
```

## Key Features

### 1. Contract Comparison (Main Feature)
- **Location**: `app/Livewire/ContractsList.php`, `SahkosopimusIndex.php`
- **Route**: `/`, `/sahkosopimus`
- Filters by pricing model, contract type, energy source, housing type
- Calculates annual costs based on user consumption
- SEO-optimized filter links with dual behavior (see SEO section)
- Low-prominence market-insight pills on comparison heroes reuse cached precomputed price statistics/forecasts; they are informational only and do not affect ranking
- Individual contract detail meta descriptions are generated from Voltikka ranking/pricing data instead of provider marketing descriptions

### 2. Contract Price Statistics
- **Location**: `app/Livewire/ContractPriceStatistics.php`, `app/Services/ContractStatistics/ContractPriceStatisticsService.php`
- **Route**: `/sahkosopimus/tilastot`
- Tracks daily contract-price trends from imported contract prices
- Historical backfill infers availability from `price_components.price_date`
- Spot contract totals use stored spot-price history plus supplier margin
- Contract-type energy-price table displays spot as trailing-12-month realized daily average + typical margin, with p20–p80 daily variation under the value, so it is comparable with longer-term contract prices
- Commands: `contracts:calculate-price-statistics`, `contracts:backfill-price-statistics`, `contracts:warm-price-statistics-cache`
- Daily import calculates these statistics before optional percentile badge recalculation so this page keeps updating even if badge metrics fail
- Page requests serve cached prepared view data per period + consumption; cache keys auto-bust when statistics/snapshot/source spot-price fingerprints change
- Contract and spot-price update commands queue background warming for the default `/sahkosopimus/tilastot?kulutus=5000` page state so low-traffic first visitors do not pay the expensive cache-miss rebuild

### 3. Fixed-term Price Forecasting Backend
- **Location**: `app/Services/PriceForecasting/`, `app/Models/FixedContractPriceForecast.php`
- **Commands**: `forecasting:run-fixed-contracts`, `forecasting:evaluate-fixed-contracts`
- **Schedule**: daily forecast run at 07:30 and evaluation at 07:45 Europe/Helsinki
- Backend-only v1 model; no public UI yet
- Forecasts fixed-term 6/12/24 month market p20/median/p80 energy-price indices
- Uses FI EEX futures-implied hedge costs plus EWMA retail premium / gap closure
- Persists forecasts and later fills actual prices/errors so forecast quality can be tracked over time
- See `laravel/app/Services/PriceForecasting/AGENTS.md` before changing model semantics

### 4. Spot Price Display
- **Location**: `app/Livewire/SpotPrice.php`, `HeaderSpotPrice.php`
- **Route**: `/spot-price`
- **Data source**: ENTSO-E API via `EntsoeService`
- Features:
  - Hourly and 15-minute price granularity
  - Real-time current price in header
  - Household appliance cost calculators (sauna, laundry, dishwasher, water heater)
  - Historical comparisons (daily, weekly, monthly, year-over-year)
  - Price charts with color-coded bars
  - CSV export

### 5. Solar Panel Calculator
- **Location**: `app/Livewire/SolarCalculator.php`
- **Route**: `/aurinkopaneelit/laskuri`
- **Services**:
  - `DigitransitGeocodingService` - Finnish address geocoding
  - `PvgisService` - EU PVGIS API for solar production estimates
  - `SolarCalculatorService` - Orchestration layer
- Features:
  - Address autocomplete with geocoding
  - System size and shading configuration
  - Monthly production estimates
  - Savings calculation based on self-consumption

### 6. Electricity Consumption Calculator
- **Location**: `app/Livewire/ConsumptionCalculator.php`
- **Route**: `/sahkosopimus/laskuri`
- Estimates annual consumption based on housing type and heating

## Laravel Architecture

### Key Models (`app/Models/`)

| Model | Description |
|-------|-------------|
| `ElectricityContract` | Contract with pricing_model, target_group, metering |
| `Company` | Electricity provider (primary key: name) |
| `PriceComponent` | Pricing components (Monthly, General, DayTime, NightTime, SeasonalWinter, SeasonalOther) |
| `ElectricitySource` | Energy mix (renewable, fossil, nuclear percentages) |
| `SpotPriceHour` | Hourly spot prices |
| `SpotPriceQuarter` | 15-minute spot prices |
| `SpotPriceAverage` | Calculated averages (daily, monthly, yearly, rolling) |
| `FixedContractPriceForecast` | Stored fixed-term price forecasts plus realized actual/error metrics |
| `Postcode` | Finnish postcodes with municipality data |

### Key Livewire Components (`app/Livewire/`)

| Component | Description |
|-----------|-------------|
| `ContractsList` | Main contracts listing with filters |
| `SahkosopimusIndex` | SEO landing page for /sahkosopimus |
| `ContractDetail` | Single contract view |
| `CompanyDetail` | Company profile with their contracts |
| `CompanyList` | List of all electricity companies |
| `SpotPrice` | Spot price page with analytics |
| `HeaderSpotPrice` | Compact spot price in header |
| `SolarCalculator` | Solar panel production calculator |
| `SeoContractsList` | SEO-optimized filtered contract lists |
| `CheapestContracts` | Cheapest contracts ranking |
| `LocationsList` | Browse by municipality |

### Key Services (`app/Services/`)

| Service | Description |
|---------|-------------|
| `ContractPriceCalculator` | Calculates annual contract costs |
| `EntsoeService` | Fetches spot prices from ENTSO-E API |
| `SpotPriceAverageService` | Calculates spot price statistics |
| `PvgisService` | Fetches solar production data from EU PVGIS |
| `DigitransitGeocodingService` | Finnish address geocoding |
| `SolarCalculatorService` | Solar calculator orchestration |
| `CompanyLogoService` | Handles company logo URLs with WebP optimization |
| `AzureConsumerApiClient` | Fetches contracts from Azure API |
| `PriceForecasting/FixedTermPriceForecastService` | Builds fixed-term price forecasts from retail stats and futures hedge costs |

### Important Fields

#### ElectricityContract
| Field | Values | Description |
|-------|--------|-------------|
| `pricing_model` | Spot, FixedPrice, Hybrid | **Use this for filtering spot contracts** |
| `target_group` | Household, Company, Both | Consumer vs business |
| `contract_type` | OpenEnded, FixedTerm | Contract duration type |
| `metering` | General, Time, Season | Tariff structure |
| `fixed_time_range` | Below6, Fixed6, Between711, Fixed12, Between1323, Fixed24, Over24, Other | Fixed-term duration bucket |
| `replaced_by_contract_id` | contract id or null | Forward link to detected replacement contract |

#### PriceComponent Types
| Type | Description |
|------|-------------|
| Monthly | Fixed monthly fee (€/month) |
| General | Single rate (c/kWh) |
| DayTime | Day rate 07-22 (c/kWh) |
| NightTime | Night rate 22-07 (c/kWh) |
| SeasonalWinter | Winter rate Nov-Mar (c/kWh) |
| SeasonalOther | Other seasons (c/kWh) |

## Routes

### Main Routes
| Route | Component | Description |
|-------|-----------|-------------|
| `/` | ContractsList | Homepage with contracts |
| `/sahkosopimus` | SahkosopimusIndex | Main comparison landing page |
| `/sahkosopimus/sopimus/{id}` | ContractDetail | Contract detail |
| `/sahkosopimus/sahkoyhtiot` | CompanyList | All companies |
| `/sahkosopimus/sahkoyhtiot/{slug}` | CompanyDetail | Company profile |
| `/sahkosopimus/laskuri` | ConsumptionCalculator | Consumption calculator |
| `/sahkosopimus/halvin-sahkosopimus` | CheapestContracts | Cheapest contracts |
| `/sahkosopimus/tilastot` | ContractPriceStatistics | Contract price trend statistics |
| `/sahkosopimus/yritykselle` | SeoContractsList | Business contracts |
| `/spot-price` | SpotPrice | Spot price analytics |
| `/aurinkopaneelit/laskuri` | SolarCalculator | Solar calculator |

### SEO Routes
| Route Pattern | Type |
|---------------|------|
| `/sahkosopimus/paikkakunnat/{location}` | City-specific (e.g., /sahkosopimus/paikkakunnat/helsinki) |
| `/sahkosopimus/omakotitalo`, `/kerrostalo`, `/rivitalo` | Housing type |
| `/sahkosopimus/porssisahko`, `/kiintea-hinta`, `/kvartaalisahko`, `/aikasahko`, `/kausisahko`, `/joustosahko`, `/yleissahko` | Pricing type |
| `/sahkosopimus/tuulisahko`, `/aurinkosahko`, `/vihrea-sahko` | Energy source |
| `/sahkosopimus/sahkotarjous` | Promotions/offers |
| `/sahkosopimus/yritykselle` | Business contracts |

All SEO listing pages use `SeoContractsList` component. See "Creating SEO Contract Listing Pages" section for how to add new ones.

## External APIs

### Azure Consumer API (Contract Data)
- Endpoint: `https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/{postcode}`
- Returns full contract details
- Structure documented in `laravel/data-investigation/contract-structure.md`

### ENTSO-E API (Spot Prices)
- Fetches Finnish day-ahead electricity prices
- Service: `EntsoeService`
- Config: `services.entsoe.api_key`, `services.entsoe.finland_eic`
- Supports both hourly and 15-minute resolution

### EEX API (Electricity Futures)
- Fetches electricity futures end-of-day settlement prices from `https://api.eex-group.com/pub/market-data/chart/eod`
- Service: `App\Services\ElectricityFutures\EexFuturesService`
- Command: `php artisan futures:fetch-eex`
- Config: `config/eex_futures.php`
- Requires `Referer: https://www.eex.com/`; public chart history is limited to roughly 45 days
- Maturity/delivery selection uses EEX `YYYYMM` maturity parameters, matching the web UI delivery dropdown values; the collector dynamically probes `price-ticker` once per tenor because out-of-bounds maturities return empty HTTP 200 payloads, then reuses the discovered maturity values across markets
- EEX requests are throttled by default with roughly 15 seconds between public API calls (`EEX_FUTURES_REQUEST_DELAY_SECONDS`, jitter configurable)
- Default instruments are EEX Nordic System Price and Nordic zonal Base Month, Quarter, and Year futures; Baltic instruments must be added only after verified EEX short codes exist

### EU PVGIS API (Solar Production)
- Endpoint: `https://re.jrc.ec.europa.eu/api/v5_2/PVcalc`
- Service: `PvgisService`
- Returns monthly and annual solar production estimates

### Digitransit Geocoding API (Address Search)
- Finnish address geocoding
- Service: `DigitransitGeocodingService`
- Used by solar calculator for address lookup

## Domain Knowledge

- **VAT rate**: 25.5% (changed from 24% in September 2024)
- **Night hours**: 22:00-07:00 Finnish time (8 hours = 33% of day)
- **Winter months**: January, February, March, November, December
- **Spot contracts**: Identified by `pricing_model = 'Spot'`, NOT by name matching
- **Company logos**: Stored in Google Cloud Storage, optimized to WebP format

## Contract Replacement Handling

Inactive contracts are kept in the database for history and SEO continuity. They are not deleted.

### Current behavior
- Active contract detail pages render normally.
- Inactive contracts with a high-confidence replacement chain redirect with **301** to the latest active replacement.
- Inactive contracts without a trusted replacement still render their normal contract detail page for historical reference, but with a `noindex` robots meta tag.
- Inactive contract detail pages without a trusted replacement are excluded from the sitemap.

### Replacement matching rules
The matcher is intentionally conservative and only auto-links when confidence is high.

Hard requirements:
- same provider
- same `contract_type`
- same `metering`
- same `pricing_model`
- same `target_group`
- if fixed-term, same `fixed_time_range`

Name matching then scores candidates by:
- normalized base-name similarity
- identity token overlap
- profile/variant token overlap
- full-string similarity
- tolerance for promotion text changes like `0€ perusmaksu`, `ensimmäiset 3 kk`, etc.

### Main implementation files
- `app/Services/ContractReplacementMatcher.php`
- `app/Services/ContractReplacementLinker.php`
- `app/Console/Commands/DetectReplacementContracts.php`
- `app/Console/Commands/LinkReplacementContracts.php`
- `app/Livewire/ContractDetail.php`
- `app/Models/ElectricityContract.php`

See `laravel/AGENTS.md` for detailed implementation and chain-querying guidance.

## Code Patterns

### Filtering Contracts by Type
```php
// Spot contracts (pörssisähkö)
ElectricityContract::where('pricing_model', 'Spot')->get();

// Fixed price contracts
ElectricityContract::where('pricing_model', 'FixedPrice')->get();

// Household contracts only
ElectricityContract::where('target_group', 'Household')->get();
```

### Filtering by Energy Source
```php
// 100% renewable
ElectricityContract::whereHas('electricitySource', fn($q) =>
    $q->where('renewable_total', 100)
)->get();

// Fossil-free
ElectricityContract::whereHas('electricitySource', fn($q) =>
    $q->where('fossil_total', 0)
)->get();

// Has wind power
ElectricityContract::whereHas('electricitySource', fn($q) =>
    $q->where('renewable_wind', '>', 0)
)->get();
```

### Price Calculation 
```php
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;

$calculator = app(ContractPriceCalculator::class);
$usage = new EnergyUsage(total: 5000, basicLiving: 5000);
$result = $calculator->calculate($priceComponents, $contractData, $usage);
```

### Discount-aware pricing behavior
- `ContractPriceCalculator` now supports structured price-component discounts for first-year estimates
- calculation inputs should include latest price components with discount metadata, not only raw `price`
- contract list/cache code must avoid eager-loading full `priceComponents` history for all active contracts; use `ElectricityContract::getLatestPriceComponentsForCalculation()` so list metric rebuilds stay under memory limits
- discounted totals are component-scoped:
  - monthly fee promos apply only to `Monthly`
  - energy promos apply only to the matching energy component type
- result payloads can include both:
  - discounted totals (`total_cost`, `monthly_costs`)
  - base totals before promotions (`base_total_cost`, `base_monthly_costs`)
  - promo savings (`discount_savings_total`, `monthly_discount_savings`)
- use `ElectricityContract::getLatestPriceComponentsForCalculation()` when preparing calculator input so promo metadata is preserved

### Spot Price Access
```php
use App\Models\SpotPriceHour;
use App\Models\SpotPriceAverage;

// Current Finnish prices
$prices = SpotPriceHour::forRegion('FI')
    ->whereBetween('utc_datetime', [$start, $end])
    ->get();

// Rolling averages
$avg365 = SpotPriceAverage::latestRolling365Days('FI');
$avg30 = SpotPriceAverage::latestRolling30Days('FI');
```

## SEO Architecture

### Schema.org Structured Data
Pages include JSON-LD structured data for rich search results:
- `ContractsList` - ItemList of electricity contracts
- `ContractDetail` - Product schema for individual contracts
- `CompanyDetail` - Organization schema for companies
- `SolarCalculator` - WebApplication schema

Implementation: Each Livewire component has a `getJsonLdSchema()` method that returns the schema data, rendered via `<x-schema-markup>` component.

### Filter Links (Dual Behavior)
The filter buttons in ContractsList have dual behavior for SEO optimization:

1. **When NO filters are selected**: Filter buttons render as `<a href="...">` links with URL query parameters (e.g., `?pricingModelFilter=Spot`). This allows search engines to crawl and index filtered views.

2. **When ANY filter IS selected**: Filter buttons become Livewire toggle buttons (`wire:click`). This prevents infinite URL combinations.

The `showSeoFilterLinks` property controls this behavior - enabled only on `/sahkosopimus` routes, not on the homepage.

### Pagination SEO
- URLs use query string `?page=N` for unique, crawlable URLs
- Page titles include "– Sivu N" suffix for pages > 1
- `rel="canonical"`, `rel="prev"`, and `rel="next"` link tags are added
- Changing filters or consumption resets pagination to page 1

### Sitemap
- Sitemap URLs are generated by `app/Services/SitemapService.php` and served at `/sitemap.xml` through a cached route.
- Main indexable pages include `/sahkosopimus/tilastot`; keep `tests/Feature/SitemapTest.php` updated when adding canonical SEO pages.
- The route cache key lives in `SitemapService::CACHE_KEY`; bump or clear it when stale production sitemap content must be forced to refresh.

### Creating SEO Contract Listing Pages (Step-by-Step)

All SEO contract listing pages use the **`SeoContractsList`** Livewire component (`app/Livewire/SeoContractsList.php`) and the shared Blade template (`resources/views/livewire/seo-contracts-list.blade.php`). New pages are added by configuring the existing component, NOT by creating new components.

#### Page Categories

There are several types of SEO listing pages, each differentiated by a route parameter:

| Category | Route Parameter | Example Slug | Example URL |
|----------|----------------|-------------|-------------|
| Pricing type | `pricingType` | `porssisahko` | `/sahkosopimus/porssisahko` |
| Housing type | `housingType` | `omakotitalo` | `/sahkosopimus/omakotitalo` |
| Energy source | `energySource` | `tuulisahko` | `/sahkosopimus/tuulisahko` |
| City/Location | `location` | `helsinki` | `/sahkosopimus/paikkakunnat/helsinki` |
| Target group | `targetGroup` | `Company` | `/sahkosopimus/yritykselle` |
| Offer type | `offerType` | `promotion` | `/sahkosopimus/sahkotarjous` |

#### Checklist: Adding a New SEO Pricing Type Page

When adding a new pricing type page (e.g., `/sahkosopimus/yleissahko`), update these files in order:

**1. Route — `routes/web.php`**
Add a new route in the "SEO Pricing Type Routes" section (BEFORE the city catch-all route at the bottom):
```php
Route::get('/sahkosopimus/yleissahko', SeoContractsList::class)
    ->name('seo.pricing.yleissahko')
    ->defaults('pricingType', 'PricingTypeKey');
```
The `pricingType` default value must match a key used in the component's filtering logic.

**2. Component — `app/Livewire/SeoContractsList.php`**
Update these arrays/methods in the component:

- **`$pricingTypeNames`** — Add the Finnish display name:
  ```php
  'PricingTypeKey' => 'Display Name',
  ```
- **`generateSeoTitle()`** — Add a `match` case for the SEO page title (used in `<title>` tag)
- **`generateMetaDescription()`** — Add a meta description (used in `<meta name="description">`)
- **`generateCanonicalUrl()`** — Add the slug mapping in `$slugMap`:
  ```php
  'PricingTypeKey' => 'url-slug',
  ```
- **`getPageHeadingProperty()`** — The H1 heading is auto-generated from `$pricingTypeNames` as `"{Name}sopimukset"`. Override in the method if a custom heading is needed.
- **`getPricingTypeIntroText()`** — Add a descriptive intro paragraph (2-3 sentences explaining the contract type, shown below the H1)
- **`getContractsProperty()`** — If the new pricing type needs custom filtering logic (e.g., matching by name/description patterns instead of `pricing_model` field), add an `elseif` block in the pricing filter section. Standard `pricing_model` values (Spot, FixedPrice, Hybrid) are handled automatically.

**3. Sitemap — `app/Services/SitemapService.php`**
Add the URL slug to the `$pricingTypes` array:
```php
protected array $pricingTypes = [
    'porssisahko',
    'kiintea-hinta',
    // ...
    'yleissahko',  // Add here
];
```

**4. Internal Links — `resources/views/livewire/seo-contracts-list.blade.php`**
Add the new page to the "Katso myös" (See also) section's "Hinnoittelumalli" list:
```html
<li>
    <a href="/sahkosopimus/yleissahko" class="hover:text-coral-600">Display Name</a>
</li>
```

**5. Navigation (optional) — `resources/views/layouts/app.blade.php`**
If the page should appear in the site navigation or footer, add links in:
- Desktop dropdown menu (search for "kvartaalisahko" to find the section)
- Mobile collapsible menu
- Footer links section

#### SEO Content Elements per Page

Each SEO listing page automatically includes:

| Element | Source Method | Description |
|---------|-------------|-------------|
| `<title>` tag | `generateSeoTitle()` | Format: "Vertaa {type}sopimuksia (N sopimusta) \| Voltikka" |
| Meta description | `generateMetaDescription()` | 150-160 char description for search results |
| Canonical URL | `generateCanonicalUrl()` | Self-referencing canonical with slug |
| H1 heading | `getPageHeadingProperty()` | Usually "{Type}sopimukset" |
| Intro text | `getSeoIntroTextProperty()` | 2-3 sentence description below H1 |
| JSON-LD | `generateJsonLd()` | ItemList schema with Service items |
| Breadcrumbs | Blade template | Etusivu > Sähkösopimukset > {Page heading} |
| Internal links | Blade template | "Katso myös" section with cross-links |

#### Contract Filtering Logic

For pricing type pages, the `getContractsProperty()` method determines which contracts to show:

- **Standard pricing models** (`Spot`, `FixedPrice`, `Hybrid`): Filter by `pricing_model` column directly
- **Special types** (`Quarterly`, `TimeOfUse`, `Seasonal`): Filter by name/description patterns or `metering` field since these don't have a dedicated `pricing_model` value
- New types should use whichever approach matches the data: direct field filtering is preferred when possible

## Performance Optimizations

- **Vite build**: CSS/JS bundled and minified (`npm run build`)
- **WebP logos**: Company logos optimized to WebP format via `logos:optimize` command
- **Async fonts**: Google Fonts loaded asynchronously to avoid render-blocking
- **Resource preloading**: Critical CSS/JS preloaded in `<head>`

## Analytics and Observability

- **Plausible Analytics**: Privacy-friendly analytics script in `layouts/app.blade.php`
- **Sentry**: Laravel exception capture, tracing/profiling configuration, optional Sentry log forwarding, and ignored local CLI verification noise are configured in `laravel/bootstrap/app.php`, `laravel/config/sentry.php`, and `laravel/config/logging.php`. See `laravel/AGENTS.md` for env variables and verification commands.

## Navigation Structure

The main navigation uses dropdown menus (desktop) and collapsible sections (mobile):

- **Sähkösopimukset** (dropdown)
  - Vertaa sopimuksia
  - Halvimmat sopimukset
  - Pörssisähkö
  - Määräaikainen
  - Toistaiseksi voimassa
  - Yrityksille
  - Sähköyhtiöt
- **Sähkölaskuri**
- **Aurinkopaneelit**

The header also displays the current spot price via `HeaderSpotPrice` component.

## Documentation Maintenance

`AGENTS.md` files define documentation and context-file CRUD rules for this repository.

### Principles for context files
- Context files are **shortcuts and pointers** for coding agents.
- They should help agents find the right code and concepts **without broad codebase searching first**.
- They are **not substitutes for reading code**.
- They may document important classes, files, and methods with short purpose descriptions to speed up navigation.
- They should document **important decisions**, **constraints**, and **reasons** behind the implementation.
- If there is logic that future sessions **must not change casually**, it **must** be documented in the nearest relevant context file together with the reason.

### Where documentation should live
- Document functionality and decisions **near the code that implements them**.
- Keep root `AGENTS.md` high-level, architectural, and cross-cutting.
- Keep local `AGENTS.md` files scoped to their subtree and rich in implementation detail.
- Prefer several small, local context files over one oversized root-only document.

### CRUD rules for `AGENTS.md` files
After any meaningful domain, data model, import, routing, SEO, matching, or behavioral change:
- update this root `AGENTS.md` if the change affects project-level behavior or architecture
- update the closest existing `AGENTS.md` with implementation details
- if no nearby context file exists, create a new `AGENTS.md` in the nearest sensible directory
- add pointers from broader context files to the more specific one when useful
- keep outdated context files synchronized; if a local file becomes misleading, fix it as part of the same change

### Rule for adding new `AGENTS.md` files
When working in an area that has non-trivial domain logic, import behavior, matching rules, SEO behavior, data model semantics, or other implementation-specific decisions:
- create an `AGENTS.md` in the nearest sensible directory if one does not already exist
- keep it scoped to that subtree
- use parent/root `AGENTS.md` files for broader context and link downward to more specific files
- document notable classes/files and what they are for, as navigation shortcuts
- document important decisions and the reasons behind them
- organize code and context files by **logical feature or domain grouping**, not necessarily one subfolder per service/class
