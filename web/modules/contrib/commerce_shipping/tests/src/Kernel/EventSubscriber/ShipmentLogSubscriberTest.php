<?php

namespace Drupal\Tests\commerce_shipping\Kernel\EventSubscriber;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase;

/**
 * Tests the ShipmentLogSubscriber subscriber.
 *
 * @coversDefaultClass \Drupal\commerce_shipping\EventSubscriber\ShipmentLogSubscriber
 *
 * @group commerce_shipping
 */
class ShipmentLogSubscriberTest extends ShippingKernelTestBase implements ServiceModifierInterface {

  /**
   * A sample user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * A sample order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * A sample shipment.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $shipment;

  /**
   * The log storage.
   *
   * @var \Drupal\commerce_log\LogStorageInterface
   */
  protected $logStorage;

  /**
   * The log view builder.
   *
   * @var \Drupal\commerce_log\LogViewBuilder
   */
  protected $logViewBuilder;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_log',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_log');
    $this->logStorage = $this->container->get('entity_type.manager')->getStorage('commerce_log');
    $this->logViewBuilder = $this->container->get('entity_type.manager')->getViewBuilder('commerce_log');

    $user = $this->createUser();
    $this->user = $this->reloadEntity($user);

    $this->order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $this->user->getEmail(),
      'uid' => $this->user->id(),
      'store_id' => $this->store->id(),
    ]);
    $this->order->save();

    $this->shipment = Shipment::create([
      'type' => 'default',
      'title' => 'Shipment',
      'items' => [],
      'order_id' => $this->order->id(),
      'amount' => new Price("57.88", "USD"),
    ]);
    $this->shipment->save();
  }

  /**
   * Tests that a log is generated for finalize and ship shipment transitions.
   */
  public function testShipmentToShippedLogs() {
    // Check that there are no logs for the order at the moment.
    $logs = $this->logStorage->loadMultipleByEntity($this->order);
    $this->assertEmpty($logs);

    // Move shipment to the `ready` state.
    $this->shipment->getState()->applyTransitionById('finalize');
    $this->shipment->save();
    $logs = $this->logStorage->loadMultipleByEntity($this->order);
    $this->assertCount(1, $logs);
    $log = reset($logs);
    $build = $this->logViewBuilder->view($log);
    $this->render($build);
    $this->assertText('Shipment moved from Draft to Ready by the Finalize shipment transition.');

    // Move shipment to the `shipped` state.
    $this->shipment->getState()->applyTransitionById('ship');
    $this->shipment->save();
    $logs = $this->logStorage->loadMultipleByEntity($this->order);
    $this->assertCount(2, $logs);
    $log = $logs[2];
    $build = $this->logViewBuilder->view($log);
    $this->render($build);
    $this->assertText('Shipment moved from Ready to Shipped by the Send shipment transition.');
  }

  /**
   * Tests that a log is generated for finalize and cancel shipment transitions.
   */
  public function testShipmentToCancelLogs() {
    // Check that there are no logs for the order at the moment.
    $logs = $this->logStorage->loadMultipleByEntity($this->order);
    $this->assertEmpty($logs);

    // Move shipment to the `ready` state.
    $this->shipment->getState()->applyTransitionById('finalize');
    $this->shipment->save();
    $logs = $this->logStorage->loadMultipleByEntity($this->order);
    $this->assertCount(1, $logs);
    $log = reset($logs);
    $build = $this->logViewBuilder->view($log);
    $this->render($build);
    $this->assertText('Shipment moved from Draft to Ready by the Finalize shipment transition.');

    // Move shipment to the `canceled` state.
    $this->shipment->getState()->applyTransitionById('cancel');
    $this->shipment->save();
    $logs = $this->logStorage->loadMultipleByEntity($this->order);
    $this->assertCount(2, $logs);
    $log = $logs[2];
    $build = $this->logViewBuilder->view($log);
    $this->render($build);
    $this->assertText('Shipment moved from Ready to Canceled by the Cancel shipment transition.');
  }

}
