# Decisions: prebuilt immutable image deploy

## 2026-10-21 — Build from a clean `git archive` export of HEAD

Finding: the repository root had **no `.dockerignore`**, and the Dockerfile does
`COPY laravel/ .`. Railway is safe today only because it builds from a clean git checkout.
A local `docker build .` from the working tree would have copied into the image:

- `laravel/.env` (local secrets) and the build-time `composer`/`artisan package:discover`
  would also have read it;
- `laravel/database/database.sqlite` (about 2 GB, a local copy of production application
  data);
- `laravel/storage/` (about 117 MB: sessions, file cache, compiled views, `app/imports`,
  `app/videos`, review JSON files, logos);
- `laravel/bootstrap/cache/packages.php` and `services.php` (dev package manifests; with
  `composer install --no-dev` these can name missing dev providers);
- `laravel/vendor` (dev dependencies), `laravel/node_modules`, `laravel/public/build`,
  `public/storage` symlink, `.phpunit.result.cache`, ignored forecasting outputs;
- `remotion/node_modules` (340 MB) and the ignored `remotion/build` bundle.

Decision: the build script always builds from `git archive <HEAD>` extracted to a temporary
directory. Untracked and ignored files cannot enter the image, and the `<sha12>` in the tag
is exactly the content. The script refuses to run when tracked files have uncommitted
changes, so the tree that was tested is the tree that ships. Untracked files only produce a
note (they never ship). It warns, but does not refuse, when HEAD is not in the local
`origin/main` ref.

Second layer: a root `.dockerignore` (allowlist of the Dockerfile COPY sources:
`Dockerfile`, `docker-entrypoint.sh`, `supervisord.conf`, `laravel`, `remotion`, then the
`laravel/.gitignore`, nested storage `.gitignore`, `remotion/.gitignore` and root `build/`
rules). Verified on 2026-10-21 with a `FROM scratch; COPY . /ctx/` export of the
working-tree context: 1,494 files, exactly equal to `git ls-files` of those paths (no
extra, no missing). It does not change what Railway's current GitHub build copies; it only
removes `design/`, `tasks/`, `media/`, `legacy/` from the uploaded context. Unknown
untracked files can still pass `.dockerignore`, which is why the export is primary.

`git archive` honours `export-ignore`. Only `laravel/.gitattributes` has such rules
(`/.github`, `CHANGELOG.md`, `.styleci.yml`) and no tracked file matches them, so the export
equals a checkout. Re-check if those attributes change.

Rejected: `git worktree add` (exact too, but checks out about 830 MB of `design/` and
`tasks/` each time) and a `.dockerignore`-only fix (cannot exclude unknown untracked files,
and would ship uncommitted tracked edits under a SHA that does not contain them).

## 2026-10-21 — Build-time variables

- The Dockerfile has no `ARG`, so Railway injects no service variable into the build. The
  Railway variable names (read with read-only `get-service-config`, no values) contain no
  `VITE_*` key.
- `resources/js`, `vite.config.js`, `tailwind.config.js`, `postcss.config.js` and
  `package.json` read no `import.meta.env`, `VITE_*` or `process.env`. `.env.example` has
  `VITE_APP_NAME`, but no code reads it.
- `composer install` runs `package:discover` and `filament:upgrade` with no `.env` (same as
  Railway).
- Runtime code does not read `RAILWAY_GIT_*`; `SENTRY_RELEASE` is not a Railway variable.
  Image deploys lose Railway's commit metadata on the deployment, so the script adds OCI
  labels `org.opencontainers.image.revision=<commit>` and `.source`.

Conclusion: a local build misses nothing that the Railway build has. One difference stays:
`FROM dunglas/frankenphp:1-php8.4` and `COPY --from=composer:latest` are floating; a local
build uses the base image that the local builder pulls. Pinning digests is a possible later
change, not part of this task.

## 2026-10-21 — Tags and GHCR access

- Image repo `ghcr.io/sepsi77/voltikka`. Tag listing uses
  `/user/packages/container/voltikka/versions` (user package, not `/orgs/...`).
