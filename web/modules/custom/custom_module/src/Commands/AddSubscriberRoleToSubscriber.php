<?php

namespace Drupal\custom_module\Commands;

use Drush\Commands\DrushCommands;

/**
 * Custom Drush command for updating chiropractor clinic ownerships.
 */
class AddSubscriberRoleToSubscriber extends DrushCommands {

  /**
   * Update clinic and clinic_location ownerships for chiropractor users.
   *
   * @command custom:add-subscriber-role-to-subscriber
   * @aliases cassrts
   */
  public function update() {
    $query = \Drupal::entityQuery('user')
      ->condition('roles', ['chiropractor_active_', 'chiropractor_inactive_'], 'IN')
      ->condition('field_chiropractor_subscribers', NULL, 'IS NULL')
      ->accessCheck(FALSE);

    $uids = $query->execute();

    $updated = 0;
    foreach ($uids as $uid) {
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      if ($user->hasRole('subscriber')) {
        $this->output()->writeln('Skipped user ' . $uid . ' (' . $user->getAccountName() . '): already has subscriber.');
        continue;
      }
      $user->addRole('subscriber');
      $user->changed->preserve = TRUE;
      $user->save();
      $updated++;
      $this->output()->writeln('Updated user ' . $uid . ' (' . $user->getAccountName() . '): subscriber role added.');
    }

    $this->output()->writeln('Subscriber role added to ' . $updated . ' users.');
  }

}
