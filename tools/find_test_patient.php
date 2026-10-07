<?php

/**
 * Find a test patient account with a known password, or create one.
 *
 * Run: drush php:script /app/tools/find_test_patient.php
 */

use Drupal\user\Entity\User;

$out = function (string $line): void {
  print $line . PHP_EOL;
};

// Look for enrolled patients with passwords
$query = \Drupal::entityQuery('user')
  ->accessCheck(FALSE)
  ->condition('status', 1)
  ->condition('roles', 'enrolled_patient')
  ->condition('pass', '', '<>')
  ->range(0, 10)
  ->sort('uid', 'ASC');

$uids = $query->execute();

$out("Found " . count($uids) . " active enrolled_patient accounts with passwords:");
foreach ($uids as $uid) {
  $account = User::load($uid);
  $mail = $account->getEmail();
  $name = $account->getAccountName();
  $roles = $account->getRoles();
  $out("  uid $uid: name=$name mail=$mail roles=" . implode(',', $roles));
}

// Also check archived_patient
$query2 = \Drupal::entityQuery('user')
  ->accessCheck(FALSE)
  ->condition('status', 1)
  ->condition('roles', 'archived_patient')
  ->condition('pass', '', '<>')
  ->range(0, 10)
  ->sort('uid', 'ASC');

$uids2 = $query2->execute();

$out("");
$out("Found " . count($uids2) . " active archived_patient accounts with passwords:");
foreach ($uids2 as $uid) {
  $account = User::load($uid);
  $mail = $account->getEmail();
  $name = $account->getAccountName();
  $roles = $account->getRoles();
  $out("  uid $uid: name=$name mail=$mail roles=" . implode(',', $roles));
}