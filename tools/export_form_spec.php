<?php

/**
 * @file
 * Exports the authoritative field spec for forms being rebuilt headlessly.
 *
 * Run: lando drush php:script tools/export_form_spec.php
 * Writes: docs/headless/form-spec.json
 *
 * Captures, per contact form:
 *   - field order (form display weight), widget type and widget settings
 *   - field label, type, required, cardinality, defaults, third-party settings
 *   - allowed values for list fields, reference target for entity references
 *   - the hidden component list
 *   - field_group sectioning (from the display's field_group third-party settings)
 */

$etm = \Drupal::entityTypeManager();
$field_manager = \Drupal::service('entity_field.manager');
$entity_form_display = $etm->getStorage('entity_form_display');

$base_fields = [
  'id', 'uuid', 'langcode', 'type', 'contact_form', 'created', 'changed',
  'default_langcode', 'name', 'mail', 'subject', 'message', 'copy',
  'recipient', 'uid', 'ip_address',
];

$form_ids = ['patient_intake', 'tracking_weight'];
$out = [];

foreach ($form_ids as $form_id) {
  $form = $etm->getStorage('contact_form')->load($form_id);
  if (!$form) {
    $out[$form_id] = ['error' => 'contact form not found'];
    continue;
  }

  // Read the form display straight from config: the display entity does not
  // surface field_group third-party settings.
  $display_config = \Drupal::config('core.entity_form_display.contact_message.' . $form_id . '.default');
  $content = $display_config->get('content') ?? [];
  $hidden = array_keys(array_filter($display_config->get('hidden') ?? []));

  // Field groups are stored as third-party settings on the form display.
  $groups = [];
  $groups_raw = $display_config->get('third_party_settings.field_group') ?? [];
  foreach ($groups_raw as $group_id => $group) {
    $groups[] = [
      'id' => $group_id,
      'label' => $group['label'] ?? $group_id,
      'weight' => $group['weight'] ?? 0,
      'format_type' => $group['format_type'] ?? 'fieldset',
      'fields' => $group['children'] ?? [],
    ];
  }
  usort($groups, fn($a, $b) => $a['weight'] <=> $b['weight']);

  // Map each field to the group that contains it.
  $group_of = [];
  foreach ($groups as $group) {
    foreach ($group['fields'] as $field_name) {
      $group_of[$field_name] = $group['id'];
    }
  }

  $fields = [];
  foreach ($field_manager->getFieldDefinitions('contact_message', $form_id) as $name => $definition) {
    if (in_array($name, $base_fields, TRUE)) {
      continue;
    }

    $component = $content[$name] ?? [];
    $instance = \Drupal::config('field.field.contact_message.' . $form_id . '.' . $name);
    $settings = $definition->getSettings();

    $item = [
      'name' => $name,
      'label' => (string) $definition->getLabel(),
      'type' => $definition->getType(),
      'required' => $definition->isRequired(),
      'cardinality' => $definition->getFieldStorageDefinition()->getCardinality(),
      'weight' => $component['weight'] ?? null,
      'widget' => $component['type'] ?? null,
      'widget_settings' => $component['settings'] ?? [],
      'hidden' => in_array($name, $hidden, TRUE),
      'group' => $group_of[$name] ?? NULL,
      'description' => (string) $definition->getDescription(),
      'default_value' => $instance->get('default_value') ?? [],
      'third_party_settings' => $instance->get('third_party_settings') ?? [],
    ];

    if (in_array($definition->getType(), ['list_string', 'list_integer'], TRUE)) {
      $constraint = $definition->getConstraint('AllowedValuesConstraint');
      $item['allowed_values'] = $constraint ? $constraint->getAllowedValues() : [];
    }

    if ($definition->getType() === 'entity_reference') {
      $item['target_type'] = $settings['target_type'] ?? NULL;
      $item['reference_view'] = $settings['handler_settings']['view'] ?? NULL;
    }

    $fields[] = $item;
  }

  // Order by display weight, ungrouped last within the same weight band.
  usort($fields, fn($a, $b) => ($a['weight'] ?? 999) <=> ($b['weight'] ?? 999));

  $form_config = \Drupal::config('contact.form.' . $form_id);
  $out[$form_id] = [
    'label' => $form->label(),
    'status' => $form->status() ? 'open' : 'closed',
    'redirect' => $form_config->get('redirect'),
    'confirmation_message' => $form_config->get('message'),
    'submission_count' => (int) \Drupal::database()
      ->query('SELECT COUNT(*) FROM contact_message WHERE contact_form = :f', [':f' => $form_id])
      ->fetchField(),
    'contact_storage' => $form->getThirdPartySettings('contact_storage'),
    'hidden_components' => $hidden,
    'field_groups' => $groups,
    'field_count' => count($fields),
    'fields' => $fields,
  ];
}

$target = __DIR__ . '/../docs/headless';
if (!is_dir($target)) {
  \Drupal::service('file_system')->mkdir($target, 0755, TRUE);
}
file_put_contents(
  $target . '/form-spec.json',
  json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

print "wrote docs/headless/form-spec.json\n";
foreach ($out as $id => $data) {
  printf(
    "%-18s %2d fields, %2d groups, %s submissions\n",
    $id,
    $data['field_count'] ?? 0,
    count($data['field_groups'] ?? []),
    number_format($data['submission_count'] ?? 0)
  );
}
