<?php

/**
 * @file
 * Generates the headless intake blueprint: the design's 6 steps, hydrated with
 * the authoritative field values read live from Drupal.
 *
 * Run: lando drush php:script tools/export_intake_blueprint.php
 * Writes: docs/headless/intake-blueprint.json
 *
 * Division of authority:
 *   - Layout, step order, widget choice, half/full width, suffixes: the design
 *     file "ChiroThin — Patient Intake (Redesign).html".
 *   - Labels, required flags, option lists, defaults, descriptions, legal text:
 *     read live from Drupal config, so the blueprint cannot drift.
 *
 * #states do not exist in config (they are set in
 * custom_module/src/../custom_module.module at lines 662-706), so the visibility
 * rules are encoded here as data and checked against the module source below.
 */

$etm = \Drupal::entityTypeManager();
$fm = \Drupal::service('entity_field.manager');
$form_id = 'patient_intake';

$defs = $fm->getFieldDefinitions('contact_message', $form_id);
$display = \Drupal::config('core.entity_form_display.contact_message.' . $form_id . '.default');
$content = $display->get('content') ?? [];
$hidden = array_keys(array_filter($display->get('hidden') ?? []));

// Base fields the API owns, plus the two hidden fields the form sets itself.
$server_owned = [
  'field_clinic', 'field_domain',
  'id', 'uuid', 'langcode', 'type', 'contact_form', 'created', 'changed',
  'default_langcode', 'name', 'mail', 'subject', 'message', 'copy',
  'recipient', 'uid', 'ip_address',
];

/**
 * Reads one field's authoritative definition.
 *
 * A closure rather than a named function: Drush includes this file inside a
 * function, so top-level variables are function-local and not reachable via
 * global().
 */
$intake_field = function (string $name) use ($defs, $content, $hidden, $form_id): array {
  $def = $defs[$name] ?? NULL;
  if (!$def) {
    return ['name' => $name, 'missing' => TRUE];
  }
  $instance = \Drupal::config('field.field.contact_message.' . $form_id . '.' . $name);
  $component = $content[$name] ?? [];

  $out = [
    'name' => $name,
    'label' => (string) $def->getLabel(),
    'drupalType' => $def->getType(),
    'required' => $def->isRequired(),
    'cardinality' => $def->getFieldStorageDefinition()->getCardinality(),
    'weight' => $component['weight'] ?? NULL,
    'description' => (string) $def->getDescription(),
    'placeholder' => $component['settings']['placeholder'] ?? '',
    'default' => NULL,
    'options' => [],
  ];

  if (in_array($def->getType(), ['integer', 'decimal'], TRUE)) {
    $settings = $def->getSettings();
    if (isset($settings['min']) && $settings['min'] !== '' && $settings['min'] !== NULL) {
      $out['min'] = (int) $settings['min'];
    }
    if (isset($settings['max']) && $settings['max'] !== '' && $settings['max'] !== NULL) {
      $out['max'] = (int) $settings['max'];
    }
  }
  elseif (in_array($def->getType(), ['string', 'email', 'telephone'], TRUE)) {
    $max = $def->getSettings()['max_length'] ?? NULL;
    if ($max !== NULL && $max !== '') {
      $out['maxLength'] = (int) $max;
    }
  }

  if (in_array($def->getType(), ['list_string', 'list_integer'], TRUE)) {
    // Allowed values live in field storage config, not on the field definition.
    $allowed = \Drupal::config('field.storage.contact_message.' . $name)
      ->get('settings.allowed_values') ?? [];
    foreach ($allowed as $row) {
      $out['options'][] = [
        'value' => (string) ($row['value'] ?? ''),
        'label' => (string) ($row['label'] ?? ''),
      ];
    }
  }

  $default = $instance->get('default_value') ?? [];
  if ($default) {
    $first = reset($default);
    if (is_array($first) && isset($first['value'])) {
      $out['default'] = (string) $first['value'];
    }
    elseif (isset($first['value'])) {
      $out['default'] = $first['value'];
    }
  }

  // EPP (entity pattern) third-party settings carry token-templated values and
  // are the de facto default for several fields on this form.
  $epp = $instance->get('third_party_settings.epp.value');
  if ($epp !== NULL && $epp !== '') {
    $out['eppValue'] = (string) $epp;
  }

  return $out;
};

