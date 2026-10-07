<?php

namespace Drupal\custom_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Class ResetStartingValues.
 */
class ResetStartingValues extends ControllerBase
{

  // public function CalculateProgramDay($user_entity){
//         $days = 0;
//         $field_user_current_program_day_c = $user_entity->get('field_user_current_program_day_c')->getValue();
//         $field_weight_loss_phase = $user_entity->get('field_weight_loss_phase')->getValue();
//         $weight_loss_phase = "";
//         if($field_weight_loss_phase){
//            $weight_loss_phase = $field_weight_loss_phase[0]["value"];
//         }


  //     if($field_user_current_program_day_c){
//           $user_current_program_day_c = $field_user_current_program_day_c[0]["value"];
//           //$days = $user_current_program_day_c ;
//           $user_entity->set('field_current_program_day', $user_current_program_day_c);

  //           //Calculate Program Day (Zero)
//           if($user_current_program_day_c == 0){
//              //$days = 0;
//              $user_entity->set('field_current_program_day', 0);
//              $user_entity->set('field_program_day_term', array());
//              //Set Current Program Day (Zero-Day)
//               if($weight_loss_phase != "phase-0"){
//                 $user_entity->set('field_weight_loss_phase', "phase-0");
//               }

  //           }

  //         //Evaluate Weight Loss Phases
//         // Set Current Program Day (Loading Phase)
//         if ($user_current_program_day_c == 1 || $user_current_program_day_c == 2) {
//           $user_entity->set('field_weight_loss_phase', "phase-1");
//         }

  //        // Set Current Program Day (Losing Phase)
//         if($weight_loss_phase == "phase-1"){
//            if($user_current_program_day_c > 2){
//               $user_entity->set('field_weight_loss_phase', "phase-2");
//            }
//         }

  //           // Program Day Term
//          if($user_current_program_day_c > 0){
//                 $term_name = 1;
//                 $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
//                 ->loadByProperties(['name' => $term_name, 'vid' => 'program_days']);
//                 if($term){
//                   $term = reset($term);
//                   $term_id = $term->id();
//                   $user_entity->set('field_program_day_term', array("target_id" => $term_id));
//                 }else{
//                   $user_entity->set('field_program_day_term', array());
//                 }
//          }   

  //   }
//        $user_entity->changed->preserve = TRUE;
//        $user_entity->save();


  //   }

  public function Calculate_ProgramDay($user_entity)
  {
    // calculate program day
    // $this->_calculate_program_day($user_entity);
    //Calculate Program Day (Zero)
    // $field_program_day = $user_entity->get('field_program_day')->getValue();
    // $field_weight_loss_phase = $user_entity->get('field_weight_loss_phase')->getValue();
    // $weight_loss_phase = "";
    // if ($field_weight_loss_phase) {
    //   $weight_loss_phase = $field_weight_loss_phase[0]["value"];
    // }
    // if ($field_program_day) {
    //   $program_day = $field_program_day[0]["value"];
    //   if ($program_day == 0) {
    //     $days = 0;
    //     $user_entity->set('field_program_day_term', array());
    //     $user_entity->set('field_weight_loss_phase', "phase-0");
    //   }
    //   /* Evaluate Weight Loss Phases */
    //   // Set Current Program Day (Loading Phase)
    //   if ($program_day == 1 || $program_day == 2) {
    //     $user_entity->set('field_weight_loss_phase', "phase-1");
    //   }
    //   // Set Current Program Day (Losing Phase)
    //   if ($program_day > 2) {
    //     if ($weight_loss_phase != "phase-3" && $weight_loss_phase != "phase-4") {
    //       $user_entity->set('field_weight_loss_phase', "phase-2");
    //     }
    //   }
    //   if ($program_day > 0) {
    //     $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
    //       ->loadByProperties(['name' => $program_day, 'vid' => 'program_days']);
    //     if ($term) {
    //       $term = reset($term);
    //       $term_id = $term->id();
    //       $user_entity->set('field_program_day_term', array("target_id" => $term_id));
    //     } else {
    //       $user_entity->set('field_program_day_term', array());
    //     }
    //   }
    // }
    // $user_entity->changed->preserve = TRUE;
    // $user_entity->save();
  }

  // public function _calculate_program_day($user_entity)
  // {
  //   if (in_array('enrolled_patient', $user_entity->getRoles())) {
  //     $field_program_start_date = $user_entity->get('field_program_start_date')->getValue();
  //     if ($field_program_start_date) {
  //       $start_date = $field_program_start_date[0]["value"];
  //       $startDate = new DrupalDateTime($start_date);
  //       $tomorrowDate = new DrupalDateTime('tomorrow');
  //       if ($startDate->format('U') < $tomorrowDate->format('U')) {
  //         $start_date = DrupalDateTime::createFromTimestamp(strtotime($start_date));
  //         $field_date = new DrupalDateTime('today');
  //         $program_day = $field_date->diff($start_date)->days + 1;
  //         $program_day_invert = $field_date->diff($start_date)->invert;
  //         $value = ($program_day_invert !== 1) ? (($start_date == $field_date) ? 1 : 0) : $program_day;
  //         $user_entity->set('field_current_program_day', $value);
  //         $user_entity->set('field_program_day', $value);
  //         $user_entity->changed->preserve = TRUE;
  //         $user_entity->save();
  //       } else {
  //         $user_entity->set('field_current_program_day', 0);
  //         $user_entity->set('field_program_day', 0);
  //         $user_entity->changed->preserve = TRUE;
  //         $user_entity->save();
  //       }
  //     }
  //   }
  // }

