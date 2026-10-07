<?php

namespace Drupal\migrate_file_to_media\Plugin\Derivative;

use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\migrate_file_to_media\Plugin\migrate\destination\MediaEntityRevision;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deriver for media_entity_revision:ENTITY_TYPE entity migrations.
 */
class MediaMigrateEntityRevision implements ContainerDeriverInterface {

  /**
   * List of derivative definitions.
   *
   * @var array
   */
  protected array $derivatives = [];

  /**
   * The entity definitions.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface[]
   */
  protected array $entityDefinitions;

  /**
   * Constructs a MigrateEntity object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $entity_definitions
   *   A list of entity definition objects.
   */
  public function __construct(array $entity_definitions) {
    $this->entityDefinitions = $entity_definitions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')->getDefinitions()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinition($derivative_id, $base_plugin_definition) {
    if (!empty($this->derivatives) && !empty($this->derivatives[$derivative_id])) {
      return $this->derivatives[$derivative_id];
    }
    if ($base_plugin_definition instanceof PluginDefinitionInterface) {
      $base_plugin_definition = (array) $base_plugin_definition;
    }
    $this->getDerivativeDefinitions($base_plugin_definition);
    return $this->derivatives[$derivative_id];
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityDefinitions as $entity_type => $entity_info) {
      if ($entity_info->getKey('revision')) {
        $this->derivatives[$entity_type] = [
          'id' => "media_entity_revision:$entity_type",
          'class' => MediaEntityRevision::class,
          'requirements_met' => 1,
          'provider' => $entity_info->getProvider(),
        ];
      }
    }
    return $this->derivatives;
  }

}
