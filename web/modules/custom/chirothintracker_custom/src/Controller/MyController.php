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
use Drupal\contact\Entity\Message;


class MyController extends ControllerBase {
    protected $httpClient;

  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client')
    );
  }
  
public function MyMethod() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/resource.json';
       $domains_file_path = $module_path . '/src/Controller/domains.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
      
       $domains_json = file_get_contents($domains_file_path);
       $domains_data = json_decode($domains_json, true);

 
      //  $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
      // $clinic_entity = $storage->load(489);
      // $clinic_entity->set("field_laser_clinic", 1);
      // $clinic_entity->save();
  echo "<pre>"; print_r($f);echo "</pre>"; exit;
        exit;
      

 $nodee = Node::load(82);
 $all=$nodee->get("field_published_to")->getValue();
  if ($node) {
    // $node->set('field_published_to', $referenced_ids);
    // $node->changed->preserve = TRUE;
    // $node->save();
  } 

$nids = \Drupal::entityQuery('node')
  ->accessCheck(TRUE)
  ->condition('type', 'daily_message')   // Your content type
  //->condition('uid', 174)             // Optional: filter by author
  ->condition('status', 1)               // Only published nodes
  ->sort('changed', 'DESC')              // Sort by updated date, newest first
  ->execute();
    
$nodes = Node::loadMultiple($nids);
foreach ($nodes as $node) {

  $field_my_clinic = $node->get('field_my_clinic')->getValue()[0]['target_id'];
  // if($node->id() == 3002){
  //   $node->set('field_published_to', $field_my_clinic);
  //   $node->changed->preserve = TRUE;
  //   $node->save();
  // }
 // echo "<pre>"; print_r($node->id());echo "</pre>";
  $node->set('field_published_to', $field_my_clinic);
  $node->changed->preserve = TRUE;
  $node->save();
}
exit;
//$field_patient_uid = $new_message->get('field_patient_uid')->getValue();
             echo "<pre>"; print_r($all);echo "</pre>"; exit;
      $total_count = "";
       foreach ($data as $item) {
            $nid = $item['nid'];     
            $site_titles_raw = $item['Domain Sitename'];
            $field_body = $item['field_body'];
            $field_resource = $item['field_resource'];
            $field_resource_type = $item['field_resource_type'];
            $field_my_clinic = $item['field_my_clinic'];
            $new_references= [];
        //     if (trim($item['Domain Send to All']) === 'Yes') {
        //             $trimmed_val = array_map('trim', explode('"', $site_titles_raw));
        //             $referenced_ids = [];
        //             $id_count = "";
        //             foreach ($trimmed_val as $key => $value) {
        //                           $replacements = [
        //                                 'Drs. Ed & Sandie McCuiston' => 'Drs. Ed & Dr. Sandie McCuiston',
        //                                 'Durrum Chiropractic' => 'Dr. Courtney Durrum',
        //                                 'Ascend Aesthetic' => 'Ascend Aesthetics',
        //                                 'Back2Back Chiropractic' => 'Back 2 Back Chiropractic',
        //                                 'Beverly Hills Disc & Laser Therapy Center, INC' => 'Beverly Hills Disc & Laser Therapy Center, INC.',
        //                                 'Chiropractic and Wellness Care' => 'Chiropractic & Wellness Care',
        //                                 'ChiroThinTracker.com' => 'ChiroThinTracker.com Training Site',
        //                                 'Inspire Weight Loss' => 'Inspire Weight Loss & Health Coaching℠',
        //                                 'Iv Parlour' => 'IV Parlour',
        //                                 'Leading Edge Chiropractic' => 'ChiroThin Corporate',
        //                                 'Sydney Chiropractic and Weight Loss' => 'ChiroThin Corporate',
        //                                 "DENTON & SANGER WELLNES CENTER" => "Sanger Wellness Center"
        //                             ];
        //                             if (array_key_exists($value, $replacements)) {
        //                               $value = $replacements[$value];
        //                            }else{
        //                             $value = $value;
        //                            }
        //                             $storage = \Drupal::entityTypeManager()->getStorage('clinic');
        //                             $query = $storage->getQuery();
        //                             $ids = $query
        //                               ->condition('title', $value)
        //                               ->accessCheck(false)
        //                               ->execute();                       
        //                               if($ids){
        //                                        foreach ($ids as $id) {
        //                                          $referenced_ids[] = ['target_id' => $id];
        //                                           }
        //                                      }
        //                   }
        //                    $node = Node::load($nid);
        //                   if ($node) {
        //                     $node->set('field_published_to', $referenced_ids);
        //                     $node->changed->preserve = TRUE;
        //                     $node->save();
        //                   } 
        //                 //   echo "<pre>"; print_r($nid."::"); print_r($trimmed_val);echo "</pre>"; 
        //              echo "<pre>"; print_r($nid."|". $id_count.":"); print_r($referenced_ids);echo "</pre>";
        //     }else{
        //             // $replacements = [
        //             //     'Drs. Ed & Sandie McCuiston' => 'Drs. Ed & Dr. Sandie McCuiston',
        //             //     'Durrum Chiropractic' => 'Dr. Courtney Durrum',
        //             //     'Ascend Aesthetic' => 'Ascend Aesthetics',
        //             //     'Back2Back Chiropractic' => 'Back 2 Back Chiropractic',
        //             //     'Beverly Hills Disc & Laser Therapy Center, INC' => 'Beverly Hills Disc & Laser Therapy Center, INC.',
        //             //     'Chiropractic and Wellness Care' => 'Chiropractic & Wellness Care',
        //             //     'ChiroThinTracker.com' => 'ChiroThinTracker.com Training Site',
        //             //     'Inspire Weight Loss' => 'Inspire Weight Loss & Health Coaching℠',
        //             //     'Iv Parlour' => 'IV Parlour',
        //             //     'Leading Edge Chiropractic' => 'ChiroThin Corporate',
        //             //     'Sydney Chiropractic and Weight Loss' => 'ChiroThin Corporate',
        //             //     "DENTON & SANGER WELLNES CENTER" => "Sanger Wellness Center"
        //             // ];
                    
        //             if (array_key_exists($site_titles_raw, $replacements)) {
        //               $site_titles_raw = $replacements[$site_titles_raw];
        //            }else{
        //             $site_titles_raw = $site_titles_raw;
        //            }
        //            $storage = \Drupal::entityTypeManager()->getStorage('clinic');
        //             $query = $storage->getQuery();
        //              $ids = $query
        //               ->condition('title', $site_titles_raw)
        //               ->accessCheck(false)
        //               ->execute();
        //                 if($ids){
        //                   foreach ($ids as $id) {
        //                   $new_references[] = ['target_id' => $id];
        //                   }
        //                 }

        //           //     // $node = Node::load($nid);
        //           //     // if ($node) {
        //           //     //   $node->set('field_published_to', $new_references);
        //           //     //   $node->changed->preserve = TRUE;
        //           //     //   $node->save();
        //           //     // }
           
        //       // echo "<pre>"; print_r($site_titles_raw);echo "</pre>";
        //           // $total_count ++; 
                                    $terms_ids = [
                                    'Documents'=>78,
                                    'Guides'=>81,
                                    'Products'=>80,
                                    'Videos'=>79
                                    ];
                          $node = Node::load($nid);
                          $node->set('field_description', ['value' => $field_body, 'format' => "full_html"]);
                          $node->set('field_resource',   ['target_id' => $field_resource,'display' => 1]);
                          $terms_trimmed = array_map('trim', explode(',', $field_resource_type));
                          $new_terms_ids = [];
                          foreach ($terms_trimmed as $key => $value) {
                              $new_terms_ids[] = $terms_ids[$value];
                             
                          }
                          $node->set('field_resource_type',   $new_terms_ids);
                          $node->set('field_my_clinic',   ['target_id' => $field_my_clinic]);      
                          $node->changed->preserve = TRUE;
                          $node->save();
                          
                  
                           // echo "<pre>"; print_r($new_terms_ids);echo "</pre>";
                       
     
 
      
         }
       
      echo "<pre>"; print_r($data);echo "</pre>";
      exit;
  }//end of method

