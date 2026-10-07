<?php

/**
 * Quick probe: find ONE patient with tracking_weight submissions.
 *
 * Run: drush php:script /app/tools/probe_progress_data.php
 */

$out = function (string $line): void {
  print $line . PHP_EOL;
};

$ids = \Drupal::entityQuery('contact_message')
  ->accessCheck(FALSE)
  ->condition('contact_form', 'tracking_weight')
  ->sort('created', 'DESC')
  ->range(0, 1)
  ->execute();

if (!$ids) {
  $out("No tracking_weight submissions found.");
  return;
}

$id = reset($ids);
$msg = \Drupal::entityTypeManager()->getStorage('contact_message')->load($id);
$author_uid = $msg->get('uid')->target_id;

$out("=== Submission $id (uid=$author_uid) ===");
foreach ($msg->getFields() as $field_name => $field) {
  if ($field->isEmpty()) continue;
  $val = $field->getValue();
  if (is_array($val) && count($val) === 1 && isset($val[0]['value'])) {
    $out("  $field_name: " . $val[0]['value']);
  } elseif (is_array($val)) {
    $out("  $field_name: " . json_encode($val));
  } else {
    $out("  $field_name: $val");
  }
}

$out("\n=== User $author_uid fields ===");
$account = \Drupal\user\Entity\User::load($author_uid);
if ($account) {
  foreach ($account->getFields() as $field_name => $field) {
    if ($field->isEmpty()) continue;
    if (!str_starts_with($field_name, 'field_')) continue;
    $val = $field->getValue();
    if (is_array($val) && count($val) === 1 && isset($val[0]['value'])) {
      $out("  $field_name: " . $val[0]['value']);
    } elseif (is_array($val)) {
      $out("  $field_name: " . json_encode($val));
    }
  }
}