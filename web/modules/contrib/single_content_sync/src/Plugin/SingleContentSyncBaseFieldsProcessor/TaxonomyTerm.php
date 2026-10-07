<?php

namespace Drupal\single_content_sync\Plugin\SingleContentSyncBaseFieldsProcessor;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\single_content_sync\ContentExporterInterface;
use Drupal\single_content_sync\SingleContentSyncBaseFieldsProcessorPluginBase;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation for taxonomy_term base fields processor plugin.
 *
 * @SingleContentSyncBaseFieldsProcessor(
 *   id = "taxonomy_term",
 *   label = @Translation("Taxonomy Term base fields processor"),
 *   entity_type = "taxonomy_term",
 * )
 */
class TaxonomyTerm extends SingleContentSyncBaseFieldsProcessorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The content exporter service.
   *
   * @var \Drupal\single_content_sync\ContentExporterInterface
   */
  protected ContentExporterInterface $exporter;

  /**
   * Constructs new TaxonomyTerm plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\single_content_sync\ContentExporterInterface $exporter
   *   The content exporter service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ContentExporterInterface $exporter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->exporter = $exporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('single_content_sync.exporter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function exportBaseValues(FieldableEntityInterface $entity): array {
    $parent = 0;

    if ($entity->get('parent')->entity instanceof TermInterface) {
      /** @var \Drupal\taxonomy\TermInterface $parent_entity */
      $parent_entity = $entity->get('parent')->entity;

      if ($entity->isSyncing() || $this->exporter->isReferenceCached($parent_entity)) {
        $parent_entity->setSyncing(TRUE);
        $parent = [
          'uuid' => $parent_entity->uuid(),
          'entity_type' => $parent_entity->getEntityTypeId(),
          'base_fields' => $this->exporter->exportBaseValues($parent_entity),
          'bundle' => $parent_entity->bundle(),
        ];
      }
      else {
        $parent = $this->exporter->doExportToArray($parent_entity);
      }
    }

    return [
      'name' => $entity->getName(),
      'weight' => $entity->getWeight(),
      'langcode' => $entity->language()->getId(),
      'description' => $entity->getDescription(),
      'parent' => $parent,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function mapBaseFieldsValues(array $values, FieldableEntityInterface $entity): array {
    return [
      'name' => $values['name'],
      'weight' => $values['weight'],
      'langcode' => $values['langcode'],
      'description' => $values['description'],
    ];
  }

}
