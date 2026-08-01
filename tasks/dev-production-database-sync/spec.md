# Development production database sync

## Goal

Provide one repeatable local script that replaces the ignored development SQLite database with an up-to-date, read-only snapshot of the Voltikka production MySQL application data from Railway.

## Requirements

- Read production only. Never write to production.
- Use explicit Voltikka production Railway project, environment, and MySQL service IDs.
- Never print or persist production credentials.
- Isolate every Artisan child from cached config and inherited database URLs, then prove the effective SQLite target before migration.
- Build a fresh migrated SQLite database before copying data.
- Exclude authentication and operational runtime tables that are not needed for public-site testing.
- Copy common application tables with bounded memory use and fail on non-excluded schema drift in either direction.
- Support the explicit temporary production schema lag where only the local-derived `contract_source_observations` table is absent. Reconstruct observations and current pointers with the existing migration chronology logic; copy the table normally when production has it.
- Validate row counts, SQLite integrity, and foreign keys after any local reconstruction and before replacing the local database.
- Back up the current local SQLite database before an atomic replacement.
- Accept only wrapper-shaped temporary targets in `laravel/database`, reject aliases of the active database, and refuse to replace a database that is in use by a local process.
- Document prerequisites and normal usage.
- Add focused automated tests for safety guards and data copying.
