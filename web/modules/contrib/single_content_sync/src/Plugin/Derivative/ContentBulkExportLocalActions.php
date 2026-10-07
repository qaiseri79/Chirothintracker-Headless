<?php

namespace Drupal\single_content_sync\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\single_content_sync\Event\BulkExportRoutesEvent;
use Drupal\single_content_sync\Plugin\Menu\LocalAction\ContentBulkExportLocalAction;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Defines local actions for bulk content export on collection routes.
 */
class ContentBulkExportLocalActions extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * Constructs a ContentBulkExportLocalActions object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EventDispatcherInterface $event_dispatcher) {
    $this->entityTypeManager = $entity_type_manager;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [];
    $routes = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->hasLinkTemplate('single-content:export')
        && $entity_type->hasLinkTemplate('collection')) {
        $routes["entity.{$entity_type_id}.collection"] = [
          'entity_type_id' => $entity_type_id,
        ];
      }
    }

    if ($this->entityTypeManager->hasDefinition('node')
      && $this->entityTypeManager->getDefinition('node')->hasLinkTemplate('single-content:export')) {
      $routes['system.admin_content'] = [
        'entity_type_id' => 'node',
      ];
    }

    $event = new BulkExportRoutesEvent($routes);
    $this->eventDispatcher->dispatch($event, BulkExportRoutesEvent::EVENT_NAME);

    foreach ($event->getRoutes() as $route_name => $definition) {
      $entity_type_id = $definition['entity_type_id'] ?? NULL;
      $this->derivatives[str_replace('.', '_', $route_name)] = [
        'class' => ContentBulkExportLocalAction::class,
        'route_name' => 'single_content_sync.bulk_export',
        'title' => 'Export all',
        'weight' => 10,
        'appears_on' => [$route_name],
        'options' => [
          'query' => array_filter([
            'entity_type' => $entity_type_id,
            'bundle' => $definition['bundle'] ?? NULL,
          ]),
        ],
        'bundle_route_parameter' => $definition['bundle_route_parameter'] ?? NULL,
      ] + $base_plugin_definition;
    }

    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
