<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Available configuration keys.
 *
 * Migration: A single migration ID, or an array of migration IDs.
 *
 * @MigrateProcessPlugin(
 *   id = "check_duplicate"
 * )
 */
class CheckDuplicate extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The migration to be executed.
   *
   * @var \Drupal\migrate\Plugin\MigrationInterface
   */
  protected MigrationInterface $migration;

  /**
   * Database connection Variable.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $connection;

  /**
   * MediaEntityGenerator constructor.
   *
   * @param array $configuration
   *   Configuration values.
   * @param string $plugin_id
   *   Plugin IDs.
   * @param mixed $plugin_definition
   *   Definition of Plugins.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   Parameter for the interface of Migration.
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    Connection $connection,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->migration = $migration;
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
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if ($value) {
      $query = $this->connection->select('migrate_file_to_media_mapping', 'map');
      $query->fields('map');
      $query->condition('fid', $value, '=');
      $query->condition('target_fid', $value, '=');
      $result = $query->execute()?->fetchObject();

      if ($result && !empty($result->fid)) {
        return $result->fid;
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
