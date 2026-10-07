<?php
$router = \Drupal::service('router.route_provider');
$routes = $router->getAllRoutes();
$pats = ['clinic', 'message', 'patient', 'dashboard', 'inbox', 'review'];
echo "--- routes of interest (patterned) ---\n";
foreach ($routes as $name => $r) {
  $p = $r->getPath();
  foreach ($pats as $pat) {
    if (stripos($name, $pat) !== false || stripos($p, '/' . $pat) !== false) {
      echo $name, ' => ', $p, "\n";
      break;
    }
  }
}
echo "--- system.site front ---\n";
echo "front: ", \Drupal::config('system.site')->get('page.front'), "\n";
echo "--- user login/registration redirects? ---\n";
foreach (['redirect.settings', 'omegaconfig', 'user.settings'] as $c) {
  try { $cfg = \Drupal::config($c); echo $c, ': ', json_encode($cfg->get()), "\n"; } catch (\Exception $e) {}
}
echo "--- menu links to /user or front visible in main/user menu ---\n";
$menu_links = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadMultiple();
foreach ($menu_links as $link) {
  $uri = $link->get('link')->uri;
  $title = $link->label();
  $menu = $link->get('menu_name')->value;
  if (stripos($uri, 'user') !== false || stripos($uri, 'internal:') !== false) {
    echo $menu, ' | ', $title, ' | ', $uri, "\n";
  }
}