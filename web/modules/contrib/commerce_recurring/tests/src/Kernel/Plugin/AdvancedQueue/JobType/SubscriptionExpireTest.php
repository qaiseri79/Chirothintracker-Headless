<?php

namespace Drupal\Tests\commerce_recurring\Kernel\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\commerce_price\Price;
use Drupal\commerce_recurring\Entity\Subscription;
use Drupal\Tests\commerce_recurring\Kernel\RecurringKernelTestBase;

/**
 * @coversDefaultClass \Drupal\commerce_recurring\Plugin\AdvancedQueue\JobType\SubscriptionExpire
 * @group commerce_recurring
 */
class SubscriptionExpireTest extends RecurringKernelTestBase {

  /**
   * The recurring order manager.
   *
   * @var \Drupal\commerce_recurring\RecurringOrderManagerInterface
   */
  protected $recurringOrderManager;

  /**
   * The used queue.
   *
   * @var \Drupal\advancedqueue\Entity\QueueInterface
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->recurringOrderManager = $this->container->get('commerce_recurring.order_manager');
    /** @var \Drupal\Core\Entity\EntityStorageInterface $queue_storage */
    $queue_storage = $this->container->get('entity_type.manager')->getStorage('advancedqueue_queue');
    $this->queue = $queue_storage->load('commerce_recurring');
  }

  /**
   * @covers ::process
   */
  public function testExpire() {
    // Confirm that it is not possible to expire a subscription already expired.
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = Subscription::create([
      'type' => 'product_variation',
      'store_id' => $this->store->id(),
      'billing_schedule' => $this->billingSchedule,
      'uid' => $this->user,
      'purchased_entity' => $this->variation,
      'title' => $this->variation->getOrderItemTitle(),
      'unit_price' => new Price('2', 'USD'),
      'state' => 'expired',
      'starts' => strtotime('2020-01-01 00:00'),
      'ends' => strtotime('2020-12-31 23:59'),
    ]);
    $subscription->save();

    $job = Job::create('commerce_subscription_expire', [
      'subscription_id' => $subscription->id(),
    ]);
    $this->queue->enqueueJob($job);

    $job = $this->queue->getBackend()->claimJob();
    /** @var \Drupal\advancedqueue\ProcessorInterface $processor */
    $processor = \Drupal::service('advancedqueue.processor');
    $result = $processor->processJob($job, $this->queue);
    $this->assertEquals(Job::STATE_FAILURE, $result->getState());

    // Confirm that it is possible to expire an active subscription.
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = Subscription::create([
      'type' => 'product_variation',
      'store_id' => $this->store->id(),
      'billing_schedule' => $this->billingSchedule,
      'uid' => $this->user,
      'purchased_entity' => $this->variation,
      'title' => $this->variation->getOrderItemTitle(),
      'unit_price' => new Price('2', 'USD'),
      'state' => 'active',
      'starts' => strtotime('2020-01-01 00:00'),
      'ends' => strtotime('2020-12-31 23:59'),
    ]);
    $subscription->save();

    $job = Job::create('commerce_subscription_expire', [
      'subscription_id' => $subscription->id(),
    ]);
    $this->queue->enqueueJob($job);
    $job = $this->queue->getBackend()->claimJob();

    $result = $processor->processJob($job, $this->queue);
    $this->assertEquals(Job::STATE_SUCCESS, $result->getState());
    $subscription = $this->reloadEntity($subscription);
    $this->assertEquals('expired', $subscription->getState()->getId());
  }

}
