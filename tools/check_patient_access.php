<?php

/**
 * @file
 * Establishes the REAL access-control posture for a low-privilege patient.
 *
 * Run: lando drush php:script tools/check_patient_access.php
 *
 * The earlier HTTP probe was made with an administrator session, so it did not
 * prove anything about patient-level access. This checks Drupal's entity access
 * layer directly, which is what would govern a JSON:API or custom endpoint read.
 */

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;

// Clear any probe account left behind by an interrupted run.
$existing = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'ac_access_probe']);
if ($existing) {
  foreach ($existing as $stale) {
    $stale->delete();
  }
  print "removed stale probe account\n";
}

$account = User::create([
  'name' => 'ac_access_probe',
  'mail' => 'ac_access_probe@example.invalid',
  'status' => 1,
]);
$account->addRole('enrolled_patient');
$account->save();
$uid = $account->id();
print "created test patient uid=$uid\n";
print "testing as: {$account->getAccountName()} (uid $uid)\n";
print "roles: " . implode(', ', $account->getRoles()) . "\n\n";

// Become the patient for the remainder of the request.
$original = clone \Drupal::service('current_user');
\Drupal::service('current_user')->setAccount($account);
\Drupal::service('entity_type.manager')->getStorage('user')->resetCache();

$etm = \Drupal::entityTypeManager();

/**
 * Samples one real record per sensitive type and asks "may this patient view it?".
 */
$probes = [
  'user (someone else)' => ['user', [NULL, NULL]],
  'contact_message patient_intake' => ['contact_message', ['patient_intake', 'contact_form']],
  'contact_message message' => ['contact_message', ['message', 'contact_form']],
  'contact_message tracking_weight' => ['contact_message', ['tracking_weight', 'contact_form']],
  'webform_submission patient_intake' => ['webform_submission', ['patient_intake', 'webform_id']],
  'webform_submission log_patient_progress' => ['webform_submission', ['log_patient_progress', 'webform_id']],
  'patient_profile' => ['patient_profile', [NULL, NULL]],
  'clinic' => ['clinic', [NULL, NULL]],
  'profile customer' => ['profile', ['customer', 'type']],
  'user_role' => ['user_role', [NULL, NULL]],
];

foreach ($probes as $label => [$type, [$bundle, $bundle_field]]) {
  $storage = $etm->getStorage($type);
  $query = $storage->getQuery()->accessCheck(FALSE)->range(0, 1);
  if ($bundle) {
    $query->condition($bundle_field, $bundle);
  }
  try {
    $ids = $query->execute();
  }
  catch (\Throwable $e) {
    printf("  %-36s query error: %s\n", $label, $e->getMessage());
    continue;
  }
  $entity = $ids ? $storage->load(reset($ids)) : NULL;
  if (!$entity) {
    printf("  %-36s no sample record found\n", $label);
    continue;
  }
  printf("  %-36s view=%s  update=%s  delete=%s\n",
    $label,
    $entity->access('view') ? 'ALLOWED' : 'denied',
    $entity->access('update') ? 'ALLOWED' : 'denied',
    $entity->access('delete') ? 'ALLOWED' : 'denied'
  );
}

// Restore, then clean up the probe account.
\Drupal::service('current_user')->setAccount($original);
$account->delete();
print "\nprobe account deleted\n";
