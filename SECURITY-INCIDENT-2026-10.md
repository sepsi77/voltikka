# Security incident log: PHP release and Railway breach (October 2026)

This file tracks two events that started on 2026-10-21 and the work done for them. Keep it current until every open item is closed. All times are UTC. Never put a secret value in this file.

| Event | Summary | Status |
|---|---|---|
| A. PHP critical security release | CVE-2026-109618 (RCE through OOM corruption) and CVE-2026-109619 (RCE through stale global state). Fixed in PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12. FrankenPHP is affected. | Closed again 2026-10-26 01:53: Voltikka `deploy-000005` runs PHP 8.4.27. Voltikka ran 8.4.26 from 2026-10-23 16:31 to 2026-10-26 01:53 (stale build cache); first closed 2026-10-22 08:03. |
| B. Railway security incident [QH8L8B3E](https://status.railway.com/incident/QH8L8B3E) | An unauthorized party probably got access to stored environment variables, some of them decrypted. Railway paused all builds and deployments. Railway recommends rolling all variables and registry credentials. | Open. Rotation in progress. |

Railway disclosure: <https://blog.railway.com/p/security-disclosure-wednesday-october-21st>

## Affected sites and projects

| Workspace | Project | Services | Notes |
|---|---|---|---|
| Breezily | Voltikka | voltikka, MySQL | PHP 8.4 site |
| Breezily | Likaiset sivut | likaiset-sivut, MySQL | PHP 8.2 site |
| Breezily | Portfolio tracker | portfolio-tracker, Postgres, astonishing-light | GitHub repo source |
| Breezily | gasum-prompt-tracking | dashboard | |
| Clickbuddy (other organisation) | Clickbuddy marketing website | clickbuddy-website-ghcr, Postgres | PHP 8.4 site |
| Clickbuddy | Clickbuddy | cb-api, main-worker, cb-frontend, datashuttle, datashuttle-worker, daily-sync-worker, temporal-*, admin-tools, cb-pg, ds-pg, ds-dwh-pg, temporal-pg, Redis | Main product |
| Clickbuddy | LLM content writing evaluator | writing-evaluator, Postgres | GitHub repo source. To be deleted (user decision 2026-10-22) |
| Clickbuddy | LLM Expert Panel | llm-expert-panel, MySQL | GitHub repo source. To be deleted (user decision 2026-10-22) |

## Timeline

| Time (UTC) | Event |
|---|---|
| 2026-10-21 (day) | PHP publishes the security release. Upstream FrankenPHP images are not yet available. |
| 2026-10-21 ≈13:40 | Railway pauses builds and deployments (first shown as an outage). |
| 2026-10-21 afternoon | We build our own FrankenPHP bases from the docker-library/php ZTS Dockerfiles (SHA256 and GPG verified) and fixed images for all three PHP sites. Voltikka and likaisetsivut move from GitHub auto-deploy to GHCR image deploys. All three services point to the fixed images; no deployment can start. |
| 2026-10-21 | Clickbuddy image `deploy-000008` adds the mitigation `session.upload_progress.enabled=0` (kept in later images). |
| 2026-10-21 17:46 | Upstream FrankenPHP publishes `1-php8.4.27`, `1-php8.4.27-alpine`, `1-php8.2.35-trixie`. Same PHP 8.4.27/8.2.35, FrankenPHP v1.13.1, Caddy v2.11.7 as our bases, so no rebuild. |
| 2026-10-21 19:30 | Railway starts to bring builds back in stages (Enterprise first, then Pro, Hobby, Free/Trial). |
| 2026-10-21 22:10 | Railway publishes the security notice: roll all environment variables, API keys and private registry credentials. Deployments stay paused for all plans. |
| 2026-10-21 22:37 | Railway rotates all bucket credentials. |
| 2026-10-22 ≈01:00 | Railway removes GitHub tokens stored as service registry credentials and invalidates GitHub account connections. Sessions are signed out. |
| 2026-10-22 03:00 | Railway: builds remain paused. |
| 2026-10-22 ≈03:40 | We: all GitHub tokens deleted on GitHub; one new `read:packages` token created (stored only in local `laravel/.env`). All AI service API keys revoked. |
| 2026-10-22 ≈03:55 | We: new token set as registry credentials on voltikka, likaiset-sivut and clickbuddy-website-ghcr. Variable inventory of all 8 projects made (names only). |
| 2026-10-22 ≈04:15 | We: new token on the six other Clickbuddy image services. Voltikka bucket keys changed to references to the rotated `voltikka-backups` credentials; confirmed that the 2026-10-22 backup failed. |
| 2026-10-22 06:01 | Railway: builds are available for Enterprise only; Pro, Hobby, then Free/Trial still to come. No new deployment on the three PHP services. |
| 2026-10-22 06:29 | Railway: builds available for Pro and Enterprise. Breezily and Clickbuddy are Pro. Railway did not start deployments on its own; the three services still point to the fixed images with nothing staged. |
| 2026-10-22 07:44 | We: deployments of the current config started (`serviceInstanceDeployV2`): voltikka `897a3e71-79b5-4aa6-a78c-55ee17436ba6`, likaiset-sivut `70d43cef-d7ad-4445-a685-359aecd69d2a`, clickbuddy-website-ghcr `bce9e8fd-8d18-4b25-b2ac-fd39d81d26ec`. |
| 2026-10-22 07:47 | Voltikka (PHP 8.4.27) and Likaiset sivut (PHP 8.2.35) deployments SUCCESS; site checks pass. Voltikka now runs with the new bucket key references. Clickbuddy deployment waits at INITIALIZING. |
| 2026-10-22 07:56–08:03 | User: MySQL password regenerated (Railway Regenerate) in Voltikka and Likaiset sivut; OpenRouter and PostFast keys rotated in Voltikka. Both apps redeployed (Voltikka 08:02, Likaiset sivut 08:03); pages that read the database return 200. |
| 2026-10-22 08:03 | Clickbuddy marketing deployment SUCCESS, PHP 8.4.27; home page, `/about-us` and build assets return 200. All three PHP sites are patched. |
| 2026-10-22 08:07 | We: new `APP_KEY` on voltikka and likaiset-sivut (no `APP_PREVIOUS_KEYS`), one deployment each (voltikka `9449d1b8-2a0c-49eb-9382-799b9800d773`, likaiset-sivut `b6b13224-00f5-4591-80e0-5bdbfad23c8a`). Both SUCCESS 08:08; pages and `/admin/login` return 200. |
| 2026-10-22 08:09 | We: old Voltikka backup password copied to `BACKUP_ARCHIVE_PASSWORD_UNTIL_2026_10_22`; new `BACKUP_ARCHIVE_PASSWORD` set; deployment `e72a4084-7777-497e-8dd5-4efa7cd74c9d`. |
| 2026-10-22 08:18 | User: `ENTSOE_API_KEY`, `DIGITRANSIT_API_KEY`, `SENTRY_LARAVEL_DSN` rotated in Voltikka; deployment SUCCESS. Likaiset sivut confirmed to have no API keys in Railway. |
| 2026-10-22 08:35–08:42 | User: Clickbuddy marketing Postgres password regenerated (site returned 500 from about 08:36 until the app redeploy) and `CLICKUP_API_KEY` rotated; app deployment `ec886d74-c917-4d23-bd38-b7d88d6b2651` SUCCESS. |
| 2026-10-22 08:43 | We: new `APP_KEY` (no `APP_PREVIOUS_KEYS`) and `CONTENT_PREVIEW_SECRET` on clickbuddy-website-ghcr; deployment `095debbb-c79d-4ed4-8d09-b418df9003bb` SUCCESS 08:44; home page, `/about-us`, CSS and `/up` return 200; no errors in its logs. |
| 2026-10-25 11:30 | We: found that Voltikka `deploy-000002` to `deploy-000004` (deployed from 2026-10-23 16:31) run PHP 8.4.26. The build used a stale locally cached `dunglas/frankenphp:1-php8.4`. Build script now uses `--pull`; a fixed image needs a build and deploy. Likaiset sivut and Clickbuddy builds must be checked for the same cache problem before their next build. |
| 2026-10-26 01:42 | We: live check. `X-Powered-By`: voltikka.fi PHP/8.4.26, likaisetsivut.fi PHP/8.2.35, clickbuddy.com PHP/8.4.27. Railway images unchanged since 2026-10-22 for Likaiset sivut (`deploy-000001-0044e5c22e09`, 8.2.35) and Clickbuddy marketing (`deploy-000009-598ee08ba488`, 8.4.27). No other service in the six Railway projects runs PHP (Portfolio tracker, gasum dashboards and Clickbuddy app services are Node/Python or third-party images). |
| 2026-10-26 01:53 | We: Voltikka `deploy-000005-1d9301e66c55` (commit 1d9301e, built with `--pull`, `php -v` 8.4.27) deployed, deployment `d8c22d03-7d81-417f-9d8b-93e00dc5c806` SUCCESS. `/up`, `/sahkosopimus` and the CSS asset return 200 with `X-Powered-By: PHP/8.4.27`. |

## A. PHP patch status

| Site | Fixed image (Railway `source.image`) | PHP live now | Target | Deployed |
|---|---|---|---|---|
| Voltikka | `ghcr.io/sepsi77/voltikka:deploy-000005-1d9301e66c55` (2026-10-26 01:53) | 8.4.27 | 8.4.27 | [x] 2026-10-26 01:53 |
| Likaiset sivut | `ghcr.io/sepsi77/likaiset-sivut:deploy-000001-0044e5c22e09` | 8.2.35 | 8.2.35 | [x] 07:45 |
| Clickbuddy marketing | `ghcr.io/clickbuddy-ltd/cb-marketing-website:deploy-000009-598ee08ba488` | 8.4.27 | 8.4.27 | [x] 08:03 |

When Railway deployments resume:

- [x] Check each service in Railway for a new deployment of the fixed image. None started on its own.
- [x] Start deployments of the current config (`serviceInstanceDeployV2`; the read-only token cannot push a new tag). Done 07:44.
- [x] Check the `X-Powered-By` header on each site.
- [x] Voltikka: `/up`, one contract page, one CSS asset. Likaiset sivut: home page, `/admin/login`, internal pages, logo image (the home page has no `/storage/` image). Clickbuddy: home page, `/about-us`, build assets.
- [x] Mark the pending item in `tasks/prebuilt-image-deploy/tasks.json` done.

The next normal image build of each site uses the upstream FrankenPHP base automatically.

## B. Railway breach: mitigation checklist

A reference variable (`${{MySQL.MYSQLPASSWORD}}`, `${{shared.X}}`) follows its source; rotate the source only. Use the database Regenerate button (PostgreSQL and MySQL) instead of `ALTER USER`, then redeploy every consumer. Do not put a leaked `APP_KEY` in `APP_PREVIOUS_KEYS`: Laravel accepts cookies and signed URLs made with a previous key, so the leaked key would stay usable. Use `APP_PREVIOUS_KEYS` only where data encrypted with the old key must stay readable. Keep the old `BACKUP_ARCHIVE_PASSWORD`: existing Voltikka backups need it (now in `BACKUP_ARCHIVE_PASSWORD_UNTIL_2026_10_22`).

### Done

- [x] All GitHub tokens deleted on GitHub (user).
- [x] New `read:packages` token created; it pulls every private GHCR image we use (user).
- [x] All AI service API keys revoked (user).
- [x] New registry token on voltikka, likaiset-sivut, clickbuddy-website-ghcr.
- [x] Variable inventory of all projects (names and classes only).

### Registry credentials and connections

- [x] New registry token on the other Clickbuddy image services: cb-api, main-worker, cb-frontend, datashuttle, datashuttle-worker, daily-sync-worker (2026-10-22 ≈04:10).
- [ ] Reconnect GitHub for portfolio-tracker (writing-evaluator and llm-expert-panel are deleted).

### Priority 1: internet-reachable, money or cloud access

- [x] Public database passwords (TCP proxy on). Change the password in the database and in the variable; remove the TCP proxy if not needed:
  - [x] Voltikka MySQL `MYSQL_ROOT_PASSWORD` (Regenerate, 07:56; app redeployed 08:02)
  - [x] Clickbuddy marketing Postgres `POSTGRES_PASSWORD` (Regenerate ≈08:35; app redeployed 08:42)
  - [x] Clickbuddy cb-pg `POSTGRES_PASSWORD` (09:19; TCP proxy removed ≈09:15)
  - [x] LLM Expert Panel MySQL: closed by deleting the project (10:01)
- [x] Clickbuddy payments: PPG keys rotated at the provider (user, 2026-10-22 ≈09:40).
- [ ] Clickbuddy payments: put the new PPG values in the shared variables (cb-api and main-worker reference `${{shared.PPG_*}}`), then redeploy both. At ≈09:45 the audit log showed no shared variable change except ours at 09:07, nothing was staged, and both services last started 09:19.
- [x] Scaleway: all API keys and applications revoked, serverless Postgres deleted (user, 2026-10-22 ≈10:00). No Railway variable pointed to the serverless Postgres.
- [x] **Correction (≈10:05): the cb backend still used Scaleway Object Storage.** `DataForSeoS3StorageRepo` (`backend/clickbuddy/app/repo/data_for_seo_file_storage_repo.py`) saves every DataForSEO result with `SCW_ACCESS_KEY`/`SCW_SECRET_KEY`, `S3_ENDPOINT_URL` (s3.fr-par.scw.cloud), `S3_BUCKET_NAME`, `SCW_REGION`; main-worker logged successful saves until 09:05 today. A failed save raises, so SERP, ranked keywords, historical search volume, page intersection and keyword tasks fail with the revoked keys. (The first check missed this: `grep` here is `ugrep` and returned nothing.) Fix: a new Scaleway key limited to Object Storage, or point these variables to a Railway bucket (no code change), then redeploy cb-api and main-worker. Do not delete the Scaleway bucket before this is decided.
- [x] DataForSEO storage moved to the Railway bucket `cliickbuddy-bucket` (09:55): on cb-api and main-worker, `S3_ENDPOINT_URL`, `S3_BUCKET_NAME`, `SCW_REGION`, `SCW_ACCESS_KEY`, `SCW_SECRET_KEY` are now service-level references to the bucket (no code change). Deployments cb-api `dac93a2e`, main-worker `8fdb42cb` SUCCESS. From main-worker: head, put, get and delete of a test object under `dataforseo/` work. Old Redis cache paths name the Scaleway bucket; they fail to load and the app fetches the result again (one extra DataForSEO call each, until the cache expires).
- [x] Scaleway variables deleted (10:03): shared `SCW_*`, `S3_ENDPOINT_URL`, `S3_BUCKET_NAME`, `DOCKER_REPO` (Railway removed the cb-api/main-worker references with them); datashuttle, datashuttle-worker, daily-sync-worker `SCALEWAY_*`, `S3_*`, `TEMPORAL_LOGS/METRICS/TRACES_URL`. cb-api and main-worker keep only the five `cliickbuddy-bucket` references. Local cb `env.local`/`secrets.local`: Scaleway lines removed (local DataForSEO saves fail until a dev bucket exists; user decision).
- [x] Email guard: with `SCW_DEFAULT_PROJECT_ID` gone, `ScalewayEmailClient.from_env()` raises before any HTTP call, so the bucket secret (now in `SCW_SECRET_KEY`) is never sent to `api.scaleway.com`. cb-api and main-worker redeployed 10:04.
- [ ] Clickbuddy email notifications: the Scaleway account is closed, so email delivery fails (last successful email 2026-06-22). Replace `ScalewayEmailClient` with another provider, and give it its own variable names (not `SCW_*`).
- [x] datashuttle services: the running code reads no `SCALEWAY_*`, `S3_ENDPOINT_URL` or `TEMPORAL_LOGS/METRICS/TRACES_URL` (Scaleway Cockpit) variables; they can be deleted.
- [x] Google Ads developer token revoked (user, ≈10:20).
- [ ] Google: set the new `GOOGLE_ADS_DEVELOPER_TOKEN` in the shared variables (cb-api, main-worker, datashuttle x3 all reference it), then redeploy those five. Google Ads calls fail until then. `GOOGLE_API_KEY` is the Gemini API key (pydantic-ai Google provider; gemini-2.5-flash/pro and others): rotated in GCP (user, ≈10:40); set the new value in the shared variables and redeploy cb-api and main-worker. Gemini features fail until then.
- [x] Google OAuth 2 client deleted in GCP (user, ≈10:35). Effect: all stored Google refresh tokens (`user_credentials`, 26 Google connections) stop working; Google Ads sync, datashuttle loads and Google Ads features fail.
- [ ] Google OAuth: preferred, restore the deleted client (GCP keeps it 30 days; check the console), add a new client secret and delete the old one, set the new `GOOGLE_CLIENT_SECRET` (shared), redeploy cb-api and main-worker; refresh tokens keep working and stolen tokens are useless without the new secret. Otherwise create a new client, set `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`, redeploy, and ask all users to reconnect Google.
- [x] Temporal Cloud certificates were legacy: cb-api and main-worker did not reference them (unset in the containers; both connect without TLS to the self-hosted `temporal-server`). Deleted shared `TEMPORAL_CA_CERT`, `TEMPORAL_CLIENT_KEY`, `TEMPORAL_CLIENT_PEM` and their `*_ID` values (10:22).
- [x] AI keys revoked: OpenRouter (Voltikka, writing-evaluator, llm-expert-panel, Clickbuddy), `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `PERPLEXITY_API_KEY`, `REPLICATE_API_KEY`.
- [x] `JINA_AI_API_KEY` and `FIRECRAWL_API_KEY` revoked (user, 2026-10-22). New keys not yet set in Clickbuddy.
- [ ] Set the new AI keys in Railway (old values are revoked, so the features fail until then).
- [ ] Check usage dashboards of the AI providers for calls you did not make.

### Priority 2: application secrets

- [x] Voltikka: `OPENROUTER_API_KEY` and `POSTFAST_API_KEY` new keys set (user, 2026-10-22 ≈08:00).
- [x] Likaiset sivut MySQL `MYSQL_ROOT_PASSWORD` (Regenerate, 08:02; app redeployed 08:03). No TCP proxy.
- [x] Voltikka and Likaiset sivut: `APP_KEY` replaced, no `APP_PREVIOUS_KEYS` (2026-10-22 08:07).
- [x] Voltikka: `BACKUP_ARCHIVE_PASSWORD` replaced (2026-10-22 08:09). The old value is kept in `BACKUP_ARCHIVE_PASSWORD_UNTIL_2026_10_22` (not read by the app) because archives made before the change need it; yearly archives are kept for 2 years. Rule recorded in `AGENTS.md`.
- [x] Voltikka: `ENTSOE_API_KEY`, `DIGITRANSIT_API_KEY`, `SENTRY_LARAVEL_DSN` rotated (user; deployed 08:18).
- [ ] Sentry: disable the old Voltikka client key after new events arrive.
- [x] Likaiset sivut: no API keys in Railway. `MAIL_MAILER=log`, database queue/cache/session, local disk, `REDIS_PASSWORD` empty, `LOG_STDERR_FORMATTER` is a class name.
- [x] Voltikka and Likaiset sivut: admin users checked (2026-10-22 ≈08:25). One user each, unchanged since creation (Voltikka 2026-08-05, Likaiset sivut 2025-12-07). No registration on either panel. Likaiset sivut lets every user into `/admin` (`canAccessPanel()` returns true); acceptable while it has one user.
- [ ] Voltikka and Likaiset sivut: change the admin passwords (user; low impact, admin panels hold no sensitive data).
- [x] Voltikka and Likaiset sivut: log and database checks (21 Oct 12:00 – 22 Oct 08:20):
  - MySQL users: only `root` (`%`, `localhost`) and the three locked system accounts; root password changed at the Regenerate. No other users.
  - Since the MySQL restart: `Aborted_connects = 0`, no host_cache auth errors, connections only from the Railway private network.
  - MySQL logs: only the restart messages. (Default verbosity does not log failed logins, so this does not prove no access before the rotation.)
  - HTTP: `/admin/login` loaded only by bots, one browser and our curl checks; no Livewire POST from those IPs, so no login attempt. Likaiset sivut had no Livewire POSTs at all and no `/telescope` requests. Other POSTs are scanners (404/403/405).
  - App logs after the rotation: only `Connection refused` at 07:57–07:58 while MySQL restarted.
- [x] Voltikka backups: `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` were pasted values, and Railway rotated the bucket key. They are now references `${{voltikka-backups.ACCESS_KEY_ID}}` / `${{voltikka-backups.SECRET_ACCESS_KEY}}` (2026-10-22 ≈04:15). The new keys list the bucket. The 2026-10-22 00:00 backup is missing (failed with the old key); the newest backup is `voltikka-2026-10-21-00-01-43.zip`, from before the incident.
- [ ] Voltikka backups: after the next deploy, confirm that the 03:00 Helsinki backup run writes a new archive.
- [x] Clickbuddy marketing: `CLICKUP_API_KEY` (user, 08:38); `APP_KEY` without `APP_PREVIOUS_KEYS` and `CONTENT_PREVIEW_SECRET` (08:43). No `encrypted` casts; the preview secret signs 15-minute preview links in this app only. `MAIL_FROM_ADDRESS` is not secret.
- [x] Logfire: all write tokens deleted (user, 2026-10-22 ≈10:10).
- [ ] Logfire: create new write tokens and set `LOGFIRE_TOKEN` (shared, and the plain copies in datashuttle, datashuttle-worker, daily-sync-worker), then redeploy. Until then Logfire receives no logs or traces.
- [x] DataForSEO password changed at the provider (user, ≈10:30).
- [ ] Set the new `DATA_FOR_SEO_PASSWORD` in the shared variables, then redeploy cb-api and main-worker. DataForSEO calls fail until then.
- [ ] Clickbuddy shared, providers: `KEYWORDS_EVERYWHERE_API_KEY`, `HYDRA_PROXY_PASSWORD_PREFIX`, `IP_ROYAL_PROXY_PASSWORD_PREFIX`.
- [x] Own secrets rotated (10:22, scratchpad `rotate-cb-own-secrets.py`): DataShuttle key pair A (cb `DATA_SHUTTLE_API_KEY` = datashuttle `INCOMING_API_KEY`, plain compare) and pair B (datashuttle `DATA_SHUTTLE_API_KEY`; cb `DATA_SHUTTLE_INCOMING_API_KEY` = its bcrypt hash); agent prompt key (new bcrypt `AGENT_PROMPT_API_KEY_HASH`; plain key and hash updated in cb `secrets.local`); Redis `REDIS_PASSWORD` (changed live with `CONFIG SET requirepass`, no Redis restart, data kept). Deleted datashuttle `TEMPORAL_PUSH_TOKEN` (old Scaleway Cockpit token, unused). Deployed cb-api `d2e703db`, main-worker `3d6c939d`, datashuttle `37679352`, datashuttle-worker `b73d3fe9`, daily-sync-worker `31e78998`. All SUCCESS 10:23–10:24. Tests: cb→datashuttle `/data-sources` 200 with key, 403 without; datashuttle→cb `/datashuttle/credentials/google/<zero id>` 404 with key (auth passed), 401 without; `/api/agent/prompts` 200 with the new key, 401 without; Redis PING ok with 740 keys kept.
- [ ] `ENCRYPTION_KEY` (datashuttle Fernet: `destinations.credentials`, `connections.source_credentials`; cb Fernet, sealed variable: Google OAuth tokens in `user_credentials`): no key-rotation support in the code. Rotation needs a re-encryption script or a `MultiFernet([new, old])` change. The datashuttle production key is also in the private repo `sepsi77/datashuttle` (`dev.env` in git history).
- [ ] Datashuttle repo `dev.env` (private `sepsi77/datashuttle`) holds production values in git history: `ENCRYPTION_KEY`, `GOOGLE_ADS_DEVELOPER_TOKEN` (now revoked), old `DATA_SHUTTLE_API_KEY` (now rotated), Temporal IDs and Cockpit token (now deleted). Remove `dev.env` from the repo and keep only placeholders.
- [x] Clickbuddy internal databases: ds-pg, ds-dwh-pg, temporal-pg passwords regenerated (user, 09:00–09:01). The UI could not redeploy; consumers deployed through the API at 09:05 (same pinned images): temporal-server `5348ac2f`, datashuttle `78876ca4`, datashuttle-worker `2a341867`, daily-sync-worker `70bcbcf8`, cb-api `1271c9c3`, main-worker `545a2e06`.
- [x] Clickbuddy shared `DATASHUTTLE_DW_CONNECTION_STRING` was a pasted DSN with the old ds-dwh-pg password. No service references it (cb-api and main-worker use their own reference). Changed to `${{ds-dwh-pg.DATABASE_URL}}` without a deploy (2026-10-22 ≈09:10). cb-api's own reference resolves to the new ds-dwh-pg URL.
- [x] cb-api deployment `1271c9c3` SUCCESS 09:13 (migrations ran, no database errors). All six consumers of the regenerated databases now run with the new passwords.
- [x] cb-pg TCP proxy removed by the user (≈09:15; confirmed: no proxy). The user is sure it was deleted earlier; the Railway audit log does not record TCP proxy changes (the removal today is not in it either), so we cannot tell when or by whom it was created again.
- [x] Clickbuddy project audit log (kept from 2026-09-22): no access to cb-pg containers in the window. `ContainerAccess` events on ds-pg/ds-dwh-pg by `system` from Google Cloud IPs (20 Oct 20:06, 21:59; 22 Oct 04:44) match the daily pattern before the incident (12–16 per day). The user's own tunnels at 09:00–09:01 are the Regenerate actions. Railway rotated bucket credentials 21 Oct 23:15 and redeployed two databases 07:12 and 08:13.
- [x] Clickbuddy cb-pg password rotated (2026-10-22 09:19): `ALTER USER postgres` with a locally computed SCRAM verifier through `railway ssh` (local socket, trust); new password tested with a SCRAM login over the private network; `POSTGRES_PASSWORD` set without a database restart; cb-api `DATABASE_URL` confirmed to resolve to it. Deployments: cb-api `52ccc693-8b11-4c4f-9f58-bf67a2f9128f`, main-worker `3a4e7186-a20f-49cc-914a-5a342ccce9b4`. Script: scratchpad `rotate-cb-pg-password.py`. Both SUCCESS (main-worker 09:20, cb-api 09:22; cb-api migrations ran); no database errors in either. cb-pg logs from 21 Oct 12:00: no failed logins at all, also while the public proxy existed. cb-pg has only the `postgres` role and only private-network sessions.
- [x] Local `PRODUCTION_DATABASE_URL` in `backend/deployment/dev/env.local` and `secrets.local` (cb repo, both gitignored) updated with the new password (09:30). Host `pgvector.railway.internal` is still cb-pg's private domain.
- [ ] Note: the cb-pg container (started 2025-12-09) still has `PGHOST`/`PGPORT` for an old proxy `shinkansen.proxy.rlwy.net:19480` in its environment. Any in-container `psql` must use `-h /var/run/postgresql`. A cb-pg restart refreshes these (not required).
- [x] Clickbuddy cb-pg (main DB) background: had a public TCP proxy until ≈09:15 (`tokaido.proxy.rlwy.net:21498` → 5432), contrary to what we assumed. Custom image `pgvector/pgvector:pg18`, so Regenerate does not apply: change the password inside Postgres (`ALTER USER postgres` through `railway ssh` into cb-pg), then set `POSTGRES_PASSWORD` on cb-pg, then deploy cb-api and main-worker (both use `${{cb-pg.DATABASE_URL_PRIVATE}}`; no other consumer). Update the local `PRODUCTION_DATABASE_URL` in `backend/deployment/dev/env.local` and `secrets.local`. Tunnel method: `backend/deployment/dev/open_production_db_tunnel.sh` (`railway ssh`, local port 55432).
- [x] Clickbuddy Redis `REDIS_PASSWORD` rotated (10:22, live, no restart).
- [x] writing-evaluator: project deleted (10:01) (replaces rotation of `APP_KEY` and `POSTGRES_PASSWORD`). LLM key already revoked.
- [x] llm-expert-panel: project deleted (10:01) (replaces rotation of `APP_KEY` and `ADMIN_PASSWORD`). LLM key already revoked.
- [ ] Portfolio tracker: `APP_PASSWORD`; its Postgres `POSTGRES_PASSWORD`.
- [ ] gasum-prompt-tracking: `BASIC_AUTH_PASSWORD`.

### Clickbuddy marketing: admin and log checks (2026-10-22 ≈08:50)

- [x] Users: 3, all `admin` role, all created 2026-06-16 and unchanged since. No registration on the Filament panel. One logged-in session now.
- [x] Content: no change to `users`, `pages` (24), `site_settings` since 2026-06-23; no redirects.
- [x] Postgres: only the `postgres` role, only the `plpgsql` extension, sessions only from the private network.
- [x] Postgres logs from 21 Oct 12:00: no failed logins before the Regenerate. 13 `password authentication failed` lines at 08:36–08:41, all after the Regenerate and before the app redeploy (the old container with the old password). Postgres does not log successful connections here, so a login with the leaked correct password cannot be ruled out; the data shows no sign of it.
- [x] HTTP: `/admin/login` loaded only by bots and scanners; the only Livewire POSTs are 4 at 08:34 (Chrome 154 on macOS, the one logged-in session; probably the user). Other POSTs are WordPress/GraphQL scanners (404/405).
- [ ] Note: `WordPress/7.1.3; https://clickbuddy.com` from 35.214.197.125 posts to `/wp-cron.php` several times a day. An old WordPress install that still uses the clickbuddy.com URL runs somewhere; find it and shut it down or secure it.

### After deployments resume

- [ ] Redeploy every service whose variables changed (containers read variables only at start).
- [x] ds-pg and ds-dwh-pg redeployed (Railway 07:12/08:13, Regenerate 09:00), so WAL archiving has the rotated bucket keys.
- [ ] Redeploy Portfolio Postgres so WAL archiving (PITR) gets the rotated bucket keys.
- [x] datashuttle, datashuttle-worker, daily-sync-worker redeployed 09:05, so they have the rotated `cliickbuddy-bucket` keys.
- [ ] Check bucket contents for objects you did not upload or deletions you did not make.
- [ ] Review the Railway audit log for the exposure window: variable reads, registry credential changes and deployments not made by us or by "Railway Staff".
- [ ] Review service logs for unusual activity.
- [ ] Check email for a direct notice from Railway that names affected accounts.

## Notes

- Names only: the variable inventory was made from unrendered Railway variables and printed no values. Regenerate it with a script that prints names and classes only.
- Voltikka deploy rules in `AGENTS.md` still apply for normal releases. The user waived the deployment schedule for this security patch only.
