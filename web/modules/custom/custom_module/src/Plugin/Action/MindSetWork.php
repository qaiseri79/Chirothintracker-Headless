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
 *   id = "custom_module_mind_set_work",
 *   label = @Translation("Mind Set Work"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */

class MindSetWork extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;



  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {
    $result = $this->mindSetWork($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       return $object->access('update', $account, $return_as_object);
  }


  public function mindSetWork($user_entity){

        $clinic = $user_entity->get('field_clinic')->getValue();
        if($clinic){
          $clinic_id = $clinic[0]["target_id"];
          $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
          if($clinic_id){
              $clinic_entity = $storage->load($clinic_id);
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
          }
      
        }
       
  }
}