- `gh` has no `read:packages`, so the `gh` path fails; the script falls back to the macOS
  keychain GHCR credential (token login `sepsi77`, scopes `repo, write:packages`). On
  2026-10-21 the call returned HTTP 404 because the package does not exist yet. The first
  push therefore needs the explicit tag `deploy-000001-<sha12>`. A 404 is an error, not
  "zero tags", so a wrong package name cannot silently restart the numbering.
- An explicit tag must end with `-<sha12>` of HEAD. The script refuses `latest` and an
  existing tag (`docker buildx imagetools inspect`).
- `--no-push` builds `voltikka:local-<sha12>` with `--load` and never contacts GHCR.

## 2026-10-21 — Deploy script

`scripts/deploy-image.sh` uses the JSON patch form proven in Clickbuddy
(`printf '{"services":{"<id>":{"source":{"image":"..."}}}}' | railway environment edit
--project ... --environment ... --message ... --json`), because the dot-path form can
report "No changes to apply". Guards: tag format `deploy-NNNNNN-<sha12>`, no `latest`,
refuse 04:00–09:00 Helsinki, image must be readable in GHCR, interactive "deploy"
confirmation unless `--yes`. It was not run. Refusal paths were tested with a stub
`railway` binary first in `PATH`; no path reached the stub.

## Draft for root AGENTS.md "Production code deployment" (applied 2026-10-21)

