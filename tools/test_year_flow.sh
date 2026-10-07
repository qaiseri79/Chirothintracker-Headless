#!/bin/bash
set -e
TOKEN=$(docker exec chirothintrackerreact_appserver_1 drush ctt-patient-intake:invite-issue 1 2>/dev/null | head -1)
echo "minted token: $TOKEN"

make_payload() {
  node -e "
  const fs=require('fs');
  const state=JSON.parse(fs.readFileSync('/tmp/state.json','utf8')).fields;
  state.field_high_blood_pressure='1';
  state.field_bp_diagnosis_year=process.argv[1];
  state.field_high_cholesterol='0';
  state.field_thyroid_condition='0';
  state.field_diabetes='0';
  delete state.field_cholesterol_diagnosis_year;
  delete state.field_thyroid_diagnosis_year;
  delete state.field_diabetes_diagnosis_year;
  fs.writeFileSync('/tmp/year_payload.json', JSON.stringify({token: process.argv[2], fields: state}));
  " "$1" "$TOKEN"
}

echo "== attempt 1: Year Diagnosed = 2500 (out of range) =="
make_payload 2500
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' --data @/tmp/year_payload.json "http://127.0.0.1:3000/api/intake/$TOKEN"
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token,status,uses FROM ctt_patient_intake_invite WHERE token='$TOKEN'"

echo "== attempt 2: Year Diagnosed = 2020 (valid) =="
make_payload 2020
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' --data @/tmp/year_payload.json "http://127.0.0.1:3000/api/intake/$TOKEN"
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token,status,uses FROM ctt_patient_intake_invite WHERE token='$TOKEN'"

docker exec chirothintrackerreact_appserver_1 drush sql:query "DELETE FROM ctt_patient_intake_invite WHERE token='$TOKEN'"