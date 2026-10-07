<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\headless_access\PortalRoles;

/**
 * What the caller may do with the library, for the frontend to render.
 *
 * Computed here rather than in the frontend, because a client-side condition offers
 * a button whose endpoint refuses it, and that reads to a user as a broken page
 * rather than as a missing permission.
 *
 * ## The matrix
 *
 * | Role | View | Favourite | Create | Delete |
 * | --- | --- | --- | --- | --- |
 * | `chiropractor_active_` | yes | no | yes, recipes, resources & training | no |
 * | `chiropractor_inactive_` | yes | no | no | no |
 * | `enrolled_patient` | yes | yes, recipes only | no | no |
 * | `archived_patient` | yes | no | no | no |
 * | `administrator` | not admitted — works in the Drupal admin UI | no | no | no |
 *
 * Administrators are not an API audience at all. `PortalRoles::PORTAL_MEMBERS` is
 * the four portal roles and does not include `administrator`, so
 * `PortalMemberAccess` turns every one of these routes away for an admin account
 * before any controller runs. There is no separate list here to keep in step with
 * that one, because there is nothing to grant: `canDelete` and `canManageOwnContent`
 * are FALSE for every role that can reach this module, including administrator.
 *
 * ## `canCreate` describes a route, not a Drupal permission
 *
 * `canCreate` is TRUE for `chiropractor_active_` on the `recipe`,
 * `chirothin_resource` and `training` bundles because `headless_content.create`
 * — gated by `WriteChiropractorAccess`, which is the role-only half of that line
 * — exists for exactly that caller and those bundles. The two stay together, and
 * the role list below carries the same rule as that route for the same reason.
 *
 * This changed twice, and the direction of each change is the point. An earlier
 * draft reported `canCreate: true` for `administrator`, on the reasoning that it
 * described the Drupal-side permission. That was wrong in a way that mattered: it
 * made the payload claim a capability the API does not have, so the frontend would
 * render an "Add recipe" button for a user who cannot reach the endpoint behind it.
 * A capability block that lies about the API is worse than no capability block.
 * When the create route was then added deliberately, `canCreate` gained a real
 * answer — and the answer is still keyed to the actual route, so it cannot drift
 * ahead of what the endpoint will do again.
 *
 * ## Favourites are a flag permission, not a role check
 *
 * `canFavourite` is derived from the bundle as well as the role, because the
 * `favorites` flag is configured on the `recipe` bundle alone
 * (`config/sync/flag.flag.favorites.yml`, `bundles: [recipe]`). Training and
 * resources cannot be favourited at all, so a role that may favourite recipes still
 * must be told `false` for the other two bundles or the frontend renders a star on a
 * node the POST would reject.
 *
 * The role list below is advisory in the same sense and for the same reason: it is
 * so the frontend can hide a star without a 403 round trip. {@see ContentFavorites}
 * is authoritative, because it asks the flag itself via `actionAccess()` — the same
 * check the flag module's own ajax link uses. A role granted `flag favorites` in
 * config is honoured immediately by the endpoint even though
 * `canFavourite` would still say no, so the two are updated together.
 *
 * @see \Drupal\headless_content\ContentFavorites for the authoritative check.
 */
class ContentCapabilities {

  /**
   * Roles that hold the flag/unflag permissions on the `favorites` flag.
   *
   * `enrolled_patient` is the only role in `config/sync` with `flag favorites` and
   * `unflag favorites`. `chiropractor_active_` holds eleven other flags and not this
   * one; `archived_patient` and `chiropractor_inactive_` hold none of this flag's.
   *
   * @see \Drupal\headless_content\ContentFavorites, which is authoritative.
   */
  private const FAVOURITE_ROLES = [
    PortalRoles::PATIENT_ENROLLED,
  ];

  /**
   * Bundles the `favorites` flag is configured on.
   *
   * Mirrors `flag.flag.favorites.yml`. If that flag is reconfigured to cover another
   * bundle, this list and the flag config change together — and note that
   * `ContentFavorites::appliesTo()` reads the flag itself, so it does not depend on
   * this list being right in order to stay correct. Only the frontend hint would be
   * wrong.
   */
  private const FAVOURITE_BUNDLES = ['recipe'];

