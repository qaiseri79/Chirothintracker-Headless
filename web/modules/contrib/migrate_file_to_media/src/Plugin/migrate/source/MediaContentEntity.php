<?php

namespace Drupal\migrate_file_to_media\Plugin\migrate\source;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\migrate_drupal\Plugin\migrate\source\ContentEntity;

/**
 * Source plugin to get content entities from the current version of Drupal.
 *
 * This plugin uses the Entity API to export entity data. If the source entity
 * type has custom field storage fields or computed fields, this class will need
 * to be extended and the new class will need to load/calculate the values for
 * those fields.
 *
 * Available configuration keys:
 * - entity_type: The entity type ID of the entities being exported. This is
 *   calculated dynamically by the deriver so it is only needed if the deriver
 *   is not utilized, i.e., a custom source plugin.
 * - bundle: (optional) If the entity type is bundleable, only return entities
 *   of this bundle.
 * - include_translations: (optional) Indicates if the entity translations
 *   should be included, defaults to TRUE.
 * - add_revision_id: (optional) Indicates if the revision key is added to the
 *   source IDs, defaults to TRUE.
 * - fields_not_empty: (optional) Filter only entities with not empty fields.
 *
 * Examples:
 *
 * This will return the default revision for all nodes, from every bundle and
 * every translation. The revision key is added to the source IDs.
 * @code
 * source:
 *   plugin: media_content_entity:node
 * @endcode
 *
 * This will return the default revision for all nodes, from every bundle and
 * every translation. The revision key is not added to the source IDs.
 * @code
 * source:
 *   plugin: media_content_entity:node
 *   add_revision_id: false
 * @endcode
 *
 * This will only return nodes of type 'article' in their default language.
 * @code
 * source:
 *   plugin: media_content_entity:node
 *   bundle: article
 *   include_translations: false
 * @endcode
 *
 * This will only return nodes that has some values in the fields: 'field_image'
 * and 'field_text'.
 * @code
 * source:
 *   plugin: media_content_entity:node
 *   bundle: article
 *   fields_not_empty:
 *     - field_image
 *     - field_text
 * @endcode
 *
 * This will only return nodes that has some values in the field 'field_image'.
 * @code
 * source:
 *   plugin: media_content_entity:node
 *   bundle: article
 *   fields_not_empty: field_image
 * @endcode
 *
 * For additional configuration keys, refer to the parent class:
 * @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase
 *
 * @MigrateSource(
 *   id = "media_content_entity",
 *   source_module = "migrate_drupal",
 *   deriver = "\Drupal\migrate_drupal\Plugin\migrate\source\ContentEntityDeriver",
 * )
 */
class MediaContentEntity extends ContentEntity {

  /**
   * The plugin's default configuration.
   *
   * @var array
   */
  protected $defaultConfiguration = [
    'bundle' => NULL,
    'include_revisions' => FALSE,
    'include_translations' => TRUE,
    'add_revision_id' => TRUE,
    'fields_not_empty' => [],
    'batch_size' => 100,
  ];

  /**
   * Loads and yields entities, one at a time.
   *
   * @param array $ids
   *   The entity IDs.
   *
   * @return \Generator
   *   An iterable of the loaded entities.
   */
  protected function yieldEntities(array $ids) {
    $storage = $this->entityTypeManager
      ->getStorage($this->entityType->id());
    foreach ($ids as $revision_id => $id) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->configuration['include_revisions'] && $storage instanceof RevisionableStorageInterface
        ? $storage->loadRevision($revision_id)
        : $storage->load($id);
      if ($this->entityContainsRequiredFields($entity)) {
        yield $this->toArray($entity);
      }
      if ($this->configuration['include_translations']) {
        foreach ($entity->getTranslationLanguages(FALSE) as $language) {
          $translation = $entity->getTranslation($language->getId());
          if ($this->entityContainsRequiredFields($translation)) {
            yield $this->toArray($translation);
          }
        }
      }
    }
  }

  /**
   * Query to retrieve the entities.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query.
   */
  public function query() {
    $query = parent::query();
    // Include all revisions if we need.
    if ($this->configuration['include_revisions']) {
      $query->allRevisions();
    }
    // Filter only entities with not empty fields.
    $fields = (array) ($this->configuration['fields_not_empty'] ?? []);
    $or_group = $query->orConditionGroup();
    foreach ($fields as $field) {
      $or_group->condition("{$field}.target_id", 0, '>');
    }
    $query->condition($or_group);
    return $query;
  }

  /**
   * Determine whether the entity must be added to the row.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The specified entity or entity revision.
   *
   * @return bool
   *   Entity contains non empty field or no required fields to check.
   */
  protected function entityContainsRequiredFields(ContentEntityInterface $entity): bool {
    $fields = (array) $this->configuration['fields_not_empty'];
    if (empty($fields)) {
      // Nothing to check.
      return TRUE;
    }
    // At least one field must be non empty.
    $contains = FALSE;
    foreach ($fields as $field) {
      $contains |= $entity->hasField($field) && !$entity->get($field)->isEmpty();
    }
    return (bool) $contains;
  }

}
