<?php

declare(strict_types=1);

namespace Drupal\headless_access\Access;

use Drupal\headless_access\PortalRoles;

/**
 * Manages a clinic's own permanent intake link.
 *
 * This is the one policy that admits the `administrator` role, and it is worth
 * saying plainly why that is not the accident it looks like.
 *
 * {@see PortalRoles::ADMINISTRATOR} says an administrator must never appear in a
 * list an access policy reads, because the portal API is not an audience for
 * administrators: they manage clinics through the Drupal admin UI. That reasoning
 * still holds for patient data — no admin gets `ReadChiropractorAccess`, so none
 * of them can read a clinic's roster or a patient's intake answers through the
 * portal API, and nothing here changes that.
 *
 * An intake link is not patient data. It is a clinic's own address for its
 * intake form, and the clinic it belongs to is named in the request. The reason an
 * administrator is allowed here and not on the roster is that this endpoint can
 * only ever touch the single clinic the caller named, whereas the roster endpoint
 * returns every patient in a clinic and has no equivalent narrower view.
 *
 * Admins also need it for the reason they needed the old `/manage/intake-links`
 * form: supporting a clinic whose chiropractor has lost their account, locked out
 * of the portal, or never had one. Without this policy, the only way to hand such a
 * clinic a link would be to log in as their chiropractor.
 *
 * What this policy deliberately does *not* do is widen anything for a chiropractor.
 * A chiropractor holding this access is still scoped to the clinic on their own
 * account by ClinicScope, and the `?clinic=` override in the controller is gated on
 * `administer site configuration`, so naming another clinic does nothing for them.
 * The gate admits who may reach the endpoint; the controller decides which clinic
 * they can reach inside it.
 */
final class ManageIntakeLinkAccess extends PortalRoleAccess {

  /**
   * {@inheritdoc}
   */
  protected static function allowedRoles(): array {
    return PortalRoles::CHIROPRACTOR_WRITE + [PortalRoles::ADMINISTRATOR];
  }

  /**
   * {@inheritdoc}
   */
  protected static function policySubject(): string {
    return 'active chiropractor or administrator';
  }

}