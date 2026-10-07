<?php
namespace Drupal\custom_module\Plugin\Action;

use Drupal\user\Entity\User;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\eck\Entity\EckEntity;
use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Job;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_process_free_trial_four_weeks",
 *   label = @Translation("Process with (4 weeks)"),
 *   type = "webform_submission",
 *   confirm = false
 * )
 */
class ProcessFreeTrialFourWeeks extends ViewsBulkOperationsActionBase
{
  use StringTranslationTrait;
  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL)
  {
    if ($entity instanceof WebformSubmission) {
      $this->processFreeTrial($entity);
    }
  }
  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE)
  {
    return $object->access('update', $account, $return_as_object);
  }
  /**
   * Custom function to handle the free trial process.
   * @param \Drupal\webform\Entity\WebformSubmission $submission
   *   The Webform submission entity.
   */
  protected function processFreeTrial(WebformSubmission $submission)
  {
    $submission_data = $submission->getData();
    $clinic_title = $submission_data['clinic_title'];
    $chiropractor_full_name = $submission_data['chiropractor_full_name'];
    $chiropractor_email = $submission_data['chiropractor_email'];
    $product_id = $submission_data['product_id'];
    $laser_clinic = 0;
    if ($product_id == 4) {
      $laser_clinic = 1;
    }
    $status = $submission_data['status'];
    if ($status == "pending") {
      $eck = [
        'entity_type' => 'clinic',
        'type' => 'clinic',
        'title' => $clinic_title,
        'field_clinic_mail' => $chiropractor_email
      ];
      $clinic_entity = EckEntity::create($eck);
      $clinic_entity->setOwnerId(1);
      $clinic_entity->save();
      $new_clinic_id = $clinic_entity->id();
      $clinic_location_eck = [
        'entity_type' => 'clinic',
        'type' => 'clinic_location',
        'title' => $clinic_title . " (Location)",
        'field_clinic' => array('target_id' => $new_clinic_id),
        'field_laser_clinic' => $laser_clinic
      ];
      $clinic_location_entity = EckEntity::create($clinic_location_eck);
      $clinic_location_entity->setOwnerId(1);
      $clinic_location_entity->save();
      $new_clinic_location_id = $clinic_location_entity->id();
      $chiropractor_user = User::create();
      $chiropractor_user->setUsername($chiropractor_email);
      $chiropractor_user->setEmail($chiropractor_email);
      $chiropractor_user->set('field_full_name', $chiropractor_full_name);
      $chiropractor_user->addRole('chiropractor_active_');
      $chiropractor_user->addRole('chiropractor_inactive_');
      $chiropractor_user->set('field_clinic', array('target_id' => $new_clinic_id));
      $chiropractor_user->set('field_clinic_location', array('target_id' => $new_clinic_location_id));
      $chiropractor_user->enforceIsNew();
      $chiropractor_user->activate();
      $chiropractor_user->save();
      $new_user_id = $chiropractor_user->id();
      if ($new_user_id) {
        $submission_data["status"] = "freetrial";
        $submission_data["trial_period"] = 4;
        $submission->setData($submission_data);
        $submission->save();
        $trial_duration_weeks = 4;
        $trial_end_date = time() + ($trial_duration_weeks * 7 * 86400);
        // enque the user
        $payload = [
          'user_id' => $new_user_id,
          'trial_period' => $trial_end_date,
        ];
        // Create import Job and add to the "default" queue.
        $job = Job::create('alert_customer_prior_trial', $payload);
        $job->setAvailableTime($trial_end_date);
        if ($job instanceof Job) {
          $q = Queue::load('default');
          $q->enqueueJob($job);
        }
      }

    }
    \Drupal::logger('custom_module')->info('Processed (4 weeks) free trial for Webform submission ID: @id', ['@id' => 1]);
  }

}
