<?php

namespace Drupal\custom_module\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\user\Entity\User;

/**
 * @AdvancedQueueJobType(
 *   id = "alert_customer_prior_trial",
 *   label = @Translation("Alert Customer Prior Trial Ends"),
 *   max_retries = 5,
 *   retry_delay = 3600,
 * )
 */
class AlertCustomerPriorTrial extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
     global $base_url;
    try {
      $status = 0;
      // Get the Job data.
      $payload = $job->getPayload();
      if (isset($payload['user_id'])) {
            $current_time = \Drupal::time()->getRequestTime();
            $user_id = $payload['user_id'];
            $trial_end_date = $payload['trial_period'];
            $user_entity = User::load($user_id);
            if ($current_time >= $trial_end_date) {
                if($user_entity){
                  if ($user_entity->hasRole('chiropractor_active_')) {
                    $user_entity->removeRole('chiropractor_active_');
                    $user_entity->addRole('chiropractor_inactive_');
                    $user_entity->changed->preserve = TRUE;
                    $user_entity->save();
                    \Drupal::logger('custom_module')->info('Removed trial role from user ID @uid.', ['@uid' => $user_id]);
                     return JobResult::success('Trial expried successfully.');
                  }
                }
               }
            }

      // By default mark the Job as failed.
      return JobResult::failure('womp.');
    }
    catch (\Exception $e) {
      return JobResult::failure($e->getMessage());
    }
  }

}