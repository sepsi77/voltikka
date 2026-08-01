# Development database sync

This directory owns the copy from production MySQL to a fresh local SQLite file.
The public entry point is `scripts/sync-production-database.sh` in the repository root.

## Safety rules

- The Artisan command needs `VOLTIKKA_LOCAL_DATABASE_SYNC=1` and an explicit `--target` file.
- The target must exist in `laravel/database`, and its basename must start with `.production-sync-`. It must not have the same device and inode as `database/database.sqlite`.
- Each Artisan process must use `APP_ENV=local`, `DB_CONNECTION=sqlite`, the explicit temporary `DB_DATABASE`, an empty `DB_URL`, and a unique nonexistent `APP_CONFIG_CACHE`. Put these variables in the Railway child `env` command so they override Railway injection without removing injected `MYSQL_PUBLIC_URL` or `MYSQL*` source credentials.
- Before migration, run the command's `--verify-target` mode. It must prove through the booted Laravel connection that the effective driver is SQLite and the effective main database is the exact temporary target.
- `ProductionMySqlConnection` reads credentials only from Railway-injected variables. Do not print these variables or connection errors.
- Prefer `MYSQL_PUBLIC_URL`. The fallback uses `MYSQLHOST` or `RAILWAY_TCP_PROXY_DOMAIN` and their related `MYSQL*` fields.
- Start the MySQL read with `REPEATABLE READ` and `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` before all source queries.
- Keep MySQL PDO queries unbuffered. Copy one row at a time.
- Compare both migrated SQLite and production table lists after exclusions. Schema drift must fail in both directions, except that production can temporarily lack the one local-derived `contract_source_observations` table. A production-only application table still fails closed.
- When production lacks `contract_source_observations`, copy snapshots and contracts first. Then `ContractSourceObservationRebuilder` runs the existing `2026_07_30_000002_backfill_contract_source_observations.php` migration logic against the fresh target. This preserves the migration's chronology algorithm and reconstructs current pointers before final row-count, foreign-key, and SQLite integrity validation.
- If production contains `contract_source_observations`, copy it normally. Do not rebuild it.
- Preserve the target `migrations` table. Do not copy authentication, session, cache, queue, social-publication, or freshness-checkpoint tables.
- A target primary key or a required column without a default must exist in the source. Optional new target columns can use their SQLite defaults.
- Foreign keys can be off only during the target load. Row counts, `foreign_key_check`, and `integrity_check` must pass after the load.

The shell script owns migration, backup, and replacement. The Artisan command never replaces the active local database.

The wrapper must not open or change the active database until the fresh target has passed the command's sync validation. After that validation, it must:

- require `sqlite3` and use its backup mechanism instead of copying only the main file, because committed data can remain in a closed database's WAL file
- check `lsof` for the main file and its `-wal`, `-shm`, and `-journal` sidecars
- checkpoint the fresh target before it discards the target sidecars
- create and integrity-check the old database backup before replacement
- checkpoint the old database only after the second use check, check use again, remove all old sidecars, and make the final possible `lsof` check immediately before the atomic main-file replacement

`lsof` cannot remove the race between one check and a later open. The operator must stop Laravel, queue workers, and database tools for the full workflow.

These steps prevent an incomplete backup and prevent an old WAL or journal from being applied to the fresh database.
