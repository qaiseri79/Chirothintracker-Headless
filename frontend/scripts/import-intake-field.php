<?php

/**
 * Throwaway: import the two intake-token field items through the entity API.
 *
 * Two things this has to get right, both of which the obvious
 * `config.factory->getEditable(...)->save()` does NOT do:
 *
 * 1. It writes raw config and never runs FieldStorageConfig::preSave(), which is
 *    the only place `onFieldStorageDefinitionCreate()` is called from. Save the
 *    storage config that way and it lands in config with no table behind it: the
 *    field looks installed, every read of it hits a missing table, and the field
 *    module reports nothing wrong.
 * 2. Only the two named items are touched. `drush config:import` would also pull
 *    in this site's unrelated drift (core.extension, readmehelp.settings).
 *
 * So: config entity storage `create()` + `save()`, which is the same path a real
 * config import takes for these two items.
 */

$items = [
  // entity type id => [config name, entity id].
  'field_storage_config' => ['field.storage.clinic.field_intake_token', 'clinic.field_intake_token'],
  'field_config' => ['field.field.clinic.clinic.field_intake_token', 'clinic.clinic.field_intake_token'],
];

$sync = \Drupal::service('config.storage.sync');
$entityTypeManager = \Drupal::entityTypeManager();
$storage = \Drupal::service('config.storage');

// Clear anything already written, so the entity API sees these as new and runs the
// create path rather than the update path.
//
// Raw config deletion, deliberately, rather than the entity API. An earlier attempt
// left the two halves out of step — a field instance whose storage config is gone —
// and FieldConfig::getFieldStorageDefinition() then refuses to load, save or delete
// it at all ("when the field storage does not exist"). The entity API cannot clean
// up after itself there; the config storage can.
foreach ($items as [$configName]) {
  if ($storage->read($configName) !== NULL) {
    echo "clearing stale config $configName", PHP_EOL;
    $storage->delete($configName);
  }
}

// Field storage first, then the instance: FieldConfig::preSave() resolves the field
// type from its storage definition, so an instance created first has nothing to
// resolve against.
foreach ($items as $entityType => [$configName, $entityId]) {
  $data = $sync->read($configName);
  if ($data === NULL) {
    echo "FAIL: nothing in sync storage for $configName", PHP_EOL;
    continue;
  }

  $entity = $entityTypeManager->getStorage($entityType)->create($data);
  $entity->save();
  echo 'saved ', $entity->getEntityTypeId(), ':', $entity->id(), PHP_EOL;
}

$database = \Drupal::database();

if (!$database->schema()->tableExists('clinic__field_intake_token')) {
  echo "FAIL: clinic__field_intake_token was not created", PHP_EOL;
  return;
}

echo "--- clinic__field_intake_token columns ---\n";
foreach ($database->query('SHOW COLUMNS FROM clinic__field_intake_token') as $row) {
  $row = (object) $row;
  echo "  {$row->Field}: {$row->Type} key={$row->Key} null={$row->Null}\n";
}

echo "--- indexes ---\n";
foreach ($database->query('SHOW INDEX FROM clinic__field_intake_token') as $row) {
  $row = (object) $row;
  echo "  {$row->Key_name} on {$row->Column_name} unique=", ((int) $row->Non_unique === 0) ? 'yes' : 'no', PHP_EOL;
}

// The field must arrive empty: a clinic with no link is NULL, and nothing in the
// import is allowed to invent a token.
$withToken = (int) $database->select('clinic__field_intake_token', 't')
  ->condition('intake_token', NULL, 'IS NOT NULL')
  ->countQuery()
  ->execute()
  ->fetchField();
$total = (int) $database->select('clinic__field_intake_token', 't')
  ->countQuery()
  ->execute()
  ->fetchField();
echo "clinics=$total with_token=$withToken", PHP_EOL;