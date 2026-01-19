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