Root `CLAUDE.md` is a symlink to `AGENTS.md`, so one edit covers both. Replace the first
paragraph and the numbered list (lines "The `voltikka` app service is connected ..."
through step 7) with the text below. Keep the outage paragraph and the 04:00–09:00
paragraph. Replace the last paragraph ("A push to `origin/main` is a production
mutation ...") with the last paragraph below.

```markdown
The `voltikka` app service runs one immutable GHCR image, `ghcr.io/sepsi77/voltikka:<tag>`. A push to `origin/main` does **not** deploy. The service source is the image, not the GitHub repository; Railway pulls it with a read-only registry credential stored in the service settings (never print it). The source of truth for the deployed version is the service `source.image` value. Tags are `deploy-NNNNNN-<sha12>`: the number orders deployments and `<sha12>` is the exact source commit. Never deploy `latest` and never overwrite a tag.

1. Run the relevant tests and production asset build. Check `git diff --check` and `git status --short`.
2. Stage only the intended files and commit them. Push the commit to `origin/main` so the image source stays traceable (the push does not deploy).
3. Build and push the image from the repository root: `scripts/build-and-push-image.sh`. It builds `linux/amd64` from a clean `git archive` export of HEAD, so uncommitted and untracked files (`laravel/.env`, the local SQLite database, storage) never enter the image, and it refuses to run with uncommitted tracked changes. It prints `IMAGE_TAG=...`. If the GHCR tag list cannot be read, pass an explicit tag `deploy-NNNNNN-$(git rev-parse --short=12 HEAD)` with the next number. `--no-push` builds a local `voltikka:local-<sha12>` image to validate Dockerfile changes.
4. Deploy with `scripts/deploy-image.sh <IMAGE_TAG>`. It patches Railway `source.image` with the explicit project, environment and service IDs and `RAILWAY_CALLER=skill:use-railway@1.2.2`, and refuses `latest`, unknown images and the 04:00–09:00 Helsinki window. Do **not** use `railway up`, `railway redeploy`, or a dashboard redeploy for a normal release.
5. Read the service config back and confirm `source.image` is the exact tag. Use Railway MCP to find the new deployment.
6. Poll that exact deployment with `scripts/railway-poll-deployment.sh` and the explicit project, environment, service, and deployment IDs.
7. After Railway reports `SUCCESS`, verify `/up` and the relevant production page or asset. For a failure, read bounded deploy logs before taking further action.

Rollback: run `scripts/deploy-image.sh <earlier known-good tag>`. Find earlier tags in Railway deployment history or the GHCR package versions.
```

```markdown
`scripts/deploy-image.sh` (and any `source.image` change) is a production mutation because it starts a deployment. State the image tag, the target project/environment/service, and the expected effect, and get explicit user confirmation before running it. Building and pushing an image does not change production.
```

Also update the root "Production backups and disaster recovery" or Railway sections only if
the cutover changes them (it does not, as planned).

## 2026-10-21 — Local test build result

- `scripts/build-and-push-image.sh --no-push` at HEAD `5ff86c292587` (clean export, no
  `.dockerignore` in that commit): EXIT 0 in 681 s on the arm64 Mac with OrbStack amd64
  emulation (cold cache; another agent's build ran on the same builder at the same time).
  Image `voltikka:local-5ff86c292587`, amd64, 2.59 GB, label revision = full HEAD SHA.
- Same export plus the new `.dockerignore`: context 99.6 MB, all layers cached, same image
  ID (`sha256:e468f479...`). The `.dockerignore` changes the upload size only, not the image.
- Offline smoke run (`--network none`, empty directory mounted on `/app/storage`, SQLite
  database, random `APP_KEY`, `WARM_CONTRACT_CACHE_ON_START=0`): the entrypoint ran all
  migrations and config/route/view caches, supervisord started FrankenPHP, scheduler and
  queue worker, `/up` and `/` returned HTTP 200 about 6 s after start.
- Image content check (entrypoint bypassed): no `/app/.env`, no `database.sqlite`, no
  `vendor/phpunit`, no `node_modules`, no `remotion/build`, no `public/hot` or
  `public/storage`; `storage/` holds only the 10 tracked `.gitignore` files;
  `public/build/manifest.json` exists.

## 2026-10-21 — Cutover done early for the PHP security release

PHP 8.4.27 (CVE-2026-109618, CVE-2026-109619, both CVSS 9.2, FrankenPHP affected) was released at
about 13:37 UTC. Railway builds were paused by incident QH8L8B3E, and `dunglas/frankenphp` had no
8.4.27 image yet. The user decided to switch to image deploys at once:

- Base: `local/frankenphp:1-php8.4.27`, built on this Mac from docker-library/php `8.4/trixie/zts`
  (commit 9dac3d0, only PHP_VERSION/URL/ASC_URL/SHA256 changed; SHA256 and GPG verified) and
  FrankenPHP v1.13.1 (the upstream `1-php8.4` revision). Same modules and config as upstream.
- App image: `ghcr.io/sepsi77/voltikka:deploy-000001-5ff86c292587`, built from a clean
  `git archive` of 5ff86c2 with `--build-context dunglas/frankenphp:1-php8.4=docker-image://local/frankenphp:1-php8.4.27`.
  The Dockerfile `FROM` is unchanged, so later script builds use the upstream base again.
- Railway: `connect-service-source` (staged) removed repo/branch and set the image; a staged
  JSON patch added `deploy.registryCredentials` (sepsi77 + a `read:packages` token). Both were
  committed with `accept-deploy`. Volume, domain, health check and region are unchanged.
- The deploy waited for the Railway incident to end. It also releases cd7f3aa (ENTSO-E retries),
  which was on `origin/main` but not deployed (production ran e0e2237).
- Before the next build, check that the pulled upstream base has PHP >= 8.4.27.

## 2026-10-25 — Stale cached base in deploy-000002..000004

Found while investigating the 2026-10-25 backup failure: Sentry reported `runtime: php 8.4.26` on
production events. `docker run ... php -v` gives 8.4.27 for `deploy-000001-5ff86c292587` and
8.4.26 for `deploy-000004-0786b23e8f1a`. The local Docker cache held `dunglas/frankenphp:1-php8.4`
from 2026-10-10 (PHP 8.4.26), and `docker buildx build` without `--pull` used it for
`deploy-000002` (2026-10-23 16:31 UTC), `deploy-000003` and `deploy-000004`. Upstream
`1-php8.4` was already 8.4.27 (digest `sha256:b5e21479...`). Production therefore ran without the
CVE-2026-109618/109619 fixes from 2026-10-23 16:31 UTC.

Decision: `build-and-push-image.sh` always passes `--pull`. The local tag was refreshed on
2026-10-25 and gives 8.4.27. The next image must be checked with `php -v` before deploy.
