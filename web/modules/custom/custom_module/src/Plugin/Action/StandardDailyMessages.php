<?php

namespace Drupal\custom_module\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_standard_daily_messages",
 *   label = @Translation("Standard Daily Messages"),
 *   type = "user",
 *   confirm = false
 * )
 */

class StandardDailyMessages extends ViewsBulkOperationsActionBase
{
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL)
  {
    $this->sendMail($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE)
  {
    return $object->access('update', $account, $return_as_object);
  }

  public function sendMail($user_entity)
  {
    // Exit if no program day term set
    if ($user_entity->get('field_program_day_term')->isEmpty()) {
      \Drupal::logger('custom_module')->debug('Program Day Term has not yet been created.');
      return;
    }

    // Load Program Day Term
    $program_day_term = $user_entity->get('field_program_day_term')->entity;
    $program_message = $program_day_term->get('field_message_plain')->value ?? '';
    $program_day_title = $program_day_term->label();

    // Get user email and current program day
    $patient_mail = $user_entity->getEmail();
    $current_program_day = $user_entity->get('field_user_current_program_day_c')->value ?? '';

    // Exit if already notified
    $notified_day = $user_entity->get('field_std_dm_ntfd_pd')->value ?? NULL;
    if (!empty($notified_day) && $program_day_title == $notified_day) {
      \Drupal::logger('custom_module')->debug('Already Notified @email for day @notified.', [
        '@email' => $patient_mail,
        '@notified' => $notified_day,
      ]);
      return;
    }

    // Exit if message is not for current program day
    if ($program_day_title !== $current_program_day) {
      \Drupal::logger('custom_module')->debug('Skipped email to @mail: program day "@target" does not match current day "@current".', [
        '@mail' => $patient_mail,
        '@target' => $program_day_title,
        '@current' => $current_program_day,
      ]);
      return;
    }

    // Default clinic values
    $clinic_title = '';
    $clinic_mail = NULL;

    // Load Clinic entity if set
    if (!$user_entity->get('field_clinic')->isEmpty()) {
      $clinic = $user_entity->get('field_clinic')->entity;
      $clinic_title = $clinic->label();
      $clinic_mail = $clinic->get('field_clinic_mail')->value ?? NULL;
    }

    // Prepare and send email
    $mailManager = \Drupal::service('plugin.manager.mail');
    $module = 'custom_module';
    $key = 'message_email';
    $to = $patient_mail;
    $reply = $clinic_mail;

    $params = [
      'subject' => "Program Day {$program_day_title}: A Message From Your Doctor",
      'message' => $program_message,
    ];

    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = true;

    $result = $mailManager->mail($module, $key, $to, $langcode, $params, $reply, $send);

    if (!empty($result['result'])) {
      $user_entity->set('field_std_dm_ntfd_pd', $program_day_title);
      $user_entity->changed->preserve = TRUE;
      $user_entity->save();
      \Drupal::logger($module)->info("{$clinic_title}^{$patient_mail}^{$current_program_day}^{$program_message}");
    } else {
      \Drupal::logger($module)->error('Daily Email failed to "@mail".', ['@mail' => $patient_mail]);
    }
  }

}
