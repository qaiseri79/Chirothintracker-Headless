#!/bin/bash
BASE="http://127.0.0.1:55162"
echo "== chiropractor (uid 174) session =="
LOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login 174 --uri="$BASE" | tail -1)
curl -s -c /tmp/cj174 -o /dev/null -L "$LOGIN"
curl -s -b /tmp/cj174 "$BASE/manage/intake-links" -o /tmp/chiro.html -w 'HTTP %{http_code}\n'
echo "clinic options shown:"
grep -o '<option value="[0-9]*"[^>]*>[^<]*</option>' /tmp/chiro.html | head -5
echo "options count: $(grep -c '<option value=' /tmp/chiro.html || true)"
echo ""
echo "== anonymous (no session) =="
curl -s -o /dev/null -w 'HTTP %{http_code} redirect=%{redirect_url}\n' "$BASE/manage/intake-links"
echo ""
echo "== settings page as admin =="
LOGINA=$(docker exec chirothintrackerreact_appserver_1 drush user:login 1 --uri="$BASE" | tail -1)
curl -s -c /tmp/cja -o /dev/null -L "$LOGINA"
curl -s -b /tmp/cja -o /dev/null -w 'HTTP %{http_code}\n' "$BASE/admin/config/ctt-patient-intake/settings"