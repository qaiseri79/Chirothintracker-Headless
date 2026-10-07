<?php

/**
 * Dump the tracking_weight contact form field structure.
 *
 * Run: drush php:script /app/tools/probe_tracking_form.php
 */

$out = function (string $line): void {
  print $line . PHP_EOL;
};

// Load the contact form config
$form = \Drupal::configFactory()->get('contact.form.tracking_weight');
if ($form->isNew()) {
  $out("tracking_weight form not found.");
  return;
}

$out('=== contact.form.tracking_weight ===');
$out('label: ' . $form->get('label'));
$out('mail: ' . $form->get('mail'));
$out('sender: ' . $form->get('sender'));
$out('reply: ' . $form->get('reply'));
$out('selected_fields: ' . json_encode($form->get('selected_fields')));

// Get field instances for this form
$field_map = \Drupal::service('entity_field.manager')->getFieldDefinitions('contact_message', 'tracking_weight');
$out("\n=== Field definitions (bundle: tracking_weight) ===");
foreach ($field_map as $name => $def) {
  $type = $def->getType();
  $label = $def->getLabel();
  $required = $def->isRequired();
  $settings = $def->getSettings();
  $out(sprintf('  %-40s %-20s req=%s', $name, $type, $required ? 'Y' : 'N'));
  if (!empty($settings['allowed_values'])) {
    $vals = array_slice($settings['allowed_values'], 0, 5);
    $out('    options: ' . json_encode($vals) . (count($settings['allowed_values']) > 5 ? ' ...' : ''));
  }
  if (!empty($settings['min']) || !empty($settings['max'])) {
    $out('    bounds: min=' . ($settings['min'] ?? 'none') . ' max=' . ($settings['max'] ?? 'none'));
  }
  if (!empty($settings['default_value'])) {
    $out('    default: ' . json_encode($settings['default_value']));
  }
}

// Also check field groups
$groups = \Drupal::entityTypeManager()->getStorage('field_group')->loadByProperties([
  'entity_type' => 'contact_message',
  'bundle' => 'tracking_weight',
]);
$out("\n=== Field groups ===");
foreach ($groups as $group) {
  $out('  ' . $group->label() . ' (' . $group->id() . ') mode=' . $group->get('mode'));
  $children = $group->get('children');
  if ($children) {
    foreach ($children as $child) {
      $out('    - ' . $child);
    }
  }
}