/**
 * Resolves the authoritative text for a static/legal block.
 *
 * Priority: field default -> EPP token template -> legacy D7 webform config.
 * The D7 fallback matters: field_agreement lost its text in the D7->D8
 * migration and currently renders as a heading with an empty body.
 */
$resolve_legal_text = function (array $spec, string $field_name): array {
  $sources = [];

  if (!empty($spec['default'])) {
    $sources[] = 'default_value';
    return ['text' => $spec['default'], 'source' => 'default_value', 'candidates' => $sources];
  }

  if (!empty($spec['eppValue'])) {
    $sources[] = 'epp';
    return ['text' => $spec['eppValue'], 'source' => 'epp', 'candidates' => $sources];
  }

  $legacy_keys = [
    'field_agreement' => 'informed_consent_and_release_of_liability',
  ];
  $key = $legacy_keys[$field_name] ?? NULL;
  if ($key) {
    $webform = \Drupal::config('webform.webform.patient_intake');
    $element = $webform ? _intake_find_element($webform->getRawData(), $key) : NULL;
    $value = is_array($element) ? ($element['#value'] ?? $element['value'] ?? NULL) : $element;
    if (is_string($value) && trim($value) !== '') {
      $sources[] = 'legacy_webform';
      return ['text' => $value, 'source' => 'legacy_webform', 'candidates' => $sources];
    }
  }

  return ['text' => '', 'source' => NULL, 'candidates' => $sources];
};

/**
 * Recursively locates a webform element by key.
 */
function _intake_find_element(array $data, string $key) {
  foreach ($data as $k => $v) {
    if ($k === $key) {
      return $v;
    }
    if (is_array($v) && ($found = _intake_find_element($v, $key)) !== NULL) {
      return $found;
    }
  }
  return NULL;
}

// Visibility rules lifted from custom_module.module:662-706, plus the
// "select all that apply" follow-up the design places under the disqualifier.
$conditions = [
  'field_surgery_weight_loss' => [
    ['field' => 'field_history_bariatric_surgery', 'op' => 'notEmpty'],
  ],
  'field_surgery_weight_gain' => [
    ['field' => 'field_history_bariatric_surgery', 'op' => 'notEmpty'],
  ],
  'field_diabetes_diagnosis_year' => [
    ['field' => 'field_diabetes', 'op' => 'neq', 'value' => '0'],
  ],
  'field_diabetes_medications' => [
    ['field' => 'field_diabetes', 'op' => 'neq', 'value' => '0'],
  ],
  'field_bp_diagnosis_year' => [
    ['field' => 'field_high_blood_pressure', 'op' => 'eq', 'value' => '1'],
  ],
  'field_blood_pressure_medications' => [
    ['field' => 'field_high_blood_pressure', 'op' => 'eq', 'value' => '1'],
  ],
  'field_thyroid_diagnosis_year' => [
    ['field' => 'field_thyroid_condition', 'op' => 'eq', 'value' => '1'],
  ],
  'field_thyroid_medications' => [
    ['field' => 'field_thyroid_condition', 'op' => 'eq', 'value' => '1'],
  ],
  'field_cholesterol_diagnosis_year' => [
    ['field' => 'field_high_cholesterol', 'op' => 'eq', 'value' => '1'],
  ],
  'field_cholesterol_medications' => [
    ['field' => 'field_high_cholesterol', 'op' => 'eq', 'value' => '1'],
  ],
  'field_medical_eligibility_choice' => [
    ['field' => 'field_medical_eligibility', 'op' => 'eq', 'value' => '1'],
  ],
];

