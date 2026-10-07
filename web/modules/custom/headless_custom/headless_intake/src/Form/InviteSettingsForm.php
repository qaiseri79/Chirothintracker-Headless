<?php

declare(strict_types=1);

namespace Drupal\headless_intake\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the headless intake invite links.
 *
 * These three values used to sit inside ctt_patient_intake's own settings form,
 * which mixed the email notification settings with the invite-link settings.
 * They live here now, in `headless_intake.settings`, so each module owns its own
 * configuration and the original module goes back to what it was.
 *
 * Nothing needed migrating: `ctt_patient_intake.settings` only ever contained
 * `mail_subject` and `mail_body`, so no invite value was at risk of being lost.
 */
class InviteSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['headless_intake.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'headless_intake_invite_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('headless_intake.settings');

    $form['intake_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Intake frontend base URL'),
      '#description' => $this->t("Public base URL of the Next.js intake app. Generated invite links are <strong>%baseurl/intake/{token}</strong>. This is not optional in practice: if it is empty, links fall back to this Drupal site's own host, which serves no /intake/{token} page and so hands patients a broken link.", [
        '%baseurl' => $config->get('intake_base_url') ?: 'https://…',
      ]),
      '#default_value' => $config->get('intake_base_url') ?? '',
    ];

    $form['token_defaults'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Invite link defaults'),
      '#description' => $this->t('Pre-filled defaults shown on the Intake Links page; the chiropractor can change them per link.'),
    ];
    $form['token_defaults']['token_default_max_uses'] = [
      '#type' => 'number',
      '#title' => $this->t('Default max submissions'),
      '#description' => $this->t('Default “Max submissions” value for a new link.'),
      '#default_value' => $config->get('token_default_max_uses') ?? 1,
      '#min' => 1,
      '#max' => 1000,
    ];
    $form['token_defaults']['token_default_expiry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default expiry (days)'),
      '#description' => $this->t('Default “Expires after” value for a new link. 0 = never expires.'),
      '#default_value' => $config->get('token_default_expiry_days') ?? 7,
      '#min' => 0,
      '#max' => 365,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('headless_intake.settings')
      ->set('intake_base_url', rtrim((string) $form_state->getValue('intake_base_url'), '/'))
      ->set('token_default_max_uses', (int) $form_state->getValue('token_default_max_uses'))
      ->set('token_default_expiry_days', (int) $form_state->getValue('token_default_expiry_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
