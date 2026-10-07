<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\destination;

use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\migrate\destination\EntityRevision;
use Drupal\migrate\Row;

/**
 * Provides entity revision destination plugin.
 *
 * See parent class for details of usage.
 * The plugin was provided to implement specific behavior to get an entity.
 *
 * @MigrateDestination(
 *   id = "media_entity_revision",
 *   deriver = "Drupal\migrate_file_to_media\Plugin\Derivative\MediaMigrateEntityRevision"
 * )
 */
class MediaEntityRevision extends EntityRevision {

  /**
   * The entity storage.
   *
   * @var \Drupal\Core\Entity\RevisionableStorageInterface
   */
  protected $storage;

  /**
   * Gets the entity.
   *
   * Override the default plugin.
   * Each revision must exist and it must handle any revision:
   * the default one, not default.
   *
   * @param \Drupal\migrate\Row $row
   *   The row object.
   * @param array $old_destination_id_values
   *   The old destination IDs.
   *
   * @return \Drupal\Core\Entity\EntityInterface|false
   *   The entity or false if it can not be created.
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $revision_id = $old_destination_id_values ?
      reset($old_destination_id_values) :
      $row->getDestinationProperty((string) $this->getKey('revision'));
    if (!empty($revision_id) && ($entity = $this->storage->loadRevision($revision_id))) {
      $entity->setNewRevision(FALSE);
      // Set Syncing because, as it may override the 'New Revision' value.
      if ($entity instanceof SynchronizableInterface) {
        $entity->setSyncing(TRUE);
      }
    }
    else {
      throw new MigrateSkipRowException('The ' . $this->storage->getEntityTypeId() . 'revisions does not exist: ' . $revision_id);
    }
    // We need to update the entity, so that the destination row IDs are
    // correct.
    return $this->updateEntity($entity, $row);
  }

}
