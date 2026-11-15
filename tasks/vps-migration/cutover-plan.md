# Production cut-over plan: Railway → VPS (S4–S6)

Written 2026-11-15. User request 2026-11-15: switch as soon as possible, today (Sunday, low
traffic), instead of the weekday evening of decision 8. The parallel week started on 2026-11-09.
The VPS had good mornings on every day except 14.11, when the shared PerusFiksu bug stopped
Railway in the same way.

## What changes

| | Before | After |
|---|---|---|
| `voltikka.fi` DNS (Cloudflare, proxied) | CNAME → Railway custom domain | CNAME → `634f4344-4636-4790-9ecd-bdd00dfe37ac.cfargotunnel.com` |
| Database | Railway MySQL (`railway` schema) | VPS accessory `voltikka-db` (`voltikka` schema), filled from a fresh Railway dump |
| Public files | Railway volume `/app/storage/app/public` (59 logos, 4 MB) and `/app/storage/app/videos` (8 MB) | Same paths on the VPS volume `voltikka-storage` |
| Scheduler | Railway `schedule:work` (every group) | VPS web role (maintenance, spot, morning groups) and VPS Temporal (collectors, s-forecast) |
| Temporal | Railway `voltikka-worker` on `voltikka-prod` (S pilot) | VPS worker on `voltikka-prod-vps.lw6f1` (stays the production namespace; the name cannot change) |
| Backups | spatie → Railway bucket `voltikka-backups` | spatie → GCS `breezily-voltikka-backups` (europe-north1, 30-day soft delete), plus the nightly restic dump of `voltikka-db` (01:30) |
| Settings | Railway variables | `config/deploy.yml` (this PR): `APP_URL`, Sentry `production`, GTM, PostFast key, GCS backups |

Unchanged: the image (`deploy-000026-b147c81b13b1`), the pricing flags, mail to the log, daily spot
social posts off (both equal to Railway), the Sentry uptime monitor on `https://voltikka.fi/up`.

## Readiness (done before the window)

- [x] Service-account deploy path: web role deployed with `vps-deploy` only, 52 s (2026-11-15 12:15).
- [x] GCS bucket, service account `voltikka-backup` (object admin on the bucket only), HMAC key and
      archive password in Secret Manager; `vps-deploy` can read the four new secrets.
- [x] Backup to GCS: `backup:run --only-db` of the test copy, 203 MB encrypted archive (AES-256).
      Found: GCS refuses the object ACL that Flysystem sends when uniform bucket-level access is on,
      so the bucket uses fine-grained access (public access prevention stays enforced).
- [x] Restore drill (13:05): archive downloaded from GCS, decrypted with the Secret Manager password,
      restored into a throwaway `mysql:9.7` container (`--network none`) in 300 s. 60 of 60 tables,
      7,226,416 rows; only `jao_max_border_flows` (+192) and `hourly_forecast_runs` (+1) differ, both
      written by collectors after the backup. Drill container, files and archive removed.
