#!/usr/bin/env bash
#
# Re-sync DRUPAL_INTERNAL_URL in frontend/.env.local with the port Lando is
# actually publishing the appserver on right now.
#
# Lando allocates a new host port for the appserver on every `lando start` /
# `lando restart`. A stale value in .env.local is silent and total: every
# server-side Drupal call throws, `/api/auth/me` answers 502, and the symptom is
# a login that appears to do nothing plus a dashboard that bounces to /login.
# Check /api/auth/me first when login breaks — 502 means the port, 401 means the
# session.
#
# Usage (from WSL, repo root or anywhere):
#   ./frontend/scripts/sync-drupal-port.sh
#
set -euo pipefail

ENV_FILE="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.env.local}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "error: $ENV_FILE not found" >&2
  exit 1
fi

# The appserver container publishes http on a generated host port. Match the
# container by name rather than assuming container 1, and prefer the plain http
# mapping over the https one.
PORTS="$(docker ps --filter 'name=appserver' --format '{{.Ports}}' | tr ',' '\n' | tr -d ' ' | grep -E '^127\.0\.0\.1:[0-9]+->80/tcp$' | head -1 || true)"

if [[ -z "$PORTS" ]]; then
  echo "error: could not find the appserver's published http port." >&2
  echo "       is the container running? try: docker ps --format '{{.Names}}  {{.Ports}}'" >&2
  exit 1
fi

# PORTS looks like `127.0.0.1:61879->80/tcp`; take the host-side port.
PORT="$(printf '%s' "$PORTS" | sed -E 's/^[^:]+:([0-9]+)->.*/\1/')"
if [[ ! "$PORT" =~ ^[0-9]+$ ]]; then
  echo "error: could not parse a port out of '$PORTS'" >&2
  exit 1
fi
URL="http://127.0.0.1:${PORT}"

CURRENT="$(grep -E '^DRUPAL_INTERNAL_URL=' "$ENV_FILE" | cut -d= -f2- || true)"

if [[ "$CURRENT" == "$URL" ]]; then
  echo "already current: DRUPAL_INTERNAL_URL=$URL"
  exit 0
fi

# Preserve everything else in the file, including the explanatory comment block
# that sits above the assignment.
python3 - "$ENV_FILE" "$URL" <<'PY'
import re
import sys

path, url = sys.argv[1], sys.argv[2]
with open(path, encoding="utf-8") as fh:
    text = fh.read()

new, n = re.subn(
    r'(?m)^DRUPAL_INTERNAL_URL=.*$',
    f'DRUPAL_INTERNAL_URL={url}',
    text,
)
if n == 0:
    sys.exit(f"error: no DRUPAL_INTERNAL_URL assignment in {path}")

with open(path, "w", encoding="utf-8") as fh:
    fh.write(new)
PY

echo "updated: ${CURRENT:-<unset>} -> ${URL}"
echo "restart the dev server for it to take effect (.env.local is read at start)."
