<?php

namespace Drupal\Tests\multiple_selects\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;

/**
 * Tests the multiple select widget with inline form errors.
 *
 * @group multiple_selects
 */
class MultipleSelectsInlineFormErrorsTest extends BrowserTestBase {

  use EntityReferenceFieldCreationTrait;
  use TaxonomyTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'multiple_selects',
    'node',
    'field_ui',
    'taxonomy',
    'inline_form_errors',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An array containing the created tags.
   *
   * @var array
   */
  protected $tags = [];

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalLogin($this->rootUser);

    $this->drupalCreateContentType(['type' => 'page']);

    // Create a new vocabulary.
    $vocabulary = Vocabulary::create(['vid' => 'tags', 'name' => 'tags']);
    $vocabulary->save();

    for ($i = 0; $i < 10; $i++) {
      $this->tags[] = $this->createTerm($vocabulary);
    }

    // Create entity reference field with taxonomy term as a target.
    $handler_settings = [
      'target_bundles' => [
        $vocabulary->id() => $vocabulary->id(),
      ],
      'auto_create' => TRUE,
      'auto_create_bundle' => $vocabulary->id(),
    ];
    $this->createEntityReferenceField('node', 'page', 'field_tags', 'Tags', 'taxonomy_term', 'default', $handler_settings, FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $this->container->get('entity_display.repository')
      ->getFormDisplay('node', 'page')
      ->setComponent('field_tags', ['type' => 'multiple_options_select'])
      ->save();

    $this->moduleInstaller = $this->container->get('module_installer');
  }

  /**
   * Test the widget with inline form errors enabled.
   */
  public function testWidgetWithInlineFormErrorsEnabled() {
    $field_config = FieldConfig::loadByName('node', 'page', 'field_tags');
    $field_config->setRequired(TRUE);
    $field_config->save();

    $title = $this->randomMachineName();
    $this->drupalGet('node/add/page');
    $this->submitForm([
      'title[0][value]' => $title,
    ], 'Save');

    // Check that the inline from is thrown and the link links to the
    // right field.
    $this->assertSession()->pageTextContains('1 error has been found: Tags (value 1)');
    $this->assertSession()->pageTextContains('Tags field is required.');
    $link = $this->getSession()->getPage()->findLink('Tags (value 1)');
    $this->assertNotNull($link);
    $this->assertEquals('#edit-field-tags-0-target-id', $link->getAttribute('href'));

    $tag_1_key = array_rand($this->tags);
    $tag_1 = $this->tags[$tag_1_key];
    $this->submitForm([
      'title[0][value]' => $title,
      'field_tags[0][target_id]' => $tag_1->id(),
    ], 'Save');

    $this->assertSession()->pageTextContains("Page $title has been created.");
  }

}
