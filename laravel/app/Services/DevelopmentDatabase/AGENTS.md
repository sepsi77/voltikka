# Development database sync

This directory owns the copy from production MySQL to a fresh local SQLite file.
The public entry point is `scripts/sync-production-database.sh` in the repository root.

## Source transport (VPS since 2026-11-16)

Production MySQL is the Kamal accessory `voltikka-db` on `breezily-vps`. It is not published on the host; only the Docker network `kamal` reaches it. The wrapper therefore:

- reads the container address on that network with `docker inspect` over SSH
- reads `DB_PASSWORD` from the Kamal web role env file (`~/.kamal/apps/voltikka/env/roles/web.env`) over SSH into a shell variable only, and requires exactly one non-empty value
- opens `ssh -N -o BatchMode=yes -o ExitOnForwardFailure=yes -o ControlPath=none -L 127.0.0.1:<free port>:<container address>:3306 breezily-vps` in the background, and waits until that ssh process listens on the local port (`lsof`, at most 20 s)
- gives the copy child `PRODUCTION_MYSQL_URL=mysql://voltikka:<url-encoded password>@127.0.0.1:<port>/voltikka` as a shell prefix assignment, so the value is in that child environment only, not in any argument list
- closes the tunnel after the copy, and again in the `EXIT` cleanup trap on every failure path (`HUP`, `INT` and `TERM` exit through that trap)

`ControlPath=none` keeps the forward in the background ssh process. With SSH multiplexing, a forward stays in the master process after the client is killed.

The script constants `DB_NAME` and `DB_USER` must equal `DB_DATABASE` and `DB_USERNAME` in `config/deploy.yml`; `ProductionDatabaseSyncScriptTest` checks this. On the server, the wrapper runs only these two reads and the tunnel. It never writes to `voltikka-db`.

## Safety rules

- The Artisan command needs `VOLTIKKA_LOCAL_DATABASE_SYNC=1` and an explicit `--target` file.
- The target must exist in `laravel/database`, and its basename must start with `.production-sync-`. It must not have the same device and inode as `database/database.sqlite`.
- Each Artisan process must use `APP_ENV=local`, `DB_CONNECTION=sqlite`, the explicit temporary `DB_DATABASE`, an empty `DB_URL`, and a unique nonexistent `APP_CONFIG_CACHE`. Only the copy child also gets `PRODUCTION_MYSQL_URL`.
- Before migration, run the command's `--verify-target` mode. It must prove through the booted Laravel connection that the effective driver is SQLite and the effective main database is the exact temporary target.
- `ProductionMySqlConnection` reads the source only from `PRODUCTION_MYSQL_URL`. It fails closed when the variable is missing, the scheme is not `mysql`, or the host, port, user, password or database name is missing. There is no fallback: the old Railway `MYSQL_PUBLIC_URL`, `MYSQL*` and `RAILWAY_TCP_PROXY_*` variables are ignored. Do not print the URL, the password or connection errors.
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
