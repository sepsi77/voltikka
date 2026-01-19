# Voltikka - Electricity Comparison Platform

## Project Overview

Voltikka is a Finnish electricity contract comparison website that helps consumers find the best electricity deals based on their consumption patterns, location, and preferences.

### Core Value Proposition
- Compare electricity contracts across all Finnish providers
- Calculate accurate annual costs based on actual consumption patterns
- Filter by contract type (fixed, spot, open-ended) and energy source
- Real-time spot price tracking and visualization

---

## Architecture

### Hybrid Monolith Approach

```
┌──────────────────────────────────────────────────────────────┐
│                      Laravel Application                      │
│                                                              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │   Routes    │  │   Views     │  │   Controllers       │  │
│  │  (web.php)  │  │  (Blade/    │  │                     │  │
│  │             │  │   Inertia)  │  │                     │  │
│  └─────────────┘  └─────────────┘  └─────────────────────┘  │
│                                                              │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │  Eloquent   │  │   Queue     │  │   Scheduler         │  │
│  │   Models    │  │   Jobs      │  │   (Cron tasks)      │  │
│  └─────────────┘  └─────────────┘  └─────────────────────┘  │
│                                                              │
└────────────────────────────┬─────────────────────────────────┘
                             │
                             │ HTTP (internal)
                             ▼
┌──────────────────────────────────────────────────────────────┐
│              Python Calculation Service (FastAPI)             │
│                                                              │
│  ┌─────────────────────────┐  ┌───────────────────────────┐ │
│  │  Contract Price         │  │  Energy Consumption       │ │
│  │  Calculator             │  │  Estimator                │ │
│  │                         │  │                           │ │
│  │  - General metering     │  │  - Building type/size     │ │
│  │  - Time-of-use metering │  │  - Heating method         │ │
│  │  - Seasonal metering    │  │  - Regional climate       │ │
│  │  - Spot price margins   │  │  - Appliances (EV, sauna) │ │
│  └─────────────────────────┘  └───────────────────────────┘ │
│                                                              │
└──────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────┐
│                     PostgreSQL Database                       │
│                    (Google Cloud SQL)                        │
└──────────────────────────────────────────────────────────────┘
```

### Tech Stack

| Layer | Technology | Notes |
|-------|------------|-------|
| **Framework** | Laravel 11 | PHP 8.3+ |
| **Frontend** | Inertia.js + Vue 3 | Or Livewire for simpler reactivity |
| **Styling** | Tailwind CSS | Already familiar from SvelteKit version |
| **Database** | PostgreSQL | Reuse existing Cloud SQL instance |
| **Cache** | Redis | Contract calculations, session storage |
| **Queue** | Laravel Horizon + Redis | Background job processing |
| **Calculation Service** | FastAPI (Python) | Extracted from legacy backend |
| **Deployment** | Laravel Forge or Docker | Single server initially |

---

## Database Schema

### Core Tables

#### `companies`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | varchar | Company name (unique) |
| name_slug | varchar | URL-friendly slug |
| company_url | varchar | Company website |
| address | varchar | Street address |
| postal_code | varchar | Postal code |
| logo_url | varchar | Path to logo image |
| created_at | timestamp | |
| updated_at | timestamp | |

#### `electricity_contracts`
| Column | Type | Description |
|--------|------|-------------|
| id | varchar | External ID from data source |
| company_id | bigint | FK to companies |
| name | varchar | Contract name |
| contract_type | enum | 'fixed', 'spot', 'open_ended' |
| metering_type | enum | 'general', 'time', 'seasonal' |
| fixed_period_months | int | For fixed contracts |
| min_consumption_kwh | int | Minimum annual consumption |
| max_consumption_kwh | int | Maximum annual consumption |
| short_description | json | Multi-language {fi, sv, en} |
| long_description | json | Multi-language |
| extra_info | json | Additional details |
| microproduction_info | json | Solar buyback terms |
| billing_frequency | json | Billing options |
| is_active | boolean | Currently available |
| created_at | timestamp | |
| updated_at | timestamp | |

#### `price_components`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| contract_id | varchar | FK to electricity_contracts |
| price_date | date | Effective date |
| component_type | enum | 'monthly', 'general', 'day', 'night', 'seasonal_winter', 'seasonal_other' |
| price_cents | decimal | Price in cents/kWh or cents/month |
| discount_value | decimal | Discount amount |
| discount_type | enum | 'fixed', 'percentage' |
| discount_months | int | Duration of discount |
| created_at | timestamp | |

#### `electricity_sources`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| contract_id | varchar | FK to electricity_contracts |
| renewable_total | decimal | % renewable |
| renewable_wind | decimal | % wind |
| renewable_solar | decimal | % solar |
| renewable_hydro | decimal | % hydro |
| renewable_biomass | decimal | % biomass |
| nuclear_total | decimal | % nuclear |
| fossil_total | decimal | % fossil |
| created_at | timestamp | |

