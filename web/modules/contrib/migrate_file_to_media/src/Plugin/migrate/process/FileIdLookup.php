<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\MigrateStubInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\process\MigrationLookup;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field ID Lookup for migration.
 *
 * @MigrateProcessPlugin(
 *   id = "file_id_lookup"
 * )
 */
class FileIdLookup extends MigrationLookup {

  /**
   * Database connection Variable.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $connection;

  /**
   * Constructs a MigrationLookup object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The Migration the plugin is being used in.
   * @param \Drupal\migrate\MigrateLookupInterface $migrate_lookup
   *   The migrate lookup service.
   * @param \Drupal\migrate\MigrateStubInterface $migrate_stub
   *   The migrate stub service.
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, MigrateLookupInterface $migrate_lookup, MigrateStubInterface $migrate_stub, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $migrate_lookup, $migrate_stub);
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('migrate.lookup'),
      $container->get('migrate.stub'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $fid = NULL;
    if (!empty($value)) {
      if (is_array($value)) {
        $fid = !empty($value['target_id']) ? $value['target_id'] : $value['fid'];
      }
      else {
        $fid = $value;
      }
    }

    if ($fid) {
      $query = $this->connection->select('migrate_file_to_media_mapping', 'map');
      $query->fields('map');
      $query->condition('fid', $fid, '=');
      $result = $query->execute()?->fetchObject();

      if ($result) {
        // If the record has an existing media entity, return it.
        if (!empty($result->media_id)) {
          return $result->media_id;
        }

        return parent::transform($result->target_fid, $migrate_executable, $row, $destination_property);
      }
    }

    if (isset($this->configuration['skip_method']) && $this->configuration['skip_method'] == 'process') {
      return FALSE;
    }
    else {
      throw new MigrateSkipRowException();
    }
  }

}
