<?php

/**
 * @file
 * Locks down JSON:API: deny by default, then explicitly enable a small allowlist.
 *
 * Run: lando drush php:script tools/lock_down_jsonapi.php
 *
 * Context: enabling jsonapi on this site exposed 258 resources, including every
 * user record and every patient intake submission, to any authenticated role.
 * default_disabled closes all of that; only resources named here stay reachable.
 */

use Drupal\jsonapi_extras\Entity\JsonapiResourceConfig;

$settings = \Drupal::configFactory()->getEditable('jsonapi_extras.settings');
$settings->set('default_disabled', TRUE);
$settings->set('include_count', FALSE);
$settings->set('validate_configuration_integrity', FALSE);
$settings->save();

// Resources the headless frontend legitimately reads. Taxonomy option lists
// only: no user, clinic, patient_profile, contact_message or webform_submission
// data is reachable through JSON:API. Per-clinic and per-user data is served by
// scoped /api/* endpoints instead, because JSON:API cannot express
// "only rows belonging to my clinic".
$allowlist = [
  'taxonomy_term--gender',
  'taxonomy_term--yes_no',
  'taxonomy_term--program_days',
  'taxonomy_term--weight_loss_phase',
];

$storage = \Drupal::entityTypeManager()->getStorage('jsonapi_resource_config');

foreach ($allowlist as $resource_name) {
  $id = $resource_name;
  $config = $storage->load($id) ?? JsonapiResourceConfig::create(['id' => $id]);
  $config->set('path', $resource_name);
  $config->set('resourceType', $resource_name);
  $config->set('disabled', FALSE);
  // Must be an array, not NULL: ConfigurableResourceType::getResourceFieldConfiguration()
  // calls array_filter() on this value unguarded, which is a TypeError when NULL.
  $config->set('resourceFields', []);
  $config->save();
  print "enabled: $resource_name\n";
}

// Anything previously enabled but not on the list gets explicitly disabled.
$disabled_count = 0;
foreach ($storage->loadMultiple() as $existing) {
  if (!in_array($existing->id(), $allowlist, TRUE) && !$existing->get('disabled')) {
    $existing->set('disabled', TRUE);
    $existing->save();
    $disabled_count++;
  }
}
print "explicitly disabled: $disabled_count previously-enabled resources\n";

// Report what remains reachable.
print "\ndefault_disabled: " . var_export($settings->get('default_disabled'), TRUE) . "\n";
print "explicitly enabled resources (" . count($allowlist) . "):\n";
foreach ($allowlist as $name) {
  print "  $name\n";
}
