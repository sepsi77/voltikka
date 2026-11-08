#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/deploy-image.sh [--yes] [--morning-checks-passed] [--service web|worker|queue] TAG

Point one Voltikka production service at one immutable GHCR image tag that
scripts/build-and-push-image.sh has already pushed. --service web (default) is the app
service `voltikka`; --service worker is the Temporal worker `voltikka-worker`; --service queue
is the Laravel queue worker `voltikka-queue`
(tasks/temporal-orchestration/). Railway then starts a deployment of
that image. This is a PRODUCTION MUTATION: get explicit user confirmation first.

The script:
- refuses `latest` and any tag that is not deploy-NNNNNN-<sha12>
- for the web service only: refuses to run between 04:00 and 09:00 Europe/Helsinki (EEX
  futures import window), unless --morning-checks-passed is given: use it only after today's
  eex_futures, morning_retail and morning_forecast checkpoints have finished (none is running).
  A worker deploy does not stop the web container, so the window does not apply to it; Temporal
  retries the activities that the worker restart stops.
- checks that the image exists in GHCR
- asks for the word "deploy" unless --yes is given
- patches source.image with `railway environment edit` and explicit Railway IDs

Rollback is the same command with an earlier known-good tag.

After the patch, find the new deployment and poll it with scripts/railway-poll-deployment.sh.

Examples:
  scripts/deploy-image.sh deploy-000001-0123456789ab
  scripts/deploy-image.sh --yes deploy-000002-ba9876543210
  scripts/deploy-image.sh --service worker deploy-000021-0123456789ab
USAGE
}

PROJECT_ID="6d8cae01-1006-409f-8108-1d51f1abc676"
ENVIRONMENT_ID="9245cef8-41d0-486e-862f-193726511dba"
WEB_SERVICE_ID="700d0624-fa96-4266-876c-e37640d220ea"
WORKER_SERVICE_ID="73a37ca9-6280-4695-856a-7674e548e74c"
# Laravel queue worker (tasks/temporal-orchestration/phase2-plan.md). Set when the service exists.
QUEUE_SERVICE_ID=""
IMAGE_REPO="ghcr.io/sepsi77/voltikka"

TAG=""
ASSUME_YES=0
MORNING_CHECKS_PASSED=0
SERVICE="web"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --yes|-y) ASSUME_YES=1; shift ;;
    --morning-checks-passed) MORNING_CHECKS_PASSED=1; shift ;;
    --service)
      if [[ $# -lt 2 ]]; then echo "--service needs web, worker or queue." >&2; exit 2; fi
      SERVICE="$2"; shift 2 ;;
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

case "$SERVICE" in
  web) SERVICE_ID="$WEB_SERVICE_ID"; SERVICE_NAME="voltikka"
    EFFECT="Railway stops the current container and starts this image (short outage)." ;;
  worker) SERVICE_ID="$WORKER_SERVICE_ID"; SERVICE_NAME="voltikka-worker"
    EFFECT="Railway starts the Temporal worker on this image; on the old worker, interruptible commands stop and repeat on the new one, and claim-based commands finish within the drain." ;;
  queue) SERVICE_ID="$QUEUE_SERVICE_ID"; SERVICE_NAME="voltikka-queue"
    if [[ -z "$SERVICE_ID" ]]; then echo "voltikka-queue does not exist yet: set QUEUE_SERVICE_ID first." >&2; exit 2; fi
    EFFECT="Railway restarts the queue worker on this image; a running job can finish during shutdown, and an interrupted job follows the queue retry rules." ;;
  *) echo "Unknown service '${SERVICE}': use web, worker or queue." >&2; exit 2 ;;
esac

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
if [[ "$SERVICE" == "web" ]] && (( helsinki_hour >= 4 && helsinki_hour < 9 && ! MORNING_CHECKS_PASSED )); then
  echo "Refusing to deploy at $(TZ=Europe/Helsinki date +%H:%M) Europe/Helsinki." >&2
  echo "futures:fetch-eex runs from 04:00 until about 09:00, and a deploy stops it. See AGENTS.md 'Production code deployment'." >&2
  echo "If today's EEX import and both morning consumers have finished, add --morning-checks-passed." >&2
  exit 1
fi

IMAGE="${IMAGE_REPO}:${TAG}"

if ! docker buildx imagetools inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Image ${IMAGE} is not readable in GHCR. Push it with scripts/build-and-push-image.sh first." >&2
  exit 1
fi

cat <<SUMMARY
if [[ "$SERVICE" == "web" ]]; then
  echo "Before 12:00 Helsinki, confirm that the EEX import and both morning consumers have finished."
fi
Production deploy
  project:     ${PROJECT_ID} (Voltikka)
  environment: ${ENVIRONMENT_ID} (production)
  service:     ${SERVICE_ID} (${SERVICE_NAME})
  image:       ${IMAGE}
Effect: ${EFFECT}
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
    --message "Deploy ${SERVICE_NAME} ${TAG}" \
    --json

echo
echo "Railway source.image set to ${IMAGE}."
echo "Next: read the service config back, confirm source.image, then poll the new deployment:"
echo "  RAILWAY_CALLER=skill:use-railway@1.2.2 RAILWAY_AGENT_SESSION=${SESSION} scripts/railway-poll-deployment.sh \\"
echo "    --project ${PROJECT_ID} --environment ${ENVIRONMENT_ID} --service ${SERVICE_ID} --deployment <DEPLOYMENT_ID>"
