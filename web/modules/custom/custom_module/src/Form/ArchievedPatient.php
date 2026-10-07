<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\CssCommand;

/**
 * Class ArchievedPatient.
 *
 * @package Drupal\custom_module\Form
 */
class ArchievedPatient extends FormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'archieved_patient_form';
  }
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state,$user_id = NULL) {
    $form['text']['#markup'] = t('Archived patients can be re-enrolled at any time.');
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Confirm'),
      '#button_type' => 'primary',
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
   $path = \Drupal::request()->getpathInfo();
   $arg  = explode('/',$path);
   $user_id = $arg[2];
   $user = \Drupal\user\Entity\User::load($user_id);
   // $user->removeRole('archived_patient');
   // $user->addRole('enrolled_patient');
   $user->removeRole('enrolled_patient');
   $user->addRole('archived_patient');
  // $user->set('field_program_start_weight', "0");
    $user->changed->preserve = TRUE;
    $user->save();
    $form_state->setRedirect("view.patients.page_1");
  }
}
