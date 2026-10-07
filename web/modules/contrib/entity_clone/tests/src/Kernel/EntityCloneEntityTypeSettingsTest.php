<?php

namespace Drupal\Tests\entity_clone\Kernel;

use Drupal\Core\Config\StorageComparer;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Entity Clone Entity Type Settings.
 *
 * @package Drupal\Tests\entity_clone\Kernel
 * @group entity_clone
 */
#[Group('entity_clone')]
class EntityCloneEntityTypeSettingsTest extends KernelTestBase {

  /**
   * The sync config storage to simulate imported config.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $sync;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config', 'entity_clone'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);
    $this->installConfig(['entity_clone']);

    // Clone active config to sync to simulate real-world config import.
    $active = $this->container->get('config.storage');
    $this->sync = $this->container->get('config.storage.sync');

    foreach ($active->listAll() as $name) {
      $configData = $active->read($name);
      if ($configData !== FALSE) {
        $this->sync->write($name, $configData);
      }
    }

    // Ensure system.site in sync has the correct UUID.
    $systemSite = $active->read('system.site');
    if ($systemSite !== FALSE) {
      $this->sync->write('system.site', $systemSite);
    }
    else {
      $this->fail('system.site config is missing from active storage.');
    }

    // Update the config to test schema.
    $updatedConfig['form_settings']['entity_test'] = [
      'default_value' => FALSE,
      'disable' => FALSE,
      'hidden' => FALSE,
    ];

    $this->sync->write('entity_clone.settings', $updatedConfig);
  }

  /**
   * Tests entity type settings schema.
   */
  public function testConfigSchemaValidation() {
    $storageComparer = new StorageComparer(
      $this->container->get('config.storage'),
      $this->sync
    );

    $this->assertTrue($storageComparer->createChangelist()
      ->hasChanges(), 'There are changes to import.');

    try {
      $errors = $this->configImporter()->validate();
      $this->assertEmpty($errors->getErrors(), 'Config schema validated successfully.');
    }
    catch (\Exception $e) {
      $this->fail('Schema validation failed with exception: ' . $e->getMessage());
    }
  }

}
