#!/bin/bash
BASE="http://127.0.0.1:55162"
PATIENT=$(docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT u.name FROM users_field_data u JOIN user__roles r ON r.entity_id=u.uid AND r.bundle='user' WHERE u.status=1 AND r.roles_target_id='enrolled_patient' AND u.uid NOT IN (SELECT entity_id FROM user__roles WHERE roles_target_id='chiropractor_active_') LIMIT 1" | grep -v '^$' | head -1)
echo "patient name: $PATIENT"
PLOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login "$PATIENT" --uri="$BASE" | grep '/user/reset' | head -1)
echo "login url: $PLOGIN"
rm -f /tmp/cjp3
curl -s -c /tmp/cjp3 -o /dev/null -L "$PLOGIN"
echo "== GET /manage/intake-links as patient =="
curl -s -b /tmp/cjp3 -o /dev/null -w 'HTTP %{http_code}\n' "$BASE/manage/intake-links"
echo ""
CLOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login drjohn_174 --uri="$BASE" | grep '/user/reset' | head -1)
echo "chiropractor login url: $CLOGIN"
rm -f /tmp/cjc1
curl -s -c /tmp/cjc1 -o /dev/null -L "$CLOGIN"
curl -s -b /tmp/cjc1 "$BASE/manage/intake-links" -o /tmp/chiroC.html -w 'HTTP %{http_code}\n'
python3 - <<'EOF'
import re
html = open('/tmp/chiroC.html').read()
m = re.search(r'<select[^>]*id="edit-clinic"[^>]*>(.*?)</select>', html, re.S)
if m:
    opts = re.findall(r'<option value="(\d+)"[^>]*>([^<]*)</option>', m.group(1))
    print("chiropractor clinic options:", opts)
else:
    print("no clinic select")
EOF
grep -o 'drjohn_174' /tmp/chiroC.html | head -1