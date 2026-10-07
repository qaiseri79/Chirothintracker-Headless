#!/bin/bash
set -e
TOKEN=$(docker exec chirothintrackerreact_appserver_1 drush ctt-patient-intake:invite-issue 1 2>/dev/null | head -1)
echo "token: $TOKEN"
echo "== GET /intake/$TOKEN (first 300 bytes of markup) =="
curl -s "http://localhost:3000/intake/$TOKEN" | tr -d '\n' | grep -o "One Light Chiropractic[^<]*" | head -1
curl -s -o /dev/null -w 'status: %{http_code}\n' "http://localhost:3000/intake/$TOKEN"
echo "== GET unknown token =="
curl -s "http://localhost:3000/intake/deadbeefdeadbeefdeadbeefdeadbeefdeadbeef" | tr -d '\n' | grep -o "This link is no longer valid" | head -1
curl -s -o /dev/null -w 'status: %{http_code}\n' "http://localhost:3000/intake/deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"