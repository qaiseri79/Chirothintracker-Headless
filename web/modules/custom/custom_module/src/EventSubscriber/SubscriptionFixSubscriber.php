<?php

namespace Drupal\custom_module\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\commerce_recurring\Event\RecurringEvents;

/**
 * Fixes subscription unit price if a coupon was used.
 */
class SubscriptionFixSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      RecurringEvents::SUBSCRIPTION_INSERT => 'onSubscriptionInsert',
    ];
  }

  /**
   * Set correct unit price if a coupon was used.
   */
  public function onSubscriptionInsert(SubscriptionEvent $event) {
    $subscription = $event->getSubscription();

    // Get the initial order used to create the subscription.
    $initial_order = $subscription->getInitialOrder();
    if (!$initial_order) {
      return;
    }

    // Check if any coupons were used in the order.
    $coupons = $initial_order->get('coupons')->referencedEntities();
    if (empty($coupons)) {
      return; // No coupon — nothing to fix.
    }

    // Get the original product variation.
    $variation = $subscription->getPurchasedEntity();
    if (!$variation || !$variation->hasField('price')) {
      return;
    }

    // Get the full (non-discounted) price.
    $original_price = $variation->get('price')->first()->toPrice();

    // Update the subscription unit price.
    $subscription->set('unit_price', $original_price);

    \Drupal::logger('custom_module')->notice(
      'Subscription @id unit price reset to @price due to coupon use on order @order.',
      [
        '@id' => $subscription->id(),
        '@price' => $original_price->__toString(),
        '@order' => $initial_order->id(),
      ]
    );
  }
}
