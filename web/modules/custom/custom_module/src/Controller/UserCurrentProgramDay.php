<?php

namespace Drupal\custom_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;
use Drupal\custom_module\Controller\ResetStartingValues;
use Drupal\user\Entity\User;

/**
 * Class UserCurrentProgramDay.
 */
class UserCurrentProgramDay extends ControllerBase
{


  public function userRoles($user_id)
  {
    $user_entity = User::load($user_id);
    if ($user_entity) {
      if (in_array('enrolled_patient', $user_entity->getRoles())) {
        // Evaluate User Roles
        $clinic = $user_entity->get('field_clinic')->getValue();
        if ($clinic) {
          $clinic_id = $clinic[0]["target_id"];
          $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
          if ($clinic_id) {
            $clinic_entity = $storage->load($clinic_id);
            $ecommerce_enabled_field = $clinic_entity->get('field_ecommerce_enabled')->getValue();
            if ($ecommerce_enabled_field) {
              $ecommerce_enabled = $ecommerce_enabled_field[0]["value"];
              if (in_array('enrolled_patient', $user_entity->getRoles())) {
                if ($ecommerce_enabled == 1) {
                  $user_entity->addRole('ecommerce');
                  $user_entity->changed->preserve = TRUE;
                  $user_entity->save();
                }
              }
              if (in_array('ecommerce', $user_entity->getRoles()) || in_array('ecommerce_manager', $user_entity->getRoles())) {
                if ($ecommerce_enabled != 1) {
                  $user_entity->removeRole('ecommerce');
                  $user_entity->removeRole('ecommerce_manager');
                  $user_entity->changed->preserve = TRUE;
                  $user_entity->save();
                }
              }

            }
            // Mind Set Work
            $mind_set_work_field = $clinic_entity->get('field_mind_set_work')->getValue();
            if ($mind_set_work_field) {
              $mind_set_work = $mind_set_work_field[0]["value"];
              if ($mind_set_work == 1) {
                if (in_array('enrolled_patient', $user_entity->getRoles())) {
                  $user_entity->addRole('patient_mind_set');
                  $user_entity->changed->preserve = TRUE;
                  $user_entity->save();
                } else if (in_array('chiropractor_active_', $user_entity->getRoles())) {
                  $user_entity->addRole('chiropractor_mind_set');
                  $user_entity->changed->preserve = TRUE;
                  $user_entity->save();
                }
              }
            }

            //Add Roles for Chirothin

            if (in_array('enrolled_patient', $user_entity->getRoles()) || in_array('archived_patient', $user_entity->getRoles())) {
              if ($user_entity->get('field_chiropractor')->getValue()) {
                $chiropractor_id = $user_entity->get('field_chiropractor')->getValue()[0]["target_id"];
                $chiropractor = User::load($chiropractor_id);
                if (!in_array('chiropractor_white_label', $chiropractor->getRoles())) {
                  $user_entity->addRole('patient_chirothin');
                  $user_entity->removeRole('patient_white_label');
                  $user_entity->changed->preserve = TRUE;
                  $user_entity->save();
                } else if (in_array('chiropractor_white_label', $chiropractor->getRoles())) {
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

  }

  public function userProfile($user_id)
  {

    $user_entity = User::load($user_id);
    if ($user_entity) {
      if (in_array('enrolled_patient', $user_entity->getRoles())) {

        // check for Profile.
        if (in_array('patient_chirothin', $user_entity->getRoles())) {
          //check if patient profile doesn't exists then create a new one.
          $rules_patient_profiles = Views::getView('rules_patient_profiles');
          $rules_patient_profiles->setDisplay('default');
          $rules_patient_profiles->setArguments([$user_id]);
          $rules_patient_profiles->execute();
          $rules_patient_profiles_result = $rules_patient_profiles->result;
          if (!$rules_patient_profiles_result) {
            // create a new profile.
            $eck = [
              'entity_type' => 'patient_profile',
              'type' => 'chirothin_profile',
              'field_patient' => array("target_id" => $user_id)
            ];
            $chirothin_profile = \Drupal\eck\Entity\EckEntity::create($eck);
            $chirothin_profile->save();
            $new_profile_id = $chirothin_profile->id();
            // assign the newly created profile to the patient.
            $user_entity->set('field_chirothin_profile', array("target_id" => $new_profile_id));
            $user_entity->changed->preserve = TRUE;
            $user_entity->save();
          }
        }


        // Repair ChiroThin Profile
        if (in_array('patient_chirothin', $user_entity->getRoles())) {
          $field_chirothin_profile = $user_entity->get('field_chirothin_profile')->getValue();
          if (!$field_chirothin_profile) {
            // $chirothin_profile = $field_chirothin_profile[0]["target_id"];
            $patient_weight_profile = Views::getView('my_patient_profiles');
            $patient_weight_profile->setDisplay('block_1');
            $patient_weight_profile->setArguments([$user_id]);
            $patient_weight_profile->execute();
            $patient_weight_profile_result = $patient_weight_profile->result;
            if ($patient_weight_profile_result) {
              $profile_id = $patient_weight_profile_result[0]->_entity->id();
              $user_entity->set('field_chirothin_profile', array("target_id" => $profile_id));
              $user_entity->changed->preserve = TRUE;
              $user_entity->save();
            }
          }
        }




        // Update Existing Profile
        if (in_array('enrolled_patient', $user_entity->getRoles()) || in_array('archived_patient', $user_entity->getRoles())) {
          $field_chirothin_profile = $user_entity->get('field_chirothin_profile')->getValue();
          if ($field_chirothin_profile) {
            $chirothin_profile_id = $field_chirothin_profile[0]["target_id"];
            $storage = \Drupal::service('entity_type.manager')->getStorage('patient_profile');
            $patient_profile_entity = $storage->load($chirothin_profile_id);
            if ($patient_profile_entity) {
              if ($patient_profile_entity->bundle() == "chirothin_profile") {
                $field_program_start_date = $user_entity->get('field_program_start_date')->getValue();
                if ($field_program_start_date) {
                  $program_start_date = $field_program_start_date[0]["value"];
                  $patient_profile_entity->set('field_program_start_date', $program_start_date);
                }
                $field_program_start_weight = $user_entity->get('field_program_start_weight')->getValue();
                if ($field_program_start_weight) {
                  $program_start_weight = $field_program_start_weight[0]["value"];
                  $patient_profile_entity->set('field_program_start_weight', $program_start_weight);
                }
                $patient_profile_entity->save();
              }
            }
          }
        }




      }
    }

  }


  public function UserClinician($user_id)
  {

    $user_entity = User::load($user_id);
    if ($user_entity) {
      if (in_array('enrolled_patient', $user_entity->getRoles())) {
        //Reassign Chiropractor
        if (in_array('enrolled_patient', $user_entity->getRoles()) || in_array('archived_patient', $user_entity->getRoles())) {
          $field_chiropractor = $user_entity->get('field_chiropractor')->getValue();
          if (!$field_chiropractor) {
            $field_clinic = $user_entity->get('field_clinic')->getValue();
            if ($field_clinic) {
              $clinic_id = $field_clinic[0]["target_id"];
              $primary_chiropractor = Views::getView('primary_chiropractor');
              $primary_chiropractor->setDisplay('views_rules_1');
              $primary_chiropractor->setArguments([$clinic_id]);
              $primary_chiropractor->execute();
              $primary_chiropractor_result = $primary_chiropractor->result;
              if ($primary_chiropractor_result) {
                $clinician = $primary_chiropractor_result[0]->_entity->id();
                $clinician_clinic = $primary_chiropractor_result[0]->_entity->get('field_clinic')->getValue();
                if ($clinician_clinic) {
                  $clinician_clinic_id = $clinician_clinic[0]["target_id"];
                }
                $user_entity->set('field_chiropractor', array('target_id' => $clinician));
                $user_entity->set('field_clinic', array('target_id' => $clinician_clinic_id));
                $user_entity->changed->preserve = TRUE;
                $user_entity->save();
              }
            }
          }
        }

        //Reassign Clinic
        if (in_array('enrolled_patient', $user_entity->getRoles()) || in_array('archived_patient', $user_entity->getRoles())) {
          $field_chiropractor = $user_entity->get('field_chiropractor')->getValue();
          if ($field_chiropractor) {
            $field_clinic = $user_entity->get('field_clinic')->getValue();
            $chiropractor_id = $field_chiropractor[0]["target_id"];
            //$field_clinic = $field_chiropractor->get('field_clinic')->getValue();
            if (!$field_clinic) {
              $chiropractor_entity = User::load($chiropractor_id);
              $chiropractor_clinic = $chiropractor_entity->get('field_clinic')->getValue();
              if ($chiropractor_clinic) {
                $chiropractor_clinic_id = $chiropractor_clinic[0]["target_id"];
                $user_entity->set('field_clinic', array('target_id' => $chiropractor_clinic_id));
                $user_entity->changed->preserve = TRUE;
                $user_entity->save();
              }
            }
          }
        }
      }
    }
  }


  public function Evaluations($user_id)
  {
    $user_entity = User::load($user_id);
    if ($user_entity) {
      if (in_array('enrolled_patient', $user_entity->getRoles())) {
        //Evaluate User
        $ResetStartingValues = new ResetStartingValues;
        $ResetStartingValues->setLate($user_entity);
        $ResetStartingValues->resetStartingValue($user_entity);
        //$ResetStartingValues->CalculateProgramDay($user_entity);
        $ResetStartingValues->Calculate_ProgramDay($user_entity);

      }
    }

  }



  public function CalculateGoal($user_id)
  {

    $user_entity = User::load($user_id);
    if ($user_entity) {
      if (in_array('enrolled_patient', $user_entity->getRoles())) {
        $field_goal_weight = $user_entity->get('field_goal_weight')->getValue();
        if ($field_goal_weight) {
          $goal_weight = $field_goal_weight[0]["value"];
          if ($goal_weight > 0) {
            $field_program_start_weight = $user_entity->get('field_program_start_weight')->getValue();
            if ($field_program_start_weight) {
              $program_start_weight = $field_program_start_weight[0]["value"];
              if ($program_start_weight > 0) {
                $field_net_weight_loss = $user_entity->get('field_net_weight_loss')->getValue();
                if ($field_net_weight_loss) {
                  $net_weight_loss = $field_net_weight_loss[0]["value"];
                  if ($net_weight_loss > 0) {
                    $goal_differences = (float) $program_start_weight - (float) $goal_weight;
                    $goal_percentage = (float) $net_weight_loss / (float) $goal_differences;
                    $goal_percentage_whole = $goal_percentage * 100;
                    $user_entity->set('field_goal_achieved', $goal_percentage_whole);
                    $user_entity->changed->preserve = TRUE;
                    $user_entity->save();
                  }
                }

              }
            }

          }
        }

      }
    }

  }



}
