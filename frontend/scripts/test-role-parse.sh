#!/usr/bin/env bash
# Test just the role-parsing regex from smoke-patients.sh against known bodies,
# so it can be fixed without spending a real login on it.
set -u

# What core returns for a successful login with a viewable roles field.
GOOD='{"current_user":{"uid":"42","roles":["authenticated","enrolled_patient","chiropractor_active_"]},"csrf_token":"abc","logout_token":"def"}'

# An inactive chiropractor: should warn.
INACTIVE='{"current_user":{"uid":"43","roles":["authenticated","chiropractor_inactive_"]},"csrf_token":"a","logout_token":"b"}'

# Roles field not viewable: the key is absent entirely.
NOROLES='{"current_user":{"uid":"44","name":"someone"},"csrf_token":"a","logout_token":"b"}'

extract() {
  sed -n 's/.*"current_user":{"uid":"\?[0-9]*"\?,"roles":\[\([^]]*\)\].*/\1/p' <<<"$1" \
    | tr -d '"' | tr ',' ','
}

check() {
  local label="$1" body="$2" expect="$3" got
  got=$(extract "$body")
  if [ "$got" = "$expect" ]; then
    printf '  \033[32mPASS\033[0m %s\n' "$label"
  else
    printf '  \033[31mFAIL\033[0m %s\n         expected: [%s]\n         got:      [%s]\n' \
      "$label" "$expect" "$got"
  fi
}

echo "== role extraction =="
check "active chiropractor roles" \
  "$GOOD" "authenticated,enrolled_patient,chiropractor_active_"
check "inactive chiropractor roles" \
  "$INACTIVE" "authenticated,chiropractor_inactive_"
check "roles absent yields empty (warns, does not claim no roles)" \
  "$NOROLES" ""
