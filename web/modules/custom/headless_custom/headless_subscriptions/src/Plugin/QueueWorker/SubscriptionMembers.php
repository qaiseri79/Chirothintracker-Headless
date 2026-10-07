<?php
declare(strict_types=1);
namespace Drupal\headless_subscriptions\Plugin\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
/**
 * @QueueWorker(id = "headless_subscription_members", title = @Translation("Sponsored doctor access"), cron = {"time" = 20})
 */
final class SubscriptionMembers extends QueueWorkerBase {
  public function processItem($data): void {
    \Drupal::service('headless_subscriptions.sponsorship')->syncBatch((int) $data['owner'], (int) ($data['after'] ?? 0));
  }
}
