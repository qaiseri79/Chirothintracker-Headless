<?php

use Drupal\user\Entity\User;

$uid = 218; // wakracke_218 (archived_patient)
$password = 'TestPass123!';

$account = User::load($uid);
if (!$account) {
  print "User $uid not found.\n";
  exit(1);
}

$account->setPassword($password);
$account->save();

print "Set password for uid $uid:\n";
print "  email: " . $account->getEmail() . "\n";
print "  name: " . $account->getAccountName() . "\n";
print "  password: $password\n";
print "  roles: " . implode(',', $account->getRoles()) . "\n";