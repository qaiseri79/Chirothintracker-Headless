<?php

namespace Drupal\custom_module\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\user\Entity\User;

/**
 * Provides access check for blocking a chiropractor.
 */
class ChiropractorBlockAccess
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
        // Current user must have 'chiropractor_active_' role.
        if (!$account->hasRole('chiropractor_active_')) {
            return AccessResult::forbidden('Only active chiropractors can perform this action.');
        }

        // Check that the target user has either role
        $hasRole = $user->hasRole('chiropractor_active_') || $user->hasRole('chiropractor_inactive_');

        // Check that target user's field_chiropractor_subscribers references current user
        $isSubscriber = FALSE;
        if ($user->hasField('field_chiropractor_subscribers') && !$user->get('field_chiropractor_subscribers')->isEmpty()) {
            if ($user->get('field_chiropractor_subscribers')->target_id == $account->id()) {
                $isSubscriber = TRUE;
            }
        }

        if ($hasRole && $isSubscriber) {
            return AccessResult::allowed();
        } elseif (!$hasRole) {
            return AccessResult::forbidden('Target user is not an active or inactive chiropractor.');
        } elseif (!$isSubscriber) {
            return AccessResult::forbidden('You are not a subscriber of this chiropractor.');
        }

        // Default deny
        return AccessResult::forbidden();
    }
}
