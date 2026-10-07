<?php

use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Probe: clinic entity shape, brand field, and how field_agreement etc. are
 * configured, so the new intake endpoints can resolve the 4 EPP tokens from
 * the same source the live form uses.
 */

function probe_dump($label, $value): void {
  echo "## $label\n";
  print_r($value);
  echo "\n";
}

$em = \Drupal::entityTypeManager();

// 1. Clinic entity type.
$defs = $em->getDefinitions();
foreach ($defs as $type => $def) {
  /** @var EntityTypeInterface $def */
  if (in_array($type, ['clinic', 'node', 'taxonomy_term', 'contact_message', 'user']) && $type !== 'node') {
    probe_dump("entity type: $type", [
      'class short' => substr($def->getClass(), (int) strrpos($def->getClass(), '\\') + 1),
      'bundle (entity) type' => $def->getBundleEntityType(),
      'bundle labels' => method_exists($def, 'getBundleLabel')
        ? (string) $def->getBundleLabel()
        : null,
      'label' => $def->getLabel(),
      'base table' => $def->getBaseTable(),
      'fieldable' => $def->hasKey('bundle') || $def->getKey('bundle'),
    ]);
  }
}

// 2. Clinic bundles and a sample clinic instance.
try {
  $storage = $em->getStorage('clinic');
  $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('clinic');
  probe_dump('clinic bundles', array_map(fn ($b) => $b['label'] ?? $b, $bundles));
  $ids = $storage->getQuery()->accessCheck(FALSE)->range(0, 3)->execute();
  probe_dump('sample clinic ids', $ids);
  foreach ($ids as $id) {
    $clinic = $storage->load($id);
    if ($clinic) {
      echo "--- clinic $id ---\n";
      echo 'label() = ' . $clinic->label() . "\n";
      // Dump all field values (names) and a couple of likely brand-ish values.
      foreach ($clinic->getFieldDefinitions() as $fdef) {
        $fname = $fdef->getName();
        if (str_starts_with($fname, 'field_') || in_array($fname, ['title', 'name'])) {
          $val = $clinic->get($fname)->getValue();
          $shown = is_array($val) ? json_encode(array_map(fn ($row) => $row['value'] ?? $row['target_id'] ?? $row, $val), JSON_UNESCAPED_UNICODE) : (string) $val;
          echo "  $fname = $shown\n";
        }
      }
    }
  }
}
catch (\Throwable $e) {
  probe_dump('clinic storage error', $e->getMessage());
}

// 3. user.field_clinic target type.
$user_f = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('user');
if (isset($user_f['field_clinic'])) {
  $settings = $user_f['field_clinic']->getSettings();
  echo "user.field_clinic target_type = " . ($settings['target_type'] ?? '?') . "\n";
}
$msg_f = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('contact_message');
foreach (['field_clinic', 'field_agreement', 'field_program_agreement', 'field_media_release_agreement'] as $fname) {
  if (isset($msg_f[$fname])) {
    $s = $msg_f[$fname]->getSettings();
    $type = $msg_f[$fname]->getType();
    $target = $s['target_type'] ?? '-';
    $max = $s['max_length'] ?? '-';
    echo "contact_message.$fname : type=$type target=$target max=$max\n";
  }
}

// 4. EPP / default values for the three legal fields on the patient_intake form.
$fm = \Drupal::service('entity_form_display.repository')->getFormDisplay('contact_message', 'patient_intake', 'default');
$cm = \Drupal::entityTypeManager()->getStorage('contact_form')->load('patient_intake');
if ($cm && $cm->hasField('field_agreement')) {
  echo "\nfield_agreement (contact_form) value:\n" . $cm->get('field_agreement')->value . "\n---END---\n";
}
if ($cm && $cm->hasField('field_program_agreement')) {
  echo "\nfield_program_agreement (contact_form) value:\n" . $cm->get('field_program_agreement')->value . "\n---END---\n";
}
// default_value for media release lives on the field config of the message bundle
$bundle_conf = \Drupal::service('entity_type.manager')->getStorage('field_config')->load('contact_message.patient_intake.field_media_release_agreement');
if ($bundle_conf) {
  probe_dump('contact_message.patient_intake.field_media_release_agreement config', $bundle_conf->get('default_value') ?? []);
}