// Guard: the encoded rules must still correspond to real #states in the module.
// These two conditions are introduced by the design itself (the mock reveals the
// follow-ups), so they have no #states counterpart and are exempt.
$design_introduced = ['field_medical_eligibility_choice', 'field_surgery_weight_gain'];

$module = __DIR__ . '/../web/modules/custom/custom_module/custom_module.module';
$module_source = is_file($module) ? file_get_contents($module) : '';
$condition_drift = [];
foreach (array_keys($conditions) as $field) {
  if (in_array($field, $design_introduced, TRUE)) {
    continue;
  }
  if ($module_source !== '' && !str_contains($module_source, "'" . $field . "'")) {
    $condition_drift[] = $field;
  }
}

// The design's steps. Each entry is [drupalField, widget, layout flags].
$design = [
  [
    'id' => 'personal',
    'title' => 'Personal Details',
    'subtitle' => "Let's start with the basics.",
    'fields' => [
      ['field_first_name', 'text', ['half' => TRUE]],
      ['field_last_name', 'text', ['half' => TRUE]],
      ['field_email_address', 'email', ['half' => TRUE]],
      ['field_phone_number', 'tel', ['half' => TRUE]],
      ['field_mailing_address|country_code', 'select', ['half' => TRUE, 'options' => [
        ['value' => 'US', 'label' => 'United States'],
        ['value' => 'CA', 'label' => 'Canada'],
      ]]],
      ['field_gender', 'select', ['half' => TRUE]],
      ['field_mailing_address|address_line1', 'text', []],
      ['field_mailing_address|locality', 'text', ['half' => TRUE]],
      ['field_mailing_address|administrative_area', 'text', ['half' => TRUE]],
      ['field_mailing_address|postal_code', 'text', ['half' => TRUE]],
      ['field_date_of_birth', 'date', ['half' => TRUE]],
      ['field_occupation', 'text', ['half' => TRUE]],
      ['field_marital_status', 'select', ['half' => TRUE]],
      ['field_number_of_children', 'number', ['half' => TRUE, 'suffix' => 'children']],
    ],
  ],
  [
    'id' => 'contact',
    'title' => 'Contact & Motivation',
    'subtitle' => 'Who should we reach in an emergency, and why now?',
    'fields' => [
      ['field_emergency_contact_name', 'text', ['half' => TRUE, 'required' => TRUE]],
      ['field_emergency_contact_phone', 'tel', ['half' => TRUE, 'required' => TRUE]],
      ['field_motivation', 'textarea', []],
      ['field_programs', 'repeatable', ['placeholder' => 'e.g. Keto, Weight Watchers...']],
      ['field_program_start_date', 'date', []],
    ],
  ],
  [
    'id' => 'diet',
    'title' => 'Diet & Body',
    'subtitle' => 'A few numbers to set your starting point.',
    'fields' => [
      ['field_daily_activity_level', 'select', []],
      ['field_weight', 'number', ['half' => TRUE, 'suffix' => 'lbs']],
      ['field_goal_weight', 'number', ['half' => TRUE, 'suffix' => 'lbs']],
      ['field_height', 'number', ['half' => TRUE, 'suffix' => 'inches']],
    ],
  ],
  [
    'id' => 'medical',
    'title' => 'Medical Screening',
    'subtitle' => 'Standard health questions required before starting the program.',
    'fields' => [
      ['field_medical_eligibility', 'radio', []],
      ['field_medical_eligibility_choice', 'checkbox-grid', []],
      ['field_history_eating_disorder', 'checkbox-grid', []],
      ['field_history_bariatric_surgery', 'checkbox-grid', []],
      ['field_surgery_weight_loss', 'number', ['half' => TRUE, 'suffix' => 'lbs']],
      ['field_surgery_weight_gain', 'number', ['half' => TRUE, 'suffix' => 'lbs']],
      ['field_gall_bladder', 'radio', []],
      ['field_diabetes', 'radio', []],
      ['field_diabetes_diagnosis_year', 'number', ['half' => TRUE]],
      ['field_diabetes_medications', 'text', ['half' => TRUE]],
      ['field_high_blood_pressure', 'radio', []],
      ['field_bp_diagnosis_year', 'number', ['half' => TRUE]],
      ['field_blood_pressure_medications', 'text', ['half' => TRUE]],
      ['field_thyroid_condition', 'radio', []],
      ['field_thyroid_diagnosis_year', 'number', ['half' => TRUE]],
      ['field_thyroid_medications', 'text', ['half' => TRUE]],
      ['field_high_cholesterol', 'radio', []],
      ['field_cholesterol_diagnosis_year', 'number', ['half' => TRUE]],
      ['field_cholesterol_medications', 'text', ['half' => TRUE]],
      ['field_medical_symptoms', 'checkbox-grid', []],
    ],
  ],
  [
    'id' => 'health',
    'title' => 'Health & Lifestyle',
    'subtitle' => 'Help us understand the fuller picture.',
    'fields' => [
      ['field_other_symptoms', 'repeatable', []],
      ['field_medications', 'repeatable', []],
      ['field_health_challenge', 'textarea', []],
      ['field_emotional_eater', 'radio', []],
      ['field_cravings', 'repeatable', []],
      ['field_stressors|first', 'text', ['half' => TRUE, 'partLabel' => 'My greatest stressor is']],
      ['field_stressors|second', 'text', ['half' => TRUE, 'partLabel' => 'My second greatest stressor is']],
      ['field_emotional_trauma_age', 'number', ['placeholder' => 'e.g. 14']],
      ['field_emotional_trauma', 'repeatable', []],
    ],
  ],
  [
    'id' => 'consent',
    'title' => 'Consent & Signature',
    'subtitle' => 'Last step — please review and confirm.',
    'fields' => [
      ['field_agreement', 'static', []],
      ['field_program_agreement', 'static', []],
      ['field_media_release_agreement', 'static', []],
      ['field_consent_consumption', 'consent', []],
      ['field_media_release_disagree', 'checkbox', ['label' => 'I do not consent to the media release']],
      ['field_consent', 'text', ['required' => TRUE, 'placeholder' => 'Type your full legal name to sign']],
    ],
  ],
];