- [x] File copy rehearsed (Railway → VPS scratch folder, `tar` through `base64`): 62 files, 12 MB.
- [x] CodeRabbit: no actionable comments up to `c6b98ca`.
- [ ] This PR merged (needs the user's merge request).
- [ ] User ready in the Cloudflare dashboard (DNS of `voltikka.fi`), with the current Railway CNAME
      target written down for a rollback.

## Window

Start at the first **:05 Helsinki** after the user's go (after the hourly `spot:fetch`), about
35–45 min. A running VPS `SDailyForecast` does not block: the import replaces every row it wrote, so
it is terminated in step 3 (no public output; that day's S forecast is lost). Railway's S pilot had
no running workflow on 15.11. Lost in the window: the hourly runs that fall in it (`:25`
`spot:fetch-neighbours`, the `:45` Fingrid 245 vintage if the window runs late). Spot data is
fetched again each hour, so only the append-only Fingrid vintage is a real gap.

## Steps

Operator: the agent, with the user on the DNS step. Each step lists its check. Stop at a failed
check and use the rollback below.

1. **Pre-checks (T−10).** Railway web and VPS healthy; no checkpoint `running`; Railway `jobs` table
   empty or only delayed jobs; no running Temporal workflow other than `eex-europe-fetch` on either
   namespace. Write the Railway MySQL client file to the VPS through a pipe (never printed).
2. **Railway maintenance (T0).** `railway ssh` into `voltikka`: `php artisan down --retry=1800`.
   This also stops the Railway scheduler and queue worker (both respect maintenance mode). Check:
   `https://voltikka.fi/up` gives 503. Pause the S pilot Schedules on `voltikka-prod` (script in the
   Railway worker).
3. **Stop the VPS writers.** Pause every Schedule on `voltikka-prod-vps.lw6f1`; let a running
   `ArtisanJob` end, terminate a running `SDailyForecast`; `kamal app stop` (web and worker).
4. **Database.** On the VPS: drop and recreate the `voltikka` schema, then the 2026-11-09 recipe:
   `mysqldump --single-transaction --quick --routines --triggers --no-tablespaces
   --set-gtid-purged=OFF --default-character-set=utf8mb4 railway | mysql voltikka` (9 min on 11-09).
   Check: exact row counts of every table equal on both sides (`counts.sh`, zero `DIFF` lines).
   Railway is in maintenance, so its tables do not change.
5. **Files.** `tar` of `/app/storage/app/public` and `/app/storage/app/videos` from Railway into the
   VPS volume. Check: 59 logos.
6. **Cache.** `FLUSHALL` on `voltikka-redis`: its price generations were built from the test copy's
   own interpretations, not from the production data.
7. **Deploy production settings.** `kamal deploy --skip-push --version deploy-000026-b147c81b13b1`
   (both roles). The web start runs `migrate` (nothing new) and `contracts:warm-cache` (builds the
   price cache from the imported data). Check: web healthy; `curl -H 'Host: voltikka.fi'` on the
   server gives 200 for `/`, `/sahkosopimus`, `/spot-price`, `/sahkosopimus/tilastot`, one contract
   page and `/sitemap.xml`; `schedule:list` has 23 entries without collectors; environment
   `SENTRY_ENVIRONMENT=production`, `BACKUP_DISK=s3`.
8. **Resume Temporal.** Unpause the VPS Schedules. Check: all 18 active.
9. **DNS (user).** Cloudflare dashboard → `voltikka.fi` → DNS: edit the `voltikka.fi` CNAME to
   `634f4344-4636-4790-9ecd-bdd00dfe37ac.cfargotunnel.com`, proxied. Check: `curl -sI
   https://voltikka.fi/up` gives 200 without `x-railway-*` headers; the pages of step 7 through
   Cloudflare; the GTM tag in the page.
10. **Backup.** `backup:run --only-db` on the VPS to GCS. Check: the archive is in the bucket.
11. **Record** the result in `decisions.md`.

## After the window

- Railway stays in maintenance mode with the S pilot paused: no Railway scheduler, queue or worker
  writes. Stop the Railway services (no deletion) after the first VPS production morning (Mon 16.11)
  is good; this needs the user's approval. Keep the project and its MySQL volume for at least two
  weeks (decision 8).
- First production morning on the VPS: EEX 04:00, import 06:00, consumers from 07:15. The 04:00–09:00
  deploy rule applies to the VPS web role as it did to Railway.
- Docs (S7): root `AGENTS.md`, `orchestration/AGENTS.md`, `scripts/deploy-image.sh` and the
  breezily-infra README describe the VPS. Own PR after the cut-over.
- Remove the empty `voltikka-forecast-store` volume; free the first Fingrid key.

## Rollback

Until the Railway services are stopped, the rollback takes about 5 minutes:

1. DNS: the `voltikka.fi` CNAME back to the Railway target (written down in readiness).
2. Railway: `php artisan up`; unpause the S pilot Schedules on `voltikka-prod`.
3. VPS: pause its Schedules and `kamal app stop`.

Data written on the VPS after the switch is not on Railway. For a rollback after real VPS writes
(imports, statistics), dump the VPS `voltikka` schema back into Railway with the step 4 recipe in
the other direction, during a new maintenance window. After the Railway services are stopped, the
same applies with a Railway redeploy of `deploy-000026-b147c81b13b1` first.
