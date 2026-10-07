<?php

use Drupal\user\Entity\User;

$uids = \Drupal::entityQuery('user')
  ->accessCheck(FALSE)
  ->condition('status', 1)
  ->condition('roles', ['chiropractor_active_', 'chiropractor_inactive_'], 'IN')
  ->range(0, 8)
  ->execute();

foreach ($uids as $uid) {
  $u = User::load($uid);
  if (!$u) {
    continue;
  }
  $clinic = 'none';
  if ($u->hasField('field_clinic') && !$u->get('field_clinic')->isEmpty()) {
    $clinic = $u->get('field_clinic')->target_id;
  }
  echo json_encode([$uid, $u->getAccountName(), $clinic, $u->getRoles()]) . "\n";
}