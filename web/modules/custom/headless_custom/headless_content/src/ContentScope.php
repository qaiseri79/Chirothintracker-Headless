<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_content\Exception\ContentException;
// Drupal\node\NodeInterface, not Drupal\Core\Entity\NodeInterface — see the note on
// the import in ContentService.php. Here the wrong name fails closed rather than
// silently: canSee() is typed on it, so a TypeError surfaces as a 500 rather than as
// an empty list.
use Drupal\node\NodeInterface;

/**
 * Decides which content a caller may read, from the account and the clinic alone.
 *
 * This exists as its own class so there is exactly one answer to "may this caller
 * see this node". Every read in ContentService goes through
 * {@see self::visibility()} and {@see self::canSee()}; nothing reads a clinic from
 * the request. Same rule as headless_patients' ClinicScope, and the clinic
 * resolution below is a deliberate mirror of it rather than a shared dependency —
 * see the note on {@see self::clinicId()}.
 *
 * ## The audience field
 *
 * `field_published_to` is a multi-value entity reference to the ECK `clinic`
 * entity (`node.field_published_to`, cardinality -1, target_type `clinic`). It is
 * what all three legacy Views filter on:
 *
 * - `recipes` block_2 "Clinic Block" and block_3 "Shared block" both set a
 *   relationship on `field_published_to`, join `clinic_field_data` through the
 *   reverse `user.field_clinic` reference, and filter `users.uid_current = 1`. That
 *   resolves to `field_published_to` containing the viewer's clinic — and because
 *   the relationship is `required: true`, a node with no clinic on it matches
 *   neither display. The two displays have identical filters; `custom_module.module`
 *   picks which one renders from the clinic's toggle, so for recipes and training
 *   that toggle changes the *block*, never the *rows*.
 * - `resources` embed_1 "Clinic Resources" filters on the same field via an
 *   `entity_target_id` argument, and `resources_test` passes the row's clinic id
 *   into it. embed_2 "Shared Resources" is handed `arguments: ''`, which Views
 *   treats as no argument at all, so that display carries no clinic filter and
 *   returns every published resource.
 *
 * `custom_module_views_pre_render()` and `custom_module_views_query_alter()` do
 * not touch these three views, so the config above is the whole of the legacy
 * rule.
 *
 * ## Why the toggle only matters for resources
 *
 * `clinic.field_hide_shared_resources` defaults to 0 and is the switch
 * `resources_test` uses to choose between the two embeds
 * (`if: field_hide_shared_resources eq 1` renders embed_1, otherwise embed_2).
 * For recipes and training the same switch chooses between two blocks that return
 * the same rows, so reproducing it as a row filter would change nothing and
 * inventing a second meaning for it would be a bug. See
 * {@see self::SHARED_DROPS_CLINIC_FILTER}.
 *
 * ## A caller with no clinic
 *
 * There is no mode to give them: the toggle lives on the clinic, so an account
 * with an empty `field_clinic` cannot be classified as clinic-scoped or
 * shared-scoped. Rejecting with 403 is the honest answer. The alternative —
 * treating a missing clinic as `shared` — would hand the entire resource library
 * to exactly the accounts whose clinic relationship is broken.
 *
 * @see \Drupal\headless_content\ContentService for the query this feeds.
 */
class ContentScope {

  /**
   * The field carrying the audience. Multi-value reference to ECK `clinic`.
   */
  public const FIELD_PUBLISHED_TO = 'field_published_to';

  /**
   * The clinic toggle. Boolean, on the `clinic` entity bundle, default 0.
   */
  public const FIELD_HIDE_SHARED_RESOURCES = 'field_hide_shared_resources';

  /**
   * The field on user holding the clinic. Single reference.
   */
  private const FIELD_CLINIC = 'field_clinic';

