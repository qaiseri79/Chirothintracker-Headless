#!/usr/bin/env bash
set -uo pipefail
M=/app/web/modules/custom/headless_custom
for f in \
  "$M/headless_intake/src/ClinicIntakeLinkService.php" \
  "$M/headless_intake/src/ClinicLinkAccessDeniedException.php" \
  "$M/headless_intake/src/ClinicLinkNotFoundException.php" \
  "$M/headless_intake/src/Controller/IntakeApiController.php" \
  "$M/headless_intake/headless_intake.install" \
  "$M/headless_patients/src/PatientIntakeService.php" \
  "$M/headless_patients/src/Controller/ClinicIntakeLinkController.php" \
  "$M/headless_access/src/Access/ManageIntakeLinkAccess.php" \
  "$M/headless_access/src/PortalRoles.php"
do
  php -l "$f"
done
echo "--- YAML ---"
# Symfony's parser rather than yaml_parse_file(): the CLI SAPI here has no
# ext-yaml, and Drupal itself parses config through this component.
for y in \
  "$M/headless_intake/headless_intake.routing.yml" \
  "$M/headless_intake/headless_intake.info.yml" \
  "$M/headless_intake/headless_intake.services.yml" \
  "$M/headless_patients/headless_patients.routing.yml" \
  "$M/headless_patients/headless_patients.services.yml" \
  "/app/config/sync/field.storage.clinic.field_intake_token.yml" \
  "/app/config/sync/field.field.clinic.clinic.field_intake_token.yml"
do
  php -r '
    require "/app/autoload.php";
    try {
      Symfony\Component\Yaml\Yaml::parseFile($argv[1]);
      echo "yaml-ok ", $argv[1], PHP_EOL;
    }
    catch (Throwable $e) {
      echo "YAML-FAIL ", $argv[1], ": ", $e->getMessage(), PHP_EOL;
    }
  ' "$y"
done