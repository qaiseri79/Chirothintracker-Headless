<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class SubscriptionSettingsForm extends ConfigFormBase {
  public function getFormId(): string { return 'headless_subscriptions_settings'; }
  protected function getEditableConfigNames(): array { return ['headless_subscriptions.settings']; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('headless_subscriptions.settings');
    $form['description'] = ['#markup' => '<p>Settings apply only to accounts registered through the new subscription API. Configure dedicated Authorize.Net credentials in server settings; secrets are not stored in exported Drupal configuration.</p>'];
    $form['registration_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Enable doctor registration'), '#default_value' => $config->get('registration_enabled')];
    $form['checkout_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Enable new subscription payments'), '#default_value' => $config->get('checkout_enabled'), '#description' => $this->t('Requires dedicated Authorize.Net credentials. Disabling new payments does not stop existing renewals or verified notifications.')];
    $form['grace_days'] = ['#type' => 'number', '#title' => $this->t('Renewal payment grace period (days)'), '#min' => 0, '#max' => 30, '#step' => 1, '#required' => TRUE, '#default_value' => $config->get('grace_days'), '#description' => $this->t('Zero means paid access ends at the billing boundary. Scheduled cancellations do not receive a grace period.')];
    $form['cart_ttl'] = ['#type' => 'number', '#title' => $this->t('Subscription cart lifetime (seconds)'), '#min' => 300, '#max' => 86400, '#step' => 1, '#required' => TRUE, '#default_value' => $config->get('cart_ttl')];
    return parent::buildForm($form, $form_state);
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('headless_subscriptions.settings');
    foreach (['checkout_enabled', 'registration_enabled'] as $name) $config->set($name, (bool) $form_state->getValue($name));
    foreach (['grace_days', 'cart_ttl'] as $name) $config->set($name, (int) $form_state->getValue($name));
    $config->save(); parent::submitForm($form, $form_state);
  }
}
