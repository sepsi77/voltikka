#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/railway-poll-deployment.sh --project PROJECT_ID --environment ENV_ID --service SERVICE_ID [--deployment DEPLOYMENT_ID] [--interval SECONDS] [--timeout SECONDS]

Poll a Railway service deployment until it reaches a terminal state.
Prints the latest Railway log line/message before exiting.

Exit codes:
  0  deployment succeeded
  1  deployment failed/crashed/removed
  2  timed out or invalid arguments

Examples:
  scripts/railway-poll-deployment.sh \
    --project 6d8cae01-1006-409f-8108-1d51f1abc676 \
    --environment 9245cef8-41d0-486e-862f-193726511dba \
    --service 700d0624-fa96-4266-876c-e37640d220ea

  scripts/railway-poll-deployment.sh --project ... --environment ... --service ... --deployment 55d5678d-77d9-477c-974d-018b80930118
USAGE
}

project=""
environment=""
service=""
deployment=""
interval="10"
timeout="900"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project|-p) project="${2:-}"; shift 2 ;;
    --environment|-e) environment="${2:-}"; shift 2 ;;
    --service|-s) service="${2:-}"; shift 2 ;;
    --deployment|-d) deployment="${2:-}"; shift 2 ;;
    --interval) interval="${2:-}"; shift 2 ;;
    --timeout) timeout="${2:-}"; shift 2 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ -z "$project" || -z "$environment" || -z "$service" ]]; then
  echo "Missing required --project, --environment, or --service." >&2
  usage >&2
  exit 2
fi

if ! [[ "$interval" =~ ^[0-9]+$ && "$timeout" =~ ^[0-9]+$ ]]; then
  echo "--interval and --timeout must be integer seconds." >&2
  exit 2
fi

started_at=$(date +%s)
last_status=""
last_deployment=""

status_json() {
  railway service status \
    --project "$project" \
    --environment "$environment" \
    --service "$service" \
    --json
}

parse_status() {
  python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("deploymentId", "")); print(d.get("status", "")); print(str(d.get("stopped", "")))'
}

latest_message() {
  local dep_id="$1"
  local phase="$2"
  local log_args=(--project "$project" --environment "$environment" --service "$service" --lines 1 "$dep_id")

  if [[ "$phase" == "build" ]]; then
    log_args=(--project "$project" --environment "$environment" --service "$service" --build --lines 1 "$dep_id")
  elif [[ "$phase" == "deploy" ]]; then
    log_args=(--project "$project" --environment "$environment" --service "$service" --deployment --lines 1 "$dep_id")
  fi

  railway logs "${log_args[@]}" 2>/dev/null | tail -n 1 || true
}

while true; do
  now=$(date +%s)
  if (( now - started_at > timeout )); then
    echo "Timed out after ${timeout}s waiting for deployment ${deployment:-latest}." >&2
    if [[ -n "$last_deployment" ]]; then
      latest_message "$last_deployment" "build"
    fi
    exit 2
  fi

  parsed_status=$(status_json | parse_status)
  current_deployment=$(printf '%s\n' "$parsed_status" | sed -n '1p')
  status=$(printf '%s\n' "$parsed_status" | sed -n '2p')
  stopped=$(printf '%s\n' "$parsed_status" | sed -n '3p')

  if [[ -z "$deployment" ]]; then
    deployment="$current_deployment"
  fi

  # If a specific deployment is requested, wait until service status refers to it.
  if [[ "$current_deployment" != "$deployment" ]]; then
    echo "Waiting for deployment $deployment to become current (current: ${current_deployment:-unknown})..."
    sleep "$interval"
    continue
  fi

  last_deployment="$current_deployment"
  if [[ "$status" != "$last_status" ]]; then
    echo "Deployment $current_deployment status: $status (stopped=$stopped)"
    last_status="$status"
  fi

  case "$status" in
    SUCCESS)
      latest_message "$current_deployment" "deploy"
      exit 0
      ;;
    FAILED|CRASHED|REMOVED)
      msg=$(latest_message "$current_deployment" "build")
      if [[ -z "$msg" ]]; then
        msg=$(latest_message "$current_deployment" "deploy")
      fi
      echo "$msg"
      exit 1
      ;;
  esac

  sleep "$interval"
done
