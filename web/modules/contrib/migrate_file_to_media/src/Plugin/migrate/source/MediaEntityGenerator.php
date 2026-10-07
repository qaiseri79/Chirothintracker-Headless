<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\source;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns bare-bones information about every available file entity.
 *
 * @MigrateSource(
 *   id = "media_entity_generator",
 *   source_module = "file",
 * )
 */
class MediaEntityGenerator extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * An array contains Source fields.
   *
   * @var array
   */
  protected array $sourceFields = [];

  /**
   * The default langcode.
   *
   * @var string
   */
  protected string $sourceLangcode = '';

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The Entity Field Manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private EntityFieldManagerInterface $entityFieldManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private LanguageManagerInterface $languageManager;

  /**
   * The current active database's master connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * The array of field storage definitions for the entity type.
   *
   * @var \Drupal\Core\Field\FieldStorageDefinitionInterface[]
   */
  private array $fieldStorageDefinitions;

  /**
   * The array of field storage definitions for the entity type.
   *
   * @var \Drupal\Core\Entity\ContentEntityTypeInterface
   */
  private ContentEntityTypeInterface $entityDefinition;

  /**
   * Table mapping.
   *
   * @var \Drupal\Core\Entity\Sql\DefaultTableMapping
   */
  private DefaultTableMapping $tableMapping;

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The Entity Field Manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The current active database's master connection.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\migrate\MigrateException
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    LanguageManagerInterface $languageManager,
    Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->languageManager = $languageManager;
    $this->database = $database;

    // Validate configuration keys.
    $mandatory_keys = [
      'toggle_media_mapping',
      'entity_type',
      'bundle',
      'field_names',
      'source_langcode',
    ];
    foreach ($mandatory_keys as $config_key) {
      if (empty($this->configuration[$config_key])) {
        throw new \InvalidArgumentException("'$config_key' configuration key should not be an empty.");
      }
    }
    if (!is_array($this->configuration['field_names'])) {
      throw new \InvalidArgumentException("'field_names' configuration key should be an array.");
    }

    // Prepare required properties.
    foreach ($this->configuration['field_names'] as $name) {
      $this->sourceFields[$name] = $name;
    }

    $entityType = $this->configuration['entity_type'];
    $this->sourceLangcode = $this->configuration['source_langcode'];
    $this->fieldStorageDefinitions = $this->entityFieldManager->getFieldStorageDefinitions($entityType);
    if ($diff = array_diff($this->configuration['field_names'], array_keys($this->fieldStorageDefinitions))) {
      throw new \InvalidArgumentException("'field_names' contains undefined names of the fields: " . implode(', ', $diff));
    }

    $definition = $this->entityTypeManager->getDefinition($entityType);
    if (!($definition instanceof ContentEntityTypeInterface)) {
      throw new \InvalidArgumentException("Unsupported entity type '{$entityType}'.");
    }
    $this->entityDefinition = $definition;
    $this->tableMapping = new DefaultTableMapping($this->entityDefinition, $this->fieldStorageDefinitions);
    $this->tableMapping = $this->tableMapping::create($this->entityDefinition, $this->fieldStorageDefinitions);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ?MigrationInterface $migration = NULL,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('language_manager'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row): bool {
    // Set source file.
    if (!empty($row->getSource()['target_id'])) {
      /** @var \Drupal\file\Entity\File|null $file */
      $file = $this->entityTypeManager->getStorage('file')->load($row->getSource()['target_id']);
      if ($file) {
        $row->setSourceProperty('file_path', $file->getFileUri());
        $row->setSourceProperty('file_name', $file->getFilename());
        $row->setSourceProperty('uid', $file->getOwnerId());
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'target_id' => $this->t('The file entity ID.'),
      'file_id' => $this->t('The file entity ID.'),
      'file_path' => $this->t('The file path.'),
      'file_name' => $this->t('The file name.'),
      'file_alt' => $this->t('The file arl.'),
      'file_title' => $this->t('The file title.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'target_id' => [
        'type' => 'integer',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    // If the langcode is NULL, it means that we are migrating the general
    // files with some default langcode, so we have to check all
    // translations of the entity. Default langcode is a source one (if exists).
    // In other case we migrate the certain language.
    if (is_null($this->configuration['langcode'])) {
      $langcodes = array_keys($this->languageManager->getLanguages());
      // Default language must be the last one.
      usort($langcodes, [$this, 'sortLangcodesCallback']);
    }
    else {
      $langcodes = [$this->configuration['langcode']];
    }

    // Retrieve the other required values.
    $entity_type = $this->configuration['entity_type'];
    $bundle = $this->configuration['bundle'];
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    $files_found = [];

    foreach ($this->sourceFields as $name => $source_field) {
      // The field definitions for the current entity type + bundle must exist.
      if (is_null($fieldDefinitions[$name])) {
        throw new RequirementsException("Wrong source configs: the field '$name' doesn't exist for $entity_type:$bundle");
      }

      if ($fieldDefinitions[$name]->getType() === 'image') {
        // If default image is set, we have to add it to migration.
        $default_image = $fieldDefinitions[$name]->getSetting('default_image');
        if (isset($default_image['uuid'])) {
          if ($image = $this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $default_image['uuid']])) {
            $data = [
              'nid' => NULL,
              'target_id' => array_key_first($image),
              'alt' => $default_image['alt'] ?? NULL,
              'title' => $default_image['title'] ?? NULL,
              'display' => NULL,
              'description' => NULL,
              'langcode' => $this->configuration['langcode'] ?? $this->sourceLangcode,
              'entity' => NULL,
            ];
            // Use hash as key to exclude data duplications.
            $files_found[$this->getDataHash($data)] = $data;
          }
        }
      }

      $query = $this->getQuery($bundle, $name);
      $results = $query->execute()?->fetchCol();

      if ($results) {

        $storage = $this->entityTypeManager->getStorage($this->configuration['entity_type']);
        $entities = $storage instanceof RevisionableStorageInterface
          ? $storage->loadMultipleRevisions($results)
          : $storage->loadMultiple($results);

        foreach ($entities as $entity) {
          foreach ($langcodes as $langcode) {
            if ($entity->hasTranslation($langcode)) {
              $entity = $entity->getTranslation($langcode);
            }
            else {
              // Skip if translation doesn't exists.
              continue;
            }

            foreach ($entity->{$name}->getValue() as $reference) {
              $data = [
                'nid' => $entity->id(),
                'target_id' => $reference['target_id'],
                'alt' => $reference['alt'] ?? NULL,
                'title' => $reference['title'] ?? NULL,
                'display' => $reference['display'] ?? NULL,
                'description' => $reference['description'] ?? NULL,
                'langcode' => $this->configuration['langcode'] ?? $this->sourceLangcode,
                'entity' => $entity,
              ];

              // Use hash as key to exclude data duplications.
              $files_found[$this->getDataHash($data)] = $data;

            }
          }
        }
      }
    }
    return new \ArrayIterator($files_found);
  }

  /**
   * Prepare query of the source plugin.
   *
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   The name of the field.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   An appropriate SelectQuery object for this database connection. Note that
   *   it may be a driver-specific subclass of SelectQuery, depending on the
   *   driver.
   *
   * @throws \Drupal\Core\Entity\Sql\SqlContentEntityStorageException
   */
  protected function getQuery(string $bundle, string $field_name): SelectInterface {
    // Get field storage definition.
    $field_storage_definition = $this->fieldStorageDefinitions[$field_name];

    // Get the field tables in order:
    // - base table;
    // - revision table.
    $table_names = $this->tableMapping->getAllFieldTableNames($field_name);
    if (empty($table_names)) {
      throw new RequirementsException("The field $field_name is not mapped to any table.");
    }
    $table_name = !empty($this->configuration['include_revisions']) && $this->entityDefinition->isRevisionable()
      // The last table must contains revisions.
      ? end($table_names)
      // The first table is a base one.
      : reset($table_names);
    $target_id_column = $this->tableMapping->getFieldColumnName($field_storage_definition, 'target_id');

    $query = $this->database->select($table_name, 'ft');
    $query->addExpression('MAX(revision_id)', 'revision_id');
    $query->condition('deleted', 0);
    $query->condition('bundle', $bundle);
    $query->condition($target_id_column, 0, '>');
    if (!is_null($this->configuration['langcode'])) {
      $query->condition('langcode', $this->configuration['langcode']);
    }
    $query->groupBy($target_id_column);
    $query->orderBy('revision_id', 'DESC');
    return $query;
  }

  /**
   * Prepare a unique hash from the file data.
   *
   * @param array $data
   *   Prepared file data.
   *
   * @return string
   *   Hash.
   *
   * @see initializeIterator()
   */
  protected function getDataHash(array $data): string {
    return md5(serialize(array_filter($data, function ($k) {
      return in_array($k, ['target_id', 'langcode']);
    }, ARRAY_FILTER_USE_KEY)));
  }

  /**
   * Comparison function for usort on langcodes.
   *
   * The default source langcode must be the last one to set the correct file
   * properties when the translation with source langcode is exists.
   *
   * @param string $a
   *   The first langcode.
   * @param string $b
   *   The second langcode.
   *
   * @return int
   *   -1 or 1 if the first langcode should, respectively, come before or after
   *   the second; 0 if no one value is the default langcode.
   */
  protected function sortLangcodesCallback($a, $b) {
    $result = 0;
    if ($a === $this->sourceLangcode) {
      $result = 1;
    }
    if ($b === $this->sourceLangcode) {
      $result = -1;
    }
    return $result;
  }

}
