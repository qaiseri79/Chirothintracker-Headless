<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Processes durably stored, verified notifications; retries after failures.
 *
 * @QueueWorker(id = "headless_subscription_events", title = @Translation("Portal subscription events"), cron = {"time" = 30})
 */
final class SubscriptionEvents extends QueueWorkerBase {
  public function processItem($data): void {
    $repo = \Drupal::service('headless_subscriptions.repository');
    $event = $repo->event((string) $data);
    if (!$event || $event['status'] === 'done') return;
    \Drupal::service('headless_subscriptions.subscription')->processEvent($event['payload']);
    $repo->eventDone((string) $data);
  }
}
