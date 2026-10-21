#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/build-and-push-image.sh [--no-push] [TAG]

Build the Voltikka production image for linux/amd64 from a clean `git archive` export of
the committed HEAD and push it to GHCR with one immutable tag.

- Uncommitted changes never ship: the build context is the committed HEAD, not the working
  tree. The script refuses to run when tracked files have uncommitted changes, so that the
  tested tree and the shipped tree are the same. Untracked files are ignored.
- With no TAG, the next tag is generated from the GHCR package history as
  deploy-NNNNNN-<sha12>. An explicit TAG must end with -<sha12> of HEAD.
- The script refuses `latest` and refuses a tag that already exists in GHCR.
- --no-push builds and loads voltikka:local-<sha12> into the local Docker engine. It does
  not read or write GHCR. Use it to validate Dockerfile changes.

This script never changes Railway. Deploy a pushed tag with scripts/deploy-image.sh.

Examples:
  scripts/build-and-push-image.sh
  scripts/build-and-push-image.sh "deploy-000001-$(git rev-parse --short=12 HEAD)"
  scripts/build-and-push-image.sh --no-push
USAGE
}

IMAGE_REPO="${IMAGE_REPO:-ghcr.io/sepsi77/voltikka}"
PACKAGE_NAME="${PACKAGE_NAME:-voltikka}"
TAG_PREFIX="deploy"
TAG=""
PUSH=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-push) PUSH=0; shift ;;
    --help|-h) usage; exit 0 ;;
    -*) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    *)
      if [[ -n "$TAG" ]]; then
        echo "Only one TAG is allowed." >&2
        exit 2
      fi
      TAG="$1"
      shift
      ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo "Refusing to build: tracked files have uncommitted changes." >&2
  echo "Commit the exact code to deploy (or stash the other changes), then run again." >&2
  git status --short --untracked-files=no >&2
  exit 1
fi

COMMIT="$(git rev-parse HEAD)"
SHA12="$(git rev-parse --short=12 HEAD)"

untracked_count="$(git status --porcelain --untracked-files=normal | grep -c '^??' || true)"
if [[ "$untracked_count" != "0" ]]; then
  echo "Note: ${untracked_count} untracked path(s) exist. They are not in the image."
fi

if git rev-parse --verify --quiet origin/main >/dev/null \
  && ! git merge-base --is-ancestor "$COMMIT" origin/main; then
  echo "Warning: ${SHA12} is not in the local origin/main ref. Push the commit before a production deploy so the image source stays traceable." >&2
fi

package_tags_from_gh() {
  if ! command -v gh >/dev/null 2>&1; then
    return 1
  fi

  gh api \
    --paginate \
    "/user/packages/container/${PACKAGE_NAME}/versions?per_page=100" \
    --jq '.[].metadata.container.tags[]?' 2>/dev/null
}

