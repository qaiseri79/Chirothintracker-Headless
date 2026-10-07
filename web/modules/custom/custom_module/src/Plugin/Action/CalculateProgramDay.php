<?php

namespace Drupal\custom_module\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_calculate_program_day",
 *   label = @Translation("Calculate Program Day"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */

class CalculateProgramDay extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;




  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {

    $this->CalculateProgram($entity);

  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       return $object->access('update', $account, $return_as_object);
  }


  public function CalculateProgram($user_entity){
        $program_start_date_field = $user_entity->get('field_program_start_date')->getValue();
       if($program_start_date_field){
          $start_date_value = $program_start_date_field[0]['value'];
          $start_date = new DrupalDateTime($start_date_value);
          $tomorrow  = new DrupalDateTime("tomorrow");
          $endDate  = new DrupalDateTime("tomorrow");
        

          if ($start_date < $tomorrow) {
             $days = 0;
            // Calculate the difference in days.
             $days_text = (int) $start_date->diff($endDate)->format('%r%a');
             $days = $days_text;
             if(empty($days)){
              $days = 0;
             }
            $user_entity->set('field_current_program_day', $days);
            $user_entity->changed->preserve = TRUE;
            $user_entity->save();
            
          } 
       }
  }
}
