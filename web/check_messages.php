<?php
require_once "autoload.php";
$kernel = \Drupal\Core\DrupalKernel::createFromRequest(\Symfony\Component\HttpFoundation\Request::create("/", "GET"), "prod", FALSE);
$kernel->boot();
$container = $kernel->getContainer();
$database = $container->get("database");

$result = $database->query("SELECT contact_form, COUNT(*) as count FROM contact_message GROUP BY contact_form")->fetchAll();
foreach ($result as $row) {
  echo $row->contact_form . ": " . $row->count . "\n";
}

// Check field_patient_uid values
$result2 = $database->query("SELECT DISTINCT field_patient_uid_value FROM contact_message WHERE field_patient_uid_value IS NOT NULL LIMIT 10")->fetchAll();
foreach ($result2 as $row) {
  echo "field_patient_uid: " . $row->field_patient_uid_value . "\n";
}

// Check field_from/field_to
$result3 = $database->query("SELECT field_from_value, field_to_value, field_patient_uid_value FROM contact_message WHERE contact_form = 'message' LIMIT 5")->fetchAll();
foreach ($result3 as $row) {
  echo "from: " . $row->field_from_value . ", to: " . $row->field_to_value . ", patient: " . $row->field_patient_uid_value . "\n";
}