<?php

namespace Drupal\chirothintracker_custom\Controller;

use Drupal\Core\Controller\ControllerBase;
use \Symfony\Component\HttpFoundation\Response;
use Drupal\node\Entity\Node;
use GuzzleHttp\Exception\ClientException;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionForm;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\custom_module\Controller\UserCurrentProgramDay;
use Drupal\custom_module\Controller\ResetStartingValues;
use Drupal\Core\Render\Markup;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Drush;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Drupal\commerce_recurring\Entity\BillingSchedule;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_order\Entity\OrderItem;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;

class MyECKController extends ControllerBase {
    protected $httpClient;

  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client')
    );
  }
  
public function MyMethodClinic() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/eck_clinic.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
       foreach ($data as $key => $value) {
                 $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
                 $clinic_entity = $storage->load($value['Id']);
                  $clinic_entity->set("field_clinic_body",$value['field_body']);
                  $clinic_entity->set("field_footer_logo",$value['field_footer_logo']);
                  $clinic_entity->set("field_logo",$value['field_logo']);
                  $clinic_entity->set("field_false_clinic",$value['field_false_clinic']);
                  $clinic_entity->set("field_hide_shared_resources",$value['field_hide_shared_resources']);
                  $clinic_entity->set("field_custom_daily_messages",$value['field_custom_daily_messages']);
                  $clinic_entity->set("field_ecommerce_enabled",$value['field_ecommerce_enabled']);
                  $clinic_entity->set("field_clinic_mail",$value['field_clinic_mail']);
                  $clinic_entity->set("field_laser_clinic",$value['field_laser_clinic']);
                  $clinic_entity->set("field_video_messages",$value['field_video_messages']);
                  $clinic_entity->set("field_mind_set_work",$value['field_mind_set_work']);
                  $clinic_entity->set("field_intake_forms_enabled",$value['field_intake_forms_enabled']);
                  $clinic_entity->set("field_enrollment_package",$value['field_enrollment_package']);
                  $clinic_entity->set("field_brand",$value['field_brand']);
                  $clinic_entity->save();
       }


       // echo "<pre>"; print_r($total_count);echo "</pre>";
        echo "<pre>"; print_r(count($data));echo "</pre>";
      exit;
  }//end of method

  public function MyMethodClinicLocation() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/eck_clinic_location.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
       foreach ($data as $key => $value) {
                 $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
                 $clinic_entity = $storage->load($value['Id']);
                 $clinic_entity->set("field_clinic",$value['Clinic']);
                 $clinic_entity->save();
       }


        echo "<pre>"; print_r(count($data));echo "</pre>";
        //echo "<pre>"; print_r($data);echo "</pre>";
      exit;
  }//end of method

  public function MyMethodChirothinProfile() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/patient_profile_weight_loss.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
       foreach ($data as $key => $value) {
                 $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');
               
                $clinic_entity = $storage->load($value['Id']);
                $clinic_entity->set("field_program_start_date",['value' => date('Y-m-d', strtotime($value['field_program_start_date']))]);
                $clinic_entity->set("field_patient",$value['field_patient']);
                $clinic_entity->set("field_program_start_weight",$value['field_program_start_weight']);
                $clinic_entity->set("field_abdomen_loss",$value['field_abdomen_loss']);
                $clinic_entity->set("field_arm_left_bicep_loss",$value['field_arm_left_bicep_loss']);
                $clinic_entity->set("field_arm_right_bicep_loss",$value['field_arm_right_bicep_loss']);
                $clinic_entity->set("field_back_bra_line_loss",$value['field_back_bra_line_loss']);
                $clinic_entity->set("field_buttocks_loss",$value['field_buttocks_loss']);
                $clinic_entity->set("field_calf_left_loss",$value['field_calf_left_loss']);
                $clinic_entity->set("field_calf_right_loss",$value['field_calf_right_loss']);
                $clinic_entity->set("field_hips_loss",$value['field_hips_loss']);
                $clinic_entity->set("field_neck_loss",$value['field_neck_loss']);
                $clinic_entity->set("field_chest_loss",$value['field_chest_loss']);
                $clinic_entity->set("field_shoulders_loss",$value['field_shoulders_loss']);
                $clinic_entity->set("field_thigh_left_loss",$value['field_thigh_left_loss']);
                $clinic_entity->set("field_thigh_right_loss",$value['field_thigh_right_loss']);
                $clinic_entity->set("field_profile_inches_lost",$value['field_profile_inches_lost']);
               $clinic_entity->save();
       }


       // echo "<pre>"; print_r($strtotime($row['LastChanged']));echo "</pre>";
      echo "<pre>"; print_r(count($data));echo "</pre>";
      exit;
  }//end of method
    public function MyMethodUlrtraSlim() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/patient_profile_ultra_slim.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
       foreach ($data as $key => $value) {
                  $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');  
                  $clinic_entity = $storage->load($value['Id']);
                  $clinic_entity->set("field_program_start_date",['value' => date('Y-m-d', strtotime($value['field_program_start_date']))]);
                  $clinic_entity->set("field_sessions_available",$value['field_sessions_available']);
                  $clinic_entity->set("field_patient",$value['field_patient']);
                  $clinic_entity->set("field_abdomen_loss",$value['field_abdomen_loss']);
                  $clinic_entity->set("field_arm_left_bicep_loss",$value['field_arm_left_bicep_loss']);
                  $clinic_entity->set("field_arm_right_bicep_loss",$value['field_arm_right_bicep_loss']);
                  $clinic_entity->set("field_back_bra_line_loss",$value['field_back_bra_line_loss']);
                  $clinic_entity->set("field_buttocks_loss",$value['field_buttocks_loss']);
                  $clinic_entity->set("field_hips_loss",$value['field_hips_loss']);
                  $clinic_entity->set("field_neck_loss",$value['field_neck_loss']);
                  $clinic_entity->set("field_thigh_left_loss",$value['field_thigh_left_loss']);
                  $clinic_entity->set("field_thigh_right_loss",$value['field_thigh_right_loss']);
                  $clinic_entity->set("field_visceral_fat_zone_1_loss",$value['field_visceral_fat_zone_1_loss']);
                  $clinic_entity->set("field_visceral_fat_zone_2_loss",$value['field_visceral_fat_zone_2_loss']);
                  $clinic_entity->set("field_visceral_fat_zone_3_loss",$value['field_visceral_fat_zone_3_loss']);
                  $clinic_entity->set("field_visceral_fat_zone_4_loss",$value['field_visceral_fat_zone_4_loss']);
                  $clinic_entity->set("field_visceral_fat_zone_5_loss",$value['field_visceral_fat_zone_5_loss']);
                  $clinic_entity->set("field_visceral_fat_zone_6_loss",$value['field_visceral_fat_zone_6_loss']);
                  $clinic_entity->set("field_waistbuster_zone_1_loss",$value['field_waistbuster_zone_1_loss']);
                  $clinic_entity->set("field_waistbuster_zone_2_loss",$value['field_waistbuster_zone_2_loss']);
                  $clinic_entity->set("field_waistbuster_zone_3_loss",$value['field_waistbuster_zone_3_loss']);
                  $clinic_entity->set("field_waistbuster_zone_4_loss",$value['field_waistbuster_zone_4_loss']);
                  $clinic_entity->set("field_waistbuster_zone_5_loss",$value['field_waistbuster_zone_5_loss']);
                  $clinic_entity->set("field_waistbuster_zone_6_loss",$value['field_waistbuster_zone_6_loss']);
                  $clinic_entity->set("field_wastbuster_zone_6l_loss",$value['field_wastbuster_zone_6l_loss']);
                  //$clinic_entity->save();
       }


       // echo "<pre>"; print_r($strtotime($row['LastChanged']));echo "</pre>";
       echo "<pre>"; print_r($data);echo "</pre>";
      exit;
  }//end of method
    public function MyMethodLipoLight() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/patient_profile_lipolight.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
       foreach ($data as $key => $value) {
                $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');
                $clinic_entity = $storage->load($value['Id']);
                $clinic_entity->set("field_program_start_date",['value' => date('Y-m-d', strtotime($value['field_program_start_date']))]);
                $clinic_entity->set("field_patient",$value['field_patient']);
                $clinic_entity->set("field_abdomen_loss",$value['field_abdomen_loss']);
                $clinic_entity->set("field_arm_left_bicep_loss",$value['field_arm_left_bicep_loss']);
                $clinic_entity->set("field_arm_right_bicep_loss",$value['field_arm_right_bicep_loss']);
                $clinic_entity->set("field_back_bra_line_loss",$value['field_back_bra_line_loss']);
                $clinic_entity->set("field_calf_left_loss",$value['field_calf_left_loss']);
                $clinic_entity->set("field_calf_right_loss",$value['field_calf_right_loss']);
                $clinic_entity->set("field_hips_loss",$value['field_hips_loss']);
                $clinic_entity->set("field_neck_loss",$value['field_neck_loss']);
                $clinic_entity->set("field_chest_loss",$value['field_chest_loss']);
                $clinic_entity->set("field_shoulders_loss",$value['field_shoulders_loss']);
                $clinic_entity->set("field_thigh_left_loss",$value['field_thigh_left_loss']);
                $clinic_entity->set("field_thigh_right_loss",$value['field_thigh_right_loss']);
                $clinic_entity->set("field_sessions_available",$value['field_sessions_available']);
                //$clinic_entity->save();
       }


       // echo "<pre>"; print_r($strtotime($row['LastChanged']));echo "</pre>";
     //  echo "<pre>"; print_r($data);echo "</pre>";
      exit;
  }//end of method
    public function MyMethodZerona() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/patient_profile_zerona.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
       foreach ($data as $key => $value) {
                $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');
                $clinic_entity = $storage->load($value['Id']);
                $clinic_entity->set("field_program_start_date",['value' => date('Y-m-d', strtotime($value['field_program_start_date']))]);
                $clinic_entity->set("field_patient",$value['field_patient']);
                $clinic_entity->set("field_abdomen_loss",$value['field_abdomen_loss']);
                $clinic_entity->set("field_arm_left_bicep_loss",$value['field_arm_left_bicep_loss']);
                $clinic_entity->set("field_arm_right_bicep_loss",$value['field_arm_right_bicep_loss']);
                $clinic_entity->set("field_back_bra_line_loss",$value['field_back_bra_line_loss']);
                $clinic_entity->set("field_calf_left_loss",$value['field_calf_left_loss']);
                $clinic_entity->set("field_calf_right_loss",$value['field_calf_right_loss']);
                $clinic_entity->set("field_hips_loss",$value['field_hips_loss']);
                $clinic_entity->set("field_neck_loss",$value['field_neck_loss']);
                $clinic_entity->set("field_chest_loss",$value['field_chest_loss']);
                $clinic_entity->set("field_shoulders_loss",$value['field_shoulders_loss']);
                $clinic_entity->set("field_thigh_left_loss",$value['field_thigh_left_loss']);
                $clinic_entity->set("field_thigh_right_loss",$value['field_thigh_right_loss']);
                $clinic_entity->set("field_sessions_available",$value['field_sessions_available']);
                //$clinic_entity->save();
       }


       // echo "<pre>"; print_r($strtotime($row['LastChanged']));echo "</pre>";
      echo "<pre>"; print_r($data);echo "</pre>";
      exit;
  }//end of method

    public function MyMethodIntake() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/patient_intake.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
        //echo "<pre>"; print_r("adfdsf");echo "</pre>";
       //exit;
       foreach ($data as $key => $value) {
         $message_entity = \Drupal\contact\Entity\Message::load($value['sid']);
         $domain = $value["Domain"];
         if (is_numeric($domain)) {
      
             $clinic_id = getClinicFromDomain($domain);
             //echo "<pre>"; print_r($data[$key]);echo "</pre>";
           //  echo "<pre>"; print_r(count($clinic_id));echo "</pre>";
              $message_entity->set('field_clinic',$clinic_id);
              $message_entity->save();
          //   echo "<pre>"; print_r($clinic_id);echo "</pre>";
         }
          // $clinic_id = getClinicFromDomain($value[0]["Domain"]);
       }


       
    // echo "<pre>"; print_r($data);echo "</pre>";
      exit;
  }//end of method

}
