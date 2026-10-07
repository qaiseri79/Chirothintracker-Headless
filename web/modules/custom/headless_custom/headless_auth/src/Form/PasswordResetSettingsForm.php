<?php

declare(strict_types=1);

namespace Drupal\headless_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class PasswordResetSettingsForm extends ConfigFormBase {
  public function getFormId(): string { return 'headless_auth_settings'; }
  protected function getEditableConfigNames(): array { return ['headless_auth.settings']; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['frontend_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend base URL'),
      '#description' => $this->t('The trusted portal URL, for example https://portal.example.com. Use http://localhost:3000 for local development. Reset emails link to /reset-password on this site.'),
      '#default_value' => $this->config('headless_auth.settings')->get('frontend_base_url'),
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $parts = parse_url(trim((string) $form_state->getValue('frontend_base_url')));
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      $form_state->setErrorByName('frontend_base_url', $this->t('Enter a HTTP or HTTPS URL without credentials, query parameters, or a fragment.'));
    }
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('headless_auth.settings')->set('frontend_base_url', rtrim(trim((string) $form_state->getValue('frontend_base_url')), '/'))->save();
    parent::submitForm($form, $form_state);
  }
}
