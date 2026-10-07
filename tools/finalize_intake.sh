#!/bin/sh
echo "== lint all module php =="
for f in $(find /app/web/modules/custom/ctt_patient_intake -name '*.php'); do
  php -l "$f" || exit 1
done
echo "== purge test invite tokens =="
drush sql:query "DELETE FROM ctt_patient_intake_invite"
drush sql:query "SELECT COUNT(*) AS remaining FROM ctt_patient_intake_invite"