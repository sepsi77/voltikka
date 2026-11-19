# Repository scripts

## Production-to-local database sync

Primary script:
- `sync-production-database.sh`

Run it from the repository root:

```bash
scripts/sync-production-database.sh
scripts/sync-production-database.sh --yes
```

The script reads Voltikka production MySQL on the Breezily VPS (`voltikka-db`, a Kamal accessory that only the Docker network `kamal` reaches) through an SSH tunnel to `breezily-vps`, and replaces the ignored local `laravel/database/database.sqlite` file with a fresh SQLite snapshot. It never writes to production. It replaced the Railway transport on 2026-11-16. A full run on 2026-11-16 took about 6 minutes and gave a 3.5 GB file.

Important safety rules:
- Stop local Laravel servers, queue workers, SQLite shells, and database tools for the full sync. The script checks the database and its sidecars with `lsof`, but that check is advisory.
- On the server, run only the `docker inspect` address read, the `DB_PASSWORD` read from the Kamal web role env file, and the `ssh -N -L` tunnel. Read the password only into a shell variable.
- Give the source URL (`PRODUCTION_MYSQL_URL`) to the copy child only, as a prefix assignment, never as an argument or an exported variable. Never print it, the password, or the env file.
- Close the tunnel after the copy and in the `EXIT` cleanup trap. Keep `ControlPath=none` on the tunnel so that the kill closes the forward.
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
- `../laravel/tests/Unit/ProductionMySqlConnectionTest.php`

Prerequisites are PHP with `pdo_sqlite` and `pdo_mysql`, `ssh` access to `breezily-vps` (Tailscale, user `deploy`, key without a prompt because the script uses `BatchMode=yes`), `sqlite3`, and `lsof`.

## Production image build and deploy (image since 2026-10-21; Kamal on the VPS since 2026-11-15)

Primary scripts:
- `build-and-push-image.sh` builds the root `Dockerfile` for `linux/amd64` from a clean `git archive` export of HEAD and pushes `ghcr.io/sepsi77/voltikka:deploy-NNNNNN-<sha12>`. `--no-push` loads `voltikka:local-<sha12>` locally and never contacts GHCR.
- `deploy-image.sh` deploys one pushed tag to production on `breezily-vps` with `kamal deploy --skip-push --version <TAG>` (both roles; `--role web` or `--role worker` for one), then checks `https://voltikka.fi/up`. It runs only from a checkout whose HEAD is on `origin/main` and whose `config/` and `.kamal/` have no local changes, because Kamal reads `config/deploy.yml` and `.kamal/secrets` from it. The 04:00–09:00 refusal applies when the worker role is deployed, because the worker runs the Temporal `MorningChain`; a web-only deploy (`--role web`) is not refused (see `../orchestration/AGENTS.md` for the worker drain). Railway's `railway-poll-deployment.sh` was removed on 2026-11-16 with the Railway services.

Status: production runs the GHCR image; a push to `main` does not deploy. The release flow is in the root `AGENTS.md` ("Production code deployment"). Running `deploy-image.sh` is a production mutation that needs explicit user confirmation.

Safety rules:
- Build only from the committed export. Never build the production image from the working tree: it holds `laravel/.env`, the local SQLite copy of production data, sessions, and the file cache. The root `.dockerignore` is only a second layer.
- Never push or deploy `latest`, and never overwrite a tag. An explicit tag must end with `-<sha12>` of HEAD.
- Keep the 04:00–09:00 Helsinki refusal in `deploy-image.sh` for the worker role (it runs the Temporal `MorningChain` since the morning group moved; a web-only deploy does not stop it). `--morning-checks-passed` skips only that refusal; use it only after today's EEX import and both morning consumers have a final status (`ready` or a terminal failure, never `running` or `waiting`).
- Keep the `origin/main` and clean `config/`/`.kamal/` checks: a deploy from a feature branch would apply unreviewed Kamal settings to production.
- Keep `--pull` on the `docker buildx build` call. Without it buildx reuses a locally cached `dunglas/frankenphp:1-php8.4`; `deploy-000002` to `deploy-000004` (2026-10-23) were built from a cached 8.4.26 base and put a PHP release with two critical RCE fixes missing back into production. After a build, check the PHP version in the image (`docker run --rm --platform linux/amd64 --entrypoint php <image> -v`).

Decisions and cutover record: `../tasks/prebuilt-image-deploy/decisions.md`.
