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
