<?php

namespace Drupal\custom_module\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\views\Views;
use Drupal\Core\Render\Markup;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_standard_daily_messages_custom",
 *   label = @Translation("Standard Daily Messages Custom"),
 *   type = "user",
 *   confirm = false
 * )
 */

class StandardDailyMessagesCustom extends ViewsBulkOperationsActionBase
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
      $field_program_day = $user_entity->get("field_program_day_term")->getValue();
      if ($field_program_day) {
         $program_day_term_id = $field_program_day[0]["target_id"];
         $program_day_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($program_day_term_id);
         $program_message = $program_day_term->field_message_plain->value;
         $program_day_title = $program_day_term->name->value;
      }
      $clinic = $user_entity->get("field_clinic")->getValue();
      if ($clinic) {
         $clinic_id = $clinic[0]["target_id"];
         $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
         $clinic_entity = $storage->load($clinic_id);
         $clinic_title = $clinic_entity->get('title')->getValue();
         $clinic_mail = $clinic_entity->get('field_clinic_mail')->getValue();
         if ($clinic_title) {
            $clinic_title = $clinic_title[0]["value"];
         }
         if ($clinic_mail) {
            $clinic_mail = $clinic_mail[0]["value"];
         }
      }
      $patient_mail = $user_entity->get("mail")->getValue()[0]["value"];
      $field_current_program_day = $user_entity->get("field_current_program_day")->getValue()[0]["value"];
      $custom_daily_message = Views::getView('entity_reference_custom_daily_message');
      $custom_daily_message->setDisplay('views_rules_1');
      $custom_daily_message->setArguments([$program_day_title, $clinic_id]);
      $custom_daily_message->execute();
      $custom_daily_message_result = $custom_daily_message->result;
      if ($custom_daily_message_result) {
         foreach ($custom_daily_message_result as $key => $value) {
            $message = $value->_entity->get("body")->getValue();
            $subject = $value->_entity->getTitle();
            if ($message) {
               $message = $message[0]['value'];
            }
            $host = \Drupal::request()->getSchemeAndHttpHost();
            $mailManager = \Drupal::service('plugin.manager.mail');
            $module = 'custom_module';
            $key = 'message_email';
            //$to = "qaiseri79@gmail.com";
            $to = $patient_mail;
            if ($clinic_mail) {
               $reply = $clinic_mail;
            } else {
               $reply = NULL;
            }
            $params['subject'] = $subject;
            $params['message'] = Markup::create($message);
            $langcode = \Drupal::currentUser()->getPreferredLangcode();
            $send = true;
            $result = $mailManager->mail($module, $key, $to, $langcode, $params, $reply, $send);
            if ($result['result'] !== true) {
               $log_message = 'Daily Email (Custom) to user(' . $user_entity->id() . ') "' . $patient_mail . '" failed.';
               \Drupal::logger('custom_module')->error($log_message);
            } else {
               $log_message = $clinic_title . '^' . $patient_mail . '^' . $field_current_program_day . '^' . $program_message;
               \Drupal::logger('custom_module')->notice($log_message);
            }

         }
      }

   }

}
