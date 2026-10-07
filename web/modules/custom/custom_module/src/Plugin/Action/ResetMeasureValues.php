<?php

namespace Drupal\custom_module\Plugin\Action;

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionForm;
use Drupal\views\Views;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_reset_measure_values",
 *   label = @Translation("Reset Measure Values"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */

class ResetMeasureValues extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;



  //  /**
  //  * {@inheritdoc}
  //  */

  // public function buildConfigurationForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
  //   $form['patient_message'] = [
  //     '#title' => t('Message'),
  //     '#type' => 'textarea',
  //     '#rows' => 10,
  //     '#cols' => 60,
  //     '#resizable' => TRUE,
  //     '#required' => TRUE,
  //     '#default_value' => $form_state->getValue('example_config_setting'),
  //   ];
  //   return $form;
  // }



  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {

    //$configuration = $this->configuration;
   // $message = $this->configuration["patient_message"];
    //$result = $this->setMeasureValue($entity);
    // if($result){
    //   \Drupal::logger('custom_module')->notice("Message Send to ". $entity->getAccountName());
    // }else{
       \Drupal::logger('custom_module')->notice($entity->get('field_program_start_inches')->getValue());
    // }

     return $this->t('Operation Performed on the selected users');

  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       return $object->access('update', $account, $return_as_object);
  }


  public function setMeasureValue($user_entity){
          if(in_array('enrolled_patient', $user_entity->getRoles())) {
             $user_entity->set('field_program_start_inches', "0");
             $user_entity->set('field_net_inches_lost', "0");
             // $user_entity->changed->preserve = TRUE;
             // $user_entity->save();

           
            $starting_view = Views::getView('rules_measurements');
            $starting_view->setDisplay('block_1');
            $starting_view->setArguments([$user_entity->id()]);
            $starting_view->execute();
            $starting_view_result = $starting_view->result;
             if($starting_view_result){
                 $starting_measurements =  $starting_view_result[0]->_entity->getData()['field_total_measurements'];
                  $user_entity->set('field_program_start_inches', $starting_measurements);
              }


              $ending_view = Views::getView('rules_measurements');
              $ending_view->setDisplay('block_2');
              $ending_view->setArguments([$user_entity->id()]);
              $ending_view->execute();
              $ending_view_result = $ending_view->result;
             if($ending_view_result){
               $ending_measurements =  $ending_view_result[0]->_entity->getData()['field_total_measurements'];
              }     



              if($user_entity->get('field_program_start_inches')->getValue()){
                  if($user_entity->get('field_program_start_inches')->getValue()[0]["value"] > 0 && $ending_measurements > 0){
                  $total_inches_lost = (int)$starting_measurements - (int)$ending_measurements;
                  $account->set('field_net_inches_lost', $total_inches_lost);
                }
              }  
            

             $user_entity->changed->preserve = TRUE;
             $user_entity->save();

          }



            // $account = \Drupal\user\Entity\User::load($new_message_submission->getOwnerId());
            // return array($new_message_id);
  }
}
