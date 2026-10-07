<?php

namespace Drupal\Tests\multiple_selects\Functional\Update;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * Update test that checks if the element type was added to the widget settings.
 *
 * @group multiple_selects
 */
class MultipleSelectsPostUpdateAddElementTypeToWidgets extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'multiple_selects',
  ];

  /**
   * The entity form display storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityFormDisplayStorage;

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles() {
    $this->databaseDumpFiles = [
      DRUPAL_ROOT . '/core/modules/system/tests/fixtures/update/drupal-8.8.0.bare.standard.php.gz',
      __DIR__ . '/../../../fixtures/update/multiple-selects.php',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityFormDisplayStorage = $this->container->get('entity_type.manager')->getStorage('entity_form_display');
  }

  /**
   * Check if the element type was added to the widget settings.
   *
   * @see multiple_selects_post_update_add_element_type_to_widgets()
   */
  public function testPostUpdateAddConfigurationFormOptionToWidget() {
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $entity_form_display */
    $entity_form_display = $this->entityFormDisplayStorage->loadUnchanged('node.page.default');
    $this->assertArrayNotHasKey('element_type', $entity_form_display->getComponent('uid')['settings']);

    $this->runUpdates();

    $entity_form_display = $this->entityFormDisplayStorage->loadUnchanged('node.page.default');
    $this->assertEquals('select', $entity_form_display->getComponent('uid')['settings']['element_type']);
  }

}
