<?php
$router = \Drupal::service('router.route_provider');
$routes = [
  'dashboard',
  'user',
  'user.page',
  '<front>',
  'view.dashboard',
  'view.dashboard_page_1',
  'view.patient_dashboard',
];
foreach ($routes as $r) {
  $out = null;
  try { $out = $router->getRouteByName($r); } catch (\Exception $e) {}
  if ($out) {
    $du = $out->getDefaults();
    $params = $out->getOptions()['parameters'] ?? [];
    echo $r, ' => ', $out->getPath(), ' | defaults: ', json_encode($du), ' | params: ', json_encode($params), "\n";
  } else {
    echo $r, ' => NO ROUTE', "\n";
  }
}
echo "--- all routes whose pattern is under /dashboard* ---\n";
$all = $router->getRoutesByPattern('/dashboard');
foreach ($all as $name => $r) {
  echo $name, ' => ', $r->getPath(), ' | defaults: ', json_encode($r->getDefaults()), "\n";
}
echo "--- views page routes that match *dashboard* names ---\n";
$routes = $router->getAllRoutes();
foreach ($routes as $name => $r) {
  if (stripos($name, 'dashboard') !== false) {
    echo $name, ' => ', $r->getPath(), "\n";
  }
}