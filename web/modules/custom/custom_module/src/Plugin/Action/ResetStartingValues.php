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
 *   id = "custom_module_reset_starting_values",
 *   label = @Translation("Reset Starting Values"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */

class ResetStartingValues extends ViewsBulkOperationsActionBase{

  use StringTranslationTrait;



  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {


    $result = $this->resetStartingValues($entity);
 

  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       return $object->access('update', $account, $return_as_object);
  }


  public function resetStartingValues($user_entity){
    if(in_array('enrolled_patient', $user_entity->getRoles())) {
        $user_entity->set('field_net_weight_loss', "0");
        $user_entity->set('field_program_start_weight', "0");
        $user_entity->set('field_overall_start_weight', "0");
        $user_entity->set('field_program_start_inches', "0");
        $user_entity->set('field_net_inches_lost', "0");
        $user_entity->set('field_goal_achieved', "0");

        //Rules starting measurements
        $starting_view = Views::getView('rules_measurements');
        $starting_view->setDisplay('block_1');
        $starting_view->setArguments([$user_entity->id()]);
        $starting_view->execute();
        $starting_view_result = $starting_view->result;
         if($starting_view_result){
             $starting_measurements =  $starting_view_result[0]->_entity->getData()['field_total_measurements'];
              $user_entity->set('field_program_start_inches', $starting_measurements);
          }

        //Rules Ending Measurements
        $ending_view = Views::getView('rules_measurements');
        $ending_view->setDisplay('block_2');
        $ending_view->setArguments([$user_entity->id()]);
        $ending_view->execute();
        $ending_view_result = $ending_view->result;
       if($ending_view_result){
         $ending_measurements =  $ending_view_result[0]->_entity->getData()['field_total_measurements'];
         $total_inches_lost = (int)$starting_measurements - (int)$ending_measurements;
         $user_entity->set('field_net_inches_lost', $total_inches_lost);
        }  

        // max net weight gross weight          
        $max_gross_weight_view = Views::getView('rules_maximum_weight');
        $max_gross_weight_view->setDisplay('block_1');
        $max_gross_weight_view->setArguments([$submission->getOwnerId()]);
        $max_gross_weight_view->execute();
        $max_gross_weight_result = $max_gross_weight_view->result;
        if($max_gross_weight_result){
         foreach ($max_gross_weight_result as $key => $value) {
          $max_gross_weight[] =  $value->_entity->getData()['field_weight'];
          }
         $user_entity->set('field_overall_start_weight', max($max_gross_weight));
        }

        // max net weight
          $max_net_weight_view = Views::getView('rules_maximum_weight');
          $max_net_weight_view->setDisplay('block_2');
          $max_net_weight_view->setArguments([$submission->getOwnerId()]);
          $max_net_weight_view->execute();
         $max_net_weight_result = $max_net_weight_view->result;
        if($max_net_weight_result){
           $max_net_weight =  $max_net_weight_result[0]->_entity->getData()['field_weight'];
           $user_entity->set('field_program_start_weight', $max_net_weight);
        }

        // Last Weight.

            $last_weight_view = Views::getView('rules_maximum_weight');
            $last_weight_view->setDisplay('block_3');
            $last_weight_view->setArguments([$submission->getOwnerId()]);
            $last_weight_view->execute();
           $last_weight_result = $last_weight_view->result;
          if($last_weight_result){
             $last_weight =  $last_weight_result[0]->_entity->getData()['field_weight'];
             //$account->set('field_program_start_inches', 0);
           
             $program_start_weight = $user_entity->field_program_start_weight->value;
             $net_weight_loss = $program_start_weight - $last_weight;
             $user_entity->set('field_net_weight_loss',$net_weight_loss);
             $field_overall_start_weight = $user_entity->field_overall_start_weight->value;
             $gross_weight_loss = $field_overall_start_weight - $last_weight;
             $user_entity->set('field_gross_weight_loss',$gross_weight_loss);
  
          
          }
          $user_entity->changed->preserve = TRUE;
          $user_entity->save();  


    }
  }
}
