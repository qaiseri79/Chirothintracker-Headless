<?php
/**
 * Check for #states on tracking_weight form.
 * Run: drush php:script /app/tools/probe_tracking_states.php
 */

$out = function ($line) {
  print $line . PHP_EOL;
};

$out("=== Searching for states in module files ===");
$files = [
  '/app/web/modules/custom/custom_module/custom_module.module',
  '/app/web/modules/custom/ctt_patient_intake/ctt_patient_intake.module',
];

foreach ($files as $file) {
  if (file_exists($file)) {
    $content = file_get_contents($file);
    if (strpos($content, 'tracking_weight') !== false) {
      $out("Found tracking_weight in $file");
      $lines = explode("\n", $content);
      foreach ($lines as $i => $line) {
        if (strpos($line, 'tracking_weight') !== false &&
            (strpos($line, '#states') !== false || strpos($line, 'states') !== false)) {
          $out("  Line " . ($i+1) . ": " . trim($line));
        }
      }
    }
  }
}

$display = \Drupal::entityTypeManager()->getStorage('entity_form_display')
  ->load('contact_message.tracking_weight.default');
if ($display) {
  $components = $display->getComponents();
  $out("\n=== Third party settings (states) from form display ===");
  foreach ($components as $name => $comp) {
    $third = $comp['third_party_settings'] ?? [];
    if (!empty($third['states'])) {
      $out("  $name: " . json_encode($third['states']));
    }
  }
}