  /**
   * Bundles where the clinic toggle stops narrowing the audience.
   *
   * Only resources. This is the single place the legacy "shared means everybody"
   * behaviour is encoded, so a bundle added here is deliberately not clinic
   * scoped — the entry is the whole decision, not a hint at one.
   */
  private const SHARED_DROPS_CLINIC_FILTER = ['chirothin_resource'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The clinic the account belongs to, or NULL when it has none.
   *
   * Loaded from user storage rather than read off the runtime account, because the
   * account that arrives on a request is a proxy in some paths and the field is a
   * reference only the stored entity has. Mirrors ClinicScope::clinicId() in
   * headless_patients; duplicated rather than injected so that a module about
   * content does not depend on a module about the patient roster for its tenant
   * boundary. The two must agree, which is why the behaviour is identical rather
   * than merely similar.
   */
  public function clinicId(AccountInterface $account): ?int {
    if ($account->isAuthenticated() === FALSE) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    if (!$user instanceof FieldableEntityInterface || !$user->hasField(self::FIELD_CLINIC)) {
      return NULL;
    }

    $target = $user->get(self::FIELD_CLINIC)->target_id;
    if ($target === NULL || $target === '') {
      return NULL;
    }

    $id = (int) $target;

    return $id > 0 ? $id : NULL;
  }

  /**
   * The clinic the account belongs to, or a 403 failure.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   When the account has no clinic.
   */
  public function requireClinicId(AccountInterface $account): int {
    $id = $this->clinicId($account);
    if ($id === NULL) {
      throw ContentException::noClinic();
    }

    return $id;
  }

  /**
   * Whether this clinic's toggle hides the shared library.
   *
   * FALSE when the clinic entity is missing. Some accounts point at clinic ids
   * that no longer exist, and the legacy code would fatal on `$clinic->get()`;
   * failing open here keeps such an account on the same rows the toggle's default
   * (0) implies rather than turning a stale reference into a hard error.
   */
  public function hidesSharedResources(int $clinicId): bool {
    $clinic = $this->entityTypeManager->getStorage('clinic')->load($clinicId);
    if (!$clinic instanceof FieldableEntityInterface
      || !$clinic->hasField(self::FIELD_HIDE_SHARED_RESOURCES)) {
      return FALSE;
    }

    $first = $clinic->get(self::FIELD_HIDE_SHARED_RESOURCES)->first();

    return (bool) ($first->value ?? FALSE);
  }

  /**
   * Which rows of a bundle this caller may read.
   *
   * @param string $bundle
   *   Bundle machine name.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The caller.
   *
   * @return \Drupal\headless_content\ContentVisibility
   *   The decision, ready to hand to ContentService.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   When the account has no clinic.
   */
  public function visibility(string $bundle, AccountInterface $account): ContentVisibility {
    $clinicId = $this->requireClinicId($account);

    // Only resources widen. For recipes and training both legacy blocks filter on
    // the caller's clinic, so the toggle only chooses which block renders and the
    // rows are the same either way — see the class docblock.
    if (in_array($bundle, self::SHARED_DROPS_CLINIC_FILTER, TRUE)
      && $this->hidesSharedResources($clinicId) === FALSE) {
      return ContentVisibility::wholeLibrary($bundle, $clinicId);
    }

    return ContentVisibility::clinicScoped($bundle, $clinicId);
  }

  /**
   * Whether a caller may see this node, used on the single-item path.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to test.
   * @param \Drupal\headless_content\ContentVisibility $visibility
   *   The caller's decision, from {@see self::visibility()}.
   */
  public function canSee(NodeInterface $node, ContentVisibility $visibility): bool {
    if ($visibility->clinicOnly === FALSE) {
      return TRUE;
    }

    if (!$node->hasField(self::FIELD_PUBLISHED_TO)) {
      // Fail closed. All three bundles carry the field and every legacy display
      // required the relationship, so a node without it is a data problem, not a
      // licence to show content whose audience cannot be established.
      return FALSE;
    }

    return in_array($visibility->clinicId, $this->publishedClinicIds($node), TRUE);
  }

  /**
   * The clinic ids named by a node's audience field.
   *
   * This is how {@see self::canSee()} reads the audience, and it stays internal to
   * that decision. It is deliberately not serialised into the list response: the
   * audience field on this site is 456 clinics wide per recipe after the legacy bulk
   * import, so the array was nearly the whole payload and told the reader nothing they
   * did not already know from their own scope. See README §4.14.
   *
   * @return int[]
   *   Clinic ids, deduplicated. Empty when the field is absent or empty, which is
   *   what makes such a node invisible in clinic-scoped mode.
   */
  public function publishedClinicIds(NodeInterface $node): array {
    if (!$node->hasField(self::FIELD_PUBLISHED_TO)) {
      return [];
    }

    $ids = [];
    foreach ($node->get(self::FIELD_PUBLISHED_TO) as $item) {
      $target = $item->target_id ?? NULL;
      if ($target !== NULL && $target !== '') {
        $ids[] = (int) $target;
      }
    }

    return array_values(array_unique($ids));
  }

}