  /**
   * Roles that may create a recipe, resource or training item through the API.
   *
   * Exactly the roles `WriteChiropractorAccess` names (`PortalRoles::CHIROPRACTOR_WRITE`):
   * active chiropractors. An inactive chiropractor may still read their clinic
   * but not change it, and cancelling a subscription must not leave the clinic
   * editable for free — the same rule that governs archiving and re-enrolling.
   */
  private const CREATE_ROLES = [
    PortalRoles::CHIROPRACTOR_ACTIVE,
  ];

  /**
   * Bundles the create route serves.
   *
   * Mirrors the `bundle` regex on the `headless_content.create` route
   * (`recipe|training|chirothin_resource`) and `ContentService::CREATE_BUNDLES`.
   * If the route's set changes, this list and both of those change together.
   */
  private const CREATE_BUNDLES = ['recipe', 'training', 'chirothin_resource'];

  /**
   * The capability flags for an account.
   *
   * @param string $bundle
   *   Bundle machine name the frontend is rendering. Favourite capability is
   *   bundle-dependent, so this cannot be answered without it.
   * @param array<int, string> $roles
   *   The account's roles.
   *
   * @return array{canCreate: bool, canDelete: bool, canManageOwnContent: bool, canFavourite: bool, audience: string}
   *   `audience` is a short label for what the caller is, for the UI to branch on
   *   if it needs to.
   */
  public function forRoles(array $roles, string $bundle): array {
    return [
      // TRUE only for an active chiropractor on a creatable bundle — the caller
      // and bundle the `headless_content.create` route actually serves. FALSE
      // everywhere else, and `canDelete` and `canManageOwnContent` stay false for
      // every role, because there is no route behind either. These keys stay in
      // the payload so the frontend's shape does not change when a delete or edit
      // route is ever added deliberately.
      'canCreate' => in_array($bundle, self::CREATE_BUNDLES, TRUE)
        && $this->hasAny($roles, self::CREATE_ROLES),
      'canDelete' => FALSE,
      // Named for the editing work that lands later. There is no edit route today,
      // so this is false for every role including administrator.
      'canManageOwnContent' => FALSE,
      'canFavourite' => $this->canFavourite($roles, $bundle),
      'audience' => $this->audience($roles),
    ];
  }

  /**
   * Whether this role may favourite this bundle.
   *
   * Both conditions, deliberately: holding `flag favorites` is not enough for a
   * bundle the flag does not apply to.
   *
   * @param array<int, string> $roles
   * @param string $bundle
   */
  public function canFavourite(array $roles, string $bundle): bool {
    return in_array($bundle, self::FAVOURITE_BUNDLES, TRUE)
      && $this->hasAny($roles, self::FAVOURITE_ROLES);
  }

  /**
   * Whether the roles intersect.
   *
   * @param array<int, string> $roles
   * @param array<int, string> $wanted
   */
  private function hasAny(array $roles, array $wanted): bool {
    foreach ($wanted as $role) {
      if (in_array($role, $roles, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Which of the audiences the account is, for the frontend.
   *
   * `administrator` and `unknown` are unreachable through this module's routes,
   * since neither is in `PortalRoles::PORTAL_MEMBERS`. They are answered explicitly
   * rather than falling through so that a misconfigured route is visible in the
   * payload as a diagnostic rather than as a mislabelled patient.
   */
  public function audience(array $roles): string {
    if (in_array(PortalRoles::CHIROPRACTOR_ACTIVE, $roles, TRUE)
      || in_array(PortalRoles::CHIROPRACTOR_INACTIVE, $roles, TRUE)) {
      return 'chiropractor';
    }

    if (in_array(PortalRoles::PATIENT_ENROLLED, $roles, TRUE)) {
      return 'patient';
    }

    if (in_array(PortalRoles::PATIENT_ARCHIVED, $roles, TRUE)) {
      return 'archived_patient';
    }

    if (in_array(PortalRoles::ADMINISTRATOR, $roles, TRUE)) {
      return 'administrator';
    }

    return 'unknown';
  }

}