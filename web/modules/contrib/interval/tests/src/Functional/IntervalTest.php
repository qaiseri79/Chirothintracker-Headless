<?php

namespace Drupal\Tests\interval\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field_ui\Traits\FieldUiTestTrait;

/**
 * Ensures that the interval field works correctly.
 *
 * @group interval
 */
class IntervalTest extends BrowserTestBase {

  use FieldUiTestTrait;

  /**
   * Profile to use.
   *
   * @var string
   */
  protected $profile = 'testing';

  /**
   * Theme to use.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'field_ui',
    'interval',
    'entity_test',
  ];

  /**
   * Permissions to grant admin user.
   *
   * @var array
   */
  protected $permissions = [
    'access administration pages',
    'view test entity',
    'administer entity_test fields',
    'administer entity_test content',
  ];

  /**
   * Sets the test up.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser($this->permissions);
  }

  /**
   * Tests adding and editing values using interval.
   */
  public function testInterval() {
    $this->drupalLogin($this->adminUser);

    // Add a new interval field.
    $field_edit = [
      "field_storage[subform][cardinality]" => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ];
    $this->fieldUIAddNewField('entity_test/structure/entity_test', 'foobar', 'Foobar', 'interval', [], $field_edit);

    // Setup widget and formatters.
    EntityFormDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', [
        'type' => 'interval_default',
        'weight' => 20,
      ])
      ->save();

    EntityViewDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', [
        'label' => 'hidden',
        'type' => 'interval_default',
        'weight' => 20,
      ])
      ->save();

    // Test the fields values/widget.
    $this->drupalGet('entity_test/add');
    $this->assertSession()->fieldExists('field_foobar[0][interval]');
    $this->assertSession()->fieldExists('field_foobar[0][period]');

    // Add some extra fields.
    $button = $this->getSession()->getPage()->findButton('Add another item');
    $button->click();
    $button->click();

    $edit = [
      'field_foobar[0][period]' => 'week',
      'field_foobar[0][interval]' => 1,
      'field_foobar[1][period]' => 'day',
      'field_foobar[1][interval]' => 3,
      'field_foobar[2][period]' => 'quarter',
      'field_foobar[2][interval]' => 1,
      'name[0][value]' => 'Barfoo',
      'user_id[0][target_id]' => 'foo (' . $this->adminUser->id() . ')',
    ];

    $this->submitForm($edit, 'Save');
    $this->resetAll();
    $entities = \Drupal::entityTypeManager()->getStorage('entity_test')->loadByProperties([
      'name' => 'Barfoo',
    ]);
    $this->assertEquals(1, count($entities), 'Entity was saved');
    $entity = reset($entities);
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertSession()->pageTextContains('Barfoo');
    $this->assertSession()->pageTextContains('1 Week');
    $this->assertSession()->pageTextContains('3 Days');
    $this->assertSession()->pageTextContains('1 Quarter');

    // Change the formatter to raw.
    EntityViewDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', [
        'label' => 'hidden',
        'type' => 'interval_raw',
        'weight' => 20,
      ])
      ->save();
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertSession()->pageTextContains('1 Week');
    $this->assertSession()->pageTextContains('3 Days');
    $this->assertSession()->pageTextContains('1 Quarter');

    // Change the formatter to php.
    EntityViewDisplay::load('entity_test.entity_test.default')
      ->setComponent('field_foobar', [
        'label' => 'hidden',
        'type' => 'interval_php',
        'weight' => 20,
      ])
      ->save();
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertSession()->pageTextContains('7 days');
    $this->assertSession()->pageTextContains('3 days');
    $this->assertSession()->pageTextContains('3 months');

    $this->drupalGet('entity_test/manage/' . $entity->id() . '/edit');
    $edit = [
      'name[0][value]' => 'Bazbar',
      // Remove one child.
      'field_foobar[2][interval]' => '',
    ];
    $this->submitForm($edit, 'Save');
    $this->drupalGet('entity_test/' . $entity->id());
    $this->assertSession()->pageTextContains('Bazbar');
    // Reload entity.
    \Drupal::entityTypeManager()->getStorage('entity_test')->resetCache([$entity->id()]);
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->load($entity->id());
    $this->assertEquals(count($entity->field_foobar), 2, 'Two values in field');
  }

}
