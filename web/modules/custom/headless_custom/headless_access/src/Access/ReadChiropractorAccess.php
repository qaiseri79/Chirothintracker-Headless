<?php

declare(strict_types=1);

namespace Drupal\headless_access\Access;

use Drupal\headless_access\PortalRoles;

/**
 * Reads clinic-wide data: the roster, archived patients, intake submissions.
 *
 * The policy that protects other patients' names, email addresses, weights,
 * program days and intake answers, so it is worth being precise about why the
 * patient roles are absent.
 *
 * {@see \Drupal\headless_patients\ClinicScope} resolves the caller's clinic from
 * `field_clinic` on the account it loaded from storage. A patient has one too — the
 * clinic they are treated at — so a patient admitted here would be handed the
 * entire roster of their own clinic. There is no per-row filter in front of that,
 * because a clinic roster has no notion of "the requesting patient's own row".
 * Scoping to their own records is what {@see PortalMemberAccess} is for.
 */
final class ReadChiropractorAccess extends PortalRoleAccess {

  /**
   * {@inheritdoc}
   */
  protected static function allowedRoles(): array {
    return PortalRoles::CHIROPRACTOR_READ;
  }

  /**
   * {@inheritdoc}
   */
  protected static function policySubject(): string {
    return 'chiropractor';
  }

}