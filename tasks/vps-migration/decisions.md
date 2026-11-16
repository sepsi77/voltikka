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
  stop timeout 3960 s > 65-minute drain, S model files through `FORECAST_STORE_DIR`; since the PR #12
  review the worker mounts `voltikka-storage` too and the model files are in
  `/app/storage/app/forecast-models`). Accessories `voltikka-db` (MySQL 9.7, buffer pool 1 GB) and
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
- **Secret access** (2026-11-14): `.kamal/secrets` reads the secrets with the service account
  `vps-deploy@breezily-infra.iam.gserviceaccount.com` (key file `~/.config/breezily/vps-deploy.json`
  on the Mac; `secretAccessor` on each app secret only). Until then it used the gcloud login of
  seppo@breezily.fi, which expires after about 2 days (Workspace session control). Deploys then
  stopped with `Reauthentication failed` (2026-11-09, 11-12, 11-14). A new `voltikka-*` secret needs
  its own binding for `vps-deploy`; see the breezily-infra README, "Deploy secrets".
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

## 2026-11-09 – Test copy live; the group switch (phase 1, step 2)

- **Step 1 done.** PR #11 merged (`74710ab`); image `deploy-000024-74710abe15f6` on the VPS
  (`kamal deploy --skip-push`, 124 s). Web healthy, every console.php group runs on the VPS like
  on Railway. Worker on `voltikka-prod-vps.lw6f1`; the S pilot Schedules (`--group s-forecast`) are
  applied there. The weekly refit (Monday 10:45) fills the empty local model store before the first
  daily forecast (10:55). The VPS missed its 04:00 EEX run and the 06:00 import (deploy after
  06:00), so both ran by hand on 2026-11-09 (`futures:fetch-eex`, `contracts:fetch`).
- **The switch.** `config('orchestration.temporal_schedule_groups')` from `TEMPORAL_SCHEDULE_GROUPS`
  (comma list). `routes/console.php` wraps every entry in its group condition (same groups as
  `schedules.py` / `jobs.py`); `backup:run --only-files` stays always; `logos:download` (07:00) runs
  only when the morning group is listed. An unknown name throws, so a typo stops the scheduler
  instead of a group running in both schedulers. With no group listed, `schedule:list` is identical
  to `main` (39 lines), so Railway does not change.
- **Cut-over of a group on the VPS:** set the group in `TEMPORAL_SCHEDULE_GROUPS` (env of the web
  role only in `config/deploy.yml`: a typo then stops only the new web container), deploy the web
  role. Only after the deploy succeeded, run `php artisan schedule:list` in the new web container
  and confirm that the group is absent; then `schedules --apply --group <group>` in the worker
  container at once. A failed deploy keeps the old container with the group, so never apply after
  it. Rollback: pause the group's Schedules, wait until its running workflows end, then remove the
  group and redeploy the web role. Trigger-gap and failed-apply recovery:
  `tasks/temporal-orchestration/phase2-plan.md`, R1. `--apply` takes whole groups only.
- **Queue on the VPS.** The phase 2 plan moved the queue to its own service before maintenance and
  spot, because a worker job on a private file cache would keep stale prices. The VPS has the shared
  Redis cache on every role from the start, so that reason does not apply; the queue stays in the
  web container for now. A separate `queue` role (deploy survival of the LLM jobs) is a later step.

### Independent review of the switch (platform-reviewer, before the PR)

Every group assignment matches its Temporal job. Findings:

