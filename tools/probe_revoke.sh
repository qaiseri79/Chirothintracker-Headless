#!/bin/bash
BASE="http://127.0.0.1:55162"
LOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login 1 --uri="$BASE" | tail -1)
curl -s -c /tmp/cj -o /dev/null -L "$LOGIN"
TOK=$(docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token FROM ctt_patient_intake_invite WHERE status='active' ORDER BY created DESC LIMIT 1" | grep -v '^$' | head -1)
echo "token=$TOK"
curl -s -b /tmp/cj "http://127.0.0.1:55162/manage/intake-links/revoke/$TOK" -o /tmp/rev.body -w "HTTP %{http_code} len %{size_download}\n"
echo "--- first lines ---"
head -c 1500 /tmp/rev.body
echo ""
echo "--- watchdog errors ---"
docker exec chirothintrackerreact_appserver_1 drush watchdog:show --count=1 --severity=Error 2>&1 | sed -n '1,20p'