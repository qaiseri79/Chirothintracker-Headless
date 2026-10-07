<?php

namespace Drupal\Tests\time_field\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the time range widget.
 *
 * @group time_field
 */
class TimeRangeWidgetTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'time_field',
    'field_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The content type to be used in this test.
   *
   * @var string
   */
  protected $contentType = 'test_content';

  /**
   * The field name to be used in this test.
   *
   * @var string
   */
  protected $fieldName = 'field_test_time_range';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType([
      'type' => $this->contentType,
      'name' => 'Test content',
    ]);

    // Add a time range field to the test content type.
    $fieldStorage = FieldStorageConfig::create([
      'field_name' => $this->fieldName,
      'entity_type' => 'node',
      'type' => 'time_range',
      'settings' => [],
    ]);
    $fieldStorage->save();
    $field = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => $this->contentType,
      'required' => TRUE,
    ]);
    $field->save();

    // Configure the widget and formatter to make sure the field is shown.
    $form = \Drupal::configFactory()
      ->getEditable('core.entity_form_display.node.' . $this->contentType . '.default');
    $form->set('content.' . $this->fieldName . '.type', 'time_range_widget')
      ->set('content.' . $this->fieldName . '.settings', [
        'enabled' => FALSE,
        'step' => 5,
      ])
      ->set('content.' . $this->fieldName . '.third_party_settings', [])
      ->set('content.' . $this->fieldName . '.weight', 0)
      ->save();
    $view = \Drupal::configFactory()
      ->getEditable('core.entity_view_display.node.' . $this->contentType . '.default');
    $view->set('content.' . $this->fieldName . '.type', 'time_range_formatter')
      ->set('content.' . $this->fieldName . '.settings', [
        'time_format' => 'h:i a',
        'timerange_format' => 'start ~ end',
      ])
      ->set('content.' . $this->fieldName . '.third_party_settings', [])
      ->set('content.' . $this->fieldName . '.weight', 0)
      ->set('content.' . $this->fieldName . '.label', 'hidden')
      ->save();

    // Create test user for creating test nodes.
    $this->drupalLogin($this->drupalCreateUser([
      'create ' . $this->contentType . ' content',
      'administer node fields',
    ]));
  }

  /**
   * Tests the basic time range widget functionality.
   */
  public function testTimeRangeWidgetBasic() {
    $this->drupalGet('node/add/' . $this->contentType);
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'title[0][value]' => 'Test node',
      $this->fieldName . '[0][from]' => '09:15',
      $this->fieldName . '[0][to]' => '17:45',
    ], 'Save');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->addressMatches('/^\/node\/\d$/');
    $this->assertSession()->pageTextContains('09:15 am ~ 05:45 pm');
  }

  /**
   * Tests that midnight values are preserved for the time range widget.
   *
   * @param string $from
   *   The start time in 24h format.
   * @param string $to
   *   The end time in 24h format.
   * @param string $expected
   *   The expected formatted output.
   *
   * @dataProvider midnightValuesProvider
   */
  public function testTimeRangeWidgetMidnightValues($from, $to, $expected) {
    $this->drupalGet('node/add/' . $this->contentType);
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'title[0][value]' => 'Test node',
      $this->fieldName . '[0][from]' => $from,
      $this->fieldName . '[0][to]' => $to,
    ], 'Save');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->addressMatches('/^\/node\/\d$/');
    $this->assertSession()->pageTextContains($expected);
  }

  /**
   * Data provider for testTimeRangeWidgetMidnightValues().
   *
   * @return array
   *   An array of test data.
   */
  public static function midnightValuesProvider() {
    return [
      ['00:00', '01:00', '12:00 am ~ 01:00 am'],
      ['00:30', '00:00', '12:30 am ~ 12:00 am'],
      ['00:00', '00:00', '12:00 am ~ 12:00 am'],
    ];
  }

}