package_tags_from_rest() {
  local token="${GH_TOKEN:-${GITHUB_TOKEN:-}}"

  if [[ -z "${token}" ]] && command -v docker-credential-osxkeychain >/dev/null 2>&1; then
    token="$(printf 'ghcr.io' | docker-credential-osxkeychain get 2>/dev/null | python3 -c 'import json, sys; print(json.load(sys.stdin).get("Secret", ""))' 2>/dev/null || true)"
  fi

  if [[ -z "${token}" ]]; then
    return 1
  fi

  # The token goes to Python through the environment, not the process argument list.
  GHCR_LIST_TOKEN="$token" python3 - "$PACKAGE_NAME" <<'PY'
import json
import os
import sys
import urllib.error
import urllib.request

package = sys.argv[1]
token = os.environ["GHCR_LIST_TOKEN"]
page = 1
while True:
    url = f"https://api.github.com/user/packages/container/{package}/versions?per_page=100&page={page}"
    request = urllib.request.Request(
        url,
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    try:
        with urllib.request.urlopen(request) as response:
            versions = json.load(response)
    except urllib.error.HTTPError as error:
        print(f"GitHub package API returned HTTP {error.code} for {package}.", file=sys.stderr)
        sys.exit(1)

    if not versions:
        break

    for version in versions:
        for tag in version.get("metadata", {}).get("container", {}).get("tags", []):
            print(tag)

    if len(versions) < 100:
        break

    page += 1
PY
}

next_tag() {
  local tags max_number next_number

  if ! tags="$(package_tags_from_gh)"; then
    if ! tags="$(package_tags_from_rest)"; then
      echo "Unable to read existing GHCR package tags for ${PACKAGE_NAME}." >&2
      echo "Set GH_TOKEN/GITHUB_TOKEN with read:packages, or pass an explicit immutable tag." >&2
      echo "For the first image (no package yet), pass: ${TAG_PREFIX}-000001-${SHA12}" >&2
      exit 1
    fi
  fi

  max_number="$(TAGS="${tags}" python3 - "$TAG_PREFIX" <<'PY'
import os
import re
import sys

prefix = re.escape(sys.argv[1])
pattern = re.compile(rf"^{prefix}-(\d+)-[0-9a-f]{{7,40}}$")
maximum = 0
for line in os.environ.get("TAGS", "").splitlines():
    match = pattern.match(line.strip())
    if match:
        maximum = max(maximum, int(match.group(1)))
print(maximum)
PY
)"

  next_number=$((max_number + 1))
  printf '%s-%06d-%s\n' "${TAG_PREFIX}" "${next_number}" "${SHA12}"
}

if (( PUSH )); then
  if [[ -z "${TAG}" ]]; then
    TAG="$(next_tag)"
  fi

  if [[ "${TAG}" == "latest" ]]; then
    echo "Refusing to push mutable tag ':latest'. Use a generated deployment tag." >&2
    exit 1
  fi

  if [[ ! "${TAG}" =~ ^[A-Za-z0-9_.-]+$ ]]; then
    echo "Invalid image tag '${TAG}'. Use only letters, numbers, dots, underscores, and hyphens." >&2
    exit 1
  fi

  if [[ "${TAG}" != *"-${SHA12}" ]]; then
    echo "Refusing tag '${TAG}': it must end with -${SHA12} (the HEAD commit) so the tag matches the image source." >&2
    exit 1
  fi

  IMAGE="${IMAGE_REPO}:${TAG}"

  if docker buildx imagetools inspect "${IMAGE}" >/dev/null 2>&1; then
    echo "Refusing to overwrite existing image ${IMAGE}. Tags are immutable." >&2
    exit 1
  fi

  OUTPUT_ARGS=(--push)
else
  IMAGE="voltikka:local-${SHA12}"
  OUTPUT_ARGS=(--load)
fi

EXPORT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/voltikka-image-src.XXXXXX")"
cleanup() {
  rm -rf "${EXPORT_DIR}"
}
trap cleanup EXIT

git archive --format=tar "${COMMIT}" | tar -xf - -C "${EXPORT_DIR}"

if (( PUSH )); then
  echo "IMAGE_TAG=${TAG}"
fi
echo "IMAGE=${IMAGE}"
echo "SOURCE_COMMIT=${COMMIT}"
echo "Building ${IMAGE} for linux/amd64 from a clean export of ${SHA12}"

docker buildx build \
  --platform linux/amd64 \
  --file "${EXPORT_DIR}/Dockerfile" \
  --label "org.opencontainers.image.source=https://github.com/sepsi77/voltikka" \
  --label "org.opencontainers.image.revision=${COMMIT}" \
  --tag "${IMAGE}" \
  "${OUTPUT_ARGS[@]}" \
  "${EXPORT_DIR}"

if (( PUSH )); then
  echo "Pushed ${IMAGE}"
else
  echo "Loaded ${IMAGE} into the local Docker engine (not pushed)"
fi
