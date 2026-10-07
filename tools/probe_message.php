<?php

/**
 * Probe 2: contact_message.patient_intake structure needed to build the
 * message programmatically: base fields, required fields, and the exact
 * config storage for the three legal fields.
 */

$em = \Drupal::entityTypeManager();
$storage = $em->getStorage('field_config');

echo "== config storage of the three legal fields ==\n";
foreach (['field_agreement', 'field_program_agreement', 'field_media_release_agreement'] as $fname) {
  $id = "contact_message.patient_intake.$fname";
  $field = $storage->load($id);
  if ($field) {
    $default = $field->get('default_value');
    $epp = $field->get('third_party_settings.epp.value');
    echo "$fname\n";
    echo "  default_value set: " . (empty($default) ? 'no' : 'yes') . "\n";
    echo "  epp third_party value len: " . (is_string($epp) ? strlen($epp) : 'n/a') . "\n";
    if (is_string($epp) && $epp !== '') {
      echo "  epp head: " . substr($epp, 0, 120) . "\n";
    }
  }
  else {
    echo "$fname : NO field_config on the message bundle (checking contact_form bundle entity)\n";
  }
}

echo "\n== base fields on contact_message ==\n";
$fields = $em->getFieldDefinitions('contact_message', 'patient_intake');
foreach ($fields as $fname => $def) {
  if (str_starts_with($fname, 'field_')) {
    continue;
  }
  echo "  $fname : label={$def->getLabel()} required={$def->isRequired()} type={$def->getType()}\n";
}

echo "\n== contact_form.patient_intake bases ==\n";
$form = $em->getStorage('contact_form')->load('patient_intake');
if ($form) {
  /** @var \Drupal\contact\ContactFormInterface $form */
  echo "  recipients: " . implode(', ', $form->getRecipients()) . "\n";
  echo "  reply: {$form->getReply()}\n";
  echo "  subject: {$form->getSubject()}\n";
  echo "  status: " . ($form->status() ? 'open' : 'closed') . "\n";
  $form_fields = $em->getFieldDefinitions('contact_form', 'patient_intake');
  foreach (['field_agreement', 'field_program_agreement', 'field_media_release_agreement'] as $fname) {
    if (isset($form_fields[$fname])) {
      $val = $form->get($fname)->value ?? null;
      echo "  contact_form::$fname = " . (is_string($val) ? substr($val, 0, 100) . ' (len ' . strlen($val) . ')' : var_export($val, TRUE)) . "\n";
    }
  }
}

echo "\n== does the form save via Message::create + ->save()? (source of truth) ==\n";
$base = \Drupal::moduleHandler()->getModuleList();
foreach (['contact', 'contact_storage', 'webform'] as $m) {
  echo "  $m module present: " . (isset($base[$m]) ? 'yes' : 'no') . "\n";
}

echo "\n== how many user rows reference clinic 1 (for later notification test) ==\n";
$uids = \Drupal::entityQuery('user')
  ->condition('status', 1)
  ->condition('field_clinic', 1)
  ->range(0, 5)
  ->execute();
echo "  active users with field_clinic=1: " . implode(', ', $uids) . "\n";