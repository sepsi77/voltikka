#!/bin/bash
# pi-run.sh <logfile> <pi-args...>
#
# Run Pi with all output redirected to the specified log file. Programmatic runs
# load only the extensions listed below. Interactive-only background-task and
# subagent extensions stay disabled. A session-scoped timer keeps print mode
# alive while it awaits tools that use unreferenced processes. The supervisor
# removes all remaining process-group members after Pi exits.
set -u

if [ "$#" -lt 2 ]; then
  echo "Usage: $0 <logfile> <pi-args...>" >&2
  exit 2
fi

LOG="$1"
shift

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
AGENT_DIR=${PI_CODING_AGENT_DIR:-"$HOME/.pi/agent"}

# Disable automatic discovery so pi-patty-bg-tasks and pi-subagents do not load.
# Keep the extensions that programmatic executors use. Missing optional user
# extensions are skipped so the runner also works on a clean installation.
EXTENSIONS=(
  "$SCRIPT_DIR/pi-print-keepalive.ts"
  "$AGENT_DIR/npm/node_modules/pi-mcp-adapter"
  "$AGENT_DIR/npm/node_modules/pi-subdir-context"
  "$AGENT_DIR/extensions/obscura-scrape.ts"
  "$AGENT_DIR/extensions/perplexity-openrouter/index.ts"
)

PI_ARGS=(--no-extensions)
for extension in "${EXTENSIONS[@]}"; do
  if [ -e "$extension" ]; then
    PI_ARGS+=(-e "$extension")
  fi
done

# Pi reads a piped, open stdin as extra prompt input and waits for EOF. A
# background launcher can leave stdin open forever, so Pi idles without output.
exec python3 "$SCRIPT_DIR/pi-process-supervisor.py" "$LOG" \
  "${PI_ARGS[@]}" "$@" </dev/null
