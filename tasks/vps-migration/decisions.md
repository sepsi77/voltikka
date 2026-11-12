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
