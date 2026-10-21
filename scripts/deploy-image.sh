#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/deploy-image.sh [--yes] TAG

Point the Voltikka production app service at one immutable GHCR image tag that
scripts/build-and-push-image.sh has already pushed. Railway then starts a deployment of
that image. This is a PRODUCTION MUTATION: get explicit user confirmation first.

The script:
- refuses `latest` and any tag that is not deploy-NNNNNN-<sha12>
- refuses to run between 04:00 and 09:00 Europe/Helsinki (EEX futures import window)
- checks that the image exists in GHCR
- asks for the word "deploy" unless --yes is given
- patches source.image with `railway environment edit` and explicit Railway IDs

Rollback is the same command with an earlier known-good tag.

After the patch, find the new deployment and poll it with scripts/railway-poll-deployment.sh.

Examples:
  scripts/deploy-image.sh deploy-000001-0123456789ab
  scripts/deploy-image.sh --yes deploy-000002-ba9876543210
USAGE
}

PROJECT_ID="6d8cae01-1006-409f-8108-1d51f1abc676"
ENVIRONMENT_ID="9245cef8-41d0-486e-862f-193726511dba"
SERVICE_ID="700d0624-fa96-4266-876c-e37640d220ea"
IMAGE_REPO="ghcr.io/sepsi77/voltikka"

TAG=""
ASSUME_YES=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --yes|-y) ASSUME_YES=1; shift ;;
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

if [[ -z "$TAG" ]]; then
  echo "Missing TAG." >&2
  usage >&2
  exit 2
fi

if [[ "$TAG" == "latest" ]]; then
  echo "Refusing to deploy mutable tag ':latest'." >&2
  exit 1
fi

if [[ ! "$TAG" =~ ^deploy-[0-9]{6}-[0-9a-f]{12}$ ]]; then
  echo "Refusing tag '${TAG}': expected deploy-NNNNNN-<sha12> from scripts/build-and-push-image.sh." >&2
  exit 1
fi

helsinki_hour=$((10#$(TZ=Europe/Helsinki date +%H)))
if (( helsinki_hour >= 4 && helsinki_hour < 9 )); then
  echo "Refusing to deploy at $(TZ=Europe/Helsinki date +%H:%M) Europe/Helsinki." >&2
  echo "futures:fetch-eex runs from 04:00 until about 09:00, and a deploy stops it. See AGENTS.md 'Production code deployment'." >&2
  exit 1
fi

IMAGE="${IMAGE_REPO}:${TAG}"

if ! docker buildx imagetools inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Image ${IMAGE} is not readable in GHCR. Push it with scripts/build-and-push-image.sh first." >&2
  exit 1
fi

cat <<SUMMARY
Production deploy
  project:     ${PROJECT_ID} (Voltikka)
  environment: ${ENVIRONMENT_ID} (production)
  service:     ${SERVICE_ID} (voltikka)
  image:       ${IMAGE}
Effect: Railway stops the current container and starts this image (short outage).
Before 12:00 Helsinki, confirm that the EEX import and both morning consumers have finished.
SUMMARY

if (( ! ASSUME_YES )); then
  read -r -p 'Type "deploy" to continue: ' answer
  if [[ "$answer" != "deploy" ]]; then
    echo "Cancelled." >&2
    exit 1
  fi
fi

SESSION="${RAILWAY_AGENT_SESSION:-railway-skill-$(date +%s)-$$}"

printf '{"services":{"%s":{"source":{"image":"%s"}}}}' "$SERVICE_ID" "$IMAGE" | \
  RAILWAY_CALLER=skill:use-railway@1.2.2 RAILWAY_AGENT_SESSION="$SESSION" railway environment edit \
    --project "$PROJECT_ID" \
    --environment "$ENVIRONMENT_ID" \
    --message "Deploy Voltikka ${TAG}" \
    --json

echo
echo "Railway source.image set to ${IMAGE}."
echo "Next: read the service config back, confirm source.image, then poll the new deployment:"
echo "  RAILWAY_CALLER=skill:use-railway@1.2.2 RAILWAY_AGENT_SESSION=${SESSION} scripts/railway-poll-deployment.sh \\"
echo "    --project ${PROJECT_ID} --environment ${ENVIRONMENT_ID} --service ${SERVICE_ID} --deployment <DEPLOYMENT_ID>"
