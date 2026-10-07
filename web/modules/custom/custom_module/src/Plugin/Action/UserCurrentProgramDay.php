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

/**
 * 
 *
 * @Action(
 *   id = "custom_module_user_current_program_day",
 *   label = @Translation("User Current Program Day"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */

class UserCurrentProgramDay extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;



  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {

    $this->userRoles($entity);
    $this->userProfile($entity);

  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       return $object->access('update', $account, $return_as_object);
  }


  public function userRoles($user_entity){
      if(in_array('enrolled_patient', $user_entity->getRoles())) {

          // Evaluate User Roles
        $clinic = $user_entity->get('field_clinic')->getValue();
        if($clinic){
          $clinic_id = $clinic[0]["target_id"];
          $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
          if($clinic_id){
              $clinic_entity = $storage->load($clinic_id);
              $ecommerce_enabled_field = $clinic_entity->get('field_ecommerce_enabled')->getValue();
              if($ecommerce_enabled_field){
                  $ecommerce_enabled = $ecommerce_enabled_field[0]["value"];
                     if(in_array('enrolled_patient', $user_entity->getRoles())) {
                      if($ecommerce_enabled == 1){
                         $user_entity->addRole('ecommerce');
                         $user_entity->changed->preserve = TRUE;
                         $user_entity->save();
                       }
                     }
                    if(in_array('ecommerce', $user_entity->getRoles()) || in_array('ecommerce_manager', $user_entity->getRoles())) {
                       if($ecommerce_enabled != 1){
                         $user_entity->removeRole('ecommerce');
                         $user_entity->removeRole('ecommerce_manager');
                         $user_entity->changed->preserve = TRUE;
                         $user_entity->save();
                       }
                     }
                
              }
              // Mind Set Work
            $mind_set_work_field = $clinic_entity->get('field_mind_set_work')->getValue();
              if($mind_set_work_field){
                  $mind_set_work = $mind_set_work_field[0]["value"];
                  if($mind_set_work == 1){
                     if(in_array('enrolled_patient', $user_entity->getRoles())) {
                         $user_entity->addRole('patient_mind_set');
                         $user_entity->changed->preserve = TRUE;
                         $user_entity->save();
                     }else if(in_array('chiropractor_active_', $user_entity->getRoles())) {
                         $user_entity->addRole('chiropractor_mind_set');
                         $user_entity->changed->preserve = TRUE;
                         $user_entity->save();
                     }
                  }
              }

           //Add Roles for Chirothin
           
            if(in_array('enrolled_patient', $user_entity->getRoles()) || in_array('archived_patient', $user_entity->getRoles())) {
              if($user_entity->get('field_chiropractor')->getValue()){
                $chiropractor_id = $user_entity->get('field_chiropractor')->getValue()[0]["target_id"];
                $chiropractor = \Drupal\user\Entity\User::load($chiropractor_id);
                if(!in_array('chiropractor_white_label', $chiropractor->getRoles())){
                   $user_entity->addRole('patient_chirothin');
                   $user_entity->removeRole('patient_white_label');
                   $user_entity->changed->preserve = TRUE;
                   $user_entity->save();
                }else  if(in_array('chiropractor_white_label', $chiropractor->getRoles())){
                   $user_entity->addRole('patient_white_label');
                   $user_entity->removeRole('patient_chirothin');
                   $user_entity->changed->preserve = TRUE;
                   $user_entity->save();
                }
              }
            }    

          }
      
        }

      }
  }

// public function  userRoles($user_entity){
// }



public function userProfile($user_entity){
  if(in_array('enrolled_patient', $user_entity->getRoles())) {
    if(in_array('patient_chirothin', $user_entity->getRoles())) {
      
    }
  }

}

}
