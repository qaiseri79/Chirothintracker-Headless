#!/bin/sh
TOKEN="9d6b991d2baef0717ea28cb41f00ad1d0cfc"
BODY="{\"token\":\"$TOKEN\",\"fields\":{\"field_first_name\":\"Testy\",\"field_last_name\":\"McTest\",\"field_email_address\":\"testy@example.com\",\"field_high_cholesterol\":\"1\",\"field_weight\":180,\"field_date_of_birth\":\"1990-05-01T00:00:00\"}}"
echo "== submit =="
curl -s -w '\nHTTP %{http_code}\n' -X POST -H 'Content-Type: application/json' -d "$BODY" "http://127.0.0.1:55162/api/intake/submit"
echo "== invite after (expect exhausted) =="
curl -s -o /dev/null -w 'HTTP %{http_code}\n' "http://127.0.0.1:55162/api/intake/invite/$TOKEN"