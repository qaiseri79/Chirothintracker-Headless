<?php

/**
 * Inspects the test account the user supplied.
 */

$storage = \Drupal::entityTypeManager()->getStorage('user');
foreach (['qaiseri79@gmail.com', 'qaiseri79'] as $key) {
  $found = $storage->loadByProperties(['mail' => $key]);
  $found = $found ?: $storage->loadByProperties(['name' => $key]);
  foreach ($found as $account) {
    printf(
      "uid=%s name=%s mail=%s status=%s roles=[%s] pass_set=%s\n",
      $account->id(),
      $account->getAccountName(),
      $account->getEmail(),
      $account->isActive() ? 'active' : 'blocked',
      implode(', ', $account->getRoles()),
      $account->getPassword() ? 'yes' : 'no'
    );
  }
}
