# VPS migration: move Voltikka hosting from Railway to a VPS

Status 2026-11-09: started. Phase 0 (decisions) is open; nothing is built or changed.

## Goal

Run Voltikka on a VPS instead of Railway. The VPS is also the only place where the Temporal
phase 2 orchestration goes live (`tasks/temporal-orchestration/phase2-plan.md`), so the Temporal
setup can be tested there without a change to Railway production.

## Current Railway layout (what must move)

See root `AGENTS.md`, "Railway production operations", for IDs and rules.

| Railway resource | Role |
|---|---|
| `voltikka` | Web service: FrankenPHP, one GHCR image (`ghcr.io/sepsi77/voltikka:deploy-NNNNNN-<sha12>`), Supervisor with `schedule:work` and `queue:work`, volume on `/app/storage` (file cache, logs, public files such as logos), health check `/up` |
| `MySQL` | Production database |
| `voltikka-backups` bucket | Encrypted `spatie/laravel-backup` archives (S3 API) |
| `voltikka-forecast` bucket | Own S model files (private) |
| `voltikka-worker` | Temporal worker (phase 1 S pilot), same image, Temporal Cloud namespace `voltikka-prod` |
| Domain `voltikka.fi` | Public site |

Phase 2 also needs a shared Redis cache and a separate queue worker (`phase2-plan.md`).

## Requirements

- **S1 Same image.** The VPS runs the same immutable GHCR image as Railway (web, worker, queue
  modes of the existing entrypoints). No second build path.
- **S2 Test without production effect.** The first VPS environment must not write production data
  or take production Temporal tasks while Railway is production: its own database (a copy), its
  own Temporal namespace or task queue, external posting off, and no duplicate scheduler writes to
  shared stores (buckets, Sentry project tags).
- **S3 Phase 2 cut-over on the VPS only.** Temporal Schedules are applied only on the VPS, in the
  phase 2 group order (collectors, then maintenance and spot after Redis, then morning). A group's
  Temporal Schedules and its `laravel/routes/console.php` entries never both run against the same
  database.
- **S4 Data safety.** The production database moves with a verified backup and a row-count and
  integrity check; a rollback path to Railway exists until the VPS has run production for an agreed
  period.
- **S5 Operations parity.** TLS, health check, deploys by immutable tag, rollback to an earlier
  tag, backups (with restore drill), Sentry, bounded logs and the morning pipeline checks all work on
  the VPS before the production cut-over.
- **S6 Short public outage.** The production cut-over has a planned, short outage and DNS steps,
  stated and approved in advance.
- **S7 Documentation.** Root `AGENTS.md` (hosting, deploy, safe-operation rules), the deploy scripts
  and `orchestration/AGENTS.md` describe the VPS instead of Railway after the cut-over.

## Open decisions (phase 0)

1. VPS provider, region and size (EU; the database and the worker on the same host or not).
2. Database: MySQL on the VPS, a managed database, or Railway MySQL kept for a period.
3. Buckets: keep Railway object storage, or move to another S3 provider (an off-provider backup
   copy is already a wanted improvement).
4. Process model on the VPS: Docker Compose with the GHCR image, or another runner; how a deploy
   by tag and a rollback work.
5. Temporal: a separate namespace for the VPS test (for example `voltikka-vps`) or a separate task
   queue in `voltikka-prod`; what happens to Railway `voltikka-worker`.
6. Test data: how the test database is filled (production snapshot) and refreshed.
7. Secrets: where the VPS keeps the variables that Railway holds today (never printed).
8. When the deploy window (04:00–09:00) and its script refusal go away (phase 2 R5).

## Done when

- [ ] Phase 0 decisions are recorded in `decisions.md` with the user's approval.
- [ ] A VPS test environment runs the image with its own database and Temporal namespace, and the
      phase 2 collectors group has run there on Temporal Schedules for at least one week.
- [ ] The production cut-over plan (S4–S6) is written and approved.
- [ ] Production runs on the VPS; Railway services are stopped only after the agreed period.
- [ ] Docs and scripts describe the VPS (S7).
