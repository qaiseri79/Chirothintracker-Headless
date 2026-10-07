<?php

namespace Drupal\headless_auth\Authentication;

use Drupal\user\UserAuthentication;
use Drupal\user\UserInterface;

/**
 * Resolves a login identifier to an account by name, then by email address.
 *
 * Drupal matches logins against the account *name* only, but the portal asks
 * patients for the email address they enrolled with. Newer enrollments already
 * have name == mail; accounts migrated from Drupal 7 do not (they are named
 * `wakracke_218`, `Wiestfamily_220`, …), so those patients cannot log in at all.
 *
 * This extends core's own resolver and only widens the lookup: core's password
 * check, flood control, blocked-account handling, session creation and response
 * payload are untouched, and the failure message is still core's generic
 * "unrecognized username or password", so this does not reveal which addresses
 * have accounts.
 *
 * A name always wins over an email, so no existing login changes behaviour. If
 * the mail column ever holds duplicates, the lowest uid wins, mirroring the
 * `reset()` that core's own lookups use.
 *
 * @see \Drupal\user\UserAuthenticationInterface::lookupAccount()
 * @see \Drupal\user\Controller\UserAuthenticationController::login()
 */
class EmailOrNameAuthentication extends UserAuthentication {

  /**
   * {@inheritdoc}
   */
  public function lookupAccount($identifier): UserInterface|false {
    $account = parent::lookupAccount($identifier);
    if ($account !== FALSE) {
      return $account;
    }

    // Only try the mail lookup for something that can be a mail address, so a
    // mistyped username does not cost a second query.
    if (!is_string($identifier) || !str_contains($identifier, '@')) {
      return FALSE;
    }

    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $identifier]);

    return $accounts ? reset($accounts) : FALSE;
  }

}
