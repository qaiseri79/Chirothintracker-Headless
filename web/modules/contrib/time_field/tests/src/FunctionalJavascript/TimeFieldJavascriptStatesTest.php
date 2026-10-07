<?php

namespace Drupal\Tests\time_field\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests the JavaScript States functionality of the Time Field module.
 *
 * @group time_field
 */
final class TimeFieldJavascriptStatesTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'time_field',
    'time_field_test',
  ];

  /**
   * The content type to be used in this test.
   *
   * @var string
   */
  protected $contentType = 'test_content';

  /**
   * The field names to be used in these tests.
   *
   * @var string
   */
  protected $fieldNames = [
    'field_test_time_trigger',
    'field_test_time_a',
    'field_test_time_b',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType([
      'type' => $this->contentType,
      'name' => 'Test content',
    ]);

    // Add time fields to test content type.
    foreach ($this->fieldNames as $fieldName) {
      $fieldStorage = FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'time',
        'settings' => [],
      ]);
      $fieldStorage->save();
      $field = FieldConfig::create([
        'field_storage' => $fieldStorage,
        'bundle' => $this->contentType,
        'required' => FALSE,
      ]);
      $field->save();

      // Configure the widget and formatter to make sure field is shown.
      $form = \Drupal::configFactory()
        ->getEditable('core.entity_form_display.node.' . $this->contentType . '.default');
      $form->set('content.' . $fieldName . '.type', 'time_widget')
        ->set('content.' . $fieldName . '.settings', [
          'enabled' => FALSE,
          'step' => 5,
        ])
        ->set('content.' . $fieldName . '.third_party_settings', [])
        ->set('content.' . $fieldName . '.weight', 0)
        ->save();
      $form = \Drupal::configFactory()
        ->getEditable('core.entity_view_display.node.' . $this->contentType . '.default');
      $form->set('content.' . $fieldName . '.type', 'time_formatter')
        ->set('content.' . $fieldName . '.settings', [
          'time_format' => 'h:i a',
        ])
        ->set('content.' . $fieldName . '.third_party_settings', [])
        ->set('content.' . $fieldName . '.weight', 0)
        ->set('content.' . $fieldName . '.label', 'hidden')
        ->save();
    }

    // Create test user for creating test nodes.
    $this->drupalLogin($this->drupalCreateUser([
      'create ' . $this->contentType . ' content',
    ]));
  }

  /**
   * Test time field as a States trigger.
   */
  public function testStatesTimeFieldAsTrigger(): void {
    $this->drupalGet('node/add/' . $this->contentType);
    $page = $this->getSession()->getPage();
    // Find time field trigger and dependent elements.
    $field_test_time_trigger = $page->findField('field_test_time_trigger[0][value]');
    $this->assertNotEmpty($field_test_time_trigger);
    $textfield_visible_when_time_empty = $page->findField('textfield_visible_when_time_empty');
    $this->assertNotEmpty($textfield_visible_when_time_empty);
    $textfield_visible_when_time_filled = $page->findField('textfield_visible_when_time_filled');
    $this->assertNotEmpty($textfield_visible_when_time_filled);
    $textfield_visible_when_time_value_empty = $page->findField('textfield_visible_when_time_value_empty');
    $this->assertNotEmpty($textfield_visible_when_time_value_empty);
    $textfield_visible_when_time_value_23_00 = $page->findField('textfield_visible_when_time_value_23_00');
    $this->assertNotEmpty($textfield_visible_when_time_value_23_00);

    // Assert visibility when time field is empty.
    $this->assertTrue($field_test_time_trigger->isVisible());
    $this->assertTrue($textfield_visible_when_time_empty->isVisible());
    $this->assertFalse($textfield_visible_when_time_filled->isVisible());
    $this->assertTrue($textfield_visible_when_time_value_empty->isVisible());
    $this->assertFalse($textfield_visible_when_time_value_23_00->isVisible());

    // Assert visibility when time field is set to 23:00.
    $field_test_time_trigger->setValue('11:00PM');
    $this->assertFalse($textfield_visible_when_time_empty->isVisible());
    $this->assertTrue($textfield_visible_when_time_filled->isVisible());
    $this->assertFalse($textfield_visible_when_time_value_empty->isVisible());
    $this->assertTrue($textfield_visible_when_time_value_23_00->isVisible());

    // Assert visibility when time field is cleared.
    $field_test_time_trigger->setValue('');
    $this->assertTrue($textfield_visible_when_time_empty->isVisible());
    $this->assertFalse($textfield_visible_when_time_filled->isVisible());
    $this->assertTrue($textfield_visible_when_time_value_empty->isVisible());
    $this->assertFalse($textfield_visible_when_time_value_23_00->isVisible());

  }

  /**
   * Test time field as a States dependent.
   */
  public function testStatesTimeFieldAsDependent(): void {
    $this->drupalGet('node/add/' . $this->contentType);
    $page = $this->getSession()->getPage();
    // Find time fields and trigger elements.
    $field_test_time_a = $page->findField('field_test_time_a[0][value]');
    $this->assertNotEmpty($field_test_time_a);
    $field_test_time_b = $page->findField('field_test_time_b[0][value]');
    $this->assertNotEmpty($field_test_time_b);
    $checkbox_trigger_enabled_a = $page->findField('checkbox_trigger_enabled_a');
    $this->assertNotEmpty($checkbox_trigger_enabled_a);
    $checkbox_trigger_disabled_b = $page->findField('checkbox_trigger_disabled_b');
    $this->assertNotEmpty($checkbox_trigger_disabled_b);
    $checkbox_trigger_required_a = $page->findField('checkbox_trigger_required_a');
    $this->assertNotEmpty($checkbox_trigger_required_a);
    $checkbox_trigger_optional_b = $page->findField('checkbox_trigger_optional_b');
    $this->assertNotEmpty($checkbox_trigger_optional_b);
    $checkbox_trigger_visible_a = $page->findField('checkbox_trigger_visible_a');
    $this->assertNotEmpty($checkbox_trigger_visible_a);
    $checkbox_trigger_invisible_b = $page->findField('checkbox_trigger_invisible_b');
    $this->assertNotEmpty($checkbox_trigger_invisible_b);

    // Assert states on page load.
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    // Trigger states change.
    $checkbox_trigger_enabled_a->check();
    $this->assertFalse($field_test_time_a->isVisible());
    // State changes.
    $this->assertFalse($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_enabled_a->uncheck();
    $this->assertFalse($field_test_time_a->isVisible());
    // State changes.
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_required_a->check();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    // State changes.
    $this->assertTrue($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_required_a->uncheck();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    // State changes.
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_visible_a->check();
    // State changes.
    $this->assertTrue($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_visible_a->uncheck();
    // State changes.
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_disabled_b->check();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    // State changes.
    $this->assertTrue($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_disabled_b->uncheck();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    // State changes.
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_optional_b->check();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    // State changes.
    $this->assertFalse($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_optional_b->uncheck();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    // State changes.
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_invisible_b->check();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    // State changes.
    $this->assertFalse($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));

    $checkbox_trigger_invisible_b->uncheck();
    $this->assertFalse($field_test_time_a->isVisible());
    $this->assertTrue($field_test_time_a->hasAttribute('disabled'));
    $this->assertFalse($field_test_time_a->hasAttribute('required'));
    // State changes.
    $this->assertTrue($field_test_time_b->isVisible());
    $this->assertFalse($field_test_time_b->hasAttribute('disabled'));
    $this->assertTrue($field_test_time_b->hasAttribute('required'));
  }

}
