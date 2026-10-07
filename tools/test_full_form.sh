#!/bin/bash
set -e
TOKEN=$(docker exec chirothintrackerreact_appserver_1 drush ctt-patient-intake:invite-issue 1 2>/dev/null | head -1)
echo "minted token: $TOKEN"
node -e "
const fs=require('fs');
const state=JSON.parse(fs.readFileSync('/tmp/state.json','utf8')).fields;
fs.writeFileSync('/tmp/full_payload.json', JSON.stringify({token: process.argv[1], fields: state}));
" "$TOKEN" || { echo 'need state file first'; exit 1; }
echo "== POST full real-form state through Next route =="
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' --data @/tmp/full_payload.json "http://127.0.0.1:3000/api/intake/$TOKEN"
echo "== token state =="
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token,status,uses,max_uses FROM ctt_patient_intake_invite WHERE token='$TOKEN'"