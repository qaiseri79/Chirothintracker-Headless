<?php

namespace Drupal\custom_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;

/**
 * Class AddOrUpdateSession.
 */
class AddOrUpdateSession extends ControllerBase
{

  public function AddorUpdateSession_lipolight($entity, $entity_id)
  {
    $profile_id = $entity->get('field_patient_profile')->getValue()[0]['target_id'];
    $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');
    $profile_entity = $storage->load($profile_id);
    if ($profile_entity) {
      $calf_left_result = $this->calculate_inches("inches_calf_left_cs", "block_1", "block_2", "field_calf_left", $profile_id);
      if (!$calf_left_result) {
        $profile_entity->set("field_calf_left_loss", $calf_left_result);
      }
      $calf_right_result = $this->calculate_inches("inches_calf_right_cs", "block_1", "block_2", "field_calf_right", $profile_id);
      if (!$calf_right_result) {
        $profile_entity->set("field_calf_right_loss", $calf_right_result);
      }
      $thigh_left_result = $this->calculate_inches("inches_thigh_left_cs", "block_1", "block_2", "field_thigh_left", $profile_id);
      if (!$thigh_left_result) {
        $profile_entity->set("field_thigh_left_loss", $thigh_left_result);
      }
      $thigh_right_result = $this->calculate_inches("inches_thigh_right_cs", "block_1", "block_2", "field_thigh_right", $profile_id);
      if (!$thigh_right_result) {
        $profile_entity->set("field_thigh_right_loss", $thigh_right_result);
      }
      $back_bra_line_result = $this->calculate_inches("inches_back_bra_line_cs", "block_1", "block_2", "field_back_bra_line", $profile_id);
      if (!$back_bra_line_result) {
        $profile_entity->set("field_back_bra_line_loss", $back_bra_line_result);
      }
      $inches_hips_result = $this->calculate_inches("inches_hips_cs", "block_1", "block_2", "field_hips", $profile_id);
      if (!$inches_hips_result) {
        $profile_entity->set("field_hips_loss", $inches_hips_result);
      }
      $inches_chest_result = $this->calculate_inches("inches_chest_cs", "block_1", "block_2", "field_chest", $profile_id);
      if (!$inches_chest_result) {
        $profile_entity->set("field_chest_loss", $inches_chest_result);
      }
      $inches_arm_right_bicep_result = $this->calculate_inches("inches_arm_right_bicep_cs", "block_1", "block_2", "field_arm_right_bicep", $profile_id);
      if (!$inches_arm_right_bicep_result) {
        $profile_entity->set("field_arm_right_bicep_loss", $inches_arm_right_bicep_result);
      }
      $inches_arm_left_bicep_result = $this->calculate_inches("inches_arm_left_bicep_cs", "block_1", "block_2", "field_arm_left_bicep", $profile_id);
      if (!$inches_arm_left_bicep_result) {
        $profile_entity->set("field_arm_left_bicep_loss", $inches_arm_left_bicep_result);
      }
      $inches_shoulders_result = $this->calculate_inches("inches_shoulders_cs", "block_1", "block_2", "field_shoulders", $profile_id);
      if (!$inches_shoulders_result) {
        $profile_entity->set("field_shoulders_loss", $inches_shoulders_result);
      }
      $inches_neck_result = $this->calculate_inches("inches_neck_cs", "block_1", "block_2", "field_neck", $profile_id);
      if (!$inches_neck_result) {
        $profile_entity->set("field_neck_loss", $inches_neck_result);
      }
      $inches_abdomen_result = $this->calculate_inches("inches_abdomen_cs", "block_1", "block_2", "field_abdomen", $profile_id);
      if (!$inches_abdomen_result) {
        $profile_entity->set("field_abdomen_loss", $inches_abdomen_result);
      }
      $inches_above_umbilicus_result = $this->calculate_inches("inches_above_umbilicus_cs", "block_1", "block_2", "field_above_umbilicus", $profile_id);
      if (!$inches_above_umbilicus_result) {
        $profile_entity->set("field_above_umbilicus_loss", $inches_above_umbilicus_result);
      }
      $inches_below_umbilicus_result = $this->calculate_inches("inches_below_umbilicus_cs", "block_1", "block_2", "field_below_umbilicus", $profile_id);
      if (!$inches_below_umbilicus_result) {
        $profile_entity->set("field_below_umbilicus_loss", $inches_below_umbilicus_result);
      }

      $profile_entity->save();

    }

  }