public function MyMethodTraining() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
       $file_path = $module_path . '/assets/training.json';
       $domains_file_path = $module_path . '/src/Controller/domains.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
      
       $domains_json = file_get_contents($domains_file_path);
       $domains_data = json_decode($domains_json, true);

       //echo "<pre>"; print_r($data);echo "</pre>";exit;
       $total_count = "";
       $test = array();
       foreach ($data as $item) {
            $nid = $item['nid'];
            $site_titles_raw = $item['Domain Sitename'];
            $new_references= [];
            if (trim($item['Domain Send to All']) === 'Yes') {
                  $trimmed_val = array_map('trim', explode('"', $site_titles_raw));
                  if(count($trimmed_val) > 1){
                    $referenced_ids = [];
                    foreach ($trimmed_val as $key => $value) {
                                  $replacements = [
                                        'Drs. Ed & Sandie McCuiston' => 'Drs. Ed & Dr. Sandie McCuiston',
                                        'Durrum Chiropractic' => 'Dr. Courtney Durrum',
                                        'Ascend Aesthetic' => 'Ascend Aesthetics',
                                        'Back2Back Chiropractic' => 'Back 2 Back Chiropractic',
                                        'Beverly Hills Disc & Laser Therapy Center, INC' => 'Beverly Hills Disc & Laser Therapy Center, INC.',
                                        'Chiropractic and Wellness Care' => 'Chiropractic & Wellness Care',
                                        'ChiroThinTracker.com' => 'ChiroThinTracker.com Training Site',
                                        'Inspire Weight Loss' => 'Inspire Weight Loss & Health Coaching℠',
                                        'Iv Parlour' => 'IV Parlour',
                                        'Leading Edge Chiropractic' => 'ChiroThin Corporate',
                                        'Sydney Chiropractic and Weight Loss' => 'ChiroThin Corporate',
                                        "DENTON & SANGER WELLNES CENTER" => "Sanger Wellness Center"
                                    ];
                                    if (array_key_exists($value, $replacements)) {
                                      $value = $replacements[$value];
                                   }else{
                                    $value = $value;
                                   }
                                    $storage = \Drupal::entityTypeManager()->getStorage('clinic');
                                    $query = $storage->getQuery();
                                    $ids = $query
                                      ->condition('title', $value)
                                      ->accessCheck(false)
                                      ->execute();
                                          if($ids){
                                             $referenced_ids[] = ['target_id' => reset($ids)];
                                              }
                                         
                          }
                            $node = Node::load($nid);
                          if ($node) {
                            $node->set('field_published_to', $referenced_ids);
                            $node->changed->preserve = TRUE;
                            $node->save();
                          } 
                      // echo "<pre>"; print_r($nid.":"); print_r($referenced_ids);echo "</pre>";
                    }
               
            }else{
                   //  $replacements = [
                   //      'Drs. Ed & Sandie McCuiston' => 'Drs. Ed & Dr. Sandie McCuiston',
                   //      'Durrum Chiropractic' => 'Dr. Courtney Durrum',
                   //      'Ascend Aesthetic' => 'Ascend Aesthetics',
                   //      'Back2Back Chiropractic' => 'Back 2 Back Chiropractic',
                   //      'Beverly Hills Disc & Laser Therapy Center, INC' => 'Beverly Hills Disc & Laser Therapy Center, INC.',
                   //      'Chiropractic and Wellness Care' => 'Chiropractic & Wellness Care',
                   //      'ChiroThinTracker.com' => 'ChiroThinTracker.com Training Site',
                   //      'Inspire Weight Loss' => 'Inspire Weight Loss & Health Coaching℠',
                   //      'Iv Parlour' => 'IV Parlour',
                   //      'Leading Edge Chiropractic' => 'ChiroThin Corporate',
                   //      'Sydney Chiropractic and Weight Loss' => 'ChiroThin Corporate',
                   //      "DENTON & SANGER WELLNES CENTER" => "Sanger Wellness Center"
                   //  ];
                    
                   //  if (array_key_exists($site_titles_raw, $replacements)) {
                   //    $site_titles_raw = $replacements[$site_titles_raw];
                   // }else{
                   //  $site_titles_raw = $site_titles_raw;
                   // }
                   // $storage = \Drupal::entityTypeManager()->getStorage('clinic');
                   //  $query = $storage->getQuery();
                   //   $ids = $query
                   //    ->condition('title', $site_titles_raw)
                   //    ->accessCheck(false)
                   //    ->execute();
                   //    if($ids){
                   //          if(count($ids) > 1){
                   //                $nested = array();
                   //                foreach ($ids as $id) {
                   //                   $nested[] = ['target_id' => $id];
                   //                }
                   //              $new_references = $nested;
                   //            }else{
                   //              $new_references["target_id"] =  reset($ids);
                   //               }
                             
                       
                   //    }
                     // echo "<pre>"; print_r($ids);echo "</pre>";
                    // echo "<pre>"; print_r($nid.":"); print_r($new_references);echo "</pre>";
                      // $node = Node::load($nid);
                      // if ($node) {
                      //   $node->set('field_published_to', $new_references);
                      //   $node->changed->preserve = TRUE;
                      //   $node->save();
                      // }
           
               //echo "<pre>"; print_r($new_references);echo "</pre>";
                 
            }
          $total_count ++;
         //echo "<pre>"; print_r($site_titles_raw);echo "</pre>";
         
        }

        echo "<pre>"; print_r($total_count);echo "</pre>";
        //echo "<pre>"; print_r(count($test));echo "</pre>";
      exit;
}//end of training method

