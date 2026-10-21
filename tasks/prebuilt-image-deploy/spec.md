# Prebuilt immutable image deploy (local preparation)

## Goal

Move Voltikka production releases from "Railway builds from GitHub on every push to
`main`" to "manual deploy of a locally built, immutable GHCR image", the same model as the
Clickbuddy marketing site (`/Users/seppo/clickbuddy/.agents/skills/clickbuddy-website-deployment/SKILL.md`).

Benefits: a push to `main` no longer deploys; a deploy is one explicit, reviewable step;
rollback is "set `source.image` to an earlier tag"; the deployed bytes are fixed per tag.

## Scope of this task (local only)

- No git push, no registry push, no Railway mutation, no commit.
- Prepare: build-context safety, build script, deploy script, local test build, the
  AGENTS.md draft (in `decisions.md`), and the cutover checklist below.

## Requirements

1. The image must contain only committed content of one exact commit. Local secrets and
   data (`laravel/.env`, `laravel/database/database.sqlite`, sessions, file cache, imports,
   logs, `vendor`, `node_modules`, `bootstrap/cache/*.php`) must never enter it.
2. Tags are immutable: `deploy-NNNNNN-<sha12>`, number from GHCR package history,
   `<sha12>` = the source commit. Never `latest`. Never overwrite an existing tag.
3. Platform `linux/amd64` (Railway).
4. Deploy = patch Railway `source.image` with explicit project/environment/service IDs and
   `RAILWAY_CALLER=skill:use-railway@1.2.2`.
5. Existing deploy rules stay: no deploy 04:00–09:00 Helsinki, wait for the EEX import and
   morning consumers, explicit user confirmation, poll the exact deployment, verify `/up`.

## Files

- `.dockerignore` (new) — allowlist of Dockerfile COPY sources plus gitignore mirror.
- `scripts/build-and-push-image.sh` (new) — clean `git archive` export of HEAD, tag
  generation, `--no-push` local mode.
- `scripts/deploy-image.sh` (new, never run) — guarded `railway environment edit` patch.
- `scripts/AGENTS.md` — short section for the two scripts (marked "not active until cutover").

## Railway cutover checklist (NOT performed; each step needs explicit user approval)

Production IDs: project `6d8cae01-1006-409f-8108-1d51f1abc676`, environment
`9245cef8-41d0-486e-862f-193726511dba`, service `700d0624-fa96-4266-876c-e37640d220ea`.

Before the cutover:

1. Commit and push the prepared files (`.dockerignore`, the two scripts, docs). Note: a
   push still deploys through the GitHub source at this point, and the new `.dockerignore`
   then applies to that Railway build too (verified: context equals tracked files of the
   COPY paths). Respect the 04:00–09:00 window.
2. Create a GitHub classic PAT for Railway pulls with `read:packages` only (fine-grained
   tokens do not cover GHCR). Store it only in Railway. Never print it.
3. Build and push the first image from the deployed commit:
   `scripts/build-and-push-image.sh "deploy-000001-$(git rev-parse --short=12 HEAD)"`
   (the GHCR package does not exist yet, so automatic numbering returns HTTP 404 until the
   first push). Confirm the new package `ghcr.io/sepsi77/voltikka` is **private**
   (GitHub > Packages > voltikka > Package settings), and optionally link it to the
   `sepsi77/voltikka` repository.
4. Optional smoke check of the pushed image on the local machine (no production env).

Cutover (one approved change window, before 04:00 or after the morning consumers):

5. Railway service settings > Source: add private registry credentials for `ghcr.io`
   (username `sepsi77`, the read-only PAT). This is a service-level setting.
6. Change the service source from the GitHub repo to the image
   `ghcr.io/sepsi77/voltikka:deploy-000001-<sha12>`. Recommended: stage first
   (`railway environment edit ... --stage` with a JSON patch that sets `source.image`),
   then read `get-staged-changes` / `describe-service` and confirm that `source.repo` and
   `source.branch` are gone and `source.image` is the exact tag. Only then commit the
   staged change. If Railway keeps `source.repo` beside `source.image`, disconnect the
   repository in the dashboard (Settings > Source > Disconnect).
7. Confirm the GitHub trigger is gone: the service must show no connected repo/branch, and
   the Railway GitHub app needs no access to the repo for this service (no other Voltikka
   service uses it). "Check suites" is already off.
8. Confirm image auto-updates are off for the service (Railway can redeploy on a new
   image digest for a tag; with immutable tags there is none, but keep the setting off).
9. Keep the existing settings: health check `/up`, timeout 600 s, `PORT=8000`, volume
   `/app/storage`, custom domain `voltikka.fi` on port 8000, region `europe-west4`,
   1 replica. No pre-deploy command is used: `docker-entrypoint.sh` runs migrations.
   The `RAILPACK`/Dockerfile build settings become unused.
10. Poll the deployment with `scripts/railway-poll-deployment.sh` and the deployment ID,
    then verify `https://voltikka.fi/up`, the home page, one contract page and one
    `/build/assets/*.css` URL from the home page HTML (HTTP 200 and
    `Cache-Control: ... immutable`).
11. Watch the scheduler and queue worker (bounded deploy logs) and the next hourly
    `spot:fetch`.

After the cutover:

12. Apply the AGENTS.md draft from `decisions.md` to the root `AGENTS.md` (CLAUDE.md is a
    symlink, so one edit covers both) and update `scripts/AGENTS.md` to say the flow is
    active.
13. Rollback plan: `scripts/deploy-image.sh <earlier tag>`. Emergency rollback to the old
    model: reconnect the GitHub repo/branch `main` in Railway service settings.
