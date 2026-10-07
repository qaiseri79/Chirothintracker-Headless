<?php

require '/app/autoload.php';
echo file_exists('/app/autoload.php') ? "HAS_AUTOLOAD\n" : "NO_AUTOLOAD\n";
echo class_exists('Symfony\Component\Yaml\Yaml') ? "HAS_YAML\n" : "NO_YAML\n";
$files = [
  '/app/web/modules/custom/headless_custom/headless_intake/headless_intake.routing.yml',
  '/app/web/modules/custom/headless_custom/headless_intake/headless_intake.info.yml',
  '/app/web/modules/custom/headless_custom/headless_intake/headless_intake.services.yml',
  '/app/web/modules/custom/headless_custom/headless_patients/headless_patients.routing.yml',
  '/app/web/modules/custom/headless_custom/headless_patients/headless_patients.services.yml',
  '/app/config/sync/field.storage.clinic.field_intake_token.yml',
  '/app/config/sync/field.field.clinic.clinic.field_intake_token.yml',
];
foreach ($files as $file) {
  try {
    Symfony\Component\Yaml\Yaml::parseFile($file);
    echo "yaml-ok  $file\n";
  }
  catch (Throwable $e) {
    echo "YAML-FAIL $file: {$e->getMessage()}\n";
  }
}