public function MyMethodDailyMessage() {
   // $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
   // $file_path = $module_path . '/assets/daily_message.json';
   // $json = file_get_contents($file_path);
   // $data = json_decode($json, true);
   //   $total_count = "";
   //     $test = array();
   //     foreach ($data as $item) {
   //       $nid = $item['nid'];
   //       $clinic = $item['My Clinic'];
   //       $body = $item['Body'];
   //       $program_day = $item['Program Day'];
   //       $value['value']= $body;
   //       $value['format']="full_html";
   //       $node = Node::load($nid);
   //       $node->set('body',  $value);
   //       $node->set('field_my_clinic', ['target_id' => $clinic]);
   //       $node->set('field_program_day_select',  $program_day);
   //       $node->changed->preserve = TRUE;
   //       $node->save();
   //     }
          $user = User::load(71388);
          $user->removeRole('chiropractor_active_');
          $user->addRole('chiropractor_inactive_');
          $user->changed->preserve = TRUE;
          $user->save();
        $build = [
      '#markup' => $this->t('Completed'),
    ];
    return $build;

}


public function MyMethodRecipe_data() {
   $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
   $file_path = $module_path . '/assets/recipe_data.json';
   $json = file_get_contents($file_path);
   $data = json_decode($json, true);
     $total_count = "";
       $test = array();
       foreach ($data as $item) {
         $nid = $item['nid'];
         $recipe_category = $item['Recipe Category'];
         $recipe_ingredeients = $item['Recipe Ingredients'];
         $body = $item['Body'];
         $recipe_type = $item['Recipe Type'];
         $clinic = $item['My Clinic'];
         $node = Node::load($nid);

         $recipe_category_names = array_map('trim', explode(',', $recipe_category));
         $term_ids = [];
          foreach ($recipe_category_names as $term_name) {
            $term = $this->taxonomy_term_load_by_name($term_name, "recipe_category");
            if ($term) {
              $term_ids[] = ['target_id' => $term->id()];
            }
          }

          $recipe_type_names = array_map('trim', explode(',', $recipe_type));
         $receip_types_term_ids = [];
          foreach ($recipe_type_names as $term_name) {
            $term = $this->taxonomy_term_load_by_name($term_name, "recipe_types");
            if ($term) {
              $receip_types_term_ids[] = ['target_id' => $term->id()];
            }
          }


          $ingrediant_values = [];
           if($recipe_ingredeients){
              $items = array_map('trim', explode('"', $recipe_ingredeients));
              foreach ($items as $item) {
                if (!empty($item)) {
                  $ingrediant_values[] = ['value' => $item];
                }
              }
           }

         $node->set('field_recipe_category', $term_ids);
         $value['value']= $body;
         $value['format']="full_html";
         $node->set('body',  $value);
         $node->set('field_recipe_ingredient', $ingrediant_values);
         $node->set('field_recipe_type', $receip_types_term_ids);
         $node->set('field_my_clinic', ['target_id' => $clinic]);
         $node->changed->preserve = TRUE;
         $node->save();
       }

        $build = [
      '#markup' => $this->t('Completed'),
    ];
    return $build;

}

