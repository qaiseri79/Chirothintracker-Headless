<?php

/**
 * Get field groups for tracking_weight from config.
 *
 * Run: drush php:script /app/tools/probe_field_groups.php
 */

$out = function (string $line): void {
  print $line . PHP_EOL;
};

// Field groups are config entities
$ids = \Drupal::configFactory()->listAll('field.group.');
$tracking_groups = array_filter($ids, fn($id) => str_contains($id, 'contact_message.tracking_weight'));

$out("=== Field group configs ===");
foreach ($tracking_groups as $id) {
  $config = \Drupal::configFactory()->get($id);
  $label = $config->get('label');
  $children = $config->get('children');
  $mode = $config->get('mode');
  $out("  $label ($id) mode=$mode");
  if ($children) {
    foreach ($children as $child) {
      $out("    - $child");
    }
  }
}

// Also get vocabulary options for entity reference fields
$out("\n=== Taxonomy vocabularies for entity refs ===");
$vocs = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple();
foreach ($vocs as $vid => $voc) {
  $out("  $vid: " . $voc->label());
}

// Check specific field vocabularies
$field_names = [
  'field_breakfast_protein', 'field_breakfast_fruit_sel',
  'field_lunch_protein', 'field_lunch_fruit_sel', 'field_lunch_vegetables', 'field_lunch_vegetables_free', 'field_lunch_bread',
  'field_dinner_protein', 'field_dinner_fruit_sel', 'field_dinner_vegetables', 'field_dinner_vegetables_free', 'field_dinner_bread',
  'field_chiropractor_flags', 'field_flags', 'field_aggr',
];

$out("\n=== Field -> vocabulary mapping ===");
foreach ($field_names as $fname) {
  $storage = \Drupal::entityTypeManager()->getStorage('field_config');
  $field = $storage->load('contact_message.tracking_weight.' . $fname);
  if ($field) {
    $settings = $field->getSettings();
    $handler_settings = $settings['handler_settings'] ?? [];
    $target_bundles = $handler_settings['target_bundles'] ?? [];
    $out("  $fname -> " . json_encode($target_bundles));
  }
}