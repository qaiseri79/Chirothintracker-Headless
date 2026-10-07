<?php
$storage = \Drupal::entityTypeManager()->getStorage('user');
$user = $storage->load(174);
echo "account: ", $user->getAccountName(), "\n";
echo "roles: ", implode(', ', $user->getRoles()), "\n";
echo "has administer site configuration: ", var_export($user->hasPermission('administer site configuration'), true), "\n";
echo "field_clinic: ", $user->get('field_clinic')->target_id ?? '(empty)', "\n";