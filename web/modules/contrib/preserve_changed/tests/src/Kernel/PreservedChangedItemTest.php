<?php

namespace Drupal\Tests\preserve_changed\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\preserve_changed_test\Entity\EntityTestChanged;
use Drupal\preserve_changed_test\TimeMock;

/**
 * Tests the PreservedChangedItem field item class.
 *
 * @group preserve_changed
 *
 * @coversDefaultClass \Drupal\preserve_changed\PreservedChangedItem
 */
class PreservedChangedItemTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'preserve_changed',
    'preserve_changed_test',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('datetime.time')->setClass(TimeMock::class);
  }

  /**
   * @covers ::preSave
   */
  public function testPreservedChangedItem(): void {
    $this->installEntitySchema('entity_test_changed');

    $entity = EntityTestChanged::create([
      'type' => 'page',
      'name' => $this->randomString(),
    ]);
    $entity->save();
    $changed = $entity->getChangedTime();

    // At least a field should change in order to refresh the 'changed' time.
    $entity->set('name', $this->randomString())->save();

    // Check that the 'changed' timestamp was refreshed.
    $this->assertGreaterThan($changed, $entity->getChangedTime());

    $changed = $entity->getChangedTime();

    // Preserve the 'changed' timestamp value for next save.
    $entity->changed->preserve = TRUE;

    $entity->set('name', $this->randomString())->save();

    $this->assertSame($changed, $entity->getChangedTime());
  }

}
