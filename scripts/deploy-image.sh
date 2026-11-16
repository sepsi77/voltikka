#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/deploy-image.sh [--yes] [--morning-checks-passed] [--role web|worker] TAG

Deploy one immutable GHCR image tag, which scripts/build-and-push-image.sh has already pushed,
to Voltikka production on the Breezily VPS with Kamal (config/deploy.yml). Without --role,
both roles (web and worker) get the tag. This is a PRODUCTION MUTATION: get explicit user
confirmation first.

The script:
- refuses `latest` and any tag that is not deploy-NNNNNN-<sha12>
- refuses a checkout whose HEAD is not on origin/main, or that has local changes in config/ or
  .kamal/: Kamal reads the deploy config and the secret list from this checkout
- when the web role is deployed: refuses to run between 04:00 and 09:00 Europe/Helsinki (EEX
  futures import and morning consumers), unless --morning-checks-passed is given: use it only
  after today's eex_futures, morning_retail and morning_forecast checkpoints have a final status
  (ready or a terminal failure, never running or waiting). A worker deploy does not stop the web
  container, so the window does not apply to --role worker.
- checks that the image exists in GHCR
- asks for the word "deploy" unless --yes is given
- runs `kamal deploy --skip-push --version TAG` (with --roles for one role), then checks
  https://voltikka.fi/up. Kamal itself stops the old worker only after the new one is healthy
  (connected to Temporal; config/deploy.yml health-cmd), else it stops the new one.

Rollback is the same command with an earlier known-good tag.

Examples:
  scripts/deploy-image.sh deploy-000027-aeaaee327859
  scripts/deploy-image.sh --role worker deploy-000027-aeaaee327859
  scripts/deploy-image.sh --yes --morning-checks-passed deploy-000027-aeaaee327859
USAGE
}

IMAGE_REPO="ghcr.io/sepsi77/voltikka"
HEALTH_URL="https://voltikka.fi/up"

TAG=""
ASSUME_YES=0
MORNING_CHECKS_PASSED=0
ROLE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --yes|-y) ASSUME_YES=1; shift ;;
    --morning-checks-passed) MORNING_CHECKS_PASSED=1; shift ;;
    --role)
      if [[ $# -lt 2 ]]; then echo "--role needs web or worker." >&2; exit 2; fi
      ROLE="$2"; shift 2 ;;
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

case "$ROLE" in
  "") ROLES_ARGS=(); DEPLOYS_WEB=1
    EFFECT="Kamal starts both new containers; kamal-proxy switches web traffic after /up answers, and the old worker stops only after the new one has connected to Temporal. A run in the old web scheduler stops; on the old worker, interruptible commands repeat on the new one and claim-based commands finish within the drain (up to 66 min)." ;;
  web) ROLES_ARGS=(--roles web); DEPLOYS_WEB=1
    EFFECT="Kamal starts the new web container; kamal-proxy switches traffic after /up answers, then the old container stops. A run in the old web scheduler stops." ;;
  worker) ROLES_ARGS=(--roles worker); DEPLOYS_WEB=0
    EFFECT="Kamal starts the new Temporal worker and stops the old one only after the new one has connected; on the old worker, interruptible commands repeat on the new one and claim-based commands finish within the drain (up to 66 min)." ;;
  *) echo "Unknown role '${ROLE}': use web or worker." >&2; exit 2 ;;
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

cd "$(git rev-parse --show-toplevel)"
check_checkout() {
  git fetch --quiet origin main
  if ! git merge-base --is-ancestor HEAD origin/main; then
    echo "Refusing: HEAD $(git rev-parse --short=12 HEAD) is not on origin/main. Deploy from a clean checkout of main." >&2
    exit 1
  fi
  if [[ -n "$(git status --porcelain -- config .kamal)" ]]; then
    echo "Refusing: config/ or .kamal/ has local changes. Kamal must use the committed config." >&2
    exit 1
  fi
}
check_checkout

helsinki_hour=$((10#$(TZ=Europe/Helsinki date +%H)))
if (( DEPLOYS_WEB && helsinki_hour >= 4 && helsinki_hour < 9 && ! MORNING_CHECKS_PASSED )); then
  echo "Refusing to deploy the web role at $(TZ=Europe/Helsinki date +%H:%M) Europe/Helsinki." >&2
  echo "futures:fetch-eex runs from 04:00 until about 09:00, and a web deploy stops it. See AGENTS.md 'Production code deployment'." >&2
  echo "If today's EEX import and both morning consumers have a final status, add --morning-checks-passed." >&2
  exit 1
fi

IMAGE="${IMAGE_REPO}:${TAG}"

if ! docker buildx imagetools inspect "$IMAGE" >/dev/null 2>&1; then
  echo "Image ${IMAGE} is not readable in GHCR. Push it with scripts/build-and-push-image.sh first." >&2
  exit 1
fi

cat <<SUMMARY
Production deploy (Kamal, breezily-vps)
  roles:  ${ROLE:-web and worker}
  image:  ${IMAGE}
  config: $(git rev-parse --short=12 HEAD) (on origin/main)
Effect: ${EFFECT}
SUMMARY
if (( DEPLOYS_WEB )); then
  echo "Before 12:00 Helsinki, confirm that the EEX import and both morning consumers have a final status."
fi

if (( ! ASSUME_YES )); then
  read -r -p 'Type "deploy" to continue: ' answer
  if [[ "$answer" != "deploy" ]]; then
    echo "Cancelled." >&2
    exit 1
  fi
fi

# Again after the prompt, which can wait: Kamal reads config/ and .kamal/ from this checkout.
check_checkout

# The ${a[@]+...} form keeps an empty array valid under set -u in macOS bash 3.2.
kamal deploy --skip-push --version "$TAG" ${ROLES_ARGS[@]+"${ROLES_ARGS[@]}"}

status="$(curl -s --connect-timeout 10 --max-time 30 -o /dev/null -w '%{http_code}' "${HEALTH_URL}?deploy=${TAG}" || true)"
echo
echo "Deployed ${TAG} (${ROLE:-web and worker}). ${HEALTH_URL}: ${status}"
if [[ "$status" != "200" ]]; then
  echo "The health check did not return 200. Read: kamal app logs --roles web --lines 200" >&2
  exit 1
fi
