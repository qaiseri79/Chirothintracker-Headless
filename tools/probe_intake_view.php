<?php
$storage = \Drupal::entityTypeManager()->getStorage('view');
$view = $storage->load('intake_forms_cs');
if (!$view) { echo "view intake_forms_cs NOT FOUND\n"; exit; }
$display = $view->getDisplay('page_1');
echo "display page_1 access: ", json_encode($display['display_options']['access'] ?? null), "\n";
echo "fields (keys): ", json_encode(array_keys($display['display_options']['fields'] ?? [])), "\n";
echo "arguments (keys): ", json_encode(array_keys($display['display_options']['arguments'] ?? [])), "\n";
echo "filters (keys): ", json_encode(array_keys($display['display_options']['filters'] ?? [])), "\n";
echo "display_options keys: ", json_encode(array_keys($display['display_options'])), "\n";
echo "header: ", json_encode($display['display_options']['header'] ?? null), "\n";
echo "empty: ", json_encode($display['display_options']['empty'] ?? null), "\n";