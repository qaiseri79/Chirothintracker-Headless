<?php

namespace Drupal\single_content_sync\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Allows modules to alter routes that display the bulk export local action.
 */
class BulkExportRoutesEvent extends Event {

  /**
   * The event name.
   */
  public const EVENT_NAME = 'single_content_sync.bulk_export_routes';

  /**
   * The route definitions keyed by route name.
   *
   * @var array
   */
  protected array $routes;

  /**
   * Constructs a new BulkExportRoutesEvent object.
   *
   * @param array $routes
   *   The route definitions keyed by route name. Each item may contain:
   *   - entity_type_id: The entity type to preselect on the export form.
   *   - bundle: The bundle to preselect on the export form.
   *   - bundle_route_parameter: The route parameter to use as the bundle.
   */
  public function __construct(array $routes) {
    $this->routes = $routes;
  }

  /**
   * Gets the routes.
   *
   * @return array
   *   The route definitions keyed by route name.
   */
  public function getRoutes(): array {
    return $this->routes;
  }

  /**
   * Sets the routes.
   *
   * @param array $routes
   *   The route definitions keyed by route name.
   */
  public function setRoutes(array $routes): void {
    $this->routes = $routes;
  }

  /**
   * Adds a route.
   *
   * @param string $route_name
   *   The route name where the local action should appear.
   * @param string|null $entity_type_id
   *   The entity type ID to preselect on the export form.
   * @param string|null $bundle
   *   The bundle to preselect on the export form.
   * @param string|null $bundle_route_parameter
   *   The route parameter to use as the bundle.
   */
  public function addRoute(string $route_name, ?string $entity_type_id = NULL, ?string $bundle = NULL, ?string $bundle_route_parameter = NULL): void {
    $this->routes[$route_name] = array_filter([
      'entity_type_id' => $entity_type_id,
      'bundle' => $bundle,
      'bundle_route_parameter' => $bundle_route_parameter,
    ]);
  }

  /**
   * Removes a route.
   *
   * @param string $route_name
   *   The route name where the local action should not appear.
   */
  public function removeRoute(string $route_name): void {
    unset($this->routes[$route_name]);
  }

}
