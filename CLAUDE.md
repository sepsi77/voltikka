# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Voltikka is a Finnish electricity contract comparison platform. The primary application is now **Laravel 11 with Livewire 3**, with Python FastAPI retained for spot price data.

## Repository Structure

```
voltikka/
├── laravel/                 # Main Laravel 11 application (PRIMARY)
│   ├── app/
│   │   ├── Livewire/        # Livewire components (ContractsList, SpotPrice, etc.)
│   │   ├── Models/          # Eloquent models
│   │   ├── Services/        # Business logic (ContractPriceCalculator)
│   │   └── Console/Commands/ # Artisan commands (FetchContracts)
│   ├── resources/views/     # Blade templates
│   ├── database/migrations/ # Database migrations
│   └── data-investigation/  # API structure documentation
├── legacy/
│   ├── python/
│   │   ├── fastapi/         # FastAPI for spot prices (still in use)
│   │   ├── shared/          # Shared Python utilities
│   │   └── cloud-functions/ # Google Cloud Functions
│   └── voltikka/            # Legacy SvelteKit (deprecated)
```

## Build and Run Commands

### Laravel Application (Primary)
```bash
cd laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve  # Runs on http://127.0.0.1:8000
```

### Fetch Contract Data from Azure API
```bash
cd laravel
php artisan contracts:fetch  # Fetches and updates all contracts
```

### Run Tests
```bash
cd laravel
php artisan test
php artisan test --filter="ContractsFilterTest"  # Specific test
```

### FastAPI Service (Spot Prices)
```bash
cd legacy/python/fastapi
pip install -r requirements.txt
uvicorn api.main:app --reload --port 8000
```

## Laravel Architecture

### Key Models (`app/Models/`)
- **ElectricityContract** - Contract with pricing_model, target_group, metering
- **Company** - Electricity provider (primary key: name)
- **PriceComponent** - Pricing (Monthly, General, DayTime, NightTime, SeasonalWinter, SeasonalOther)
- **ElectricitySource** - Energy mix (renewable, fossil, nuclear percentages)
- **Postcode** - Finnish postcodes with municipality data

### Key Livewire Components (`app/Livewire/`)
- **ContractsList** - Main contracts listing with filters
- **ContractDetail** - Single contract view
- **CompanyDetail** - Company profile with their contracts
- **LocationsList** - Browse by municipality
- **SpotPrice** - Current spot price display

### Important Fields

#### ElectricityContract
| Field | Values | Description |
|-------|--------|-------------|
| `pricing_model` | Spot, FixedPrice, Hybrid | **Use this for filtering spot contracts** |
| `target_group` | Household, Company, Both | Consumer vs business |
| `contract_type` | OpenEnded, FixedTerm | Contract duration type |
| `metering` | General, Time, Season | Tariff structure |

#### PriceComponent Types
| Type | Description |
|------|-------------|
| Monthly | Fixed monthly fee (€/month) |
| General | Single rate (c/kWh) |
| DayTime | Day rate 07-22 (c/kWh) |
| NightTime | Night rate 22-07 (c/kWh) |
| SeasonalWinter | Winter rate Nov-Mar (c/kWh) |
| SeasonalOther | Other seasons (c/kWh) |

## External APIs

### Azure Consumer API (Contract Data)
- Endpoint: `https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/{postcode}`
- Returns full contract details including PricingModel, TargetGroup
- Structure documented in `laravel/data-investigation/contract-structure.md`

### Spot Prices
- **TODO**: The legacy FastAPI service is no longer live
- The `SpotPrice` Livewire component needs a new data source
- Options: Elering API, ENTSO-E, or store in database via scheduled fetch
- Elering API: `https://dashboard.elering.ee/api/nps/price?start={date}&end={date}`

## Domain Knowledge

- **VAT rate**: 25.5% (changed from 24% in September 2024)
- **Night hours**: 22:00-07:00 (8 hours = 33% of day)
- **Winter months**: January, February, March, November, December
- **Spot contracts**: Identified by `pricing_model = 'Spot'`, NOT by name matching
- **Company logos**: `gs://voltikka-logos/`

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

## Routes

| Route | Component | Description |
|-------|-----------|-------------|
| `/` | ContractsList | Main contracts listing |
| `/sopimus/{id}` | ContractDetail | Contract detail page |
| `/yritys/{slug}` | CompanyDetail | Company profile |
| `/paikkakunnat/{location?}` | LocationsList | Browse by location |
| `/spot-price` | SpotPrice | Current spot prices |
