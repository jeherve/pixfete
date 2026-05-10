#!/usr/bin/env bash
# Conductor run script — launches the smoke-test environment.
# https://www.conductor.build/docs/reference/scripts
set -euo pipefail

PORT="${CONDUCTOR_PORT:-9400}"
WORKSPACE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$WORKSPACE_DIR"

# Conductor only runs setup.sh on workspace creation. Existing workspaces (or
# any case where deps are missing) need a one-shot bootstrap before Run works.
if [ ! -x node_modules/.bin/wp-scripts ] || [ ! -d vendor ] || [ ! -d build ]; then
  echo "[run.sh] Dependencies missing; running setup.sh first..."
  ./.conductor/setup.sh
fi

# Watch-mode rebuild so edits in src/ recompile automatically.
npm run dev &
DEV_PID=$!
trap 'kill "$DEV_PID" 2>/dev/null || true' EXIT

# Start WP Playground using the existing blueprint.
# blueprint.json activates pixfete/pixfete.php, so the workspace must be
# mounted at .../plugins/pixfete BEFORE the activation step runs (auto-mount
# would map it to .../plugins/<workspace-dir-name>, which won't match).
exec npx --yes @wp-playground/cli@latest server \
  --blueprint=blueprint.json \
  --port="$PORT" \
  --mount-before-install="${WORKSPACE_DIR}:/wordpress/wp-content/plugins/pixfete"
