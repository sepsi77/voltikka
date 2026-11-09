# VPS migration: decisions

## 2026-11-09 – Task started; phase 2 Temporal goes live only on the VPS

- User decision: hosting moves from Railway to a VPS. The Temporal phase 2 orchestration
  (merged in PR #9) is deployed only to the VPS, never to Railway. Reason: the VPS gives a way to
  test the Temporal setup without affecting production.
- Consequence on `main`: the collector entries return to `laravel/routes/console.php`, so every
  scheduled job keeps running on Railway until its VPS cut-over, and a Railway deploy of `main`
  stays safe (`tasks/temporal-orchestration/decisions.md`, 2026-11-09).
- Two risks to settle in phase 0 (agent note): a VPS test that shares the production database
  while Railway still runs the scheduler would double-write; a VPS worker on the same Temporal
  namespace and task queue as Railway `voltikka-worker` would take production tasks.

## 2026-11-09 – PR #10 review, round 1

Head 99a6787. CodeRabbit review 5818910412 (Greptile not requested: user decision for this small
PR). Findings, all valid and fixed:

| Finding | Kind | Action |
|---|---|---|
| `WebSchedulerTest` checks `backup:run` by name, so the weekly files backup hides a missing daily database backup | inline 4507148843 (👍) | exact command strings |
| The same name-only check hides a lost `weather:fetch` / `fmi:fetch` `--only` variant | nitpick | every collector variant listed as an exact string |
| The "Fingrid 245 wind power forecast vintages" comment sat above the `ecmwf_00z` block (old misplacement restored from `543c342`) | additional comment | moved above `fingrid:fetch-forecast-vintages` |

Codification: none, because no spec review ran for this small PR; the fixes are local to one test
and one comment.

## 2026-11-09 – Temporal Cloud namespace for the VPS test copy

- User action: a separate Temporal Cloud namespace `voltikka-prod-vps.lw6f1` (address
  `voltikka-prod-vps.lw6f1.tmprl.cloud:7233`), so a VPS worker cannot take production tasks from
  `voltikka-prod`. The VPS migration thread first proposed the name `voltikka-vps`; the user's name
  is used.
- API key: GCP Secret Manager, project `breezily-infra`, secret `voltikka-temporal-api-key`
  (version 1, 2026-11-08 19:22 UTC). Kamal maps it to `TEMPORAL_API_KEY`. **The key expires on
  2028-11-07** (rotation item in root `AGENTS.md`, "Dated follow-ups").
- Verified 2026-11-09 (read-only, with the worker's own `connect()`): the key connects to the new
  namespace (registered, 30-day retention, no workflows, no Schedules), and the production
  namespace `voltikka-prod.lw6f1` refuses it with `Request unauthorized`.

## 2026-11-09 – Phase 0 decisions (user-approved)

Proposed by the VPS migration thread ("Choose a VPS Deployment Approach") and approved by the
user on 2026-11-09, with one change (item 3).

1. **Host.** The Breezily Hostinger KVM 4 VPS (Debian 13, Lithuania, 16 GB), SSH host
   `breezily-vps` (Tailscale only, user `deploy`), shared with the Portfolio tracker and Likaiset
   sivut. Runbook: `~/code/breezily-infra` (README). Kamal 2; Cloudflare Tunnel in front.
2. **Parallel run model: a separate test copy.** Railway stays production. The VPS runs a full copy
   with its own MySQL (Kamal accessory `voltikka-db`, filled with `mysqldump --single-transaction`
   from Railway, not `scripts/sync-production-database.sh`, which builds an incomplete SQLite
   copy), its own Redis and its own Temporal namespace `voltikka-prod-vps.lw6f1`. Each side writes
   only its own database, so a double write is not possible. Refresh only when a test needs
   current data; the production data moves only at the cut-over. Rejected: a VPS that fetches data
   into the Railway database (public MySQL port, shared writes, first morning test on production
   data).
3. **Full collection on the test copy, EEX included.** User change to the proposal (EEX only for
   the morning tests): the VPS runs every collector every day, `futures:fetch-eex` included.
   Reason: the VPS must collect full data from the start, so the cut-over is a clean break from
   Railway; EEX has not blocked Railway and is unlikely to block the VPS. LLM contract
   interpretation is also on (a second interpretation of each changed contract is accepted).
4. **One image: a `TEMPORAL_SCHEDULE_GROUPS` switch in `laravel/routes/console.php`.** The listed
   groups are skipped by the Laravel scheduler. Railway leaves it unset and runs every group as
   now; the VPS lists the groups that its Temporal Schedules run. Phase 2 stays off Railway.
5. **Registry.** GHCR (`ghcr.io/sepsi77/voltikka`), the same immutable tags as Railway, pulled by
   Kamal with a read-only `read:packages` token (secret `voltikka-ghcr-read-token`).
   `scripts/build-and-push-image.sh` is unchanged.
6. **Preview host.** `voltikka.breezily.net` behind Cloudflare Access, only seppo@breezily.fi. Off
   on the test copy: social posting, weekly-offers video publication, GTM/Plausible, outgoing
   email, backups to the Railway buckets, S model files in `voltikka-forecast`, a Sentry uptime
   monitor. `SENTRY_ENVIRONMENT=vps-test`.
7. **Railway `voltikka-worker` and the S pilot** run unchanged on `voltikka-prod` until the
   cut-over; the VPS runs its own copy of every Schedule on its own namespace.
8. **Parallel period: one week.** The cut-over follows after the VPS has run every schedule group
   for one week with good mornings. Weekday evening at about 20:20 Helsinki, maintenance page for
   about 30–45 min. Keep the Railway project stopped, not deleted, for at least two weeks after.
9. **Backups after the cut-over.** Keep `spatie/laravel-backup` with a GCS bucket as the target
   instead of the Railway bucket; the server's nightly restic backup runs beside it.

## 2026-11-09 – Test copy set-up (phase 1, step 1)

User request 2026-11-09: start the VPS deployment, copy the databases and set everything up.

- **Order.** Step 1 runs the current `main` image with every schedule group in `console.php` (as on
  Railway) and the Temporal worker with only the S pilot group. The VPS thus collects full data
  from the first night. Step 2 (own PR): the `TEMPORAL_SCHEDULE_GROUPS` switch, then the groups
  move to Temporal Schedules on the VPS one at a time.
- **Kamal files** at the repository root: `config/deploy.yml`, `.kamal/secrets` (commands only).
  Roles `web` (supervisord: FrankenPHP, scheduler, queue; volume `voltikka-storage` on
  `/app/storage`) and `worker` (entrypoint `docker-worker-entrypoint.sh`, image HEALTHCHECK off,
  stop timeout 3960 s > 65-minute drain, S model files on volume `voltikka-forecast-store` through
  `FORECAST_STORE_DIR`). Accessories `voltikka-db` (MySQL 9.7, buffer pool 1 GB) and
  `voltikka-redis` (Redis 8, 2 GB, `noeviction` after PR #11 review, append-only file). `CACHE_STORE=redis` from the
  start (phase 2 R2).
- **Known overlap.** Kamal starts the new container before it stops the old one. During a web
  deploy, two schedulers and two queue workers can run for some seconds; acceptable on the test
  copy, removed when phase 2 takes the scheduler out of the web container.
- **Secrets** (GCP Secret Manager, project `breezily-infra`): copied from Railway through a pipe,
  never printed: `voltikka-app-key` (same `APP_KEY`, so encrypted columns and signatures of the
  copy stay valid), `voltikka-entsoe-api-key`, `voltikka-fingrid-api-key`, `voltikka-gie-api-key`,
  `voltikka-openrouter-api-key`, `voltikka-digitransit-api-key`, `voltikka-sentry-laravel-dsn`.
  New: `voltikka-mysql-password`, `voltikka-mysql-root-password`. Not copied on purpose:
  `AWS_*` and `BACKUP_ARCHIVE_PASSWORD*` (Railway backup bucket), `FORECAST_BUCKET_*`,
  `POSTFAST_API_KEY` (no key: the weekly video skips its post), `GTM_CONTAINER_ID`, the production
  Temporal key.
- **Cloudflare Access.** The tunnel token (`~/.cloudflared/cert-breezily.net.pem`) can read but not
  create Access applications (`auth.forbidden`). Until the user creates the Access application for
  `voltikka.breezily.net`, the proxy host is `voltikka-vps.internal`: Cloudflare cannot route that
  name, so the copy is not reachable from the internet. Tests use `curl -H 'Host: …'` on the
  server.

## 2026-11-09 – PR #11 review, round 1

Head 98f88e2. CodeRabbit: no actionable comments. Greptile (Plus, score 4/5), both valid and fixed:

| Finding | Verdict | Fix |
|---|---|---|
| 4509165668 `volatile-lru` can evict active Laravel locks (they have a TTL) when Redis is full | valid (👍) | `noeviction`: a full Redis fails writes visibly; expired entries still go. Also in `phase2-plan.md`. |
| 4509165690 the Python worker reports Sentry events as `APP_ENV` (`production`), not `vps-test` | valid (👍) | `Settings.from_env()` reads `SENTRY_ENVIRONMENT` first; `tests/test_settings.py`. |

Codification (missed-findings): owner `platform-reviewer`; cause "no spec review ran" and (c) the
rules were not written. Checklist item 7 of `.pi/agents/platform-reviewer.md` (and its
`.claude/agents/` copy) now names both classes: a Redis with Laravel locks uses `noeviction`; every
runtime reports `SENTRY_ENVIRONMENT`.
