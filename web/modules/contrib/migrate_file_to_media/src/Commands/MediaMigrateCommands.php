<?php

namespace Drupal\migrate_file_to_media\Commands;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drush\Commands\DrushCommands;

/**
 * Drush 9 commands for migrate_file_to_media.
 */
class MediaMigrateCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * Entity Field Manager Interface.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private $entityFieldManager;

  /**
   * Entity Type Manager Interface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Database Connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $connection;

  /**
   * Migration plugin manager service.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The string_translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslation;

  /**
   * MediaMigrateCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity Field Manager Interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity Type Manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   Database Connection.
   * @param \Drupal\migrate\Plugin\MigrationPluginManager $migrationPluginManager
   *   Migration Plugin Manager.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrappers
   *   Stream Wrapper Manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   File system.
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   *   Translation string.
   */
  public function __construct(
    EntityFieldManagerInterface $entityFieldManager,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $connection,
    MigrationPluginManager $migrationPluginManager,
    StreamWrapperManagerInterface $stream_wrappers,
    FileSystemInterface $file_system,
    TranslationManager $string_translation,
  ) {
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeManager = $entity_type_manager;
    $this->connection = $connection;
    $this->migrationPluginManager = $migrationPluginManager;
    $this->streamWrapperManager = $stream_wrappers;
    $this->fileSystem = $file_system;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Create media destination fields.
   *
   * @param string $entity_type
   *   Entity Type.
   * @param string $bundle
   *   Bundle.
   * @param string $source_field_type
   *   Source Field Type.
   * @param string $target_media_bundle
   *   Target Media Bundle.
   *
   * @command migrate:file-media-fields
   * @aliases mf2m
   */
  public function migrateFileFields(string $entity_type, string $bundle, string $source_field_type, string $target_media_bundle): void {

    $this->output()
      ->writeln("Creating media reference fields for {$entity_type} : {$bundle}.");

    $bundle_fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    // Gather a list of all target fields.
    $map = $this->entityFieldManager->getFieldMapByFieldType($source_field_type);
    $source_fields = [];
    foreach ($map[$entity_type] as $name => $data) {
      foreach ($data['bundles'] as $bundle_name) {
        if ($bundle_name == $bundle) {
          $target_field_name = substr($name, 0, FieldStorageConfig::NAME_MAX_LENGTH - 6) . '_media';
          $source_fields[$target_field_name] = $bundle_fields[$name];
          $this->output()->writeln('Found field: ' . $name);
        }
      }
    }

    $map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');
    $media_fields = [];
    foreach ($map[$entity_type] as $name => $data) {
      foreach ($data['bundles'] as $bundle_name) {
        if ($bundle_name == $bundle) {
          $basefield = $bundle_fields[$name];
          $field_settings = $basefield->getSettings();
          $handler = $field_settings['handler'];
          if (!empty($field_settings['handler_settings']['target_bundles'])) {
            foreach ($field_settings['handler_settings']['target_bundles'] as $target_bundle) {
              if ($handler == 'default:media' && $target_bundle == $target_media_bundle) {
                // $media_fields[$name] = $field_settings;.
                $this->output()
                  ->writeln('Found existing media field: ' . $name);
              }
            }
          }
        }
      }
    }

    // Create missing fields.
    $missing_fields = array_diff_key($source_fields, $media_fields);

    foreach ($missing_fields as $new_field_name => $field) {
      try {
        $new_field = $this->createMediaField(
          $entity_type,
          $bundle,
          $field,
          $new_field_name,
          $target_media_bundle
        );
      }
      catch (\Exception $ex) {
        $this->output()
          ->writeln("Error while creating media field: {$new_field_name}.");
      }

      if (!empty($new_field)) {
        $this->output()
          ->writeln("Created media field: {$new_field->getName()}.");
      }
    }
    $this->output()->writeln("Clearing caches.");
    drupal_flush_all_caches();

  }

  /**
   * Create a new entity media reference field.
   *
   * @param string $entity_type
   *   Entity Type.
   * @param string $bundle
   *   Bundle.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $existing_field
   *   Existing Fields.
   * @param string $new_field_name
   *   New Field Type.
   * @param string $target_media_bundle
   *   Target Media Bundle.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   Return type should be FieldDefinitionInterface.
   */
  private function createMediaField(
    string $entity_type,
    string $bundle,
    FieldDefinitionInterface $existing_field,
    string $new_field_name,
    string $target_media_bundle,
  ): FieldDefinitionInterface {
    $field = FieldConfig::loadByName($entity_type, $bundle, $new_field_name);
    if (empty($field)) {

      // Load existing field storage.
      $field_storage = FieldStorageConfig::loadByName($entity_type, $new_field_name);

      // Create a field storage if none found.
      if (empty($field_storage)) {
        $field_storage = FieldStorageConfig::create(
          [
            'field_name' => $new_field_name,
            'entity_type' => $entity_type,
            'cardinality' => $existing_field->getFieldStorageDefinition()
              ->getCardinality(),
            'type' => 'entity_reference',
            'settings' => ['target_type' => 'media'],
          ]
        );
        $field_storage->save();
      }

      $field = $this->entityTypeManager->getStorage('field_config')->create([
        'field_storage' => $field_storage,
        'bundle' => $bundle,
        'label' => $existing_field->getLabel() . ' Media',
        'translatable' => $this->fieldIsTranslatable($existing_field),
        'settings' => [
          'handler' => 'default:media',
          'handler_settings' => ['target_bundles' => [$target_media_bundle => $target_media_bundle]],
        ],
      ]);
      $field->save();

      // Update Form Widget.
      $type = $entity_type . '.' . $bundle . '.default';
      /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $definition */
      $definition = $this->entityTypeManager->getStorage('entity_form_display')
        ->load($type);
      $definition->setComponent(
        $new_field_name,
        [
          'type' => 'entity_reference_autocomplete',
        ]
      )->save();

    }
    return $field;
  }

  /**
   * Check if field is translatable.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_info
   *   Field info.
   *
   * @return bool
   *   Content is translatable.
   */
  private function fieldIsTranslatable(FieldDefinitionInterface $field_info): bool {
    // If the field has image type + content translation is configured for this
    // field, then we also need to check if the 'file' property is translatable.
    $settings = NULL;
    if ($field_info instanceof ThirdPartySettingsInterface) {
      $settings = $field_info->getThirdPartySetting('content_translation', 'translation_sync');
    }
    return $field_info->isTranslatable() && (!$settings || !empty($settings['file']));
  }

  /**
   * Find duplicate file entities.
   *
   * @param string $migration_name
   *   Migration name.
   * @param array $options
   *   Array of options with bool values.
   *
   * @command migrate:duplicate-file-detection
   * @aliases migrate-duplicate
   *
   * @option check-existing-media Check for existing media
   * @option d7-mode TRUE if the source database is a Drupal 7
   */
  public function duplicateFileDetection(
    string $migration_name,
    array $options = ['check-existing-media' => FALSE, 'd7-mode' => FALSE],
  ): void {

    $manager = $this->migrationPluginManager;
    $plugins = $manager->createInstances([]);

    /** @var \Drupal\migrate\Plugin\Migration|null $migration_instance */
    $migration_instance = NULL;
    foreach ($plugins as $id => $migration) {
      if (in_array(mb_strtolower($id), [$migration_name])) {
        $migration_instance = $migration;
      }
    }

    if (!$migration_instance) {
      $this->output()->writeln($this->t("Could not find a migration instance for '@migration_name'.", ['@migration_name' => $migration_name]));
      return;
    }

    // Force update.
    $migration_instance->getIdMap()->prepareUpdate();

    // Use the migration source plugin to calculate the binary hash of
    // the related files only.
    $source = $migration_instance->getSourcePlugin();
    $source->rewind();

    while ($source->valid()) {
      $row = $source->current();

      // Support remote images.
      $file_path = $row->getSourceProperty('file_path');
      if ($file_path && !$this->isLocalUri($file_path)) {
        $file = $this->entityTypeManager->getStorage('file')->create([
          'fid' => $row->getSourceProperty('target_id'),
          'uri' => $file_path,
        ]);
      }
      else {
        /** @var \Drupal\file\Entity\File|null $file */
        $file = $this->entityTypeManager->getStorage('file')->load($row->getSourceProperty('target_id'));
      }

      if (!$file) {
        $source->next();
        $this->output()
          ->writeln($this->t("File not found: Skipped binary hash for source @target_id", ['@target_id' => $row->getSourceProperty('target_id')]));
        continue;
      }

      try {

        // Skip existing entries is command is run multiple times.
        $skip_processed = $this->connection->select('migrate_file_to_media_mapping', 'map');
        $skip_processed->fields('map');
        $skip_processed->condition('fid', $file->id(), '=');
        $skip_processed->condition('migration_id', $migration_instance->getPluginId(), '=');
        $skip_processed = $skip_processed->execute()?->fetchObject();

        if (!empty($skip_processed)) {
          $this->output()->writeln($this->t("File @file already processed.", ['@file' => $file->id()]));
          $source->next();
          continue;
        }

        if (!empty($binary_hash = $this->calculateBinaryHash($file))) {
          // Query for duplicates.
          $query = $this->connection->select('migrate_file_to_media_mapping', 'map');
          $query->fields('map');
          $query->condition('binary_hash', $binary_hash, '=');
          $result = $query->execute()?->fetchObject();

          $duplicate_fid = $file->id();
          if ($result) {
            if (!$options['d7-mode']) {
              $existing_file = $this->entityTypeManager->getStorage('file')->load($result->fid);
              $existing_file_id = $existing_file ? $existing_file->id() : FALSE;
            }
            else {
              $existing_file_id = $result->fid;
            }
            if ($existing_file_id) {
              $duplicate_fid = $existing_file_id;
              $this->output()
                ->writeln("Duplicate found for file {$existing_file_id}");
            }
          }

          $existing_media = NULL;

          // Check for existing media entities from previous migrations.
          if ($options['check-existing-media']) {
            // Check for an existing media entity.
            $query_media = $this->connection->select('migrate_file_to_media_mapping_media', 'media');
            $query_media->fields('media');
            $query_media->condition('binary_hash', $binary_hash, '=');
            $existing_media = $query_media->execute()?->fetchObject();
          }

          $this->connection->insert('migrate_file_to_media_mapping')
            ->fields([
              'type' => $row->getSourceProperty('file_type') ?? 'image',
              'migration_id' => $migration_instance->getPluginId(),
              'fid' => $file->id(),
              'target_fid' => $duplicate_fid,
              'binary_hash' => $binary_hash,
              'media_id' => $existing_media ? $existing_media->entity_id : NULL,
            ])
            ->execute();

          $this->output()
            ->writeln($this->t("Added binary hash @binary for file @file", [
              '@binary' => $binary_hash,
              '@file' => $file->id(),
            ]));
        }
        else {
          $this->output()
            ->writeln($this->t("File empty: Skipped binary hash for file @file", ['@file' => $file->id()]));
        }
      }
      catch (\Exception $ex) {
        $this->output()
          ->writeln($this->t("File not found: Skipped binary hash for file @file", ['@file' => $file->id()]));
      }

      $source->next();
    }
  }

  /**
   * Calculate hash values of media entities.
   *
   * Can later be used together with
   * migrate:duplicate-file-detection to find existing media files.
   *
   * @param string $bundle
   *   Optional media bundle, default = image.
   * @param string $field
   *   Optional media file field, default = field_media_image.
   * @param array $options
   *   Array of options with bool values.
   *
   * @option all Parse all existing media files or set to 0 to only process
   *   media items from previous imports.
   * @command migrate:duplicate-media-detection
   * @aliases migrate-duplicate-media
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function duplicateMediaImageDetection(string $bundle = 'image', string $field = 'field_media_image', array $options = ['all' => TRUE]): void {

    // Only query permanent files.
    $query = $this->connection->select('media', 'me');
    $query->fields('me', ['mid']);
    if (intval($options['all']) === 0) {
      $query->leftJoin('migrate_file_to_media_mapping_media', 'm', 'm.entity_id = me.mid');
    }
    $query->condition('bundle', $bundle);

    $mids = $query->execute()?->fetchAll();
    if ($mids === NULL) {
      $this->output()->writeln('No media found.');
      return;
    }

    $mids = array_map(
      function ($mid) {
        return $mid->mid;
      },
      $mids
    );

    $medias = $this->entityTypeManager->getStorage('media')->loadMultiple($mids);

    if (empty($medias)) {
      $this->output()->writeln("No media found.");
      return;
    }

    foreach ($medias as $media) {
      /** @var \Drupal\media\Entity\Media $media */
      try {
        /** @var \Drupal\file\Entity\File|null $file */
        $file = $media->$field->entity;

        if ($file && !empty($binary_hash = $this->calculateBinaryHash($file))) {

          $query = $this->connection->select('migrate_file_to_media_mapping_media', 'map');
          $query->fields('map');
          $query->condition('binary_hash', $binary_hash);
          $result = $query->execute()?->fetchObject();

          $duplicate_id = $media->id();
          if ($result) {
            $existing_media = $this->entityTypeManager->getStorage('media')->load($result->entity_id);
            if ($existing_media) {
              $duplicate_id = $existing_media->id();
              $this->output()
                ->writeln("Duplicate found for file {$existing_media->id()}");
            }
          }

          // Check if we have an existing mapping. Update matching record.
          $upsert_query = $this->connection->select('migrate_file_to_media_mapping_media', 'map');
          $upsert_query->fields('map');
          $upsert_query->condition('binary_hash', $binary_hash);
          $current_mapping = $upsert_query->execute()?->fetchObject();
          if ($current_mapping) {
            $this->connection->update('migrate_file_to_media_mapping_media')
              ->condition('id', $current_mapping->id)
              ->fields([
                'media_bundle' => $bundle,
                'fid' => $file->id(),
                'entity_id' => $media->id(),
                'target_entity_id' => $duplicate_id,
                'binary_hash' => $binary_hash,
              ])
              ->execute();

            $this->output()
              ->writeln("Updated binary hash {$binary_hash} for media {$media->id()}");
          }
          else {
            // Insert new mappings for existing media entities.
            $this->connection->insert('migrate_file_to_media_mapping_media')
              ->fields([
                'media_bundle' => $bundle,
                'fid' => $file->id(),
                'entity_id' => $media->id(),
                'target_entity_id' => $duplicate_id,
                'binary_hash' => $binary_hash,
              ])
              ->execute();

            $this->output()
              ->writeln("Added binary hash {$binary_hash} for media {$media->id()}");
          }
        }
        else {
          $this->output()
            ->writeln("Media empty: Skipped binary hash for media {$media->id()}");
        }
      }
      catch (\Exception $ex) {
        $this->output()
          ->writeln("Media not found: Skipped binary hash for media {$media->id()}. Exception: {$ex->getMessage()}");
      }
    }
  }

  /**
   * Private function to calculate binary hash.
   */
  private function calculateBinaryHash(File $file): ?string {
    $binary_hash = NULL;

    $uri = $file->getFileUri();
    if ($uri === NULL) {
      return $binary_hash;
    }

    // For rokka files, we can use the metadata table to get the correct
    // binary_hash value.
    if (strpos($uri, 'rokka://') === 0) {
      $query = $this->connection->select('rokka_metadata', 'rokka');
      $query->fields('rokka');
      $query->condition('uri', $file->getFileUri(), '=');
      if ($rokka_metadata = $query->execute()?->fetchObject()) {
        $binary_hash = $rokka_metadata->binary_hash;
      }
    }
    // For YouTube, Vimeo and Wistia we can use the file url to get the correct
    // binary_hash value.
    elseif (strpos($uri, 'youtube://') === 0 || strpos($uri, 'vimeo://') === 0 || strpos($uri, 'wistia://') === 0) {
      $binary_hash = sha1($uri);
    }
    elseif ($data = file_get_contents($uri)) {
      $binary_hash = sha1($data);
    }

    return $binary_hash;
  }

  /**
   * Determines if the given URI or path is considered local.
   *
   * A URI or path is considered local if it either has no scheme component,
   * or the scheme is implemented by a stream wrapper which extends
   * \Drupal\Core\StreamWrapper\LocalStream.
   *
   * @param string $uri
   *   The URI or path to test.
   *
   * @return bool
   *   Returns Boolean value.
   */
  private function isLocalUri(string $uri): bool {
    $scheme = $this->streamWrapperManager->getScheme($uri);

    // The vfs scheme is vfsStream, which is used in testing. vfsStream is a
    // simulated file system that exists only in memory, but should be treated
    // as a local resource.
    if ($scheme == 'vfs') {
      $scheme = FALSE;
    }
    return $scheme === FALSE || $this->streamWrapperManager->getViaScheme((string) $scheme) instanceof LocalStream;
  }

}
