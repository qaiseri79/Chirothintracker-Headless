<?php

// Throwaway: prove the intake-link routes registered and see what they carry.
use Drupal\Core\Routing\RouteProviderInterface;

$container = \Drupal::getContainer();
/** @var RouteProviderInterface $routes */
$routes = $container->get('router.route_provider')->getAllRoutes();

foreach ($routes as $name => $route) {
  if (!str_contains($name, 'intake_link') && !str_contains($name, 'intake.submit') && !str_contains($name, 'intake.invite')) {
    continue;
  }
  printf(
    "%s\n  path=%s methods=%s\n  controller=%s\n  access=%s\n",
    $name,
    $route->getPath(),
    implode(',', $route->getMethods()) ?: 'ANY',
    (string) $route->getDefault('_controller'),
    $route->getRequirement('_custom_access') ?: ($route->getRequirement('_permission') ?: $route->getRequirement('_access') ?: '(none)'),
  );
}