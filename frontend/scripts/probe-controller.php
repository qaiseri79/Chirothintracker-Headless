<?php

/** Throwaway: match intake paths the way a real request does. */

$matcher = \Drupal::service('router');

$paths = [
  '/api/headless/patients',
  '/api/intake/invite/abc123abc123abc123',
  '/api/intake/submit',
  '/api/intake-link',
];

foreach ($paths as $path) {
  $request = \Symfony\Component\HttpFoundation\Request::create($path, 'GET');
  try {
    $match = $matcher->matchRequest($request);
    echo str_pad($path, 42), ' -> ', ($match instanceof \Drupal\Core\Routing\RouteMatchInterface ? $match->getRouteName() : gettype($match)), PHP_EOL;
  }
  catch (Throwable $e) {
    echo str_pad($path, 42), ' -> ', get_class($e), ': ', $e->getMessage(), PHP_EOL;
  }
}

echo '--- invite route defaults and requirements ---', PHP_EOL;
foreach (['headless_intake.invite', 'headless_intake.submit'] as $name) {
  $route = \Drupal::service('router.route_provider')->getRouteByName($name);
  echo $name, PHP_EOL;
  echo '  path:      ', $route->getPath(), PHP_EOL;
  echo '  host:      ', var_export($route->getHost(), TRUE), PHP_EOL;
  echo '  schemes:   ', var_export($route->getSchemes(), TRUE), PHP_EOL;
  echo '  methods:   ', implode(',', $route->getMethods()), PHP_EOL;
  echo '  condition: ', $route->getCondition(), PHP_EOL;
  echo '  defaults:  ', json_encode(array_intersect_key($route->getDefaults(), ['_controller' => 1, '_route' => 1])), PHP_EOL;
  echo '  options:   ', json_encode($route->getOptions()), PHP_EOL;
}

echo '--- base path ---', PHP_EOL;
echo '  base_path: ', var_export(\Drupal::request()->getBaseUrl(), TRUE), PHP_EOL;
echo '  base_url:  ', var_export(\Drupal::request()->getBaseUrl() . \Drupal::request()->getPathInfo(), TRUE), PHP_EOL;
echo '  script:    ', var_export(\Drupal::request()->getScriptName(), TRUE), PHP_EOL;