#!/bin/bash
BASE="http://127.0.0.1:55162"
LOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login 174 --uri="$BASE" | grep '/user/reset' | head -1)
echo "login URL: $LOGIN"
rm -f /tmp/cjX
curl -s -c /tmp/cjX -o /dev/null -L "$LOGIN"
echo "cookies:"; grep -o 'SSESS[^[:space:]]*' /tmp/cjX | head -3
curl -s -b /tmp/cjX "$BASE/manage/intake-links" -o /tmp/chiro2.html -w 'HTTP %{http_code}\n'
echo "who: $(grep -o 'drjohn_174' /tmp/chiro2.html | head -1)"
python3 - <<'EOF'
import re
html = open('/tmp/chiro2.html').read()
m = re.search(r'<select[^>]*id="edit-clinic"[^>]*>(.*?)</select>', html, re.S)
if m:
    opts = re.findall(r'<option value="(\d+)"[^>]*>([^<]*)</option>', m.group(1))
    print("clinic options:", opts)
else:
    print("no clinic select found")
EOF