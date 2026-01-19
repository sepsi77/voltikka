# Voltikka Laravel Migration

## Project Overview

Voltikka is a Finnish electricity contract comparison platform. The goal is to migrate from the legacy Python/SvelteKit architecture to a **standalone Laravel application**.

### Current Architecture (Legacy)
- **Frontend**: SvelteKit with Sanity CMS
- **Backend**: Python FastAPI for calculation services
- **Database**: PostgreSQL on Google Cloud SQL
- **Cloud Functions**: Google Cloud Functions for data fetching

### Target Architecture
- **Single Laravel Application** handling:
  - Web frontend (Blade/Livewire or Inertia.js)
  - API endpoints
  - Calculation services (port from Python)
  - Scheduled tasks for data fetching

## Migration Priorities

1. **Database Models** - Recreate SQLAlchemy models as Eloquent models
2. **Calculation Logic** - Port `contract_price_calculator.py` and `energy_calculator/calculator.py` to PHP
3. **External API Integrations** - Azure Consumer API, Elering API, OpenAI API
4. **Frontend** - Rebuild UI in Laravel (Blade/Livewire or Inertia)
5. **Scheduled Tasks** - Replace Cloud Functions with Laravel scheduled commands

## Key Domain Knowledge

- **VAT rates**: 24% standard (10% was temporary Dec 2022 - Apr 2023)
- **Night time hours**: 22:00-07:00 (8 hours = 33% of day)
- **Winter pricing months**: January, February, March, November, December
- **Metering types**: General (single rate), Time (day/night), Seasonal (winter/other)

## Database Models to Migrate

From `legacy/python/shared/voltikka/database/models.py`:
- `Company` - Electricity providers
- `ElectricityContract` - Contract details with pricing, metering types
- `PriceComponent` - Per-kWh and monthly pricing (General, DayTime, NightTime, SeasonalWinter, SeasonalOther, Monthly)
- `Postcode` - Finnish postcodes with municipality data
- `SpotPriceHour` - Hourly Nord Pool spot prices

## Guidelines

- Follow Laravel conventions and best practices
- Use Eloquent ORM patterns (relationships, scopes, accessors/mutators)
- Write Feature and Unit tests for ported calculation logic
- Keep commits atomic and well-documented
- Maintain backward compatibility with existing PostgreSQL database schema
- Use Laravel's built-in features (validation, form requests, resources, etc.)

## Important Reference Files

### Legacy Python (source for migration)
- `legacy/python/shared/voltikka/database/models.py` - Database models
- `legacy/python/shared/voltikka/database/schemas.py` - Pydantic schemas
- `legacy/python/fastapi/api/modules/contract_price_calculator.py` - Price calculation logic
- `legacy/python/fastapi/api/energy_calculator/calculator.py` - Energy consumption estimation
- `legacy/python/cloud-functions/` - Data fetching functions

### Documentation
- `ARCHITECTURE.md` - Full architecture documentation and migration plan
- `CLAUDE.md` - Project context and code patterns