  public function AddorUpdateSession_zerona($entity, $decrement_session = FALSE)
  {
    $profile = $entity->get('field_patient_profile')->entity;

    if (!$profile) {
      return;
    }

    $profile_id = $profile->id();
    $total_inches_loss = 0;

    // Mapping of measurement fields.
    $measurements = [
      'calf_left' => ['inches_calf_left_cs', 'block_1', 'block_2', 'field_calf_left'],
      'calf_right' => ['inches_calf_right_cs', 'block_1', 'block_2', 'field_calf_right'],
      'thigh_left' => ['inches_thigh_left_cs', 'block_1', 'block_2', 'field_thigh_left'],
      'thigh_right' => ['inches_thigh_right_cs', 'block_1', 'block_2', 'field_thigh_right'],
      'back_bra_line' => ['inches_back_bra_line_cs', 'block_1', 'block_2', 'field_back_bra_line'],
      'hips' => ['inches_hips_cs', 'block_1', 'block_2', 'field_hips'],
      'chest' => ['inches_chest_cs', 'block_1', 'block_2', 'field_chest'],
      'arm_right_bicep' => ['inches_arm_right_bicep_cs', 'block_1', 'block_2', 'field_arm_right_bicep'],
      'arm_left_bicep' => ['inches_arm_left_bicep_cs', 'block_1', 'block_2', 'field_arm_left_bicep'],
      'shoulders' => ['inches_shoulders_cs', 'block_1', 'block_2', 'field_shoulders'],
      'neck' => ['inches_neck_cs', 'block_1', 'block_2', 'field_neck'],
      'abdomen' => ['inches_abdomen_cs', 'block_1', 'block_2', 'field_abdomen'],
      'above_umbilicus' => ['inches_above_umbilicus_cs', 'block_1', 'block_2', 'field_above_umbilicus'],
      'below_umbilicus' => ['inches_below_umbilicus_cs', 'block_1', 'block_2', 'field_below_umbilicus'],
    ];

    // Loop through and handle all in one pass
    foreach ($measurements as $key => [$inch_field, $block1, $block2, $entity_field]) {
      $result = $this->calculate_inches($inch_field, $block1, $block2, $entity_field, $profile_id);
      $profile->set("field_{$key}_loss", $result);
      $total_inches_loss += $result;
    }
    $profile->set("field_profile_inches_lost", $total_inches_loss);

    // Decrement session if this is a new insert
    if ($decrement_session && $profile->hasField('field_sessions_available')) {
      $sessions = (int) $profile->get('field_sessions_available')->value;
      $profile->set('field_sessions_available', max(0, $sessions - 1));
    }
    $profile->save();
  }


