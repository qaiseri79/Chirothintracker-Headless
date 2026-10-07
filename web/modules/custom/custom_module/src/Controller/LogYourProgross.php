<?php

namespace Drupal\custom_module\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Form\FormBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\Views;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_module\Controller\ResetStartingValues;
  
/**
 * Class LogYourProgross.
 */
class LogYourProgross extends ControllerBase {


public function MarkasNew($submission_owner) {
     // flag the owner of the submission.
        $flag_service = \Drupal::service('flag');
        $flag = $flag_service->getFlagById('reviewed_patients'); 
        $patient = \Drupal\user\Entity\User::load($submission_owner);
        $chiropractor_id = $patient->get("field_chiropractor")->getValue()[0]["target_id"];
        $chiropractor = \Drupal\user\Entity\User::load($chiropractor_id);
        $flagging = $flag_service->getFlagging($flag, $patient, $chiropractor);
        if (!$flagging) {
          $flag_service->flag($flag, $patient, $chiropractor);
        }
}

public function CalculateTotalInches($data) {
        $neck = $data->get('field_neck')->getValue();
        $shoulders = $data->get('field_shoulders')->getValue();
        $chest = $data->get('field_chest')->getValue();
        $arm_left_bicep = $data->get('field_arm_left_bicep')->getValue();
        $arm_right_bicep = $data->get('field_arm_right_bicep')->getValue();
        $abdomen = $data->get('field_abdomen')->getValue();
        $hips = $data->get('field_hips')->getValue();
        $thigh_left = $data->get('field_thigh_left')->getValue();
        $thigh_right = $data->get('field_thigh_right')->getValue();
        $calf_left = $data->get('field_calf_left')->getValue();
        $calf_right = $data->get('field_calf_right')->getValue();

        $field_neck = !empty($neck) ? $neck[0]['value'] : '';
        $field_shoulders = !empty($shoulders) ? $shoulders[0]['value'] : '';
        $field_chest = !empty($chest) ? $chest[0]['value'] : '';
        $field_arm_left_bicep = !empty($arm_left_bicep) ? $arm_left_bicep[0]['value'] : '';
        $field_arm_right_bicep = !empty($arm_right_bicep) ? $arm_right_bicep[0]['value'] : '';
        $field_abdomen = !empty($abdomen) ? $abdomen[0]['value'] : '';
        $field_hips = !empty($hips) ? $hips[0]['value'] : '';
        $field_thigh_left = !empty($thigh_left) ? $thigh_left[0]['value'] : '';
        $field_thigh_right = !empty($thigh_right) ? $thigh_right[0]['value'] : '';
        $field_calf_left = !empty($calf_left) ? $calf_left[0]['value'] : '';
        $field_calf_right = !empty($calf_right) ? $calf_right[0]['value'] : '';
        $total = (float)$field_neck+(float)$field_shoulders+(float)$field_chest+(float)$field_arm_left_bicep+(float)$field_arm_right_bicep+(float)$field_abdomen+(float)$field_hips+(float)$field_thigh_left+(float)$field_thigh_right+(float)$field_calf_left+(float)$field_calf_right;
        return $total;
  }

}
