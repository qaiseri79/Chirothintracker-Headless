<?php

namespace Drupal\migrate_file_to_media\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Media migrate event subscriber.
 */
class MediaMigrateSubscriber implements EventSubscriberInterface {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $connection;

  /**
   * Constructs a new MediaMigrateSubscriber object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Kernel request event handler.
   *
   * Map fid and mid.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   Response event.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    $config = (array) $event->getMigration()->getPluginDefinition();

    // Skip if it's not the first step of the migration.
    if (empty($config['source']['toggle_media_mapping'])) {
      return;
    }

    $row = $event->getRow();
    $fid = $row->getSourceProperty('target_id');
    $dest_ids = $event->getDestinationIdValues();

    // Skip if there is no destination ID.
    if (empty($dest_ids)) {
      return;
    }

    $mid = reset($dest_ids);

    $this->connection->update('migrate_file_to_media_mapping')
      ->condition('target_fid', $fid)
      ->fields([
        'media_id' => $mid,
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::POST_ROW_SAVE => ['onPostRowSave'],
    ];
  }

}
