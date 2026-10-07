<?php

declare(strict_types=1);

namespace Drupal\headless_access;

/**
 * The portal's role machine names, declared once.
 *
 * Every headless module needs to know which roles are allowed to reach which
 * endpoint, and all of them need to agree. When each module declared its own
 * custom permission, that agreement was maintained by hand across four modules
 * and it had already drifted: headless_patients invented its own pair, and
 * headless_progress granted its permission to the patient roles while
 * headless_patients deliberately did not. Nothing in code enforced the
 * difference, so the next module would have picked whichever it happened to copy.
 *
 * Keeping the role names here rather than in each access class means a role that
 * is renamed, added or retired is a one-line change, and the behaviour of every
 * endpoint moves together instead of one module at a time.
 *
 * ## The model these names encode
 *
 * - `chiropractor_active_` — a chiropractor with a live subscription. Reads the
 *   clinic and writes to it.
 * - `chiropractor_inactive_` — a chiropractor who has cancelled. Read-only: still
 *   sees their clinic and their patients, but cannot change anything.
 * - `enrolled_patient` — a patient currently in a program.
 * - `archived_patient` — a former patient. Read-only, same as an inactive
 *   chiropractor.
 *
 * The read-only half of that model is not expressed by a separate permission. It
 * falls out of which role list a route accepts, which is why {@see
 * \Drupal\headless_access\Access\WriteChiropractorAccess} names only the active
 * role.
 *
 * ## Roles deliberately absent
 *
 * `patient_chirothin` was the broad historic patient role on roughly 27k
 * accounts. It is retired, is not used by any view, and is intentionally not
 * listed: an endpoint that enumerates roles here is a list of what may reach the
 * API, so an unlisted role is denied. Accounts still carrying it will not reach
 * the headless endpoints until they are moved onto `enrolled_patient` or
 * `archived_patient`.
 */
final class PortalRoles {

  /** Chiropractor with a live subscription. */
  public const CHIROPRACTOR_ACTIVE = 'chiropractor_active_';

  /** Chiropractor who has cancelled. Read-only. */
  public const CHIROPRACTOR_INACTIVE = 'chiropractor_inactive_';

  /** Store-management role, synchronized only for new subscription-managed doctors. */
  public const ECOMMERCE_MANAGER = 'ecommerce_manager';

  /** Patient currently in a program. */
  public const PATIENT_ENROLLED = 'enrolled_patient';

  /** Former patient. Read-only. */
  public const PATIENT_ARCHIVED = 'archived_patient';

  /**
   * Site administrator. Staff, not a portal user.
   *
   * Declared but deliberately kept out of {@see self::PORTAL_MEMBERS} and every
   * other list here, which is the entire point of naming it: administrators author
   * content and manage accounts in the Drupal admin UI, and are not an audience
   * for `/api/headless`. A policy that enumerated this role would hand an admin
   * session the same API as a patient.
   *
   * A module may reference this constant to *describe* the role — to report
   * `audience: 'administrator'` in a payload, or to assert in a test that such an
   * account is refused. It must never appear in a list that an access policy reads.
   *
   * This constant was added 2026-10-01, after `headless_content` referenced it in
   * `ContentCapabilities::audience()` without it existing. Referencing an
   * undeclared class constant is an `Error` at runtime, not a notice, so the
   * capability block — which every list response carries — would have thrown on
   * every request rather than failing visibly at build time.
   *
   * The rule above — never in a list an access policy reads — has exactly one
   * deliberate exception:
   * {@see \Drupal\headless_access\Access\ManageIntakeLinkAccess}, which admits an
   * administrator because managing a clinic's own intake link is staff work and
   * because that endpoint can only touch the one clinic the caller names. It
   * admits no patient data: no administrator can read a roster or an intake
   * submission through the portal API, and adding this role to a policy that guards
   * those is still forbidden.
   */
  public const ADMINISTRATOR = 'administrator';

  /**
   * Roles that may read clinic-wide data.
   *
   * Both chiropractor states, because cancelling a subscription removes the
   * ability to change the clinic without removing the ability to see it.
   */
  public const CHIROPRACTOR_READ = [
    self::CHIROPRACTOR_ACTIVE,
    self::CHIROPRACTOR_INACTIVE,
  ];

  /**
   * Roles that may change clinic-wide data.
   *
   * Active only. An inactive chiropractor and an archived patient are read-only,
   * which is the whole difference between this list and
   * {@see self::CHIROPRACTOR_READ}.
   */
  public const CHIROPRACTOR_WRITE = [
    self::CHIROPRACTOR_ACTIVE,
  ];

  /**
   * Any signed-in portal account, doctor or patient.
   *
   * For endpoints that return data the caller's own role entitles them to see,
   * such as a patient's own progress or a conversation they are a party to.
   *
   * This is deliberately a gate and not a grant. Holding one of these roles lets a
   * caller *reach* the endpoint; it does not widen what the endpoint returns. The
   * scoping to the caller's own records is done by the query in the service that
   * owns the endpoint, which is the only place that knows what "own" means for
   * that data. Putting the rows behind this list too would give every patient
   * every other patient's data.
   */
  public const PORTAL_MEMBERS = [
    self::CHIROPRACTOR_ACTIVE,
    self::CHIROPRACTOR_INACTIVE,
    self::PATIENT_ENROLLED,
    self::PATIENT_ARCHIVED,
  ];

}