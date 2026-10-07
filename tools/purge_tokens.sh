#!/bin/sh
drush sql:query "DELETE FROM ctt_patient_intake_invite WHERE token IN ('d89ae9765197057bd43b317c0b869d9cf170','0d702cc2c8844c6531a7959006bc7056a7d2')"
drush sql:query 'SELECT token,status,uses,max_uses,clinic_id FROM ctt_patient_intake_invite'