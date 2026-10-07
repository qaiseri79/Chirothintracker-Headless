<?php

namespace Drupal\custom_module\Controller;

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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\user\Entity\User;
use Drupal\commerce_product\Entity\Product;


class Operations extends ControllerBase {
  
  
public function perform() {

  $shipping_methods = [];

  // Get shipments associated with the order.
  $shipments = \Drupal::entityTypeManager()
    ->getStorage('commerce_shipment')
    ->loadByProperties(['order_id' => 539]);
   $shipment = reset($shipments);
   $shipping_method = $shipment->getShippingMethod();
  //$shipping_method_id = $shipping_method->getPluginId();
  $shipping_method_label = $shipping_method->label();

 echo "<pre>"; print_r($shipping_method_label); echo "</pre>"; exit;
 //        $client = new Client();
 //        $response = $client->get('https://dev-chirothin10.pantheonsite.io/modules/custom/custom_module/json/subscriber_spreedsheet.json');
 //        $data = json_decode($response->getBody(), TRUE);

 //         //      $httpclient = new Client();
 //         //    $url = "https://home.chirothintracker.com/check_user_exists/bewell@arneclinic.com";
 //         //    $httpresponse = $httpclient->get($url);
 //         //    $check_user = json_decode($httpresponse->getBody(), TRUE);
            
 //         
 //        foreach ($data as $key => $value) {
            
 //            // $httpclient = new Client();
 //            // $url = "https://home.chirothintracker.com/check_user_exists/".$value["Email Address"];
 //            // $httpresponse = $httpclient->get($url);
 //            // $check_user = json_decode($httpresponse->getBody(), TRUE);
 //            // echo "<pre>"; print_r($check_user);  echo "</pre>"; 
 //             $user = user_load_by_mail($value["Email Address"]);
 //             if($user){
 //                    // $httpclient = new Client();
 //                    // $url = "https://home.chirothintracker.com/export/enrolled_patient/".$check_user[0]["Id"];
 //                    // $httpresponse = $httpclient->get($url);
 //                    // $enrolled_patient = json_decode($httpresponse->getBody(), TRUE);
 //                    //    echo "<pre>"; print_r($enrolled_patient);  echo "</pre>"; 
 //                    $clinic_id = $user->get("field_clinic")->getValue()[0]["target_id"];
 //                     $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
 //                     $clinic_entity = $storage->load($clinic_id);
 //                     $clinic_title = $clinic_entity->get("title")->getValue();
 //                    $view = Views::getView('clinician_subscription');
 //                    $view->setDisplay('embed_2');
 //                   $view->setArguments([$clinic_id]);
 //                    $view->execute();
 //                    $result = $view->result[0]->field_clinic_clinic_field_data_uid;
 //                    $total += $result;

 //             //       $enroll_patients = Views::getView('clinician_subscription');
 //             //        $enroll_patients->setDisplay('block_2');
 //             //        // contextual relationship filter
 //             //       $enroll_patients->setArguments([$clinic_id]);
 //             //        $enroll_patients->execute();
 //             //       // echo "<pre>"; print_r("Clinic Title: "); print_r($clinic_title[0]["value"]); print_r("(".$clinic_id.")"); echo "</pre>";
 //             // if($enroll_patients->result){
 //             //        foreach ($enroll_patients->result as $key => $value) {
 //             //             $user_id =  $value->field_clinic_clinic_field_data_uid;
 //             //            // echo "<pre>"; print_r("Clinic ID: "); print_r($clinic_id);  echo "</pre>"; 
                          
 //             //            // echo "<pre>"; print_r("Enrolled Patient ID: "); print_r($user_id);  echo "</pre>";
 //             //             $patients[] = $user_id;
 //             //        }
 //             //     } 

                 

 //                echo "<pre>"; print_r("User exists: ");  echo "</pre>"; 
 //                 //echo "<pre>"; print_r("Clinic ID: "); print_r($clinic_id);  echo "</pre>"; 
              
 //             }else{
 //                  echo "<pre>"; print_r("doesn't exists..."); echo "</pre>";
 //             }

 //        }
      
 // echo "<pre>"; print_r($total); echo "</pre>";
 //          exit;

 //        // $counter = 0;
 //        // foreach ($patients as $element) {

 //        //    // echo "<pre>"; print_r($element); echo "</pre>";
 //        //     $httpclient = new Client();
 //        //     $url = 'https://home.chirothintracker.com/export/log_y_progress_count/'.$element;
 //        //     $httpresponse = $httpclient->get($url);
 //        //     $data_result = json_decode($httpresponse->getBody(), TRUE);
 //        //     echo "<pre>"; print_r("Enrolled Patient ID: "); print_r($element); print_r("Log Progress Count".$data_result[0]["ID"]); echo "</pre>"; 
 //        //     $counter++;
 //        //     if ($counter >= 300) {
 //        //         break; // Exit loop after 1000 elements
 //        //     }
 //        // }


 //        // $chunkSize = 300;
 //        // $arraySize = count($patients);
 //        // $loopCount = ceil($arraySize / $chunkSize);

 //        // for ($i = 0; $i < $loopCount; $i++) {
 //        //     $startIndex = $i * $chunkSize;
 //        //     $chunk = array_slice($patients, $startIndex, $chunkSize);
            
 //        //     // Process $chunk, which contains up to 1000 elements
 //        //     foreach ($chunk as $element) {
 //        //         echo "<pre>"; print_r($element); echo "</pre>"; 
 //        //     }
 //        // }

 //        $chunkSize = 100;
 //        $chunks = array_chunk($patients, $chunkSize);
 //        $chunks = array_values($chunks);

 //             foreach ($chunks[49] as $element) {

 //           // echo "<pre>"; print_r($element); echo "</pre>";
 //            $httpclient = new Client();
 //            $url = 'https://home.chirothintracker.com/export/log_y_progress_count/'.$element;
 //            $httpresponse = $httpclient->get($url);
 //            $data_result = json_decode($httpresponse->getBody(), TRUE);
 //           // echo "<pre>"; print_r("Enrolled Patient ID: "); print_r($element); print_r("Log Progress Count".$data_result[0]["ID"]); echo "</pre>"; 
 //            $total += $data_result[0]["ID"];
         
           
 //        }
         
 //       echo "<pre>"; print_r($total); echo "</pre>"; exit;
 //        echo "<pre>"; print_r("Operations..."); echo "</pre>"; exit;
 //        return array();
    }

}