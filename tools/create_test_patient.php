<?php

/**
 * Create a test patient with a known password.
 *
 * Run: drush php:script /app/tools/create_test_patient.php
 */

use Drupal\user\Entity\User;

$email = 'test.patient@headless.local';
$password = 'TestPass123!';

// Delete if exists
$existing = \Drupal::entityQuery('user')
  ->accessCheck(FALSE)
  ->condition('mail', $email)
  ->execute();
if ($existing) {
  $account = User::load(reset($existing));
  $account->delete();
  print "Deleted existing test account.\n";
}

$account = User::create([
  'name' => $email,
  'mail' => $email,
  'pass' => $password,
  'status' => 1,
  'roles' => ['authenticated', 'enrolled_patient', 'patient_chirothin'],
]);
$account->save();

print "Created test patient:\n";
print "  uid: " . $account->id() . "\n";
print "  email: $email\n";
print "  password: $password\n";
print "  roles: " . implode(',', $account->getRoles()) . "\n";