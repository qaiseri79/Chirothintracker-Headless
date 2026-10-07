<?php

declare(strict_types=1);

namespace Drupal\headless_access\Access;

use Drupal\headless_access\PortalRoles;

/**
 * Any signed-in portal account, doctor or patient.
 *
 * For endpoints that return what the caller's own role entitles them to see: a
 * patient's own progress, a conversation they are one party to, their own profile.
 * headless_messages needs both audiences on the same routes, and headless_progress
 * is patient-scoped, so neither can use a chiropractor-only policy.
 *
 * **This admits; it does not widen.** Holding a role here gets a caller as far as
 * the controller. What the response contains is decided by the query in the
 * service that owns the endpoint, because that is the only place that knows what
 * "mine" means for that particular set of records. This class is deliberately not
 * given a second job of narrowing anything — a policy class that also filtered
 * rows would be a second, competing implementation of scoping logic that already
 * exists in those services, and the two would drift.
 */
final class PortalMemberAccess extends PortalRoleAccess {

  /**
   * {@inheritdoc}
   */
  protected static function allowedRoles(): array {
    return PortalRoles::PORTAL_MEMBERS;
  }

  /**
   * {@inheritdoc}
   */
  protected static function policySubject(): string {
    return 'signed-in portal';
  }

}