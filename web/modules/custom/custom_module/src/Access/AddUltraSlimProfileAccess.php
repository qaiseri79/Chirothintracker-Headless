<?php

namespace Drupal\custom_module\Access;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides access check for blocking a chiropractor.
 */
class AddUltraSlimProfileAccess
{

    /**
     * Checks access to block chiropractor form.
     *
     * @param \Drupal\user\Entity\User $user
     *   The user targeted (from route {uid}).
     * @param \Drupal\Core\Session\AccountInterface $account
     *   The currently logged-in user.
     *
     * @return \Drupal\Core\Access\AccessResult
     */
    public static function access(User $user, AccountInterface $account)
    {
        $is_patient = FALSE;

        // -------------------------------------------------------------
        // Check if this user ($user) is a patient of the current
        //    chiropractor ($account) directly or via subscribers.
        // -------------------------------------------------------------
        if ($user->hasField('field_chiropractor') && !$user->get('field_chiropractor')->isEmpty()) {
            // Direct relationship: patient's field_chiropractor points to this chiropractor.
            if ($user->get('field_chiropractor')->target_id == $account->id()) {
                $is_patient = TRUE;
            } else {
                // Indirect relationship: chiropractor’s subscribers include this patient.
                $chiropractor = User::load($account->id());
                if ($chiropractor && $chiropractor->hasField('field_chiropractor_subscribers') && !$chiropractor->get('field_chiropractor_subscribers')->isEmpty()) {
                    foreach ($chiropractor->get('field_chiropractor_subscribers')->referencedEntities() as $subscriber) {
                        if ($subscriber->id() == $user->id()) {
                            $is_patient = TRUE;
                            break;
                        }
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // Check if the patient has "Red Light" feature enabled.
        // -------------------------------------------------------------
        if ($is_patient && $user->hasField('field_laser_patient_status') && !$user->get('field_laser_patient_status')->isEmpty()) {
            $term = $user->get('field_laser_patient_status')->entity;
            $is_patient = !empty($term) && !empty($term->field_laser->value);
        }

        // -------------------------------------------------------------
        // Access check based on role and subscriber relationships.
        // -------------------------------------------------------------
        if ($is_patient) {
            // Chiropractor with 'chiropractor_laser' role has access.
            if ($account->hasRole('chiropractor_laser')) {
                return AccessResult::allowed();
            }

            // If not, check if one of this chiropractor’s subscribers has that role.
            $chiropractor = User::load($account->id());
            if ($chiropractor && $chiropractor->hasField('field_chiropractor_subscribers')) {
                foreach ($chiropractor->get('field_chiropractor_subscribers')->referencedEntities() as $subscriber) {
                    if ($subscriber instanceof UserInterface && $subscriber->hasRole('chiropractor_laser')) {
                        return AccessResult::allowed();
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // Default: deny access.
        // -------------------------------------------------------------
        return AccessResult::forbidden('Access Denied');
    }

}
