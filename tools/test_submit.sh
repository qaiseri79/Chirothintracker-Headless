#!/bin/sh
BODY='{"token":"4c903765bd3652912b67d41a86110039893b","fields":{"field_first_name":"Testy","field_last_name":"McTest","field_email_address":"testy@example.com","field_high_cholesterol":"1","field_weight":180,"field_date_of_birth":"1990-05-01T00:00:00"}}'
echo "== submit =="
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' -d "$BODY" "http://127.0.0.1:55162/api/intake/submit"
echo "== invite again (should be exhausted) =="
curl -s -w '\nHTTP %{http_code}\n' "http://127.0.0.1:55162/api/intake/invite/4c903765bd3652912b67d41a86110039893b"
echo "== second submit (should be 410) =="
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' -d "$BODY" "http://127.0.0.1:55162/api/intake/submit"
echo "== unknown token invite (should be 404) =="
curl -s -w '\nHTTP %{http_code}\n' "http://127.0.0.1:55162/api/intake/invite/deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"