// Human labels for address sub-components, which have no field label of their own.
$part_labels = [
  'country_code' => 'Country',
  'address_line1' => 'Street Address',
  'locality' => 'City',
  'administrative_area' => 'State',
  'postal_code' => 'Zip Code',
];

// Build the blueprint.
$steps = [];
$placed = [];
$missing = [];

foreach ($design as $step) {
  $out_fields = [];
  foreach ($step['fields'] as [$target, $widget, $flags]) {
    [$field_name, $part] = array_pad(explode('|', $target, 2), 2, NULL);
    $spec = $intake_field($field_name);

    if (!empty($spec['missing'])) {
      $missing[] = $target;
      continue;
    }

    $entry = [
      'name' => $part ? $field_name . '[' . $part . ']' : $field_name,
      'drupalField' => $field_name,
      'part' => $part,
      'widget' => $widget,
      'label' => $flags['partLabel'] ?? $part_labels[$part] ?? ($part ? ucfirst(str_replace('_', ' ', $part)) : $spec['label']),
      'required' => $flags['required'] ?? $spec['required'],
      'half' => $flags['half'] ?? FALSE,
      'options' => $flags['options'] ?? $spec['options'],
      'default' => $spec['default'],
      'description' => $flags['description'] ?? $spec['description'],
      'placeholder' => $flags['placeholder'] ?? $spec['placeholder'],
      'drupalType' => $spec['drupalType'],
    ];
    if (isset($spec['min'])) {
      $entry['min'] = $spec['min'];
    }
    if (isset($spec['max'])) {
      $entry['max'] = $spec['max'];
    }
    if (isset($spec['maxLength'])) {
      $entry['maxLength'] = $spec['maxLength'];
    }
    if (isset($flags['suffix'])) {
      $entry['suffix'] = $flags['suffix'];
    }
    if ($widget === 'static') {
      $legal = $resolve_legal_text($spec, $field_name);
      $entry['text'] = trim($legal['text']);
      $entry['textSource'] = $legal['source'];
      // Tokens must be resolved server-side from the token-derived clinic, never
      // from request input: [current-page:query:*] is attacker-controlled.
      $entry['unresolvedTokens'] = array_values(array_unique(array_filter(
        preg_match_all('/\[([a-z-]+:[^\]]+)\]/', $entry['text'], $m) ? $m[1] : []
      )));
    }
    if (isset($conditions[$field_name])) {
      $entry['visibleWhen'] = $conditions[$field_name];
    }

    // Repeatable UI, joined back into the single-value field on submit.
    if ($widget === 'repeatable') {
      $entry['repeatable'] = TRUE;
      $entry['submitTransform'] = 'joinNewline';
    }
    // Two design inputs writing into one field. Address parts are excluded:
    // they map to distinct sub-fields and must not be concatenated.
    if ($part && $spec['drupalType'] !== 'address') {
      $entry['submitTransform'] = 'joinNewline';
    }

    $out_fields[] = $entry;
    $placed[$field_name] = TRUE;
  }

  $steps[] = [
    'id' => $step['id'],
    'title' => $step['title'],
    'subtitle' => $step['subtitle'],
    'fields' => $out_fields,
  ];
}

