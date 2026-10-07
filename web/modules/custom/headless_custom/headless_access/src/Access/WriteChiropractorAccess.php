<?php

declare(strict_types=1);

namespace Drupal\headless_access\Access;

use Drupal\headless_access\PortalRoles;

/**
 * Changes clinic-wide data: creating, archiving, re-enrolling, review state.
 *
 * Active chiropractors only. A cancelled chiropractor keeps
 * {@see ReadChiropractorAccess} so they can still see their clinic, but the moment
 * they could archive a patient or bring one back, cancelling a subscription would
 * have no effect on anything — the clinic would be just as editable as before,
 * for free, forever. An archived patient is read-only for the same reason: they
 * have left the program, and the record they left behind is not theirs to change.
 *
 * The only difference between this and the read policy is one line of role list,
 * which is the point of splitting them. Folding write into read, or reading the
 * write list as a subset check, would make the two indistinguishable at the route
 * and the distinction would have to be re-derived in every controller.
 */
final class WriteChiropractorAccess extends PortalRoleAccess {

  /**
   * {@inheritdoc}
   */
  protected static function allowedRoles(): array {
    return PortalRoles::CHIROPRACTOR_WRITE;
  }

  /**
   * {@inheritdoc}
   */
  protected static function policySubject(): string {
    return 'active chiropractor';
  }

}