#!/bin/bash
BASE="http://127.0.0.1:55162"
echo "== php -l =="
docker exec chirothintrackerreact_appserver_1 sh -c "php -l /app/web/modules/custom/ctt_patient_intake/src/Form/IntakeLinksForm.php && php -l /app/web/modules/custom/ctt_patient_intake/src/Form/IntakeLinkRevokeForm.php"
echo "== login =="
LURL=$(docker exec chirothintrackerreact_appserver_1 drush user:login admin --uri="$BASE" | grep '/user/reset' | head -1)
rm -f /tmp/cjfix
curl -s -c /tmp/cjfix -o /dev/null -L "$LURL"
echo "== GET /manage/intake-links =="
curl -s -b /tmp/cjfix -o /tmp/fix1.html -w 'HTTP %{http_code}\n' "$BASE/manage/intake-links"
grep -o 'ctt_patient_intake_intake_links_form' /tmp/fix1.html | head -1
echo "== POST generate (clinic 1) =="
FB=$(grep -o 'name="form_build_id" value="[^"]*"' /tmp/fix1.html | sed 's/.*value="//;s/"//')
FT=$(grep -o 'name="form_token" value="[^"]*"' /tmp/fix1.html | sed 's/.*value="//;s/"//')
curl -s -b /tmp/cjfix -c /tmp/cjfix -o /tmp/fix2.html -w 'HTTP %{http_code}\n' --data-urlencode 'clinic=1' --data-urlencode "form_build_id=$FB" --data-urlencode "form_token=$FT" --data-urlencode 'form_id=ctt_patient_intake_intake_links_form' --data-urlencode 'op=Generate intake link' "$BASE/manage/intake-links"
echo "-- rebuilt page (no wakeup error) --"
grep -io 'cannot initialize readonly property' /tmp/fix2.html || echo 'no readonly error'
grep -io 'intake/[A-Za-z0-9_-]\{16,\}' /tmp/fix2.html | head -1
echo "== generated token row =="
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token,status,current_uses,max_uses FROM ctt_patient_intake_invite ORDER BY id DESC LIMIT 1"