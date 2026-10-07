<?php

namespace Drupal\ctt_patient_intake\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the Patient Intake email notification.
 *
 * Only users with the 'administer site configuration' permission
 * (i.e. administrators) can access this form — enforced in routing.
 */
class PatientIntakeSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ctt_patient_intake.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ctt_patient_intake_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ctt_patient_intake.settings');

    $form['mail_subject'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Email subject'),
      '#description'   => $this->t('Subject line for the clinic notification email. You may use <strong>@patient_name</strong> and <strong>@chiropractor_name</strong>.'),
      '#default_value' => $config->get('mail_subject') ?? 'New patient intake submission from @name',
      '#required'      => TRUE,
      '#maxlength'     => 255,
    ];

    $form['mail_body'] = [
      '#type'          => 'text_format',
      '#title'         => $this->t('Email body'),
      '#format' => 'email_html',
      '#allowed_formats' => ['email_html'],
      '#description'   => $this->t('Body of the clinic notification email. You may use <strong>@patient_name</strong> and <strong>@chiropractor_name</strong>.'),
      '#default_value' => $config->get('mail_body') ?? "Hello,\n\nA new patient intake form has been submitted by @name.\n\nPlease log in to the site to review the submission.\n\nThank you.",
      '#required'      => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ctt_patient_intake.settings')
      ->set('mail_subject', $form_state->getValue('mail_subject'))
      ->set('mail_body', $form_state->getValue(['mail_body', 'value']))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
