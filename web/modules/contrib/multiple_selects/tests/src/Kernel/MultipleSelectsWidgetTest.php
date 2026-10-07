<?php

namespace Drupal\Tests\multiple_selects\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the multiple select widget.
 *
 * @group multiple_selects
 */
class MultipleSelectsWidgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'multiple_selects',
    'field',
  ];

  /**
   * The field widget definition manager.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager
   */
  protected $fieldWidgetDefinitionManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->fieldWidgetDefinitionManager = $this->container->get('plugin.manager.field.widget');
  }

  /**
   * Test that the correct field_types are configured.
   */
  public function testWidgetDefinitionFieldTypes() {
    $widget_definition = $this->fieldWidgetDefinitionManager->getDefinition('multiple_options_select');
    $this->assertEquals([
      'entity_reference',
      'list_integer',
      'list_float',
      'list_string',
    ], $widget_definition['field_types']);
  }

}