  public function AddorUpdateSession_UltraSlim($entity, $entity_id)
  {
    $profile_id = $entity->get('field_patient_profile')->getValue()[0]['target_id'];
    $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');
    $profile_entity = $storage->load($profile_id);


    if ($profile_entity) {
      $inches_waistbuster_zone_6_left_thigh_result = $this->calculate_inches("inches_waistbuster_zone_6_female_left_thigh_cs", "block_1", "block_2", "field_waist_buster_zone_6l", $profile_id);
      if (!$inches_waistbuster_zone_6_left_thigh_result) {
        $profile_entity->set("field_wastbuster_zone_6l_loss", $inches_waistbuster_zone_6_left_thigh_result);
      } else {
        $profile_entity->set("field_wastbuster_zone_6l_loss", 0);
      }

      $inches_waistbuster_zone_6_right_thigh_result = $this->calculate_inches("inches_waistbuster_zone_6_cs", "block_1", "block_2", "field_waistbuster_zone_6", $profile_id);
      if (!$inches_waistbuster_zone_6_right_thigh_result) {
        $profile_entity->set("field_waistbuster_zone_6_loss", $inches_waistbuster_zone_6_right_thigh_result);
      } else {
        $profile_entity->set("field_waistbuster_zone_6_loss", 0);
      }

      $inches_waistbuster_zone_5_result = $this->calculate_inches("inches_waistbuster_zone_5_cs", "block_1", "block_2", "field_waistbuster_zone_5", $profile_id);
      if (!$inches_waistbuster_zone_5_result) {
        $profile_entity->set("field_waistbuster_zone_5_loss", $inches_waistbuster_zone_5_result);
      } else {
        $profile_entity->set("field_waistbuster_zone_5_loss", 0);
      }

      $inches_waistbuster_zone_4_result = $this->calculate_inches("inches_waistbuster_zone_4_cs", "block_1", "block_2", "field_waistbuster_zone_4", $profile_id);
      if (!$inches_waistbuster_zone_4_result) {
        $profile_entity->set("field_waistbuster_zone_4_loss", $inches_waistbuster_zone_4_result);
      } else {
        $profile_entity->set("field_waistbuster_zone_4_loss", 0);
      }


      $inches_waistbuster_zone_3_result = $this->calculate_inches("inches_waistbuster_zone_3_cs", "block_1", "block_2", "field_waistbuster_zone_3", $profile_id);
      if (!$inches_waistbuster_zone_3_result) {
        $profile_entity->set("field_waistbuster_zone_3_loss", $inches_waistbuster_zone_3_result);
      }

      $inches_waistbuster_zone_2_result = $this->calculate_inches("inches_waistbuster_zone_2_cs", "block_1", "block_2", "field_waistbuster_zone_2", $profile_id);
      if (!$inches_waistbuster_zone_2_result) {
        $profile_entity->set("field_waistbuster_zone_2_loss", $inches_waistbuster_zone_2_result);
      }
      $inches_waistbuster_zone_1_result = $this->calculate_inches("inches_waistbuster_zone_1_cs", "block_1", "block_2", "field_waistbuster_zone_1", $profile_id);
      if (!$inches_waistbuster_zone_1_result) {
        $profile_entity->set("field_waistbuster_zone_1_loss", $inches_waistbuster_zone_1_result);
      }

      /////////////////////////////////////Visceral Zone////////////////////////////////////////////
      $inches_visceral_fat_zone_6_result = $this->calculate_inches("inches_visceral_fat_zone_6_cs", "block_1", "block_2", "field_visceral_fat_zone_6", $profile_id);
      if (!$inches_visceral_fat_zone_6_result) {
        $profile_entity->set("field_visceral_fat_zone_6_loss", $inches_visceral_fat_zone_6_result);
      }
      $inches_visceral_fat_zone_5_result = $this->calculate_inches("inches_visceral_fat_zone_5_cs", "block_1", "block_2", "field_visceral_fat_zone_5", $profile_id);
      if (!$inches_visceral_fat_zone_5_result) {
        $profile_entity->set("field_visceral_fat_zone_5_loss", $inches_visceral_fat_zone_5_result);
      }

      $inches_visceral_fat_zone_4_result = $this->calculate_inches("inches_visceral_fat_zone_4_cs", "block_1", "block_2", "field_visceral_fat_zone_4", $profile_id);
      if (!$inches_visceral_fat_zone_4_result) {
        $profile_entity->set("field_visceral_fat_zone_4_loss", $inches_visceral_fat_zone_4_result);
      }
      $inches_visceral_fat_zone_3_result = $this->calculate_inches("inches_visceral_fat_zone_3_cs", "block_1", "block_2", "field_visceral_fat_zone_3", $profile_id);
      if (!$inches_visceral_fat_zone_3_result) {
        $profile_entity->set("field_visceral_fat_zone_3_loss", $inches_visceral_fat_zone_3_result);
      }
      $inches_visceral_fat_zone_2_result = $this->calculate_inches("inches_visceral_fat_zone_2_cs", "block_1", "block_2", "field_visceral_fat_zone_2", $profile_id);
      if (!$inches_visceral_fat_zone_2_result) {
        $profile_entity->set("field_visceral_fat_zone_2_loss", $inches_visceral_fat_zone_2_result);
      }
      $inches_visceral_fat_zone_1_result = $this->calculate_inches("inches_visceral_fat_zone_1_cs", "block_1", "block_2", "field_visceral_fat_zone_1", $profile_id);
      if (!$inches_visceral_fat_zone_1_result) {
        $profile_entity->set("field_visceral_fat_zone_1_loss", $inches_visceral_fat_zone_1_result);
      }

      $inches_thigh_right_result = $this->calculate_inches("inches_thigh_right_cs", "block_1", "block_2", "field_thigh_right", $profile_id);
      if (!$inches_thigh_right_result) {
        $profile_entity->set("field_thigh_right_loss", $inches_thigh_right_result);
      }
      $inches_thigh_left_result = $this->calculate_inches("inches_thigh_left_cs", "block_1", "block_2", "field_thigh_left", $profile_id);
      if (!$inches_thigh_left_result) {
        $profile_entity->set("field_thigh_left_loss", $inches_thigh_left_result);
      }

      $inches_back_bra_line_result = $this->calculate_inches("inches_back_bra_line_cs", "block_1", "block_2", "field_back_bra_line", $profile_id);
      if (!$inches_back_bra_line_result) {
        $profile_entity->set("field_back_bra_line_loss", $inches_back_bra_line_result);
      }
      $inches_buttocks_result = $this->calculate_inches("inches_buttocks_cs", "block_1", "block_2", "field_buttocks", $profile_id);
      if (!$inches_buttocks_result) {
        $profile_entity->set("field_buttocks_loss", $inches_buttocks_result);
      }

      $inches_hips_result = $this->calculate_inches("inches_hips_cs", "block_1", "block_2", "field_hips", $profile_id);
      if (!$inches_hips_result) {
        $profile_entity->set("field_hips_loss", $inches_hips_result);
      }
      $inches_arm_left_bicep_result = $this->calculate_inches("inches_arm_right_bicep_cs", "block_1", "block_2", "field_arm_right_bicep", $profile_id);
      if (!$inches_arm_left_bicep_result) {
        $profile_entity->set("field_arm_right_bicep_loss", $inches_arm_left_bicep_result);
      }

      $inches_arm_left_bicep_result = $this->calculate_inches("inches_arm_left_bicep_cs", "block_1", "block_2", "field_arm_left_bicep", $profile_id);
      if (!$inches_arm_left_bicep_result) {
        $profile_entity->set("field_arm_left_bicep_loss", $inches_arm_left_bicep_result);
      }
      $inches_neck_result = $this->calculate_inches("inches_neck_cs", "block_1", "block_2", "field_neck", $profile_id);
      if (!$inches_neck_result) {
        $profile_entity->set("field_neck_loss", $inches_neck_result);
      }
      $inches_abdomen_result = $this->calculate_inches("inches_abdomen_cs", "block_1", "block_2", "field_abdomen", $profile_id);
      if (!$inches_abdomen_result) {
        $profile_entity->set("field_abdomen_loss", $inches_abdomen_result);
      }
      $profile_entity->save();

    }

  }

  public function calculate_inches($view_name, $last_view_display, $first_view_display, $field_name, $profile_id)
  {
    $first_field = "";
    $last_field = "";
    $last_field_result = $this->getView($view_name, $last_view_display, $profile_id);
    if ($last_field_result) {
      $last_field = $last_field_result[0]->_entity->get($field_name)->getValue()[0]['value'];
    }
    $first_field_result = $this->getView($view_name, $first_view_display, $profile_id);
    if ($first_field_result) {
      $first_field = $first_field_result[0]->_entity->get($field_name)->getValue()[0]['value'];
    }

    if ($first_field && $last_field) {
      $total_loss = (float) $first_field - (float) $last_field;
    } else {
      $total_loss = 0;
    }

    return $total_loss;
  }

  public function getView($view_name, $display_id, $view_argument)
  {

    $fetch_view = Views::getView($view_name);
    $fetch_view->setDisplay($display_id);
    if ($view_argument) {
      $fetch_view->setArguments([$view_argument]);
    }
    $fetch_view->execute();
    return $fetch_view->result;

  }




}