// Coverage: every displayable field must appear in the blueprint.
$unplaced = [];
foreach ($defs as $name => $def) {
  if (in_array($name, $server_owned, TRUE) || isset($placed[$name])) {
    continue;
  }
  if (in_array($name, $hidden, TRUE)) {
    continue;
  }
  $unplaced[] = $name;
}

$blueprint = [
  'form' => $form_id,
  'generated' => gmdate('c'),
  'source' => 'ChiroThin — Patient Intake (Redesign).html',
  'fieldCount' => count($placed),
  'steps' => $steps,
];

$target_dir = __DIR__ . '/../docs/headless';
if (!is_dir($target_dir)) {
  \Drupal::service('file_system')->mkdir($target_dir, 0755, TRUE);
}
file_put_contents(
  $target_dir . '/intake-blueprint.json',
  json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

print "wrote docs/headless/intake-blueprint.json\n";
printf("steps: %d, fields placed: %d\n\n", count($steps), count($placed));

print "COVERAGE\n";
printf("  placed in blueprint : %d\n", count($placed));
printf("  unplaced (would be LOST): %s\n", $unplaced ? implode(', ', $unplaced) : 'none');
printf("  in design but not in Drupal: %s\n", $missing ? implode(', ', $missing) : 'none');
printf("  #states drift vs custom_module.module: %s\n", $condition_drift ? implode(', ', $condition_drift) : 'none');

$empty_legal = [];
$token_report = [];
foreach ($steps[count($steps) - 1]['fields'] as $f) {
  if ($f['widget'] === 'static') {
    printf(
      "  %-32s text=%5d chars from %s%s\n",
      $f['drupalField'],
      strlen($f['text']),
      $f['textSource'] ?? 'NONE',
      $f['unresolvedTokens'] ? '  tokens=' . implode(',', $f['unresolvedTokens']) : ''
    );
    if (empty($f['text'])) {
      $empty_legal[] = $f['drupalField'];
    }
    if (!empty($f['unresolvedTokens'])) {
      $token_report[$f['drupalField']] = $f['unresolvedTokens'];
    }
  }
}
printf("  legal text missing: %s\n", $empty_legal ? implode(', ', $empty_legal) : 'none');
if ($token_report) {
  print "\n  TOKENS REQUIRING SERVER-SIDE RESOLUTION (must come from the invite token):\n";
  foreach ($token_report as $field => $tokens) {
    foreach ($tokens as $t) {
      print sprintf("    %-30s %s\n", $field, $t);
    }
  }
}
