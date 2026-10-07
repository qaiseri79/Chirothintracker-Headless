<?php

/**
 * Check form display and #states for tracking_weight.
 *
 * Run: drush php:script /app/tools/probe_tracking_display.php
 */

$out = function (string $line): void {
  print $line . PHP_EOL;
};

// Get form display for tracking_weight
$display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('contact_message.tracking_weight.default');
if (!$display) {
  $out("No form display found.");
  return;
}

$components = $display->getComponents();
$out("=== Form display components ===");
foreach ($components as $name => $comp) {
  $type = $comp['type'] ?? '?';
  $region = $comp['region'] ?? '?';
  $weight = $comp['weight'] ?? 0;
  $settings = $comp['settings'] ?? [];
  $third = $comp['third_party_settings'] ?? [];
  $out(sprintf("  %-40s type=%-20s region=%-10s weight=%d", $name, $type, $region, $weight));
  if (!empty($third['states'])) {
    $out('    STATES: ' . json_encode($third['states']));
  }
  if (!empty($settings)) {
    $out('    settings: ' . json_encode($settings));
  }
}

// Also check if there's a custom module handling this form
$out("\n=== Checking for custom form alter/hooks ===");
$module_handler = \Drupal::moduleHandler();
$out('ctt_patient_intake exists: ' . ($module_handler->moduleExists('ctt_patient_intake') ? 'yes' : 'no'));
$out('custom_module exists: ' . ($module_handler->moduleExists('custom_module') ? 'yes' : 'no'));