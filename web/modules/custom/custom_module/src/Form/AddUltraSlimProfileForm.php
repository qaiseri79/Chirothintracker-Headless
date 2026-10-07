<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Ajax\CloseDialogCommand;

/**
 * Add UltraSlim Profile Form.
 */
class AddUltraSlimProfileForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_ultraslim_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL) {
    $this->user = $user;

    // Program Start Date (Date field)
    $form['program_start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Program Start Date'),
      '#required' => TRUE,
    ];

    // Sessions Available (Integer field)
    $form['sessions_available'] = [
      '#type' => 'number',
      '#title' => $this->t('Sessions Available'),
      '#min' => 0,
      '#required' => TRUE,
    ];

    // Submit button
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#attributes' => ['class' => ['btn', 'btn-success']],
      '#ajax' => [
        'callback' => '::submitFormAjax',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Optional: extra validation if needed
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No direct submit; handled via AJAX.
  }

  /**
   * AJAX submit handler.
   */
  public function submitFormAjax(array &$form, FormStateInterface $form_state) {
    $program_start_date = $form_state->getValue('program_start_date');
    $sessions_available = $form_state->getValue('sessions_available');

    // Create ECK entity (patient_profile -> ultraslim_profile)
    $values = [
      'type' => 'ultraslim_profile', // bundle
      'field_program_start_date' => $program_start_date,
      'field_sessions_available' => $sessions_available,
      'field_patient' => $this->user->id(),
    ];

    /** @var \Drupal\eck\Entity\EckEntity $entity */
    $entity = \Drupal::entityTypeManager()
      ->getStorage('patient_profile')
      ->create($values);

    $entity->save();

    \Drupal::messenger()->addMessage($this->t('UltraSlim profile has been created.'));

    // AJAX response
    $response = new AjaxResponse();
    $response->addCommand(new CloseDialogCommand());
    $previous_url = \Drupal::service('request_stack')->getCurrentRequest()->headers->get('referer');
    $response->addCommand(new RedirectCommand($previous_url));

    return $response;
  }

}
