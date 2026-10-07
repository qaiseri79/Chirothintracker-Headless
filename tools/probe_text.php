<?php

/**
 * Probe 3: exact live source of the agreement texts + message base fields.
 */

use Drupal\Core\Entity\EntityTypeManagerInterface;

$em = \Drupal::entityTypeManager();

echo "== field config files (config factory) ==\n";
foreach (['field_agreement', 'field_program_agreement', 'field_media_release_agreement'] as $fname) {
  $conf = \Drupal::config("field.field.contact_message.patient_intake.$fname");
  $raw = $conf->getRawData();
  $epp = $conf->get('third_party_settings.epp.value');
  $default = $conf->get('default_value');
  echo "$fname\n";
  echo "  epp.value present: " . (is_string($epp) ? 'yes (len ' . strlen($epp) . ')' : 'no') . "\n";
  echo "  default_value present: " . (is_array($default) ? 'yes' : 'no') . "\n";
  echo "  third_party_settings keys: " . implode(',', array_keys($raw['third_party_settings'] ?? [])) . "\n";
}

echo "\n== base fields on contact_message.patient_intake ==\n";
$efm = \Drupal::service('entity_field.manager');
$fields = $efm->getFieldDefinitions('contact_message', 'patient_intake');
foreach ($fields as $fname => $def) {
  if (str_starts_with($fname, 'field_')) {
    continue;
  }
  echo "  $fname : label={$def->getLabel()} required={$def->isRequired()} type={$def->getType()}\n";
}

echo "\n== contact_form.patient_intake ==\n";
$form = $em->getStorage('contact_form')->load('patient_intake');
if ($form) {
  echo "  recipients: " . implode(', ', $form->getRecipients()) . "\n";
  echo "  reply: {$form->getReply()}\n";
  echo "  subject: {$form->getSubject()}\n";
  echo "  status: " . ($form->status() ? 'open' : 'closed') . "\n";
}

echo "\n== module presence ==\n";
$mods = \Drupal::moduleHandler()->getModuleList();
foreach (['contact', 'contact_storage', 'epp', 'webform'] as $m) {
  echo "  $m: " . (isset($mods[$m]) ? 'yes' : 'no') . "\n";
}

echo "\n== custom_module.services api ==\n";
if (\Drupal::hasService('custom_module.services')) {
  $svc = \Drupal::service('custom_module.services');
  echo "  has method getSubscriberByChiropractor: " . (method_exists($svc, 'getSubscriberByChiropractor') ? 'yes' : 'no') . "\n";
}

echo "\n== admins / clinic users for send tests ==\n";
if (\Drupal::moduleHandler()->moduleExists('user')) {
  $uids = \Drupal::entityQuery('user')
    ->condition('status', 1)
    ->condition('field_clinic', 1)
    ->range(0, 5)
    ->execute();
  echo "  active users field_clinic=1: " . implode(', ', $uids) . "\n";
  foreach ($uids as $uid) {
    $user = \Drupal::userEntity($uid);
    echo "    uid $uid: {$user->getEmail()} " . ($user->getDisplayName()) . "\n";
  }
}