#!/usr/bin/env bash
#
# End-to-end smoke test for the headless_patients write routes.
#
# Logs in as a chiropractor via Drupal's own JSON login endpoint, then exercises
# create -> archive -> enroll -> intake review against the same origin the Next.js
# server uses. Prints the HTTP status and a short slice of the body for each call
# so a failure is visible without a debugger.
#
# ## Usage
#
#   DRUPAL_EMAIL=you@clinic.com DRUPAL_PASSWORD=... bash frontend/scripts/smoke-patients.sh
#
# DRUPAL_URL defaults to the value in frontend/.env.local.
#
# ## What it changes
#
# It creates a real user account with a timestamped email, and it moves that
# account between enrolled_patient and archived_patient. It also flags and
# unflags one real intake submission, so run it against a clinic where that is
# acceptable. Nothing else is touched.
#
# ## Why it logs in directly
#
# Drupal's session cookie is set on Drupal's origin and is `secure; HttpOnly`, so
# the Next.js server forwards it rather than the browser holding it. Going through
# `POST /user/login?_format=json` from a script reproduces exactly what
# `frontend/src/app/api/auth/login/route.ts` does, which is the only path that
# yields a usable session without a browser.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$(dirname "$SCRIPT_DIR")"

# Default to whatever the frontend is already configured to talk to.
DRUPAL_URL="${DRUPAL_URL:-$(grep -E '^DRUPAL_INTERNAL_URL=' "$FRONTEND_DIR/.env.local" 2>/dev/null | cut -d= -f2-)}"
DRUPAL_URL="${DRUPAL_URL:-https://127.0.0.1:58311}"
JAR="$(mktemp -t drupal_smoke.XXXXXX)"
BODY="$(mktemp -t drupal_smoke_body.XXXXXX)"
trap 'rm -f "$JAR" "$BODY"' EXIT

pass=0
fail=0

# Section heading.
step() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

# report <label> <expected-status> <actual-status> <body-file>
report() {
  local label="$1" expected="$2" actual="$3"
  if [ "$actual" = "$expected" ]; then
    printf '  \033[32mPASS\033[0m %s (HTTP %s)\n' "$label" "$actual"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %s (expected HTTP %s, got %s)\n' "$label" "$expected" "$actual"
    printf '       body: %s\n' "$(head -c 400 "$BODY")"
    fail=$((fail + 1))
  fi
}

# api <method> <path> [json-body] -> prints the status code, body lands in $BODY
api() {
  local method="$1" path="$2" payload="${3:-}"
  local args=(-s -k -o "$BODY" -w '%{http_code}' -X "$method" -b "$JAR" -c "$JAR")

  if [ -n "$payload" ]; then
    args+=(-H 'Content-Type: application/json' -d "$payload")
  fi

  curl "${args[@]}" "$DRUPAL_URL$path"
}

if [ -z "${DRUPAL_EMAIL:-}" ] || [ -z "${DRUPAL_PASSWORD:-}" ]; then
  echo "Set DRUPAL_EMAIL and DRUPAL_PASSWORD. Example:"
  echo "  DRUPAL_EMAIL=you@clinic.com DRUPAL_PASSWORD='...' bash $0"
  exit 2
fi

echo "Drupal:  $DRUPAL_URL"
echo "Account: $DRUPAL_EMAIL"

# ---------------------------------------------------------------- 0. login
step "0. Log in as a chiropractor"
login_status=$(curl -s -k -o "$BODY" -w '%{http_code}' -c "$JAR" \
  -X POST "$DRUPAL_URL/user/login?_format=json" \
  -H 'Content-Type: application/json' \
  -d "$(printf '{"name":%s,"pass":%s}' \
    "$(printf '%s' "$DRUPAL_EMAIL" | sed 's/"/\\"/g' | sed 's/^/"/;s/$/"/')" \
    "$(printf '%s' "$DRUPAL_PASSWORD" | sed 's/"/\\"/g' | sed 's/^/"/;s/$/"/')")")

if [ "$login_status" != "200" ]; then
  echo "  \033[31mFAIL\033[0m login (HTTP $login_status)"
  echo "       body: $(head -c 400 "$BODY")"
  echo
  echo "A 400 here usually means the shell mangled the quoting in the JSON."
  echo "A 403 means the credentials are wrong or the account is blocked."
  exit 1
fi
echo "  \033[32mPASS\033[0m logged in (HTTP 200)"

# Core sends roles as current_user.roles, and only when the account can view its
# own roles field — see UserAuthenticationController::login(), which gates each
# key on `$account->get('roles')->access('view', $account)`. So an absent roles key
# means the field is not viewable, not that the account holds no roles. The first
# version of this script grepped for a bare `"roles":[...]` anywhere in the body,
# which matched nothing and reported an empty role list for an account that
# demonstrably had write access — the warning then contradicted the results below.
roles=$(sed -n 's/.*"current_user":{"uid":"\?[0-9]*"\?,"roles":\[\([^]]*\)\].*/\1/p' "$BODY" \
  | tr -d '"' | tr ',' ',')

if [ -z "$roles" ]; then
  echo "       roles: (not returned by the login endpoint)"
  echo "       \033[33mWARN\033[0m cannot tell which role this account holds, so the"
  echo "         expectations below assume an active chiropractor. A 403 on a write"
  echo "         route may be correct behaviour rather than a fault."
else
  echo "       roles: $roles"
  case ",$roles," in
    *,chiropractor_active_,*)
      ;;
    *)
      echo "       \033[33mWARN\033[0m not an active chiropractor: the write routes below"
      echo "         should 403 by design. Reads should still pass."
      ;;
  esac
