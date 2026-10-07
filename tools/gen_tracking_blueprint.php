<?php

/**
 * Generate the tracking-blueprint.json with taxonomy options embedded.
 * Run: drush php:script /app/tools/gen_tracking_blueprint.php > /home/danielsudenfield/chirothintracker_react/frontend/src/lib/tracking/tracking-blueprint.json
 */

$out = function ($line) { print $line . PHP_EOL; };

// Fetch taxonomy options
$vocabularies = [
  'protein' => 'Protein',
  'fruit' => 'Fruit',
  'vegetables' => 'Vegetables',
  'vegetables_free_' => 'Vegetables (Free)',
  'bread' => 'Bread',
  'patient_flags' => 'Patient Flags',
  'chiropractor_flags' => 'Chiropractor Flags',
];

$taxonomy_options = [];
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
  $taxonomy_options[$vid] = $options;
}

$blueprint = [
  'form' => 'tracking_weight',
  'generated' => date('c'),
  'source' => 'drupal',
  'fieldCount' => 45,
  'steps' => [
    [
      'id' => 'basics',
      'title' => 'Daily Check-in',
      'subtitle' => 'Start with today\'s weight and how you\'re feeling',
      'fields' => [
        [
          'name' => 'field_date',
          'drupalField' => 'field_date',
          'part' => null,
          'widget' => 'date',
          'label' => 'Date',
          'required' => true,
          'half' => true,
          'options' => [],
          'default' => null,
          'description' => 'The date for this log entry',
          'placeholder' => 'mm/dd/yyyy',
          'drupalType' => 'datetime',
          'maxLength' => 10,
        ],
        [
          'name' => 'field_weight',
          'drupalField' => 'field_weight',
          'part' => null,
          'widget' => 'number',
          'label' => 'Today\'s Weight',
          'required' => true,
          'half' => true,
          'options' => [],
          'default' => null,
          'description' => 'Your weight this morning in pounds',
          'placeholder' => 'e.g. 185.5',
          'drupalType' => 'decimal',
          'min' => 50,
          'max' => 500,
          'suffix' => 'lbs',
        ],
        [
          'name' => 'field_water_intake',
          'drupalField' => 'field_water_intake',
          'part' => null,
          'widget' => 'number',
          'label' => 'Water Intake',
          'required' => true,
          'half' => true,
          'options' => [],
          'default' => null,
          'description' => 'Total ounces of water consumed yesterday',
          'placeholder' => 'e.g. 100',
          'drupalType' => 'decimal',
          'min' => 0,
          'max' => 200,
          'suffix' => 'oz',
        ],
        [
          'name' => 'field_grade',
          'drupalField' => 'field_grade',
          'part' => null,
          'widget' => 'select',
          'label' => 'Adherence Grade',
          'required' => true,
          'half' => true,
          'options' => [
            ['value' => '10', 'label' => '10 - Perfect'],
            ['value' => '9', 'label' => '9'],
            ['value' => '8', 'label' => '8'],
            ['value' => '7', 'label' => '7'],
            ['value' => '6', 'label' => '6'],
            ['value' => '5', 'label' => '5'],
            ['value' => '4', 'label' => '4'],
            ['value' => '3', 'label' => '3'],
            ['value' => '2', 'label' => '2'],
            ['value' => '1', 'label' => '1'],
          ],
          'default' => null,
          'description' => 'On a scale of 1-10, how well did you follow the plan yesterday?',
          'placeholder' => 'Select a grade',
          'drupalType' => 'list_integer',
        ],
        [
          'name' => 'field_mind_set_work',
          'drupalField' => 'field_mind_set_work',
          'part' => null,
          'widget' => 'checkbox',
          'label' => 'Completed Mindset Work',
          'required' => true,
          'half' => false,
          'options' => [],
          'default' => '0',
          'description' => 'Did you complete your mindset exercises yesterday?',
          'placeholder' => '',
          'drupalType' => 'boolean',
        ],
      ],
    ],
    [
      'id' => 'measurements',
      'title' => 'Body Measurements',
      'subtitle' => 'Track your inches lost (all optional)',
      'fields' => [
        ['name' => 'field_neck', 'drupalField' => 'field_neck', 'part' => null, 'widget' => 'number', 'label' => 'Neck', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Neck circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 8, 'max' => 24, 'suffix' => 'in'],
        ['name' => 'field_chest', 'drupalField' => 'field_chest', 'part' => null, 'widget' => 'number', 'label' => 'Chest', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Chest circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 28, 'max' => 60, 'suffix' => 'in'],
        ['name' => 'field_shoulders', 'drupalField' => 'field_shoulders', 'part' => null, 'widget' => 'number', 'label' => 'Shoulders', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Shoulders circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 30, 'max' => 60, 'suffix' => 'in'],
        ['name' => 'field_arm_left_bicep', 'drupalField' => 'field_arm_left_bicep', 'part' => null, 'widget' => 'number', 'label' => 'Left Bicep', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Left bicep circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 8, 'max' => 22, 'suffix' => 'in'],
        ['name' => 'field_arm_right_bicep', 'drupalField' => 'field_arm_right_bicep', 'part' => null, 'widget' => 'number', 'label' => 'Right Bicep', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Right bicep circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 8, 'max' => 22, 'suffix' => 'in'],
        ['name' => 'field_abdomen', 'drupalField' => 'field_abdomen', 'part' => null, 'widget' => 'number', 'label' => 'Abdomen', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Abdomen circumference at navel', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 20, 'max' => 60, 'suffix' => 'in'],
        ['name' => 'field_hips', 'drupalField' => 'field_hips', 'part' => null, 'widget' => 'number', 'label' => 'Hips', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Hips circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 28, 'max' => 60, 'suffix' => 'in'],
        ['name' => 'field_thigh_left', 'drupalField' => 'field_thigh_left', 'part' => null, 'widget' => 'number', 'label' => 'Left Thigh', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Left thigh circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 14, 'max' => 36, 'suffix' => 'in'],
        ['name' => 'field_thigh_right', 'drupalField' => 'field_thigh_right', 'part' => null, 'widget' => 'number', 'label' => 'Right Thigh', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Right thigh circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 14, 'max' => 36, 'suffix' => 'in'],
        ['name' => 'field_calf_left', 'drupalField' => 'field_calf_left', 'part' => null, 'widget' => 'number', 'label' => 'Left Calf', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Left calf circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 10, 'max' => 24, 'suffix' => 'in'],
        ['name' => 'field_calf_right', 'drupalField' => 'field_calf_right', 'part' => null, 'widget' => 'number', 'label' => 'Right Calf', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Right calf circumference', 'placeholder' => 'inches', 'drupalType' => 'decimal', 'min' => 10, 'max' => 24, 'suffix' => 'in'],
      ],
    ],
    [
      'id' => 'breakfast',
      'title' => 'Breakfast',
      'subtitle' => 'What you ate for breakfast',
      'fields' => [
        ['name' => 'field_breakfast_protein', 'drupalField' => 'field_breakfast_protein', 'part' => null, 'widget' => 'select', 'label' => 'Protein', 'required' => false, 'half' => true, 'options' => $taxonomy_options['protein'], 'default' => null, 'description' => 'Select protein option', 'placeholder' => 'Select protein', 'drupalType' => 'entity_reference'],
        ['name' => 'field_breakfast_fruit_sel', 'drupalField' => 'field_breakfast_fruit_sel', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Fruit', 'required' => false, 'half' => true, 'options' => $taxonomy_options['fruit'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_breakfast_other', 'drupalField' => 'field_breakfast_other', 'part' => null, 'widget' => 'text', 'label' => 'Other', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Any other breakfast items', 'placeholder' => 'e.g. oatmeal, toast', 'drupalType' => 'string', 'maxLength' => 255],
      ],
    ],
    [
      'id' => 'lunch',
      'title' => 'Lunch',
      'subtitle' => 'What you ate for lunch',
      'fields' => [
        ['name' => 'field_lunch_protein', 'drupalField' => 'field_lunch_protein', 'part' => null, 'widget' => 'select', 'label' => 'Protein', 'required' => false, 'half' => true, 'options' => $taxonomy_options['protein'], 'default' => null, 'description' => 'Select protein option', 'placeholder' => 'Select protein', 'drupalType' => 'entity_reference'],
        ['name' => 'field_lunch_fruit_sel', 'drupalField' => 'field_lunch_fruit_sel', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Fruit', 'required' => false, 'half' => true, 'options' => $taxonomy_options['fruit'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_lunch_vegetables', 'drupalField' => 'field_lunch_vegetables', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Vegetables (4oz)', 'required' => false, 'half' => true, 'options' => $taxonomy_options['vegetables'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_lunch_vegetables_free', 'drupalField' => 'field_lunch_vegetables_free', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Free Vegetables', 'required' => false, 'half' => true, 'options' => $taxonomy_options['vegetables_free_'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_lunch_bread', 'drupalField' => 'field_lunch_bread', 'part' => null, 'widget' => 'select', 'label' => 'Bread', 'required' => false, 'half' => true, 'options' => $taxonomy_options['bread'], 'default' => null, 'description' => 'Select bread option', 'placeholder' => 'Select bread', 'drupalType' => 'entity_reference'],
        ['name' => 'field_lunch_other', 'drupalField' => 'field_lunch_other', 'part' => null, 'widget' => 'text', 'label' => 'Other', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Any other lunch items', 'placeholder' => 'e.g. soup, salad', 'drupalType' => 'string', 'maxLength' => 255],
      ],
    ],
    [
      'id' => 'dinner',
      'title' => 'Dinner',
      'subtitle' => 'What you ate for dinner',
      'fields' => [
        ['name' => 'field_dinner_protein', 'drupalField' => 'field_dinner_protein', 'part' => null, 'widget' => 'select', 'label' => 'Protein', 'required' => false, 'half' => true, 'options' => $taxonomy_options['protein'], 'default' => null, 'description' => 'Select protein option', 'placeholder' => 'Select protein', 'drupalType' => 'entity_reference'],
        ['name' => 'field_dinner_fruit_sel', 'drupalField' => 'field_dinner_fruit_sel', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Fruit', 'required' => false, 'half' => true, 'options' => $taxonomy_options['fruit'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_dinner_vegetables', 'drupalField' => 'field_dinner_vegetables', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Vegetables (4oz)', 'required' => false, 'half' => true, 'options' => $taxonomy_options['vegetables'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_dinner_vegetables_free', 'drupalField' => 'field_dinner_vegetables_free', 'part' => null, 'widget' => 'checkbox-grid', 'label' => 'Free Vegetables', 'required' => false, 'half' => true, 'options' => $taxonomy_options['vegetables_free_'], 'default' => null, 'description' => 'Select all that apply', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_dinner_bread', 'drupalField' => 'field_dinner_bread', 'part' => null, 'widget' => 'select', 'label' => 'Bread', 'required' => false, 'half' => true, 'options' => $taxonomy_options['bread'], 'default' => null, 'description' => 'Select bread option', 'placeholder' => 'Select bread', 'drupalType' => 'entity_reference'],
        ['name' => 'field_dinner_other', 'drupalField' => 'field_dinner_other', 'part' => null, 'widget' => 'text', 'label' => 'Other', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Any other dinner items', 'placeholder' => 'e.g. dessert, snack', 'drupalType' => 'string', 'maxLength' => 255],
      ],
    ],
    [
      'id' => 'additional',
      'title' => 'Additional Notes',
      'subtitle' => 'Optional health markers and notes',
      'fields' => [
        ['name' => 'field_blood_pressure', 'drupalField' => 'field_blood_pressure', 'part' => null, 'widget' => 'text', 'label' => 'Blood Pressure', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'e.g. 120/80', 'placeholder' => '120/80', 'drupalType' => 'string', 'maxLength' => 20],
        ['name' => 'field_blood_sugar', 'drupalField' => 'field_blood_sugar', 'part' => null, 'widget' => 'number', 'label' => 'Morning Blood Sugar', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Fasting blood glucose', 'placeholder' => 'mg/dL', 'drupalType' => 'integer', 'min' => 50, 'max' => 400, 'suffix' => 'mg/dL'],
        ['name' => 'field_chiroburst_bend', 'drupalField' => 'field_chiroburst_bend', 'part' => null, 'widget' => 'checkbox', 'label' => 'Completed ChiroBurst Bend', 'required' => false, 'half' => false, 'options' => [], 'default' => '0', 'description' => 'Did you do your ChiroBurst exercises?', 'placeholder' => '', 'drupalType' => 'boolean'],
        ['name' => 'field_sleep_hours', 'drupalField' => 'field_sleep_hours', 'part' => null, 'widget' => 'number', 'label' => 'Sleep Hours', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Hours of sleep last night', 'placeholder' => 'e.g. 7.5', 'drupalType' => 'decimal', 'min' => 0, 'max' => 24, 'suffix' => 'hrs'],
        ['name' => 'field_flags', 'drupalField' => 'field_flags', 'part' => null, 'widget' => 'radio', 'label' => 'Patient Flags', 'required' => false, 'half' => false, 'options' => $taxonomy_options['patient_flags'], 'default' => null, 'description' => 'Select any flags', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_chiropractor_flags', 'drupalField' => 'field_chiropractor_flags', 'part' => null, 'widget' => 'radio', 'label' => 'Chiropractor Flags', 'required' => false, 'half' => false, 'options' => $taxonomy_options['chiropractor_flags'], 'default' => null, 'description' => 'Select any flags', 'placeholder' => '', 'drupalType' => 'entity_reference'],
        ['name' => 'field_notes', 'drupalField' => 'field_notes', 'part' => null, 'widget' => 'textarea', 'label' => 'Notes', 'required' => false, 'half' => false, 'options' => [], 'default' => null, 'description' => 'Any additional notes for your clinician', 'placeholder' => 'Optional notes...', 'drupalType' => 'string_long'],
        ['name' => 'field_other_food', 'drupalField' => 'field_other_food', 'part' => null, 'widget' => 'text', 'label' => 'Other Food', 'required' => false, 'half' => true, 'options' => [], 'default' => null, 'description' => 'Any other food not captured above', 'placeholder' => 'e.g. supplements, snacks', 'drupalType' => 'string', 'maxLength' => 255],
      ],
    ],
  ],
];

print json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);