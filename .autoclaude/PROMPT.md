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

## Development Approach: Test-Driven Development (TDD)

**CRITICAL: All development must follow strict TDD practices.**

### TDD Workflow
1. **Write the test FIRST** - Before implementing any functionality, write failing tests that define the expected behavior
2. **Run the test** - Confirm it fails (red phase)
3. **Write minimal code** - Implement only enough code to make the test pass
4. **Run tests again** - Confirm the test passes (green phase)
5. **Refactor** - Clean up the code while keeping tests green
6. **Commit** - Atomic commits with tests always passing

### Testing Requirements
- **Unit Tests**: Required for all models, services, and utility classes
- **Feature Tests**: Required for all API endpoints and web routes
- **Test Coverage**: Aim for 80%+ coverage on business logic
- **Test Location**:
  - `tests/Unit/` for isolated unit tests
  - `tests/Feature/` for integration/feature tests

### Example TDD Cycle for Models
```php
// 1. Write test first (tests/Unit/CompanyTest.php)
public function test_company_generates_slug_from_name(): void
{
    $company = Company::factory()->make(['name' => 'Oy Energia Ab']);
    $this->assertEquals('oy-energia-ab', $company->name_slug);
}

// 2. Run test - it fails
// 3. Implement the slug generation in Company model
// 4. Run test - it passes
// 5. Refactor if needed
// 6. Commit
```

### Testing Ported Calculation Logic
When porting Python calculation logic to PHP:
1. Write tests based on the Python implementation's expected outputs
2. Use the same test cases from legacy tests if available
3. Verify edge cases: zero consumption, seasonal boundaries, discount expiration

## Guidelines

- Follow Laravel conventions and best practices
- Use Eloquent ORM patterns (relationships, scopes, accessors/mutators)
- **ALWAYS write tests BEFORE implementation (TDD)**
- Keep commits atomic and well-documented
- Maintain backward compatibility with existing PostgreSQL database schema
- Use Laravel's built-in features (validation, form requests, resources, etc.)
- Use Laravel's testing helpers: factories, assertions, database transactions

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
