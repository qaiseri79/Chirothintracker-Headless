<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_content\Exception\ContentException;
// Drupal\node\NodeInterface, not Drupal\Core\Entity\NodeInterface — see the note on
// the import in ContentService.php.
use Drupal\node\NodeInterface;

/**
 * Reads and writes the `favorites` flag on content nodes.
 *
 * The flag already exists in the site: `flag.flag.favorites.yml` is an
 * `entity:node` flag with `global: false`, on the `recipe` bundle, and only
 * `enrolled_patient` holds `flag favorites` / `unflag favorites`. The recipes
 * View wires it up with a `flag_relationship` on the flag `favorites` and
 * `user_scope: current`.
 *
 * So this class adds no new permission model. It asks the flag whether the caller
 * may act (`actionAccess()`, which is the permission check the flag module itself
 * uses) and then flips it. A role without `flag favorites` gets a 403, which is
 * the same answer the legacy ajax link would have given.
 *
 * Reads are batched per request: `getFlagUserFlaggings()` is one query for every
 * favourite the caller has on this flag, rather than one query per recipe in a
 * 200-row list. Same lesson as `ProgressService::termLabels()`.
 */
class ContentFavorites {

  /**
   * The flag machine name.
   */
  public const FLAG_ID = 'favorites';

  /**
   * The flag entity, resolved once per request.
   */
  private ?FlagInterface $flag = NULL;

  public function __construct(
    private readonly FlagServiceInterface $flagService,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * The flag, or NULL when the module's dependency is somehow absent.
   */
  public function flag(): ?FlagInterface {
    if ($this->flag === NULL) {
      $this->flag = $this->flagService->getFlagById(self::FLAG_ID);
    }

    return $this->flag;
  }

  /**
   * Whether this flag applies to a bundle at all.
   *
   * Only `recipe` today. Checked before any access test so a POST against a
   * training node fails as "this cannot be favourited" rather than as a 403 the
   * caller cannot act on.
   */
  public function appliesTo(string $bundle): bool {
    $flag = $this->flag();
    if ($flag === NULL) {
      return FALSE;
    }

    $bundles = $flag->getBundles();

    return $bundles === [] || in_array($bundle, $bundles, TRUE);
  }

  /**
   * Whether the caller may flag or unflag this node.
   *
   * Both actions at once: a caller allowed to flag is allowed to unflag, and a
   * caller allowed to neither is refused either way. Asking per action costs two
   * checks and tells the frontend nothing it can use.
   */
  public function canFlag(NodeInterface $node): bool {
    $flag = $this->flag();
    if ($flag === NULL || !$this->appliesTo($node->bundle())) {
      return FALSE;
    }

    $account = $this->currentUser;
    if (!$account->isAuthenticated()) {
      return FALSE;
    }

    return $flag->actionAccess('flag', $account, $node)->isAllowed()
      || $flag->actionAccess('unflag', $account, $node)->isAllowed();
  }

  /**
   * The caller's favourites among these node ids.
   *
   * @param array<int|string> $nodeIds
   *   Node ids from the current page.
   *
   * @return array<int, bool>
   *   Node id => TRUE, for the ones the caller has flagged. Node ids not in the
   *   input are absent.
   */
  public function favouriteIds(array $nodeIds): array {
    $flag = $this->flag();
    if ($flag === NULL || $nodeIds === [] || !$this->currentUser->isAuthenticated()) {
      return [];
    }

    $wanted = array_map('intval', $nodeIds);

    $flagged = [];
    foreach ($this->flagService->getFlagUserFlaggings($flag, $this->currentUser) as $flagging) {
      $entityId = (int) ($flagging->get('entity_id')->value ?? 0);
      if ($entityId > 0) {
        $flagged[$entityId] = TRUE;
      }
    }

    // Intersect rather than returning everything: the caller may have favourites
    // from other pages, and the caller of this method only asked about these rows.
    return array_intersect_key($flagged, array_flip($wanted));
  }

  /**
   * Whether the caller has flagged this node.
   */
  public function isFavourite(NodeInterface $node): bool {
    return ($this->favouriteIds([$node->id()])[$node->id()] ?? FALSE) === TRUE;
  }

  /**
   * Flags or unflags a node and returns the resulting state.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node. Visibility must already have been checked by the caller.
   * @param bool|null $wanted
   *   TRUE to flag, FALSE to unflag, NULL to flip whatever it is now. The flip is
   *   the default because that is what a star button sends; passing the intended
   *   state instead makes the call idempotent, which is what a client retrying
   *   wants.
   *
   * @return bool
   *   TRUE if the node is favourited afterwards.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   When the flag does not apply to the bundle, or the caller may not act.
   */
  public function set(NodeInterface $node, ?bool $wanted = NULL): bool {
    $flag = $this->flag();

    if ($flag === NULL || !$this->appliesTo($node->bundle())) {
      throw ContentException::notFavouriteable($node->bundle());
    }

    if (!$this->canFlag($node)) {
      throw ContentException::favouriteNotAllowed();
    }

    $current = $this->isFavourite($node);
    $target = $wanted ?? !$current;

    // Already in the requested state. Not an error: a retried POST should not
    // return 409, and the flag module throws a LogicException here that says so.
    if ($current === $target) {
      return $current;
    }

    if ($target) {
      $this->flagService->flag($flag, $node, $this->currentUser);
    }
    else {
      $this->flagService->unflag($flag, $node, $this->currentUser);
    }

    return $target;
  }

}
