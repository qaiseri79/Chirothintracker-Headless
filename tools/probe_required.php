<?php

foreach (['field_date_of_birth', 'field_program_start_date'] as $name) {
  $conf = \Drupal::config("field.storage.contact_message.$name");
  echo "$name type={$conf->get('type')} datetime_type={$conf->get('settings.datetime_type')}\n";
}

echo "\n== required bundle fields ==\n";
$em = \Drupal::service('entity_field.manager');
foreach ($em->getFieldDefinitions('contact_message', 'patient_intake') as $fname => $def) {
  if (str_starts_with($fname, 'field_') && $def->isRequired()) {
    $type = $def->getType();
    $card = $def->getFieldStorageDefinition()->getCardinality();
    echo "$fname : $type (card $card)\n";
  }
}

echo "\n== allowed values for required list fields ==\n";
foreach (['field_gender', 'field_phone_number'] as $name) {
  $conf = \Drupal::config("field.storage.contact_message.$name");
  $vals = $conf->get('settings.allowed_values');
  if ($vals) {
    echo "$name: " . json_encode(array_map(fn($r) => $r['value'], $vals)) . "\n";
  }
}