  public function setLate($user_entity)
  {
    //Rules starting measurements
    $last_submission = Views::getView('last_submission_cs');
    $last_submission->setDisplay('block_3');
    $last_submission->setArguments([$user_entity->id()]);
    $last_submission->execute();
    $last_submission_result = $last_submission->result;
    if ($last_submission_result) {
      $field_date = $last_submission_result[0]->_entity->get('field_date')->getValue()[0]['value'];
      $start = new DrupalDateTime('now');
      $end = new DrupalDateTime($field_date);
      $days_difference = $start->diff($end)->format("%r%a");
      if ($days_difference < 0) {
        $difference = 3;
      } else {
        $difference = 2;
      }

    } else {
      $difference = 1;
    }

    if ($difference) {
      $user_entity->set('field_late', $difference);
      $user_entity->changed->preserve = TRUE;
      $user_entity->save();
    }

  }
  public function resetStartingValue($user_entity)
  {

    if (in_array('enrolled_patient', $user_entity->getRoles())) {
      $user_entity->set('field_goal_achieved', 0);
      //Rules starting measurements
      $starting_view = Views::getView('rules_measurements_cs');
      $starting_view->setDisplay('block_1');
      $starting_view->setArguments([$user_entity->id()]);
      $starting_view->execute();
      $starting_view_result = $starting_view->result;
      if ($starting_view_result) {
        $starting_measurements = $starting_view_result[0]->_entity->get('field_total_measurements')->getValue()[0]['value'];
        $user_entity->set('field_program_start_inches', $starting_measurements);
      } else {
        $user_entity->set('field_program_start_inches', 0);
      }
      //Rules Ending Measurements
      $ending_view = Views::getView('rules_measurements_cs');
      $ending_view->setDisplay('block_2');
      $ending_view->setArguments([$user_entity->id()]);
      $ending_view->execute();
      $ending_view_result = $ending_view->result;
      if ($ending_view_result) {
        $ending_measurements = $ending_view_result[0]->_entity->get('field_total_measurements')->getValue()[0]['value'];
        if ($starting_measurements && $ending_measurements) {
          $total_inches_lost = (float) $starting_measurements - (float) $ending_measurements;
          $user_entity->set('field_net_inches_lost', $total_inches_lost);
        }

      } else {
        $user_entity->set('field_net_inches_lost', 0);
      }
      // max net weight gross weight          
      $max_gross_weight_view = Views::getView('rules_maximum_weight_cs');
      $max_gross_weight_view->setDisplay('block_1');
      $max_gross_weight_view->setArguments([$user_entity->id()]);
      $max_gross_weight_view->execute();
      $max_gross_weight_result = $max_gross_weight_view->result;
      if ($max_gross_weight_result) {
        foreach ($max_gross_weight_result as $key => $value) {
          $max_gross_weight[] = $value->_entity->get('field_weight')->getValue()[0]['value'];
        }
        $user_entity->set('field_overall_start_weight', max($max_gross_weight));
      } else {
        $user_entity->set('field_overall_start_weight', 0);
      }
      // max net weight
      $max_net_weight_view = Views::getView('rules_maximum_weight_cs');
      $max_net_weight_view->setDisplay('block_2');
      $max_net_weight_view->setArguments([$user_entity->id()]);
      $max_net_weight_view->execute();
      $max_net_weight_result = $max_net_weight_view->result;
      if ($max_net_weight_result) {
        $max_net_weight = $max_net_weight_result[0]->_entity->get('field_weight')->getValue()[0]['value'];
        $user_entity->set('field_program_start_weight', $max_net_weight);
      } else {
        $user_entity->set('field_program_start_weight', 0);
      }
      // Last Weight.
      $last_weight_view = Views::getView('rules_maximum_weight_cs');
      $last_weight_view->setDisplay('block_3');
      $last_weight_view->setArguments([$user_entity->id()]);
      $last_weight_view->execute();
      $last_weight_result = $last_weight_view->result;
      if ($last_weight_result) {
        $last_weight = $last_weight_result[0]->_entity->get('field_weight')->getValue()[0]['value'];
        $program_start_weight = $user_entity->field_program_start_weight->value;
        $net_weight_loss = (float) $program_start_weight - (float) $last_weight;
        $user_entity->set('field_net_weight_loss', $net_weight_loss);
        $field_overall_start_weight = $user_entity->field_overall_start_weight->value;
        $gross_weight_loss = (float) $field_overall_start_weight - (float) $last_weight;
        $user_entity->set('field_gross_weight_loss', $gross_weight_loss);
      } else {
        $user_entity->set('field_net_weight_loss', 0);
      }
      $user_entity->changed->preserve = TRUE;
      $user_entity->save();
    }
  }
}
