<?php

/**
 * Get taxonomy terms for tracking form vocabularies.
 * Run: drush php:script /app/tools/get_taxonomy_options.php
 */

$out = function ($line) { print $line . PHP_EOL; };

$vocabularies = [
  'protein' => 'Protein',
  'fruit' => 'Fruit',
  'vegetables' => 'Vegetables',
  'vegetables_free_' => 'Vegetables (Free)',
  'bread' => 'Bread',
  'patient_flags' => 'Patient Flags',
  'chiropractor_flags' => 'Chiropractor Flags',
];

$out("{");
foreach ($vocabularies as $vid => $label) {
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
    ->loadByProperties(['vid' => $vid]);
  $options = [];
  foreach ($terms as $term) {
    $options[] = [
      'value' => (string)$term->id(),
      'label' => $term->label(),
    ];
  }
  $out("  '$vid': " . json_encode($options) . ",");
}
$out("}");