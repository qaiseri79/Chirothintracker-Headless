#!/bin/bash
set -e
TOKEN=$(docker exec chirothintrackerreact_appserver_1 drush ctt-patient-intake:invite-issue 1 2>/dev/null | head -1)
echo "minted token: $TOKEN"

BODY="{\"token\":\"$TOKEN\",\"fields\":{
\"field_first_name\":\"Testy\",
\"field_last_name\":\"McTest\",
\"field_email_address\":\"testy@example.com\",
\"field_phone_number\":\"555-123-4567\",
\"field_gender\":\"F\",
\"field_date_of_birth\":\"1990-05-01\",
\"field_program_start_date\":\"2026-09-26\",
\"field_weight\":\"180\",
\"field_daily_activity_level\":\"2\",
\"field_medical_eligibility\":\"0\",
\"field_high_cholesterol\":\"1\",
\"field_diabetes\":\"0\",
\"field_high_blood_pressure\":\"0\",
\"field_thyroid_condition\":\"0\",
\"field_gall_bladder\":\"0\",
\"field_emotional_eater\":\"0\",
\"field_mailing_address[country_code]\":\"US\",
\"field_mailing_address[address_line1]\":\"123 Main St\",
\"field_mailing_address[locality]\":\"Columbus\",
\"field_mailing_address[administrative_area]\":\"OH\",
\"field_mailing_address[postal_code]\":\"43215\",
\"field_emergency_contact_name\":\"Bucky McTest\",
\"field_emergency_contact_phone\":\"614-555-0100\",
\"field_consent\":\"Testy McTest\",
\"field_consent_consumption\":1
}}"

echo "== POST via Next /api/intake =="
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' -d "$BODY" "http://localhost:3000/api/intake/$TOKEN"

echo "== token state =="
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token, status, uses, max_uses FROM ctt_patient_intake_invite WHERE token='$TOKEN'"
echo "== newest contact_message (Testy) =="
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT id, contact_form, field_clinic_target_id, field_first_name_value, field_last_name_value, field_email_address_value, field_program_start_date_value, SUBSTRING(field_mailing_address_address_line1,1,20) AS addr, LEFT(field_agreement_value,40) AS agreement FROM contact_message cm JOIN contact_message__field_first_name f ON f.entity_id=cm.id JOIN contact_message__field_last_name l ON l.entity_id=cm.id JOIN contact_message__field_clinic c ON c.entity_id=cm.id JOIN contact_message__field_program_start_date d ON d.entity_id=cm.id JOIN contact_message__field_mailing_address a ON a.entity_id=cm.id JOIN contact_message__field_agreement ag ON ag.entity_id=cm.id WHERE cm.field_first_name_value='Testy' ORDER BY cm.id DESC LIMIT 1;"