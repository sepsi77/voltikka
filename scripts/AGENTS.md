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

## Production image build and deploy (active since 2026-10-21)

Primary scripts:
- `build-and-push-image.sh` builds the root `Dockerfile` for `linux/amd64` from a clean `git archive` export of HEAD and pushes `ghcr.io/sepsi77/voltikka:deploy-NNNNNN-<sha12>`. `--no-push` loads `voltikka:local-<sha12>` locally and never contacts GHCR.
- `deploy-image.sh` patches the production app service `source.image` to one pushed tag with `railway environment edit` and the explicit Railway IDs.

Status: since 2026-10-21 the production service source is the GHCR image; a push to `main` does not deploy. The release flow is in the root `AGENTS.md` ("Production code deployment"). Running `deploy-image.sh` is a production mutation that needs explicit user confirmation.

Safety rules:
- Build only from the committed export. Never build the production image from the working tree: it holds `laravel/.env`, the local SQLite copy of production data, sessions, and the file cache. The root `.dockerignore` is only a second layer.
- Never push or deploy `latest`, and never overwrite a tag. An explicit tag must end with `-<sha12>` of HEAD.
- Keep the 04:00–09:00 Helsinki refusal in `deploy-image.sh`.

Decisions and cutover record: `../tasks/prebuilt-image-deploy/decisions.md`.
