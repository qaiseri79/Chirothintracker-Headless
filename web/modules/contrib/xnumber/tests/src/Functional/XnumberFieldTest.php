<?php

namespace Drupal\Tests\xnumber\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\xnumber\Utility\Xnumber as Numeric;

/**
 * Tests the creation of xnumber module numeric fields.
 *
 * @group field
 */
class XnumberFieldTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'entity_test',
    'field_ui',
    'xnumber',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'view test entity',
      'administer entity_test content',
      'administer content types',
      'administer node fields',
      'administer node display',
      'bypass node access',
      'administer entity_test fields',
    ]));
  }

  /**
   * Tests xinteger field.
   */
  public function testNumberIntegerField() {
    // Test all sizes signed / unsigned with all the default settings.
    foreach (Numeric::getStorageMaxMin() as $size => $values) {
      foreach ([FALSE, TRUE] as $unsigned) {
        $max = $unsigned ? $values['unsigned'] : $values['signed']['max'];
        $min = $unsigned ? 0 : $values['signed']['min'];
        $field = $this->createNumberField('xinteger', [
          'unsigned' => $unsigned,
          'size' => $size,
        ]);
        // Check the storage schema.
        $schema = $field->getFieldStorageDefinition()->getSchema();
        $expected = [
          'columns' => [
            'value' => [
              'type' => 'int',
              'unsigned' => $unsigned,
              'size' => $size,
            ],
          ],
          'unique keys' => [],
          'indexes' => [],
          'foreign keys' => [],
        ];
        $this->assertEquals($expected, $schema);
        // Some random value within min =><= max range.
        $widget_settings = $this->saveNumberFormDisplaySettings($field);
        $widget_settings['value'] = mt_rand($min, $max);
        $this->displaySubmitAssertForm($widget_settings);
        $widget_settings['value'] = $widget_settings['max'] + 1;
        $this->displaySubmitAssertValueHigherThanMax($widget_settings);
        $widget_settings['value'] = $widget_settings['min'] - 1;
        $this->displaySubmitAssertValueLowerThanMin($widget_settings);
      }
    }

    // Test default 'normal' size signed with specific field settings.
    $settings = [
      'step' => '2222',
      'placeholder' => 'Test My Placeholder',
      'prefix' => 'Test My Prefix',
      'suffix' => 'Test My Suffix',
    ];
    $field = $this->createNumberField('xinteger', [], $settings);
    $field->setSetting('default_value', '3804');
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Submit the default_value.
    $this->displaySubmitAssertForm($widget_settings);
    // Double check for the field attributes above.
    $this->assertNumberFieldAttributes($widget_settings);
    // Valid entries.
    foreach (['-2147481426', '2147482368'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['-2147481425', '2147482367'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    // Test wrong number type.
    $widget_settings['value'] = $widget_settings['step'] + 1.1;
    $this->displaySubmitAssertInvalidNumber($widget_settings);
    $widget_settings['value'] = '20-40';
    $this->displaySubmitAssertNotNumber($widget_settings);
    // Test prefix, suffix and content.
    $this->displaySubmitAssertPrefixSuffixContent($field);
  }

  /**
   * Tests xdecimal field.
   */
  public function testNumberDecimalField() {
    // Test unsafe precision signed.
    $field = $this->createNumberField('xdecimal', [
      'precision' => 16,
      'scale' => 5,
    ], [
      'step' => '1.23456',
      'min' => '-1999999994.13758',
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Valid entries.
    foreach (['-1147483641.63518', '2147483711.17954'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['-1147483641.63517', '2147483711.17953'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    $widget_settings['value'] = '1000000000000';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-1999999994.13759';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test unsafe precision unsigned.
    $field = $this->createNumberField('xdecimal', [
      'unsigned' => TRUE,
      'precision' => 16,
      'scale' => 5,
    ], [
      'step' => '1.23456',
      'min' => '1147483644.94656',
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Valid entries.
    foreach (['1234567899.94944', '1947483648.37056'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['1234567899.94943', '1947483648.37055'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    $widget_settings['value'] = '1000000000000';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-0.00001';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test maximum scale unsigned.
    $field = $this->createNumberField('xdecimal', [
      'unsigned' => TRUE,
      'precision' => 14,
      'scale' => 10,
    ], [
      'step' => '0.1234567891',
      'min' => '1.1111111019',
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Valid entries.
    foreach (['1.234567891', '9876.0493008436'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['1.234567892', '9876.0493008437'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    $widget_settings['value'] = '100000';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '1.1111111018';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test maximum scale signed.
    $field = $this->createNumberField('xdecimal', [
      'precision' => 14,
      'scale' => 10,
    ], [
      'step' => '0.1234567891',
      'min' => '-2133.9505084035',
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Valid entries.
    foreach (['-2133.7035948253', '3133.8272257044'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['-2133.7035948252', '3133.8272257045'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    $widget_settings['value'] = '100000';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-2133.9505084036';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test mimimum precision / scale signed.
    $field = $this->createNumberField('xdecimal', [
      'precision' => 2,
      'scale' => 1,
    ], [
      'step' => '1.2',
      'min' => '-2.4',
      'max' => '9.2',
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Valid entries.
    foreach (['-1.2', '7.2'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['-1.3', '7.3'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    $widget_settings['value'] = '9.3';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-2.5';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test mimimum precision / scale unsigned.
    $field = $this->createNumberField('xdecimal', [
      'unsigned' => TRUE,
      'precision' => 2,
      'scale' => 1,
    ], [
      'step' => '1.2',
      'min' => '1.2',
      'max' => '9.2',
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Valid entries.
    foreach (['1.2', '7.2'] as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    // Wrong entries.
    foreach (['1.3', '7.3'] as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }
    $widget_settings['value'] = '9.3';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '1.1';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test step scale lower than the field scale unsigned.
    // Valid values are calculated using the default '0.01' step and
    // '0' min and '9999.9999' max. Client validation may fail for decimal part
    // not ending with 99 though programmatically any decimal value will be
    // validated and saved in a database up to 2 meaningful digits after the
    // decimal point (the current step scale).
    $field = $this->createNumberField('xdecimal', [
      'unsigned' => TRUE,
      'precision' => 8,
      'scale' => 4,
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    $valid_entries = [
      '0',
      '0.01',
      '1',
      '1.1',
      '1.01',
      '9999.99',
      '9999.999',
      '9999.9999',
    ];
    foreach ($valid_entries as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    $widget_settings['value'] = '10000';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-0.01';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test step scale lower than the field scale signed.
    $field = $this->createNumberField('xdecimal', [
      'precision' => 8,
      'scale' => 4,
    ]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    $valid_entries = [
      '-9999.9999',
      '-9999.999',
      '-9999.99',
      '-1.01',
      '-1.1',
      '-1',
    ] + $valid_entries;
    foreach ($valid_entries as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    $widget_settings['value'] = '10000';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-10000';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test default_value, placeholder, prefix and suffix field settings.
    $field = $this->createNumberField('xdecimal', [
      'precision' => 8,
      'scale' => 4,
    ]);
    $settings = [
      'default_value' => '2345.6789',
      'placeholder' => 'Test My Placeholder',
      'prefix' => 'Test My Prefix',
      'suffix' => 'Test My Suffix',
    ];
    foreach ($settings as $name => $setting) {
      $field->setSetting($name, $setting);
    }
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Submit default value.
    $this->displaySubmitAssertForm($widget_settings);
    // Double check for the field attributes above.
    $this->assertNumberFieldAttributes($widget_settings);
    // Try to create entries with more than one decimal separator and with minus
    // sign not in the first position.
    $wrong_entries = [
      '3.14.159',
      '0..45469',
      '..4589',
      '6.459.52',
      '6.3..25',
      '3-3',
      '4-',
      '1.3-',
      '1.2-4',
      '-10-10',
    ];
    foreach ($wrong_entries as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertNotNumber($widget_settings);
    }
    // Test prefix, suffix and content.
    $this->displaySubmitAssertPrefixSuffixContent($field);
  }

  /**
   * Test xfloat field.
   */
  public function testNumberFloatField() {
    // Test default 'any' step; ;unsigned.
    $field = $this->createNumberField('xfloat', ['unsigned' => TRUE]);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Because there is no step attribute so any reasonable entries are valid.
    $valid_entries = [
      '0',
      '987654321',
      '987654321.12',
      '987654321.12345',
    ];
    foreach ($valid_entries as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    $widget_settings['value'] = '-0.00001';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    // Test specific field settings; signed.
    $field = $this->createNumberField('xfloat');
    $settings = [
      'step' => '4.56789',
      'min' => '-987654321.02172',
      'max' => '987654321.02172',
      'default_value' => '-4.56789',
      'placeholder' => 'Test My Placeholder',
      'prefix' => 'Test My Prefix',
      'suffix' => 'Test My Suffix',
    ];
    foreach ($settings as $name => $setting) {
      $field->setSetting($name, $setting);
    }
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    // Submit default value.
    $this->displaySubmitAssertForm($widget_settings);
    // Double check for the field attributes above.
    $this->assertNumberFieldAttributes($widget_settings);
    $widget_settings['value'] = '987654321.02173';
    $this->displaySubmitAssertValueHigherThanMax($widget_settings);
    $widget_settings['value'] = '-987654321.02173';
    $this->displaySubmitAssertValueLowerThanMin($widget_settings);

    $valid_entries = [
      '-987654321.02172',
      '-123456787.84377',
      '-56783.44059',
      '-9.13578',
      '0',
      '9.13578',
      '56783.44059',
      '123456787.84377',
      '987654321.02172',
    ];
    foreach ($valid_entries as $valid_entry) {
      $widget_settings['value'] = $valid_entry;
      $this->displaySubmitAssertForm($widget_settings);
    }
    $wrong_entries = [
      '-123456787.84376',
      '-56783.44058',
      '-9.13577',
      '0.1',
      '9.13577',
      '56783.44058',
      '123456787.84376',
    ];
    foreach ($wrong_entries as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertInvalidNumber($widget_settings);
    }

    // Try to create entries with more than one decimal separator and with minus
    // sign not in the first position.
    $wrong_entries = [
      '3.14.159',
      '0..45469',
      '..4589',
      '6.459.52',
      '6.3..25',
      '3-3',
      '4-',
      '1.3-',
      '1.2-4',
      '-10-10',
    ];
    foreach ($wrong_entries as $wrong_entry) {
      $widget_settings['value'] = $wrong_entry;
      $this->displaySubmitAssertNotNumber($widget_settings);
    }
    // Test prefix, suffix and content.
    // By default the scale is 2 but current step has 5 fractional digits.
    $field->setSetting('scale', 5);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    $this->displaySubmitAssertPrefixSuffixContent($field);
  }

  /**
   * Tests xnumber base fields.
   */
  public function testXnumberBaseFields() {
    \Drupal::service('module_installer')->install(['xnumber_test']);
    $fields = \Drupal::service('entity_field.manager')
      ->getBaseFieldDefinitions('entity_test', 'entity_test');

    foreach (['xinteger_base', 'xdecimal_base', 'xfloat_base'] as $field_name) {
      $field = $fields[$field_name];
      $widget_settings = $this->saveNumberFormDisplaySettings($field);
      // Submit with all the default settings defined.
      $this->displaySubmitAssertForm($widget_settings);
    }
  }

  /**
   * Creates a number field of the given type with optional settings.
   */
  public function createNumberField(string $type, array $config_settings = [], array $field_settings = []) {
    $field_name = mb_strtolower($this->randomMachineName());
    $config = [
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'type' => $type,
    ];
    if (!empty($config_settings)) {
      $config['settings'] = $config_settings;
    }
    $storage = FieldStorageConfig::create($config);
    $storage->save();

    $field = [
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ];
    if (!empty($field_settings)) {
      $field['settings'] = $field_settings;
    }
    $field = FieldConfig::create($field);
    $field->save();

    return $field;
  }

  /**
   * Helper method to save a number field attributes for the form display.
   */
  public function saveNumberFormDisplaySettings(FieldConfigInterface|FieldStorageDefinitionInterface $field, bool $generate_settings = FALSE) {
    $field_name = $field->getName();
    $config = [
      'type' => 'xnumber',
      'settings' => $field->getSettings(),
    ];
    // Set the default argument to TRUE to test random field settings.
    if ($generate_settings) {
      $settings = $field->getItemDefinition()->getClass()::generateSampleValue($field);
      $field->setSettings($settings + $field->getSettings())->save();
      $config['settings'] = $settings;
    }
    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $widget = $display_repository->getFormDisplay('entity_test', 'entity_test', 'default');
    $widget->setComponent($field_name, $config)->save();
    $types = [
      'xinteger' => 'number_integer',
      'xdecimal' => 'number_decimal',
      'xfloat' => 'number_decimal',
    ];

    $display_repository->getViewDisplay('entity_test', 'entity_test')
      ->setComponent($field_name, [
        'type' => $types[$field->getType()],
        'settings' => [
          'prefix_suffix' => $field->getSetting('prefix_suffix') ?? TRUE,
          'scale' => $field->getSetting('scale') ?? 2,
        ],
      ])
      ->save();
    $widget_settings = $widget->getRenderer($field_name)->getFormDisplayModeSettings();
    // The entries below will be passed to assert helper methods.
    $widget_settings['field_name'] = $field_name;
    $widget_settings['default_value'] = is_numeric($widget_settings['default_value']) ?
      $widget_settings['default_value'] : '';
    $widget_settings['value'] = $widget_settings['value'] ?? $widget_settings['default_value'];

    return $widget_settings;
  }

  /**
   * Helper method to display and submit an invalid number.
   */
  private function displaySubmitAssertNotNumber(array $settings) {
    extract($settings);
    $this->drupalGet('entity_test/add');
    $edit = [
      "{$field_name}[0][value]" => $value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("{$field_name} must be a number.");
  }

  /**
   * Helper method to display and submit an invalid number.
   */
  private function displaySubmitAssertInvalidNumber(array $settings) {
    extract($settings);
    $this->drupalGet('entity_test/add');
    $edit = [
      "{$field_name}[0][value]" => $value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("{$field_name} is not a valid number.");
  }

  /**
   * Helper method to display and submit a value lower than min.
   */
  public function displaySubmitAssertValueLowerThanMin(array $settings) {
    extract($settings);
    $this->drupalGet('entity_test/add');
    $edit = [
      "{$field_name}[0][value]" => $value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("{$field_name} must be higher than or equal to {$min}.");
  }

  /**
   * Helper method to display and submit a value higher than max.
   */
  public function displaySubmitAssertValueHigherThanMax(array $settings) {
    extract($settings);
    $this->drupalGet('entity_test/add');
    $edit = [
      "{$field_name}[0][value]" => $value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("{$field_name} must be lower than or equal to {$max}.");
  }

  /**
   * Helper method to assert prefix, suffix and content.
   */
  public function displaySubmitAssertPrefixSuffixContent(FieldConfigInterface|FieldStorageDefinitionInterface $field) {
    $field->setSetting('prefix_suffix', FALSE);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    extract($widget_settings);

    $valid_entry = $step;
    $widget_settings['value'] = $valid_entry;
    $this->displaySubmitAssertForm($widget_settings);
    preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
    $this->drupalGet('entity_test/' . $match[1]);
    // Check the field value is being displayed.
    $this->assertSession()->elementTextContains('xpath', '//div', $valid_entry);
    // Check prefix & suffix are not being displayed.
    if ($prefix !== '' && $suffix !== '') {
      $this->assertSession()->elementTextNotContains('xpath', '//div', $prefix);
      $this->assertSession()->elementTextNotContains('xpath', '//div', $suffix);
    }
    // Update settings.
    $field->setSetting('prefix_suffix', TRUE);
    $widget_settings = $this->saveNumberFormDisplaySettings($field);
    $this->drupalGet('entity_test/' . $match[1]);
    $this->assertSession()->elementTextContains('xpath', '//div', $valid_entry);
    // Verify that the "content" attribute has been set to the value of the
    // field, and prefix & suffix are being displayed.
    if ($field->getType() == 'xinteger' && ($prefix !== '' && $suffix !== '')) {
      $this->assertSession()
        ->elementTextContains('xpath', '//div[@content="' . $valid_entry . '"]', $prefix . $valid_entry . $suffix);
    }
  }

  /**
   * Helper method to display and submit a number field.
   */
  public function displaySubmitAssertForm(array $settings) {
    extract($settings);
    // Display and assert creation form.
    $this->drupalGet('entity_test/add');
    $this->assertNumberFieldAttributes($settings);

    // Add the $value and submit the field either with value or default value.
    $edit = [
      "{$field_name}[0][value]" => $value,
    ];
    $this->submitForm($edit, 'Save');

    preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
    $id = $match[1];
    $this->assertSession()->pageTextContains('entity_test ' . $id . ' has been created.');
    // Test programmatically saving a sring number value casted to a respective
    // number type.
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->load($id);
    $field_type = $entity->get($field_name)
      ->getItemDefinition()->getFieldDefinition()->getType();
    if ($field_type == 'xinteger') {
      $value = intval($value);
    }
    else {
      $value = floatval($value);
    }
    $entity->set($field_name, $value);
    $entity->save();
    $actual_value = $entity->get($field_name)->value;
    $message = "The expected {$value} is not the same as the actual {$actual_value}";
    $this->assertTrue($value === $actual_value, $message);
  }

  /**
   * Helper method to assert a number field attributes.
   */
  public function assertNumberFieldAttributes(array $settings) {
    extract($settings);
    $selector = str_replace('_', '-', "//input[@id='edit-{$field_name}-0-value']");
    $this->assertSession()
      ->fieldValueEquals("{$field_name}[0][value]", $default_value);
    if (!empty($min)) {
      $this->assertSession()
        ->elementAttributeContains('xpath', $selector, 'min', $min);
    }
    if (!empty($max)) {
      $this->assertSession()
        ->elementAttributeContains('xpath', $selector, 'max', $max);
    }
    $this->assertSession()
      ->elementAttributeContains('xpath', $selector, 'step', $step);
    if (!empty($placeholder)) {
      $this->assertSession()
        ->elementAttributeContains('xpath', $selector, 'placeholder', $placeholder);
    }
    if (!empty($prefix)) {
      $this->assertSession()->pageTextContains($prefix);
    }
    if (!empty($suffix)) {
      $this->assertSession()->pageTextContains($suffix);
    }
  }

  /**
   * Tests decimal field using random generated values.
   *
   * For debugging purposes change prefix "run" in the method name for "test".
   */
  public function runRandomNumberDecimalFieldTest() {
    $storage_settings = [
      [16, 5, '123.45678'],
      [14, 10, '1234.9876543219'],
      [2, 1, '0.9'],
      [8, 4, '1234.5678'],
    ];

    foreach ($storage_settings as $setting) {
      foreach ([FALSE, TRUE] as $unsigned) {
        $field = $this->createNumberField('xdecimal', [
          'unsigned' => $unsigned,
          'precision' => $setting[0],
          'scale' => $setting[1],
        ],
        ['step' => $setting[2]]
        );
        $widget_settings = $this->saveNumberFormDisplaySettings($field, TRUE);
        $this->displaySubmitAssertForm($widget_settings);
        extract($widget_settings);
        $step_scale = Numeric::getDecimalDigits($setting[2]);
        $widget_settings['value'] = bcadd($max, $step, $step_scale);
        $this->displaySubmitAssertValueHigherThanMax($widget_settings);
        $widget_settings['value'] = bcsub($min, $step, $step_scale);
        $this->displaySubmitAssertValueLowerThanMin($widget_settings);
      }
    }
  }

}