| ID | Finding | Verdict | Action |
|---|---|---|---|
| PLT-001 | With `morning` on Temporal, no scheduler completes an import started by hand (`contracts:complete-import` runs only after the chain's own import); a deferred `pending_completion` stays and the consumers stay closed | valid, deferred | **Condition of the morning cut-over:** before `morning` is listed, add a standalone completion Schedule in the morning group, or make `MorningChain` with `date=` the only recovery for a missed import. Not needed while `morning` runs in `console.php`. |
| PLT-002 | `schedules --apply --only <id>` applies one job of a group: it runs in both schedulers, or the rest of a listed group runs nowhere | valid, fixed | `--apply` takes `--group` only; test in `test_jobs.py`. |
| PLT-003 | The runbook text dropped "only after the web deploy succeeded" and "let running workflows end" | valid, fixed | Text above. |

## 2026-11-09 – PR #12 review, round 1

Head 871badd. Greptile (Plus, score 4/5) and CodeRabbit (1 actionable).

| Finding | Reviewer | Verdict | Action |
|---|---|---|---|
| 4509406068 After the maintenance cut-over, `backup:run` (BACKUP_DISK=local), clean and monitor run in the worker, which had no storage volume: archives in a temporary container, apart from the weekly files backup | Greptile | valid (👍) | The worker role mounts `voltikka-storage` (one host, one Docker volume); S model files move into it (`/app/storage/app/forecast-models`). The empty `voltikka-forecast-store` volume is removed after the next worker deploy. |
| 4509406071 The reverse group test compared only command names, so a lost `weather:fetch --only=` variant passed | Greptile | valid (👍) | Full command strings in both directions; the two Temporal-only flags are named (`--if-configured`, `--skip-logos`). Proved: deleting `weather-fetch-ecmwf-prev-day1` from `JOBS` is detected. |
| 4509411679 Stage a group paused, verify, then activate; document a partial apply | CodeRabbit | partly valid (👍 + reply) | Added the partial-apply recovery (re-run, or pause the created schedules) and the fact that a re-apply unpauses. Not adopted: a staged-paused apply mode; one operator applies one group with `schedule:list` proof, which is enough for this tool. |

Codification (missed-findings): owner `platform-reviewer` (it reviewed this diff before the PR and
missed both Greptile findings); cause (d). Its checklist item 6 now names: a job that moves to the
worker must still reach the storage it writes (volume, disk), and a consistency test must compare
full command strings, not names.

## 2026-11-09 – PR #12 review, round 2

Head bea9c3c. CodeRabbit: no inline comments (only the general docstring-coverage check, not
blocking); threads resolved. Every Greptile finding of round 1 is fixed and covered by this review.

## 2026-11-09 – Collectors cut-over on the VPS (user-approved)

User approval 2026-11-09: the collectors cut-over on the VPS test copy, today between 16:00 and
16:40 Helsinki (after the 15:30 collector time, away from the hourly :45 Fingrid vintage). This PR
sets `TEMPORAL_SCHEDULE_GROUPS: collectors` on the web role. Steps after the merge: deploy the web
role, confirm with `schedule:list` that the 16 collector entries are absent, then
`schedules --apply --group collectors` in the worker container. Railway does not change.

## 2026-11-09 – PR #12 image on the VPS; first full VPS morning

- `deploy-000025-886abe858cb7` (PR #12: the worker mounts `voltikka-storage`) deployed to both
  roles at 07:45 Helsinki (109 s). Web healthy; worker on `voltikka-prod-vps.lw6f1`.
- Morning of 2026-11-09 on the VPS: `contract_import` (06:14, by hand), `eex_futures` (07:45, by
  hand), `morning_retail` and `morning_forecast` (07:50) all `ready`.
- **Lesson (wait condition).** The pre-deploy check counted `waiting` as finished, so the deploy
  started while both consumers were still `waiting` for EEX. No tick ran in the old container during
  the deploy, and the 07:50 tick completed both in the new one, so nothing was lost. A deploy waits
  for final statuses of all three checkpoints (`ready` or a terminal failure), never `waiting` or
  `running`.
- The empty `voltikka-forecast-store` volume stays until Kamal prunes the stopped 000024 worker (its
  rollback target); then remove it.

## 2026-11-09 – Collectors cut-over done (18:49 Helsinki)

- **The planned 16:03 start did not run.** It was a timer of the agent session only, and the
  session was not active then. New user approval at 17:40 to run it at once. Lesson: an approved
  change at a set time needs an operator who is present, or a durable scheduler, never a session
  timer.
- **First attempt (17:42) stopped before any change:** the gcloud login of the operator had expired,
  so `kamal deploy` could not read the GCP secrets (`Reauthentication failed`). No container, lock
  or Schedule changed. The user logged in again (`gcloud auth login`); check the login before a
  planned deploy.
- **Deploy.** `ac7e530` (`TEMPORAL_SCHEDULE_GROUPS: collectors` on the web role), image
  `deploy-000025-886abe858cb7`, `--roles web`, 50.5 s; new web container healthy, `/up` 200.
- **Proof.** `schedule:list` in the new web container: 23 entries (was 39), no collector entry;
  `spot:fetch`, `contracts:fetch`, `futures:fetch-eex`, both backups still present.
- **Apply.** `schedules --apply --group collectors` in the worker: 16 Schedules created. Every one
  read back by ID (`describe`): all 16 exist and are active. `list_schedules` showed only some of
  them right after the apply, because the Temporal list index is eventually consistent; read back by
  ID, not by list.
- First Temporal collector run: `fingrid-forecast-vintages` at 19:45 Helsinki; the web scheduler ran
  the 18:45 one before the deploy, so no gap.

## 2026-11-12 – First days of the Temporal collectors; own Fingrid key for the VPS

- 2026-11-09 18:49 to 2026-11-12 11:46 Helsinki: 117 collector workflows, 114 completed, no missed
  or paused Schedule (`fingrid-forecast-vintages` 65 hourly runs without a gap).
- **`fingrid:fetch` failed on 10.11, 11.11 and 12.11** (`exited 1`): each day 8–10 of about 24
  datasets with an HTTP `RequestException`, different datasets each day; the others were saved.
  Railway had 2 failed datasets in the same days. Both ran at 10:20 Helsinki with the same API key,
  so the two runs together went above Fingrid's request limit. Proof: a run alone on the VPS
  (11:48) saved every dataset. No data was lost: each run fetches 72 hours.
- **Fix (user, 2026-11-12):** Fingrid gives two API keys per account and no more. The VPS uses the
  second key: new version 2 of GCP secret `voltikka-fingrid-api-key` (version 1, the first key, is
  still enabled); Railway keeps the first key. Both VPS roles redeployed at 11:55 (69 s) after all
  four morning checkpoints were `ready` and no workflow ran; both containers have the second key
  (hash compared, never printed); a test `fingrid:fetch` had no failed dataset.
- After the production cut-over, the first key is free again; keep one key per running system.

## 2026-11-14 – Status check; production import incident; findings on the VPS

- **Production incident (both systems).** The first interpretation of Keravan Energia PerusFiksu
  24kk (Hybrid: fixed Nov–Mar, Spot + 0.55 c/kWh Apr–Oct) gave a `base_only_hybrid` /
  `forward_curve_spot` result that the strict reader rejects. The exception stopped every price
  cache build, so `contracts:fetch` failed at `price_cache_refresh` (06:11) on Railway and on the
  VPS, and both morning consumers waited. The API data and the daily statistics were already
  stored; only the cache, the forecast and the retail collection were missing. Fix PR #15
  (`b147c81`): the contract is excluded with one error log. User approved the whole recovery:
  `deploy-000026-b147c81b13b1` to Railway web at 09:00 (SUCCESS), a fresh production
  `contracts:fetch` (ready 09:13), `morning_forecast` and `morning_retail` ready 09:16 (9 forecasts,
  69 retail rows); the same image on both VPS roles (180 s) and a fresh VPS import. The calculator
  mismatch itself is a separate follow-up. Production also missed both consumers on 12.11
  (`publication_miss`); a backfill of that forecast needs its own approval.
- **Fingrid.** The second key did not stop the 10:20 failures (8 datasets on 13.11). Probable
  cause: Fingrid counts the request limit per account, not per key; runs alone (11:48, 11:57 on
  12.11) had no failures. Proposed: move the VPS `fingrid-fetch` Schedule away from 10:20 (PR).
- **VPS restart.** Unattended upgrades rebooted the host at 02:00 on 11.11
  (`Automatic-Reboot "true"`, time 02:00). All containers came back. Not yet in the runbook.
- **gcloud.** The personal login expired after about 2 days (9.11, 12.11, 14.11), which stops
  every `kamal deploy`. A service account for the Kamal secrets is a separate task (user
  delegated it to another agent).
- **Lesson (operator).** A `git stash` at the end of a command line in a worktree stashed the
  uncommitted fix; it was restored at once. Never run stash commands in a shared repository.

## 2026-11-15 – Cut-over today; plan and production settings

- **User decision:** switch production to the VPS as soon as possible, on Sunday 2026-11-15 (low
  traffic), instead of a weekday evening (decision 8). The user approved writing the cut-over plan
  and setting up the GCS backup bucket. The cut-over itself still needs the user's go. Plan:
  `cutover-plan.md`.
- **Cloudflare Access for `voltikka.breezily.net` dropped.** After the switch the VPS serves
  `voltikka.fi`, so the preview host is no longer needed. The proxy host is now `voltikka.fi`.
- **Settings compared with Railway (names and non-secret values only).** Railway web has no `MAIL_*`
  variables (mail goes to the log) and no `SPOT_SOCIAL_PUBLISHING_ENABLED` (off), so the VPS keeps
  both. Differences taken over: `APP_URL`, Sentry `production` (Railway sets no
  `SENTRY_ENVIRONMENT`, so it reports `APP_ENV`), `GTM_CONTAINER_ID` (public, in every page),
  `POSTFAST_API_KEY` (copied through a pipe to secret `voltikka-postfast-api-key`),
  `BACKUP_MAX_STORAGE_MB` 10000. S model files stay on the VPS volume, not in a bucket: the weekly
  refit rebuilds them.
- **Backups (decision 9).** GCS bucket `breezily-voltikka-backups` (project `breezily-infra`,
  europe-north1, 30-day soft delete, public access prevention), service account `voltikka-backup`
  with `roles/storage.objectAdmin` on this bucket only, HMAC key in secrets
  `voltikka-backup-hmac-access-id` / `-secret`, new archive password `voltikka-backup-archive-password`
  (new archives, so the Railway passwords are not needed). `vps-deploy` can read all four new
  secrets. Uniform bucket-level access is off on purpose: Flysystem sends an object ACL (`private`)
  on every write, and GCS refuses any ACL with `Cannot insert legacy ACL ... uniform bucket-level
  access is enabled` (the first test backup failed so). The AWS SDK checksum headers were not the
  cause (a multipart create without ACL passed).
- **Service-account deploy proved.** The branch had the old `.kamal/secrets` (before PR #16); after
  the merge of `main`, `kamal deploy --roles web` with the same image passed in 52 s with only
  `vps-deploy` (the personal login had expired at that time).
- **02:00 reboot** is already in the breezily-infra README (decision "Automatic updates with a reboot
  at 02:00 Helsinki time"); no change.
- **S pilot on the VPS.** `SDailyForecast` stopped at the 11:30 morning cut with `inputs_missing` on
  10.11–14.11; on 15.11 the morning inputs were ready for the first time. Not a cut-over blocker (no
  public output); a separate check.

## 2026-11-15 – Production cut-over done (VPS serves voltikka.fi)

User go 2026-11-15 ("Merge the PR and start the migration if possible now"); the user changed the
DNS record. Times are Helsinki.

- 19:17 PR #14 merged (`1a6a64a`). Pre-checks: no running workflow on either namespace, Railway
  `jobs` empty; the only `running` checkpoint is the stale `eex_futures` row of 2026-10-02.
- 19:19 Railway `php artisan down --retry=1800`; S pilot Schedules on `voltikka-prod` paused.
  `/` gave 503, `/up` stayed 200: the Sentry uptime monitor on `/up` did not see the outage.
- 19:20 VPS Schedules paused (18), `kamal app stop`, `voltikka` schema dropped and recreated
  (`utf8mb4_0900_ai_ci`, grants kept), dump and import in 436 s. Exact counts: 60 of 60 tables,
  7,227,519 rows on both sides, no difference.
- Files: `public` and `videos` from Railway into `voltikka-storage` (64 files, 59 logos). Redis
  `FLUSHALL`.
- 19:28–19:30 `kamal deploy --skip-push --version deploy-000026-b147c81b13b1` from `main`, both
  roles (126 s). `schedule:list` 21 entries, equal to Railway's 37 minus the 16 collectors (the
  plan's "23" was a counting error). Environment: `APP_URL=https://voltikka.fi`, Sentry
  `production`, `BACKUP_DISK=s3`, GTM in the page.
- 19:31 VPS Schedules unpaused: 18 active. 19:33 first production `backup:run --only-db` to GCS:
  203.27 MB, completed. Railway MySQL client file deleted from the VPS.
- About 19:35 the user pointed the `voltikka.fi` CNAME to the tunnel. Checks through Cloudflare:
  200 for `/up`, `/`, `/sahkosopimus`, `/spot-price`, `/sahkosopimus/tilastot`, a contract page,
  `/sahkosopimus/sahkoyhtiot`, `/sahkosopimus/porssisahko`, `/markkinakatsaus`,
  `/maksatko-liikaa`, `/sitemap.xml`; no `x-railway` headers; kamal-proxy logs show the requests;
  https canonical and assets; a company logo from `/storage`; `http://` redirects to https. In a
  browser, the 18 000 kWh preset recalculated the cards through Livewire (451 → 1 544 €/v for the
  first card), with no page errors. Laravel errors since the deploy: only the known PerusFiksu
  exclusion (PR #15 behaviour). No failed jobs.
- Outage: 19:19 to about 19:35, about 16 minutes.
- Railway stays in maintenance mode with the S pilot paused. Stopping its services needs the user's
  approval after a good first VPS morning (Mon 16.11). Rollback until then: `cutover-plan.md`.

## 2026-11-16 – First VPS production morning; Railway services stopped

- Morning of 2026-11-16 on the VPS: `eex_futures` ready 05:43, `contract_import` ready 06:11
  (through `pending_completion` 06:07–06:11), `morning_retail` ready 07:15, `morning_forecast`
  ready 07:30. No failed jobs. Backups at 03:00 in GCS (two archives, 406.62 MB).
- The 02:00 reboot (an update needed it) gave about 40 Laravel errors: the scheduler lost Docker's
  DNS at shutdown (`live-restore`), one overlap lock stayed in Redis for 33 minutes, and the first
  start failed `migrate` before MySQL was ready. Fixes: PR #20 (`docker-entrypoint.sh` waits up to
  60 s for MySQL and Redis; image `deploy-000027-aeaaee327859`, deployed to both VPS roles at 07:30
  after the morning checkpoints) and breezily-infra PR #2 (`breezily-app-containers.service` stops
  the app containers before Docker at shutdown and starts them at boot; installed on the server).
- User approval 2026-11-16: stop the Railway services. 08:30–08:31 `railway down` removed the active
  deployments of `voltikka`, `voltikka-worker` and `MySQL` (status `REMOVED`). Volumes and buckets
  are kept. `voltikka.fi` stays 200 on the VPS.
- Rollback from now on: redeploy the Railway MySQL and `deploy-000026-b147c81b13b1` on the Railway
  services, then the steps in `cutover-plan.md` ("Rollback"). Data written on the VPS after the
  switch must be dumped back first.
- Still open in phase 3: docs PR (root `AGENTS.md` "Production site" and Railway sections,
  `orchestration/AGENTS.md`, `scripts/deploy-image.sh`, breezily-infra README Voltikka row), free
  the first Fingrid key, delete the Railway project after 2026-11-30 (root `AGENTS.md`, "Dated
  follow-ups").
## 2026-11-16 – PR #23 (docs and Kamal deploy script), review round 1

Head `6f201ea`. CodeRabbit review 5916034010 (5 findings), Greptile Plus 4/5 (3 findings, 2 of
them duplicates). All valid (👍):
- `curl` health check without timeouts (both): `--connect-timeout 10 --max-time 30`.
- Worker readiness (both): `/up` checks only web. Fixed smaller: the script waits up to 60 s for
  `Worker started` (logged after `connect()`) in `voltikka-worker-<TAG>`. A Docker health command
  that would stop Kamal from stopping the old worker changes `worker.py` and `config/deploy.yml`;
  not in this docs PR (reply in both threads).
- Re-check `origin/main` and clean `config/`/`.kamal/` after the prompt (CodeRabbit).
- Railway archive boundary is the cut-over time 2026-11-15 19:35, and the old-password rule
  (`BACKUP_ARCHIVE_PASSWORD_UNTIL_2026_10_22` before 2026-10-22 08:10 UTC) is back in both
  backup texts (CodeRabbit, Greptile).
- DevelopmentDatabase notice names the rules that stay and the Railway transport rules that the
  VPS port replaces (CodeRabbit).

Round 2 (head `8633ff9`, CodeRabbit, 4 findings, all valid 👍): the Railway archive restore needs
the Railway bucket's own S3 settings and its passwords (restore text now has a separate paragraph
with the bucket ID, the settings to pipe into a mode-600 env file, the two password variables and
the approval rule); the worker check has a 60 s deadline from `SECONDS`, `ConnectTimeout=10` and a
remote `timeout 10`, and it also requires `State.Running=true` (tested against the running worker
and a missing container).

## 2026-11-16 – PR #24 (worker health check), review round 1

Head `5e69ea2`. CodeRabbit did not review (hourly allowance used up). Greptile Plus 4/5, one
finding, valid (👍): a worker rollback to an image without `READY_FILE` (`deploy-000027` and
older) would fail the new health check. Fix: the health command also passes when the image's
`worker.py` has no `READY_FILE`. Tested on `breezily-vps` with `deploy-000027`: the old image is
`healthy` at once; with `READY_FILE` in `worker.py` the container is `starting` until the ready file
exists, then `healthy`.

Round 2 (head `007648a`; CodeRabbit after its rate limit, 1 finding): "legacy images count as
healthy without a connection check": rejected (👎 + reply). The fallback only keeps the current
behaviour of rollback images (no health check), and those images have no in-container connection
signal (stdout log only).

## 2026-11-16 – Maintenance, spot and morning move to Temporal (user decision)

User decision 2026-11-16: "I think we can move everything over to Temporal now." This PR sets
`TEMPORAL_SCHEDULE_GROUPS: collectors,maintenance,spot,morning` on the web role.

- **Queue.** Stays in the web container (decision 2026-11-09): the shared Redis cache removes the
  reason for a separate `voltikka-queue`. A web deploy stops a running interpretation job; the queue
  retries it.
- **PLT-001 (condition of the morning cut-over).** Resolved by the second option: a missed or
  failed morning is recovered only by starting `MorningChain` for today's date; no hand-started
  `contracts:fetch`. Root `AGENTS.md` and `orchestration/AGENTS.md` state the rule.
- **Deploy window.** The morning chain runs in the worker, and the EEX step is interruptible, so the
  04:00–09:00 refusal of `scripts/deploy-image.sh` now applies when the worker role is deployed;
  a web-only deploy is free. Phase 4 (no window) needs two good Temporal mornings and a decision on
  the EEX step.
- **R4 morning test.** `MorningChain` has not run on real PHP and data yet. The first production
  run (the next 04:00) is the acceptance test; rollback: pause `morning-chain`, remove `morning`
  from the list, deploy web, and run the remaining commands in the web scheduler.
- **Worker memory 2g → 4g**, as the web role: the import, the price-cache builds and the consumers
  now run in the worker (up to 8 activities at once). The server has about 12 GB free.
- **Steps after the merge** (runbook above): deploy the web role outside 04:00–12:00, check
  that `schedule:list` has exactly two entries (`backup:run --only-files` and `logos:download`,
  as `WebSchedulerTest` expects), then at once
  `schedules --apply --group maintenance --group spot --group morning` in the worker.

### PR #25 review, round 1

Head `06aa14d`. CodeRabbit (3 findings) and Greptile Plus 4/5 (2 findings), all valid (👍):
- A new `MorningChain` fails while an old child is open (same `eex-futures-<date>` /
  `contract-import-<date>` IDs) (Greptile), and it does not repair a `failed` consumer claim
  (CodeRabbit): the recovery rule now says to read the running chain first, let it run, start a
  new chain only after the old one ended, and inspect a failed claim before an approved rerun.
- Status: maintenance, spot and morning are "listed on 2026-11-16, live from their cut-over"
  until the cut-over is recorded here (CodeRabbit).
- Stale window sentences removed or fixed in root `AGENTS.md`, `scripts/AGENTS.md` and
  `orchestration/AGENTS.md`: the window applies to worker deploys (both).

Round 2 (head `a5d0099`, CodeRabbit, 1 finding, valid 👍): start a replacement chain before
12:00 Helsinki; after noon the consumers stay incomplete without a separately approved run.

Round 3 (head `3d6f69b`, CodeRabbit, 2 findings, valid 👍): start a replacement chain by about
10:00 so EEX (1 h 42 min clean) and the import end before noon; check that both children are
closed (Temporal UI or `temporal workflow describe`) before a new chain starts.