#### `postcodes`
| Column | Type | Description |
|--------|------|-------------|
| postcode | varchar(5) | Primary key |
| name_fi | varchar | Finnish name |
| name_sv | varchar | Swedish name |
| municipality_code | varchar | Municipal code |
| region | enum | 'south', 'central', 'north' |

#### `contract_postcode` (pivot)
| Column | Type |
|--------|------|
| contract_id | varchar |
| postcode | varchar(5) |

#### `spot_price_hours`
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| region | varchar | 'FI' for Finland |
| timestamp | timestamp | Hour timestamp (UTC) |
| price_cents | decimal | Price in cents/kWh |
| vat_rate | decimal | VAT rate (0.10 or 0.24) |
| created_at | timestamp | |

---

## Features

### Phase 1: Core Comparison (MVP)

- [ ] **Contract Listing Page**
  - Display all active contracts
  - Sort by calculated annual cost
  - Show company logo, contract name, price breakdown
  - Electricity source badges (wind, nuclear, renewable)

- [ ] **Basic Filtering**
  - Contract type (fixed, spot, open-ended)
  - Location (postcode-based availability)
  - Energy source preferences

- [ ] **Consumption Presets**
  - Studio (2,000 kWh/year)
  - Apartment (5,000 kWh/year)
  - Row house (8,000 kWh/year)
  - House without electric heating (12,000 kWh/year)
  - House with electric heating (20,000 kWh/year)

- [ ] **Contract Detail Page**
  - Full pricing breakdown
  - Company information
  - Terms and conditions
  - Link to provider

### Phase 2: Advanced Features

- [ ] **Consumption Calculator**
  - Building type and size
  - Number of residents
  - Heating method and efficiency
  - Supplementary heating (heat pump, fireplace)
  - Special appliances (EV, sauna, pool)
  - Regional climate adjustment

- [ ] **Price History Charts**
  - Contract price trends over time
  - Comparison across contract types

- [ ] **Spot Price Dashboard**
  - Current hourly price
  - Today's price curve
  - Cheapest hours indicator
  - Historical daily averages

- [ ] **Company Pages**
  - All contracts from a provider
  - Company information

- [ ] **Location Pages**
  - Contracts available in specific area
  - Regional pricing insights

### Phase 3: Enhancements

- [ ] **User Accounts** (optional)
  - Save preferences
  - Price alerts
  - Comparison history

- [ ] **Blog/Content**
  - Energy saving tips
  - Market news
  - Contract guides

- [ ] **Multi-language Support**
  - Finnish (primary)
  - Swedish
  - English

---

## Scheduled Jobs

### Data Fetching (replaces Cloud Functions)

| Job | Schedule | Description |
|-----|----------|-------------|
| `FetchContracts` | Daily 06:00 | Fetch contract data from Azure API for all postcodes |
| `FetchSpotPrices` | Hourly | Fetch day-ahead prices from Elering API |
| `UpdateActiveContracts` | Daily 07:00 | Mark active/inactive contracts |
| `GenerateDescriptions` | Daily 08:00 | Generate missing descriptions via OpenAI |
| `CachePopularResults` | Daily 09:00 | Pre-calculate costs for preset consumption profiles |
| `CleanupOldPrices` | Weekly | Archive old price component data |

### Implementation

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->job(new FetchContracts)->dailyAt('06:00');
    $schedule->job(new FetchSpotPrices)->hourly();
    $schedule->job(new UpdateActiveContracts)->dailyAt('07:00');
    $schedule->job(new GenerateDescriptions)->dailyAt('08:00');
    $schedule->job(new CachePopularResults)->dailyAt('09:00');
    $schedule->job(new CleanupOldPrices)->weekly();
}
```

---

## External APIs

### Data Sources

| API | Purpose | Frequency |
|-----|---------|-----------|
| **Azure Consumer API** | Contract and pricing data | Daily |
| `ev-shv-prod-app-wa-consumerapi1.azurewebsites.net` | | |
| **Elering API** | Nord Pool spot prices | Hourly |
| `dashboard.elering.ee/api/nps/price` | | |
| **OpenAI API** | Generate contract descriptions | As needed |

### Internal Services

| Service | Purpose |
|---------|---------|
| **Python Calculation Service** | Price calculations and consumption estimates |
| Endpoint: `/calculate-price` | Takes contract + consumption, returns annual cost |
| Endpoint: `/estimate-consumption` | Takes building params, returns kWh estimate |

---

## Python Calculation Service

### Extracted from Legacy Backend

The calculation service is a minimal FastAPI app containing only the pricing and consumption logic.

### Endpoints

#### `POST /calculate-price`
```json
// Request
{
  "contract": {
    "id": "contract-123",
    "metering_type": "time",
    "contract_type": "fixed",
    "price_components": [...]
  },
  "consumption": {
    "annual_kwh": 12000,
    "night_share": 0.35,
    "monthly_distribution": [...]
  },
  "spot_average": 5.5  // For spot contracts
}

