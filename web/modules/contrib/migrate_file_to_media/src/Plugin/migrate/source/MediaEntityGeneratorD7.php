<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\source;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * Returns bare-bones information about every available file entity.
 *
 * @MigrateSource(
 *   id = "media_entity_generator_d7",
 *   source_module = "file",
 * )
 */
class MediaEntityGeneratorD7 extends Node implements ContainerFactoryPluginInterface {

  /**
   * The default langcode.
   *
   * @var string|null
   */
  protected ?string $sourceLangcode = NULL;

  /**
   * An array contains Source fields.
   *
   * @var array
   */
  protected array $sourceFields = [];

  /**
   * The join options between the node and the node_revisions table.
   */
  const JOIN = 'n.vid = nr.vid';

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
   * @param \Drupal\Core\State\StateInterface $state
   *   State Management.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Parameter of Entity Type Manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module Handler.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_type_manager, $module_handler);

    // Do not joint source tables.
    $this->configuration['ignore_map'] = TRUE;

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

    foreach ($this->configuration['field_names'] as $name) {
      $this->sourceFields[$name] = $name;
    }

    $this->sourceLangcode = $this->configuration['source_langcode'] ?? NULL;

  }

  /**
   * {@inheritDoc}
   */
  public function count($refresh = FALSE): int {
    return $this->initializeIterator()->count();
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
      'file_mime' => $this->t('The file mime type'),
      'file_type' => $this->t('The file type'),
    ];
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
  public function query() {
    // Select node in its last revision.
    $query = $this->select('node_revision', 'nr')
      ->fields(
        'n',
        [
          'nid',
          'type',
          'language',
          'status',
          'created',
          'changed',
          'comment',
          'promote',
          'sticky',
          'tnid',
          'translate',
        ]
      )
      ->fields(
        'nr',
        [
          'vid',
          'title',
          'log',
          'timestamp',
        ]
      );
    $query->addField('n', 'uid', 'node_uid');
    $query->addField('nr', 'uid', 'revision_uid');
    $query->innerJoin('node', 'n', static::JOIN);

    // If the content_translation module is enabled, get the source langcode
    // to fill the content_translation_source field.
    if ($this->moduleHandler->moduleExists('content_translation')) {
      $query->leftJoin('node', 'nt', 'n.tnid = nt.nid');
      $query->addField('nt', 'language', 'source_langcode');
    }

    if (!empty($this->configuration['langcode'])) {
      $this->handleTranslations($query);
    }

    if (isset($this->configuration['bundle'])) {
      $query->condition('n.type', $this->configuration['bundle'], is_array($this->configuration['bundle']) ? 'IN' : '=');
    }

    // Process more fresh data first.
    $query->orderBy('nr.vid', 'DESC');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \ArrayIterator {

    $query_files = $this->select('file_managed', 'f')
      ->fields('f')
      ->condition('uri', 'temporary://%', 'NOT LIKE')
      ->orderBy('f.timestamp');

    $all_files = $query_files->execute()->fetchAllAssoc('fid');

    $files_found = [];

    foreach ($this->sourceFields as $name => $source_field) {

      $parent_iterator = parent::initializeIterator();

      foreach ($parent_iterator as $entity) {
        $nid = $entity['nid'];
        $vid = $entity['vid'];
        $langcode = $this->configuration['langcode'] ?? NULL;
        $field_value = $this->getFieldValues('node', $name, $nid, $vid, $langcode);

        foreach ($field_value as $reference) {

          if (!empty($all_files[$reference['fid']]['uri'])) {

            // Support remote file urls.
            $file_url = $all_files[$reference['fid']]['uri'];
            if (!empty($this->configuration['d7_file_url'])) {
              $file_url = str_replace('public://', '', $file_url);
              $file_path = UrlHelper::encodePath($file_url);
              $file_url = $this->configuration['d7_file_url'] . $file_path;
            }

            // Make sure the file name is correct based on the file url.
            $file_name = $all_files[$reference['fid']]['filename'];
            $file_url_pieces = explode('/', $file_url);
            if ($file_name !== end($file_url_pieces)) {
              $file_name = end($file_url_pieces);
            }

            if (!isset($files_found[$reference['fid']])) {
              $files_found[$reference['fid']] = [
                'nid' => $entity['nid'],
                'vid' => $entity['vid'],
                'target_id' => $reference['fid'],
                'alt' => $reference['alt'] ?? NULL,
                'title' => $reference['title'] ?? NULL,
                'display' => $reference['display'] ?? NULL,
                'description' => $reference['description'] ?? NULL,
                'langcode' => is_null($langcode) || $langcode === 'und' ? $this->sourceLangcode : $langcode,
                'entity' => $entity,
                'file_name' => $file_name,
                'file_path' => $file_url,
                'file_mime' => $all_files[$reference['fid']]['filemime'],
                'file_type' => $all_files[$reference['fid']]['type'] ?? NULL,
              ];
            }
          }
        }
      }
    }
    return new \ArrayIterator($files_found);
  }

}
