<?php
$router = \Drupal::service('router.route_provider');
$routes = $router->getRoutesByPattern('/manage');
foreach ($routes as $name => $r) { echo $name, ' => ', $r->getPath(), ' | defaults: ', json_encode($r->getDefaults(), JSON_PRETTY_PRINT), "\n"; }
$routes = $router->getRoutesByPattern('/manage/intake');
foreach ($routes as $name => $r) { echo $name, ' => ', $r->getPath(), ' | defaults: ', json_encode($r->getDefaults(), JSON_PRETTY_PRINT), "\n"; }
echo "--- menu link for /manage/intake ---\n";
$menu_links = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['link' => ['uri' => 'internal:/manage/intake']]);
foreach ($menu_links as $link) {
  echo $link->id(), ' | ', $link->get('menu_name')->value, ' | ', $link->label(), ' | ', $link->get('weight')->value, "\n";
  foreach ($link->get('roles') as $r) { if ($r->value) echo '  role: ', $r->value, "\n"; }
}