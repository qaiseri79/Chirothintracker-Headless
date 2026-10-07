<?php

declare(strict_types=1);

namespace Drupal\headless_access\Access;

use Drupal\headless_access\PortalRoles;

/** Patient operations require enrollment and effective subscription access. */
final class WritePatientAccess extends PortalRoleAccess {
  protected static function allowedRoles(): array {
    return [PortalRoles::PATIENT_ENROLLED];
  }
  protected static function policySubject(): string {
    return 'active enrolled patient';
  }
}
