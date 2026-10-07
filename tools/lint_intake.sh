#!/bin/sh
for f in \
  /app/web/modules/custom/ctt_patient_intake/src/Form/IntakeLinksForm.php \
  /app/web/modules/custom/ctt_patient_intake/src/Form/IntakeLinkRevokeForm.php \
  /app/web/modules/custom/ctt_patient_intake/src/Form/PatientIntakeSettingsForm.php \
  /app/web/modules/custom/ctt_patient_intake/src/IntakeInviteService.php \
  /app/web/modules/custom/ctt_patient_intake/ctt_patient_intake.install ; do
  php -l "$f" || exit 1
done