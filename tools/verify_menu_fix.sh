#!/bin/bash
BASE="http://127.0.0.1:55162"
LURL=$(docker exec chirothintrackerreact_appserver_1 drush user:login admin --uri="$BASE" | grep '/user/reset' | head -1)
rm -f /tmp/cjchk
curl -s -c /tmp/cjchk -o /dev/null -L "$LURL"
for u in "/" "/manage/intake-links" "/manage/intake"; do
  code=$(curl -s -b /tmp/cjchk -o /dev/null -w '%{http_code}' "$BASE$u")
  echo "$u -> HTTP $code"
done
echo "== fresh watchdogs since fix =="
docker exec chirothintrackerreact_appserver_1 drush watchdog:show --severity=Error --count=5