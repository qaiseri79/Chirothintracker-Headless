<?php

namespace Drupal\custom_module\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\commerce_recurring\Event\RecurringEvents;
use Drupal\commerce_recurring\Event\SubscriptionEvent;


/**
 * Listens for subscription update events.
 */
class SubscriptionUpdateEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Subscribe to the commerce_recurring.commerce_subscription.update event
    $events[RecurringEvents::COMMERCE_RECURRING_UPDATE][] = ['onSubscriptionUpdate',100];
    return $events;
  }

  /**
   * Respond to the subscription update event.
   *
   * @param \Drupal\commerce_recurring\Event\SubscriptionEvent $event
   *   The event object containing the subscription data.
   */
  public function onSubscriptionUpdate(SubscriptionEvent $event) {
    \Drupal::logger('custom_module')->notice('Event triggered');
    $subscription = $event->getSubscription();

    // Perform custom logic on the subscription update
    // For example, logging the subscription ID and status
    \Drupal::logger('custom_module')->notice('Subscription with ID @id has been updated. Status: @status', [
      '@id' => $subscription->id(),
      '@status' => $subscription->getState()->getLabel(),
    ]);

    // Add your custom logic here (e.g., send notifications, update other entities, etc.)
  }
}