// Response
{
  "total_annual_cost": 1450.50,
  "average_monthly_cost": 120.88,
  "monthly_breakdown": [...],
  "component_costs": {
    "variable": 1200.00,
    "fixed": 250.50
  }
}
```

#### `POST /estimate-consumption`
```json
// Request
{
  "building": {
    "type": "detached_house",
    "area_sqm": 150,
    "residents": 4,
    "efficiency_rating": "2010s",
    "region": "south"
  },
  "heating": {
    "primary": "ground_heat_pump",
    "supplementary": ["fireplace"]
  },
  "appliances": {
    "ev_km_weekly": 200,
    "has_sauna": true,
    "sauna_sessions_weekly": 2,
    "has_pool": false
  }
}

// Response
{
  "annual_kwh": 18500,
  "monthly_kwh": [...],
  "breakdown": {
    "heating": 8000,
    "water_heating": 3500,
    "appliances": 4000,
    "ev": 2000,
    "sauna": 1000
  }
}
```

---

## Project Structure

```
electricity-comparison/
├── ARCHITECTURE.md          # This file
├── legacy/                  # Old codebase (reference only)
│   ├── python/              # FastAPI backend + cloud functions
│   └── voltikka/            # SvelteKit frontend + Sanity CMS
│
├── laravel/                 # New Laravel application
│   ├── app/
│   │   ├── Console/         # Scheduled jobs
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   └── Requests/
│   │   ├── Jobs/            # Queue jobs (FetchContracts, etc.)
│   │   ├── Models/          # Eloquent models
│   │   └── Services/        # Business logic
│   │       └── CalculationService.php  # HTTP client to Python
│   ├── database/
│   │   └── migrations/
│   ├── resources/
│   │   └── js/              # Inertia + Vue components
│   └── routes/
│
└── calculation-service/     # Extracted Python service
    ├── app/
    │   ├── main.py          # FastAPI app
    │   ├── calculator.py    # Price calculation logic
    │   └── estimator.py     # Consumption estimation logic
    ├── Dockerfile
    └── requirements.txt
```

---

## Migration Plan

### Step 1: Extract Calculation Service
- [ ] Create `calculation-service/` from legacy FastAPI
- [ ] Keep only calculator and estimator modules
- [ ] Add `/calculate-price` and `/estimate-consumption` endpoints
- [ ] Test with existing data

### Step 2: Scaffold Laravel App
- [ ] Create new Laravel 11 project
- [ ] Configure PostgreSQL connection to Cloud SQL
- [ ] Create Eloquent models matching existing schema
- [ ] Verify database connectivity

### Step 3: Build Core Features
- [ ] Contract listing with filtering
- [ ] Consumption preset selector
- [ ] Integration with calculation service
- [ ] Contract detail pages

### Step 4: Implement Scheduled Jobs
- [ ] Port Azure API fetching logic
- [ ] Port Elering spot price fetching
- [ ] Port OpenAI description generation
- [ ] Set up Laravel Horizon for monitoring

### Step 5: Frontend Polish
- [ ] Responsive design
- [ ] Price charts (Chart.js or similar)
- [ ] SEO optimization
- [ ] Performance tuning

### Step 6: Deployment
- [ ] Set up Laravel Forge (or Docker)
- [ ] Deploy calculation service
- [ ] Configure Redis and queues
- [ ] DNS and SSL

---

## Environment Variables

```env
# Laravel
APP_NAME=Voltikka
APP_ENV=production
APP_URL=https://voltikka.fi

# Database
DB_CONNECTION=pgsql
DB_HOST=cloud-sql-proxy
DB_DATABASE=electricity_comparison
DB_USERNAME=
DB_PASSWORD=

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Calculation Service
CALCULATION_SERVICE_URL=http://127.0.0.1:8000

# External APIs
AZURE_API_BASE_URL=https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net
ELERING_API_URL=https://dashboard.elering.ee/api/nps/price
OPENAI_API_KEY=

# Storage
GCS_BUCKET=voltikka-cache
GCS_LOGOS_BUCKET=voltikka-logos
```

---

## Notes

- The existing PostgreSQL database in Cloud SQL can be reused directly
- Company logos are stored in `gs://voltikka-logos/`
- Pre-cached results stored in `gs://voltikka-cache/`
- VAT rates: 24% standard, 10% was temporary (Dec 2022 - Apr 2023)
- Spot prices are for Finland (FI) region from Nord Pool
