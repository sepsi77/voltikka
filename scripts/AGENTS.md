# Repository scripts

## Production-to-local database sync

Primary script:
- `sync-production-database.sh`

Run it from the repository root:

```bash
scripts/sync-production-database.sh
scripts/sync-production-database.sh --yes
```

The script reads Voltikka production MySQL through Railway and replaces the ignored local `laravel/database/database.sqlite` file with a fresh SQLite snapshot. It never writes to production.

Important safety rules:
- Stop local Laravel servers, queue workers, SQLite shells, and database tools for the full sync. The script checks the database and its sidecars with `lsof`, but that check is advisory.
- Use the explicit Railway project, production environment, and MySQL service IDs already stored in the script. Do not rely on the linked Railway shell context.
- Keep `RAILWAY_CALLER=skill:use-railway@1.2.2` and one stable `RAILWAY_AGENT_SESSION` on the Railway call.
- Never print Railway variables, `MYSQL_PUBLIC_URL`, or other database credentials.
- Keep every local Artisan child isolated from cached configuration and `DB_URL`. It must prove that the effective connection is the explicit temporary SQLite target before migration.
- Production reads must stay in one read-only consistent transaction.
- Do not copy authentication, cache, session, queue, social-publication, or freshness-ledger rows.
- Schema drift fails closed. The only explicit exception is the local-derived `contract_source_observations` table while production lacks it; the fresh target reconstructs it with the existing migration logic.
- Do not replace the active database until row counts, foreign keys, SQLite integrity, and the timestamped SQLite backup have passed validation.
- Preserve the same-filesystem temporary file, SQLite backup, WAL checkpoint, sidecar removal, final use check, and atomic rename sequence.

Implementation details and tests:
- `../laravel/app/Console/Commands/SyncProductionDatabase.php`
- `../laravel/app/Services/DevelopmentDatabase/AGENTS.md`
- `../laravel/tests/Feature/SyncProductionDatabaseCommandTest.php`
- `../laravel/tests/Feature/ProductionSchemaLagDatabaseSyncTest.php`
- `../laravel/tests/Unit/DevelopmentDatabaseSynchronizerTest.php`
- `../laravel/tests/Unit/ProductionDatabaseSyncScriptTest.php`

Prerequisites are PHP with `pdo_sqlite` and `pdo_mysql`, an authenticated Railway CLI, `sqlite3`, and `lsof`.
