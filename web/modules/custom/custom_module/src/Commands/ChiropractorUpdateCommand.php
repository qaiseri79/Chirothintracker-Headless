<?php

namespace Drupal\custom_module\Commands;

use Drush\Commands\DrushCommands;

/**
 * Custom Drush command for updating chiropractor clinic ownerships.
 */
class ChiropractorUpdateCommand extends DrushCommands {

  /**
   * Update clinic and clinic_location ownerships for chiropractor users.
   *
   * @command custom:update-clinic-clinic-location
   * @aliases cuccl
   */
  public function update() {
    $user_ids = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('roles', ['chiropractor_active_', 'chiropractor_inactive_'], 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($user_ids);

    $this->output()->writeln("Found " . count($users) . " users to process.");

    foreach ($users as $user) {
      $uid = $user->id();
      $this->output()->writeln("Processing user ID: $uid");

      if ($user->hasField('field_clinic') && !$user->get('field_clinic')->isEmpty()) {
        $clinic_id = $user->get('field_clinic')->target_id;
        $clinic = \Drupal::entityTypeManager()->getStorage('clinic')->load($clinic_id);

        if ($clinic) {
          $clinic->setOwnerId($uid);
          $clinic->save();

          $this->output()->writeln("  Set owner of clinic ID: $clinic_id");

          $location_ids = \Drupal::entityQuery('clinic')
            ->condition('type', 'clinic_location')
            ->condition('field_clinic.target_id', $clinic_id)
            ->accessCheck(FALSE)
            ->execute();

          $locations = \Drupal::entityTypeManager()->getStorage('clinic')->loadMultiple($location_ids);

          foreach ($locations as $location) {
            $location->setOwnerId($uid);
            $location->save();
            $this->output()->writeln("    Set owner of clinic_location ID: " . $location->id());
          }
        } else {
          $this->output()->writeln("  Clinic with ID $clinic_id not found.");
        }
      } else {
        $this->output()->writeln("  No clinic referenced for user ID: $uid");
      }
    }

    $this->output()->writeln("Finished updating clinic ownerships.");
  }

}
