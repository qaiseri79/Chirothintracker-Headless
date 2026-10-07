#!/bin/bash
set -e
BASE="http://127.0.0.1:55162"
CDIR=$(mktemp -d)
LOGIN=$(docker exec chirothintrackerreact_appserver_1 drush user:login 1 --uri="$BASE" | tail -1)
curl -s -c "$CDIR/cj" -o /dev/null -L "$LOGIN"

GTOKEN=$(docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token FROM ctt_patient_intake_invite WHERE status='active' ORDER BY created DESC LIMIT 1" | grep -v '^$' | head -1)
echo "revoking: $GTOKEN"
RURL="$BASE/manage/intake-links/revoke/$GTOKEN"
curl -s -b "$CDIR/cj" -c "$CDIR/cj" "$RURL" -o "$CDIR/rev.html" -w 'GET %{http_code}\n'
grep -o '<title>[^<]*</title>' "$CDIR/rev.html" | head -1
echo "has question: $(grep -c 'Revoke the intake link' "$CDIR/rev.html")"
echo "form_id field: $(grep -o 'name="form_id"[^>]*' "$CDIR/rev.html" | head -1)"
RFID=$(grep -o 'name="form_id" value="[^"]*"' "$CDIR/rev.html" | sed 's/.*value="//;s/"//')
RFBID=$(grep -o 'name="form_build_id" value="[^"]*"' "$CDIR/rev.html" | sed 's/.*value="//;s/"//')
RFTOK=$(grep -o 'name="form_token" value="[^"]*"' "$CDIR/rev.html" | sed 's/.*value="//;s/"//')
echo "fid=$RFID"
echo "bpid=$RFBID"
echo "tok=$RFTOK"
curl -s -b "$CDIR/cj" -c "$CDIR/cj" -X POST "$RURL" \
  --data-urlencode "op=Revoke link" \
  --data-urlencode "form_id=$RFID" \
  --data-urlencode "form_build_id=$RFBID" \
  --data-urlencode "form_token=$RFTOK" \
  -o "$CDIR/post.html" -w 'POST %{http_code}\n' -D "$CDIR/post.hdr"
grep -i '^location:' "$CDIR/post.hdr" || echo "(no redirect header shown)"
grep -o '<title>[^<]*</title>' "$CDIR/post.html" | head -1
echo "landed on Intake Links page: $(grep -c 'ctt_patient_intake_intake_links_form' "$CDIR/post.html" || true)"
docker exec chirothintrackerreact_appserver_1 drush sql:query "SELECT token,status FROM ctt_patient_intake_invite WHERE token='$GTOKEN'"
rm -rf "$CDIR"