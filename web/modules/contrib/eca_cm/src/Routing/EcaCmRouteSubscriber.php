<?php

namespace Drupal\eca_cm\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Subscriber for ECA Core Modeler routes.
 */
class EcaCmRouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ParametersUiRouteSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $entityType = $this->entityTypeManager->getDefinition('eca');
    $route = new Route(
      "/admin/config/workflow/eca-cm",
      [
        '_title' => 'ECA',
        '_controller' => 'Drupal\eca_cm\Controller\CoreModeller::list',
      ],
      [
        '_permission' => $entityType->getAdminPermission(),
      ],
      ['_admin_route' => TRUE]
    );
    $collection->add("eca_cm.list", $route);
    if (!$collection->get('entity.eca.collection')) {
      $collection->add("entity.eca.collection", $route);
    }

    $route = new Route(
      "/admin/config/workflow/eca-cm/edit/{eca}",
      [
        '_title' => 'Edit ECA',
        '_controller' => 'Drupal\eca_cm\Controller\CoreModeller::edit',
      ],
      [
        '_permission' => $entityType->getAdminPermission(),
      ],
      [
        '_admin_route' => TRUE,
        'parameters' => [
          'eca' => [
            'type' => 'entity:eca',
          ],
        ],
      ]
    );
    $collection->add('entity.eca.classic_form', $route);
    if (!$collection->get('entity.eca.delete_form')) {
      $collection->add("entity.eca.edit_form", $route);
    }

    if (!$collection->get('entity.eca.delete_form')) {
      $route = new Route(
        "/admin/config/workflow/eca-cm/delete/{eca}",
        [
          '_title' => 'Delete ECA',
          '_entity_form' => 'eca.delete',
        ],
        [
          '_permission' => $entityType->getAdminPermission(),
        ],
        [
          '_admin_route' => TRUE,
          'parameters' => [
            'eca' => [
              'type' => 'entity:eca',
            ],
          ],
        ]
      );
      $collection->add("entity.eca.delete_form", $route);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', 0];
    return $events;
  }

}
