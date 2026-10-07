<?php

/**
 * @file
 * Prints the real field definitions on the contact_message bundle.
 */

declare(strict_types=1);

$storage = \Drupal::entityTypeManager()->getStorage('contact_message');
$etm = \Drupal::entityTypeManager();

print "=== contact_message field definitions ===\n";

$manager = \Drupal::service('entity_field.manager');
foreach ($manager->getFieldDefinitions('contact_message', 'message') as $name => $definition) {
  $storage_definition = $definition->getFieldStorageDefinition();
  $type = $storage_definition ? $storage_definition->getType() : '?';
  $cardinality = $storage_definition ? $storage_definition->getCardinality() : '?';
  print sprintf("  %-32s type=%-14s card=%-4s label=%s\n", $name, $type, (string) $cardinality, (string) $definition->getLabel());
}

print "\n=== sample message raw values ===\n";
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('contact_form', 'message')
  ->sort('created', 'DESC')
  ->range(0, 3)
  ->execute();

foreach ($storage->loadMultiple($ids) as $msg) {
  print "\n-- id " . $msg->id() . " --\n";
  foreach ($msg->getFields() as $name => $item_list) {
    $value = $item_list->getValue();
    if ($value === [] || $value === NULL) {
      continue;
    }
    print "  $name = " . json_encode($value) . "\n";
  }
}
