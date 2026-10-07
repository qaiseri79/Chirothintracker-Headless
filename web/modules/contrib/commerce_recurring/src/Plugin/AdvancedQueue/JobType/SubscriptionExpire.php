<?php

namespace Drupal\commerce_recurring\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;

/**
 * Provides the job type for expiring subscriptions.
 *
 * @AdvancedQueueJobType(
 *   id = "commerce_subscription_expire",
 *   label = @Translation("Expire subscription"),
 * )
 */
class SubscriptionExpire extends RecurringJobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $subscription_id = $job->getPayload()['subscription_id'];
    $subscription_storage = $this->entityTypeManager->getStorage('commerce_subscription');
    /** @var \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription */
    $subscription = $subscription_storage->load($subscription_id);
    if (!$subscription) {
      return JobResult::failure('Subscription not found.');
    }
    if (!$subscription->getState()->isTransitionAllowed('expire')) {
      return JobResult::failure(sprintf('Unsupported subscription status. Supported statuses: ("trial", "pending", "active"), Actual: "%s").', $subscription->getState()->getId()));
    }
    $subscription->getState()->applyTransitionById('expire');
    $subscription->save();

    return JobResult::success();
  }

}
