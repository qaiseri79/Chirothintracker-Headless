#!/bin/bash
set -e
BASE="http://127.0.0.1:55162"
CDIR=$(mktemp -d)
LOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login 1 --uri="$BASE" | tail -1)
echo "== login: $LOGIN"
curl -s -c "$CDIR/cj" -o /dev/null -L "$LOGIN"

echo "== GET /manage/intake-links =="
curl -s -b "$CDIR/cj" -c "$CDIR/cj" "$BASE/manage/intake-links" -o "$CDIR/page.html" -w 'HTTP %{http_code}\n'
grep -o 'name="form_id" value="[^"]*"' "$CDIR/page.html" | head -1
grep -c 'Generate intake link' "$CDIR/page.html" || true
grep -o 'id="edit-clinic"' "$CDIR/page.html" | head -1 || echo "no clinic select"

echo "== POST generate (clinic 1, max_uses 2, 5 days) =="
FID=$(grep -o 'name="form_id" value="[^"]*"' "$CDIR/page.html" | sed 's/.*value="//;s/"//')
FBID=$(grep -o 'name="form_build_id" value="[^"]*"' "$CDIR/page.html" | sed 's/.*value="//;s/"//')
FTOK=$(grep -o 'name="form_token" value="[^"]*"' "$CDIR/page.html" | sed 's/.*value="//;s/"//')
curl -s -b "$CDIR/cj" -c "$CDIR/cj" -X POST "$BASE/manage/intake-links" \
  --data-urlencode "clinic=1" \
  --data-urlencode "max_uses=2" \
  --data-urlencode "expiry_days=5" \
  --data-urlencode "op=Generate intake link" \
  --data-urlencode "form_id=$FID" \
  --data-urlencode "form_build_id=$FBID" \
  --data-urlencode "form_token=$FTOK" \
  -o "$CDIR/gen.html" -w 'HTTP %{http_code}\n'
echo "generated url present: $(grep -c 'ctt-intake-generated-url' "$CDIR/gen.html" || true)"
GENURL=$(grep -o 'value="http[^"]*/intake/[^"]*"' "$CDIR/gen.html" | head -1 | sed 's/.*value="//;s/"$//')
echo "generated: $GENURL"
GTOKEN=${GENURL##*/intake/}

echo "== newest tokens for clinic 1 =="
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token, status, uses, max_uses, FROM_UNIXTIME(created) created, FROM_UNIXTIME(expires_at) expires FROM ctt_patient_intake_invite WHERE clinic_id=1 ORDER BY created DESC LIMIT 2"

echo "== GET revoke confirm page for $GTOKEN =="
RURL="$BASE/manage/intake-links/revoke/$GTOKEN"
curl -s -b "$CDIR/cj" -c "$CDIR/cj" "$RURL" -o "$CDIR/rev.html" -w 'HTTP %{http_code}\n'
grep -o 'Revoke the intake link %token\|Revoke the intake link' "$CDIR/rev.html" | head -1 || echo "no question text"
RFID=$(grep -o 'name="form_id" value="[^"]*"' "$CDIR/rev.html" | sed 's/.*value="//;s/"//')
RFBID=$(grep -o 'name="form_build_id" value="[^"]*"' "$CDIR/rev.html" | sed 's/.*value="//;s/"//')
RFTOK=$(grep -o 'name="form_token" value="[^"]*"' "$CDIR/rev.html" | sed 's/.*value="//;s/"//')

echo "== POST revoke =="
curl -s -b "$CDIR/cj" -c "$CDIR/cj" -X POST "$RURL" \
  --data-urlencode "op=Revoke link" \
  --data-urlencode "form_id=$RFID" \
  --data-urlencode "form_build_id=$RFBID" \
  --data-urlencode "form_token=$RFTOK" \
  -o /dev/null -w 'HTTP %{http_code}\n' -L
echo "token status now:"
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token, status FROM ctt_patient_intake_invite WHERE token='$GTOKEN'"

rm -rf "$CDIR"