public function taxonomy_term_load_by_name($name, $vid) {
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
    ->loadByProperties(['name' => $name, 'vid' => $vid]);
  return $terms ? reset($terms) : NULL;
}

public function MyMethodRecipe() {
       $module_path = \Drupal::service('extension.list.module')->getPath('chirothintracker_custom');
        $file_path = $module_path . '/assets/recipe.json';
       $domains_file_path = $module_path . '/src/Controller/domains.json';
       $json = file_get_contents($file_path);
       $data = json_decode($json, true);
      
       $domains_json = file_get_contents($domains_file_path);
       $domains_data = json_decode($domains_json, true);

       echo "<pre>"; print_r($data);echo "</pre>";exit;
       $total_count = "";
       $test = array();
       foreach ($data as $item) {
            $nid = $item['nid'];
            $site_titles_raw = $item['Domain Sitename'];
            $new_references= [];
             if (trim($item['Domain Send to All']) === 'Yes') {
            //       $trimmed_val = array_map('trim', explode('"', $site_titles_raw));
                  
            //                     $replacements = [
            //             'Drs. Ed & Sandie McCuiston' => 'Drs. Ed & Dr. Sandie McCuiston',
            //             'Durrum Chiropractic' => 'Dr. Courtney Durrum',
            //             'Ascend Aesthetic' => 'Ascend Aesthetics',
            //             'Back2Back Chiropractic' => 'Back 2 Back Chiropractic',
            //             'Beverly Hills Disc & Laser Therapy Center, INC' => 'Beverly Hills Disc & Laser Therapy Center, INC.',
            //             'Chiropractic and Wellness Care' => 'Chiropractic & Wellness Care',
            //             'ChiroThinTracker.com' => 'ChiroThinTracker.com Training Site',
            //             'Inspire Weight Loss' => 'Inspire Weight Loss & Health Coaching℠',
            //             'Iv Parlour' => 'IV Parlour',
            //             'Leading Edge Chiropractic' => 'ChiroThin Corporate',
            //             'Sydney Chiropractic and Weight Loss' => 'ChiroThin Corporate',
            //             "DENTON & SANGER WELLNES CENTER" => "Sanger Wellness Center"
            //         ];
                    
            //         if (array_key_exists($site_titles_raw, $replacements)) {
            //           $site_titles_raw = $replacements[$site_titles_raw];
            //        }else{
            //         $site_titles_raw = $site_titles_raw;
            //        }
            //     $storage = \Drupal::entityTypeManager()->getStorage('clinic');
            //      $query = $storage->getQuery();
            //       $ids = $query
            //        ->condition('title', $site_titles_raw)
            //        ->accessCheck(false)
            //        ->execute();
            //             $node = Node::load($nid);
            //           if ($node) {
            //             $node->set('field_published_to', array("target_id"=>reset($ids)));
            //             $node->changed->preserve = TRUE;
            //             $node->save();
            //           } 
            //        echo "<pre>"; print_r($nid.":"); print_r(reset($ids));echo "</pre>";
            //   // $total_count ++;
            }else{
                   //  $replacements = [
                   //      'Drs. Ed & Sandie McCuiston' => 'Drs. Ed & Dr. Sandie McCuiston',
                   //      'Durrum Chiropractic' => 'Dr. Courtney Durrum',
                   //      'Ascend Aesthetic' => 'Ascend Aesthetics',
                   //      'Back2Back Chiropractic' => 'Back 2 Back Chiropractic',
                   //      'Beverly Hills Disc & Laser Therapy Center' => 'Beverly Hills Disc & Laser Therapy Center, INC.',
                   //      'Chiropractic and Wellness Care' => 'Chiropractic & Wellness Care',
                   //      'ChiroThinTracker.com' => 'ChiroThinTracker.com Training Site',
                   //      'Inspire Weight Loss' => 'Inspire Weight Loss & Health Coaching℠',
                   //      'Iv Parlour' => 'IV Parlour',
                   //      'Leading Edge Chiropractic' => 'ChiroThin Corporate',
                   //      'Sydney Chiropractic and Weight Loss' => 'ChiroThin Corporate',
                   //      "DENTON & SANGER WELLNES CENTER" => "Sanger Wellness Center"
                   //  ];
                    
                   //  if (array_key_exists($site_titles_raw, $replacements)) {
                   //    $site_titles_raw = $replacements[$site_titles_raw];
                   // }else{
                   //  $site_titles_raw = $site_titles_raw;
                   // }
                   // $storage = \Drupal::entityTypeManager()->getStorage('clinic');
                   //  $query = $storage->getQuery();
                   //   $ids = $query
                   //    ->condition('title', $site_titles_raw)
                   //    ->accessCheck(false)
                   //    ->execute();
                   //    if($ids){
                   //          if(count($ids) > 1){
                   //              //   $nested = array();
                   //              //   foreach ($ids as $id) {
                   //              //      $nested[] = ['target_id' => $id];
                   //              //   }
                   //              // $new_references = $nested;
                   //            }else{
                   //              $new_references["target_id"] =  reset($ids);
                   //               }
                             
                       
                   //    }
                   //  // echo "<pre>"; print_r(count($ids));echo "</pre>";
                   //  //echo "<pre>"; print_r($nid.":"); print_r($new_references);echo "</pre>";
                   //    $node = Node::load($nid);
                   //    if ($node) {
                   //      $node->set('field_published_to', $new_references);
                   //      $node->changed->preserve = TRUE;
                   //      $node->save();
                   //    }
           
               //echo "<pre>"; print_r($new_references);echo "</pre>";
             
            }
         
         //echo "<pre>"; print_r($site_titles_raw);echo "</pre>";
        //  $total_count ++;
        }

        echo "<pre>"; print_r($total_count);echo "</pre>";
        //echo "<pre>"; print_r(count($test));echo "</pre>";
      exit;

}// end of recipe method
  public function find_duplicate_clinic(){

      $clinic_storage = \Drupal::entityTypeManager()->getStorage('clinic');
      $query = $clinic_storage->getQuery()
        ->condition('type', 'clinic') // bundle condition
        ->accessCheck(false);
      $clinic_ids = $query->execute();
      $clinics = $clinic_storage->loadMultiple($clinic_ids);
        foreach ($clinics as $clinic) {
          $duplicates[] = $clinic->label();        
        }
     // $titles = array_map('strtolower', $duplicates);
      $counts = array_count_values($duplicates);
      $duplicates = array_filter($counts, function ($count) {
          return $count > 1;
        });
      $duplicate_values = array_keys($duplicates);
      return $duplicate_values;
  }

  // public function updateSingleNode(){
  //             $textjson = '[
  //                     {
  //                         "nid": "1832",
  //                         "title": "6. Lifetime Phase Video",
  //                         "body": "<div class=\"media_embed\" height=\"360px\" width=\"640px\">\n<iframe allow=\"autoplay; fullscreen\" allowfullscreen=\"\" frameborder=\"0\" height=\"360px\" src=\"https:\/\/player.vimeo.com\/video\/414978647\" width=\"640px\"><\/iframe><\/div>",
  //                         "Summary": "",
  //                         "format": "filtered_html",
  //                         "My Clinic": "157",
  //                         "Video": "",
  //                         "Domain Send to All": "No"
  //                     }
  //                 ]';
  //         $data = json_decode($textjson, true);
  //         $node = Node::load(280);
  //           if ($node) {
  //             $value['value']='<div class="media_embed" height="480px" width="1249px"><iframe allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen="" frameborder="0" height="480px" src="https://www.youtube.com/embed/2FuL1oQjeGg" width="1249px"></iframe></div>';
  //             //$value['summary']="This Self Help Technique developed by Dr. John Schellenberg will help you deal with the emotional aspects of Cravings, Stalls and Hunger.";
  //             $value['format']="full_html";
  //             $node->set('body', $value);
  //             $node->changed->preserve = TRUE;
  //             $node->save();
  //           } 

  //       exit;
  // }

}
