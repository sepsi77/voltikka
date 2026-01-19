# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Voltikka is a Finnish electricity contract comparison platform. The codebase is transitioning from a legacy Python/SvelteKit architecture to Laravel, with Python FastAPI retained for calculation services.

## Repository Structure

```
voltikka/
├── ARCHITECTURE.md          # Full architecture documentation and migration plan
├── legacy/
│   ├── python/
│   │   ├── fastapi/         # FastAPI calculation service
│   │   ├── shared/          # Shared database models and utilities (voltikka package)
│   │   └── cloud-functions/ # Google Cloud Functions for data fetching
│   └── voltikka/            # Legacy SvelteKit frontend + Sanity CMS
```

## Build and Run Commands

### FastAPI Service
```bash
cd legacy/python/fastapi
pip install -r requirements.txt
uvicorn api.main:app --reload --port 8000
```

### Shared Package
```bash
cd legacy/python/shared
pip install -e .  # Install in editable mode
python setup.py bdist_wheel  # Build wheel
```

## Architecture

### Database (PostgreSQL on Google Cloud SQL)
- **SQLAlchemy 2.0** ORM with mapped_column syntax
- Key models in `legacy/python/shared/voltikka/database/models.py`:
  - `Company` - Electricity providers
  - `ElectricityContract` - Contract details with pricing, metering types
  - `PriceComponent` - Per-kWh and monthly pricing (General, DayTime, NightTime, SeasonalWinter, SeasonalOther, Monthly)
  - `Postcode` - Finnish postcodes with municipality data
  - `SpotPriceHour` - Hourly Nord Pool spot prices

### Metering Types
Three pricing models handled in `contract_price_calculator.py`:
- **General** - Single rate for all hours
- **Time** - Day (07-22) and night (22-07) rates
- **Seasonal** - Winter day (Nov-Mar, 07-22), other times

### Key Calculation Logic
- `legacy/python/fastapi/api/modules/contract_price_calculator.py` - Calculates annual electricity costs based on contract pricing and consumption patterns
- `legacy/python/fastapi/api/energy_calculator/calculator.py` - Estimates annual electricity consumption based on building characteristics, heating method, appliances

### External APIs
- **Azure Consumer API** - Fetches contract data from Finnish electricity market
- **Elering API** - Nord Pool spot prices for Finland (FI region)
- **OpenAI API** - Generates contract descriptions

## Code Patterns

### Database Sessions
```python
from voltikka.database.database import session_factory
with session_factory() as session:
    # Use session
```

### Pydantic Schemas
- Database schemas: `legacy/python/shared/voltikka/database/schemas.py`
- API schemas: `legacy/python/fastapi/api/local_schemas.py`

### URL Slugs
Models with `name` fields auto-generate `name_slug` via SQLAlchemy events using `voltikka.utils.name_to_slug`.

## Domain Knowledge

- VAT rates: 24% standard (10% was temporary Dec 2022 - Apr 2023)
- Night time hours: 22:00-07:00 (8 hours = 33% of day)
- Winter pricing months: January, February, March, November, December
- Company logos stored in `gs://voltikka-logos/`
