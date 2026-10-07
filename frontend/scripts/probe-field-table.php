<?php

$database = \Drupal::database();
$schema = $database->schema();

echo "--- tables matching clinic ---\n";
foreach ($database->query("SHOW TABLES LIKE '%clinic%'") as $row) {
  echo "  ", reset($row), "\n";
}

echo "--- clinic__field_intake_token columns ---\n";
try {
  $result = $database->query("SHOW COLUMNS FROM clinic__field_intake_token");
  foreach ($result as $row) {
    $row = (object) $row;
    printf("  %s: %s(%s) null=%s key=%s%s", $row->Field, $row->Type, $row->Key, $row->Null, PHP_EOL);
  }
}
catch (Throwable $e) {
  echo "  ", $e->getMessage(), "\n";
}

echo "--- clinic__field_intake_token indexes ---\n";
foreach ($database->query("SHOW INDEX FROM clinic__field_intake_token") as $row) {
  $row = (object) $row;
  echo "  {$row->Key_name} on {$row->Column_name} unique=", (int) $row->Non_unique === 0 ? 'yes' : 'no', PHP_EOL;
}

echo "--- field storage entity ---\n";
$storage = \Drupal::entityTypeManager()->getStorage('field_storage_config');
$entity = $storage->load('clinic.field_intake_token');
echo 'loaded: ', var_export($entity !== NULL, TRUE), PHP_EOL;
if ($entity) {
  echo 'target entity type: ', $entity->getTargetEntityTypeId(), PHP_EOL;
  echo 'field name: ', $entity->getName(), PHP_EOL;
  echo 'type: ', $entity->getType(), PHP_EOL;
}

echo "--- field instance entity ---\n";
$instance = \Drupal::entityTypeManager()->getStorage('field_config')
  ->load('clinic.clinic.field_intake_token');
echo 'loaded: ', var_export($instance !== NULL, TRUE), PHP_EOL;
if ($instance) {
  echo 'bundle: ', $instance->getBundle(), PHP_EOL;
  echo 'target type: ', $instance->getTargetEntityTypeId(), PHP_EOL;
}