<?php

/**
 * Probe: locate whatever serves /history and describe it.
 *
 * Run: drush php:script /app/tools/probe_history_view.php
 */

use Drupal\Core\Routing\RouteProviderInterface;

$out = function (string $line): void {
  print $line . PHP_EOL;
};

$out('=== views module status ===');
$out('views installed: ' . (\Drupal::moduleHandler()->moduleExists('views') ? 'yes' : 'no'));
$out('views_ui: ' . (\Drupal::moduleHandler()->moduleExists('views_ui') ? 'yes' : 'no'));
$out('views_data: ' . (\Drupal::moduleHandler()->moduleExists('views_data') ? 'yes' : 'no'));

$out('');
$out('=== routes matching /history ===');
/** @var \Drupal\Core\Routing\RouteProviderInterface $provider */
$provider = \Drupal::service('router.route_provider');
$routes = $provider->getAllRoutes();
foreach ($routes as $name => $route) {
  $path = $route->getPath();
  if (!str_contains($path, 'history')) {
    continue;
  }
  $defs = $route->getDefaults();
  $out(sprintf(
    '  %-60s %-22s title=%s controller=%s',
    $name,
    $path,
    $defs['_title'] ?? '-',
    $defs['_controller'] ?? '-'
  ));
  $requirements = $route->getRequirements();
  $out('      requirements: ' . json_encode($requirements));
}

$out('');
$out('=== all non-admin routes (candidate public entry points) ===');
$skip = ['/admin', '/node', '/user', '/core', '/jsonapi', '/vendor'];
foreach ($routes as $name => $route) {
  $path = $route->getPath();
  if (str_starts_with($path, '/_') || str_starts_with($path, '/internal')) {
    continue;
  }
  foreach ($skip as $prefix) {
    if (str_starts_with($path, $prefix)) {
      continue 2;
    }
  }
  $out(sprintf('  %-46s %-58s %s', $path, $name, $route->getDefaults()['_title'] ?? '-'));
}

$out('');
$out('=== config entities whose id mentions history ===');
$storage = \Drupal::entityTypeManager()->getStorage('config');
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('id', 'history', 'CONTAINS')->execute();
foreach ($ids as $id) {
  $out('  ' . $id);
}
