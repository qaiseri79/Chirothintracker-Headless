<?php

namespace Drupal\headless_progress;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Generic flagging service for any entity/flag combination.
 *
 * Replaces duplicated flag logic across custom_module.
 */
class FlagService {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\flag\FlagServiceInterface $flag_service
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected FlagServiceInterface $flag_service,
  ) {}

  /**
   * Flags an entity for a given flagger.
   *
   * @param string $flag_id
   *   The machine name of the flag (e.g., 'reviewed_patients', 'follow_up').
   * @param string $entity_type
   *   The entity type ID (e.g., 'user', 'node', 'contact_message').
   * @param int $entity_id
   *   The entity ID to flag.
   * @param string $flagger_type
   *   The flagger entity type (usually 'user').
   * @param int $flagger_id
   *   The flagger entity ID (e.g., chiropractor UID).
   *
   * @return bool
   *   TRUE if flag was applied (or already existed), FALSE on failure.
   */
  public function flagEntity(
    string $flag_id,
    string $entity_type,
    int $entity_id,
    string $flagger_type = 'user',
    int $flagger_id = 0
  ): bool {
    // A flag needs somebody to own it. `$flagger_id` of 0 means the caller
    // could not resolve a flagger — an unassigned patient has no chiropractor
    // on `field_chiropractor`, for instance. Loading user 0 gives the anonymous
    // account, and `FlagServiceInterface::flag()` then throws a LogicException
    // ("Anonymous users must be identified by session_id") from
    // `FlagCountManager`. That exception propagates out of the submit path, so
    // a patient with no assigned chiropractor would get a 500 after their log
    // had already been saved. Returning FALSE here keeps the miss local: the
    // log is recorded and simply is not flagged for review yet.
    if ($flagger_id <= 0) {
      return FALSE;
    }

    $entity = $this->entity_type_manager->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      return FALSE;
    }

    $flagger = $this->entity_type_manager->getStorage($flagger_type)->load($flagger_id);
    if (!$flagger) {
      return FALSE;
    }

    $flag = $this->flag_service->getFlagById($flag_id);
    if (!$flag) {
      return FALSE;
    }

    // Check if already flagged
    $existing = $this->flag_service->getFlagging($flag, $entity, $flagger);
    if ($existing) {
      return TRUE;
    }

    $this->flag_service->flag($flag, $entity, $flagger);
    return TRUE;
  }

  /**
   * Unflags an entity.
   *
   * @param string $flag_id
   *   The machine name of the flag.
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID to unflag.
   * @param string $flagger_type
   *   The flagger entity type.
   * @param int $flagger_id
   *   The flagger entity ID.
   *
   * @return bool
   *   TRUE if unflagged, FALSE if not flagged or error.
   */
  public function unflagEntity(
    string $flag_id,
    string $entity_type,
    int $entity_id,
    string $flagger_type = 'user',
    int $flagger_id = 0
  ): bool {
    $entity = $this->entity_type_manager->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      return FALSE;
    }

    $flagger = $this->entity_type_manager->getStorage($flagger_type)->load($flagger_id);
    if (!$flagger) {
      return FALSE;
    }

    $flag = $this->flag_service->getFlagById($flag_id);
    if (!$flag) {
      return FALSE;
    }

    $existing = $this->flag_service->getFlagging($flag, $entity, $flagger);
    if (!$existing) {
      return FALSE;
    }

    $this->flag_service->unflag($flag, $entity, $flagger);
    return TRUE;
  }

  /**
   * Checks if an entity is flagged.
   *
   * @param string $flag_id
   * @param string $entity_type
   * @param int $entity_id
   * @param string $flagger_type
   * @param int $flagger_id
   * @return bool
   */
  public function isFlagged(
    string $flag_id,
    string $entity_type,
    int $entity_id,
    string $flagger_type = 'user',
    int $flagger_id = 0
  ): bool {
    $entity = $this->entity_type_manager->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      return FALSE;
    }

    $flagger = $this->entity_type_manager->getStorage($flagger_type)->load($flagger_id);
    if (!$flagger) {
      return FALSE;
    }

    $flag = $this->flag_service->getFlagById($flag_id);
    if (!$flag) {
      return FALSE;
    }

    return (bool) $this->flag_service->getFlagging($flag, $entity, $flagger);
  }
}