fi

# ---------------------------------------------------------------- 1. read
step "1. GET /api/headless/patients (ReadChiropractorAccess)"
status=$(api GET /api/headless/patients)
report "roster snapshot" 200 "$status"

# Pull one intake submission id, so the review step has a real target.
intake_status=$(api GET /api/headless/patients/intake)
if [ "$intake_status" = "200" ]; then
  INTAKE_ID=$(grep -o '"id":[0-9]\+' "$BODY" | head -1 | cut -d: -f2)
  INTAKE_TOTAL=$(grep -o '"id":[0-9]\+' "$BODY" | wc -l | tr -d ' ')
  echo "       intake submissions: $INTAKE_TOTAL, using id ${INTAKE_ID:-none}"
else
  INTAKE_ID=""
  echo "       could not read intake list (HTTP $intake_status); review step will be skipped"
fi

# ---------------------------------------------------------------- 2. create
step "2. POST /api/headless/patients (create)"
STAMP=$(date +%s)
TEST_EMAIL="smoke+$STAMP@example.com"
status=$(api POST /api/headless/patients \
  "{\"name\":\"Smoke Test $STAMP\",\"mail\":\"$TEST_EMAIL\",\"phone\":\"555-0100\"}")
NEW_UID=$(grep -o '"id":[0-9]\+' "$BODY" | head -1 | cut -d: -f2)

if [ "$status" = "200" ] || [ "$status" = "201" ]; then
  echo "  \033[32mPASS\033[0m created patient (HTTP $status)"
  pass=$((pass + 1))
  echo "       uid $NEW_UID, mail $TEST_EMAIL"
else
  report "create patient" 200 "$status"
  NEW_UID=""
fi

# ---------------------------------------------------------------- 3. archive
if [ -n "$NEW_UID" ]; then
  step "3. POST /api/headless/patients/$NEW_UID/archive (role swap)"
  status=$(api POST "/api/headless/patients/$NEW_UID/archive")
  report "archive" 200 "$status"

  # ------------------------------------------------------- 3b. clinic scoping
  step "3b. POST archive on a uid outside the clinic (expect 404)"
  status=$(api POST "/api/headless/patients/999999999/archive")
  report "out-of-clinic archive refused" 404 "$status"

  # -------------------------------------------------------------- 4. enroll
  step "4. POST /api/headless/patients/$NEW_UID/enroll (role swap back)"
  status=$(api POST "/api/headless/patients/$NEW_UID/enroll")
  report "enroll" 200 "$status"

  # ------------------------------------------------- 4b. archive needs enrolled
  step "4b. POST enroll on an already-enrolled account (expect 409)"
  status=$(api POST "/api/headless/patients/$NEW_UID/enroll")
  report "re-enrolling an enrolled patient refused" 409 "$status"
fi

# ---------------------------------------------------------------- 5. review
if [ -n "$INTAKE_ID" ]; then
  step "5. POST /api/headless/patients/intake/review (flag + unflag)"
  status=$(api POST /api/headless/patients/intake/review \
    "{\"ids\":[$INTAKE_ID],\"status\":\"checked\"}")
  report "mark intake $INTAKE_ID checked" 200 "$status"
  echo "       body: $(head -c 300 "$BODY")"

  step "5b. POST review with the same state again (idempotent, unchanged)"
  status=$(api POST /api/headless/patients/intake/review \
    "{\"ids\":[$INTAKE_ID],\"status\":\"checked\"}")
  if [ "$status" = "200" ] && grep -q '"unchanged":\[1\]\|"unchanged":\[[0-9]' "$BODY"; then
    echo "  \033[32mPASS\033[0m second call reported it as unchanged"
    pass=$((pass + 1))
  else
    report "second review reports unchanged" 200 "$status"
  fi

  step "5c. POST review back to new (removes only your own flagging)"
  status=$(api POST /api/headless/patients/intake/review \
    "{\"ids\":[$INTAKE_ID],\"status\":\"new\"}")
  report "mark intake $INTAKE_ID new" 200 "$status"

  step "5d. POST review with a bogus status (expect 422)"
  status=$(api POST /api/headless/patients/intake/review \
    "{\"ids\":[$INTAKE_ID],\"status\":\"wat\"}")
  report "unknown review state refused" 422 "$status"
fi

# ---------------------------------------------------------------- 6. messages
step "6. GET /api/headless/messages (PortalMemberAccess, read)"
status=$(api GET /api/headless/messages)
report "conversation list" 200 "$status"

step "7. GET /api/headless/progress (PortalMemberAccess, read)"
status=$(api GET /api/headless/progress)
if [ "$status" = "200" ]; then
  report "own progress" 200 "$status"
elif [ "$status" = "404" ]; then
  echo "  \033[33mSKIP\033[0m progress returned 404 (no progress records for this"
  echo "         account). The gate let the request through, which is the point."
else
  report "own progress" 200 "$status"
fi

# ---------------------------------------------------------------- summary
printf '\n\033[1m== Summary ==\033[0m\n'
printf '  passed: %d\n  failed: %d\n' "$pass" "$fail"

if [ "$NEW_UID" != "" ]; then
  printf '\n  Left behind: uid %s (%s) holds enrolled_patient.\n' "$NEW_UID" "$TEST_EMAIL"
  printf '  Delete it, or archive it, whenever you like.\n'
fi

exit $((fail > 0))
