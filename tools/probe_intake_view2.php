<?php
$storage = \Drupal::entityTypeManager()->getStorage('view');
$view = $storage->load('intake_forms_cs');
$display = $view->getDisplay('page_1');
$defaults = $display['display_options']['defaults'] ?? [];
echo "overrides (defaults field): ", json_encode($defaults), "\n";
$def_display = $view->getDisplay('default');
echo "default access: ", json_encode($def_display['display_options']['access'] ?? null), "\n";
echo "default filters keys: ", json_encode(array_keys($def_display['display_options']['filters'] ?? [])), "\n";
echo "default arguments keys: ", json_encode(array_keys($def_display['display_options']['arguments'] ?? [])), "\n";
echo "default relationships keys: ", json_encode(array_keys($def_display['display_options']['relationships'] ?? [])), "\n";
echo "default sorts: ", json_encode($def_display['display_options']['sorts'] ?? null), "\n";
foreach (($def_display['display_options']['filters'] ?? []) as $k => $f) {
  echo "filter $k: ", json_encode(array_intersect_key($f, array_flip(['plugin_id','field','id','table','value','operator']))), "\n";
}
echo "default header: ", json_encode($def_display['display_options']['header'] ?? null), "\n";
echo "base: ", $view->get('base_table'), " / base_field: ", $view->get('base_field'), " / title: ", $view->get('title'), "\n";
echo "entity_type for base: ", ($view->get('base_table') === 'contact_message' ? 'contact_message' : ''), "\n";