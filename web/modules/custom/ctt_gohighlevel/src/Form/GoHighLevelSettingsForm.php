<?php

namespace Drupal\ctt_gohighlevel\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ctt_gohighlevel\Service\GoHighLevelApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure CTT GoHighLevel settings.
 */
class GoHighLevelSettingsForm extends ConfigFormBase {

  /**
   * The GoHighLevel API service.
   *
   * @var \Drupal\ctt_gohighlevel\Service\GoHighLevelApiService
   */
  protected $gohighlevelApi;

  /**
   * Constructs a GoHighLevelSettingsForm object.
   *
   * @param \Drupal\ctt_gohighlevel\Service\GoHighLevelApiService $gohighlevel_api
   *   The GoHighLevel API service.
   */
  public function __construct(GoHighLevelApiService $gohighlevel_api) {
    $this->gohighlevelApi = $gohighlevel_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ctt_gohighlevel.api')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ctt_gohighlevel.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ctt_gohighlevel_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ctt_gohighlevel.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable GoHighLevel Integration'),
      '#default_value' => $config->get('enabled') ?? TRUE,
      '#description' => $this->t('Enable or disable the GoHighLevel integration for license subscriptions.'),
    ];

    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#collapsible' => FALSE,
    ];

    $form['api_settings']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GoHighLevel API Key'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
      '#description' => $this->t('Enter your GoHighLevel API key. You can find this in your <strong>Settings > Business Profile</strong> in your GoHighLevel account.'),
      '#attributes' => [
        'placeholder' => 'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
      ],
    ];

    $form['api_settings']['location_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location ID'),
      '#default_value' => $config->get('location_id'),
      '#required' => TRUE,
      '#description' => $this->t('Your GoHighLevel Location ID. <strong>This is required</strong> for API operations. You can find this in your GoHighLevel account under Settings.'),
    ];

    $form['api_settings']['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Connection'),
      '#ajax' => [
        'callback' => '::testApiConnection',
        'wrapper' => 'connection-test-result',
        'effect' => 'fade',
      ],
    ];

    $form['api_settings']['connection_result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'connection-test-result'],
    ];

    $form['subscription_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('License Subscription Settings'),
      '#collapsible' => FALSE,
    ];

    $form['subscription_settings']['subscription_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Active Subscription Tag'),
      '#default_value' => $config->get('subscription_tag') ?? 'CTT - New Subscriber',
      '#required' => TRUE,
      '#description' => $this->t('Tag to apply when a license subscription becomes active. This will trigger your welcome automation in GoHighLevel.'),
    ];

    $form['subscription_settings']['cancellation_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cancelled Subscription Tag'),
      '#default_value' => $config->get('cancellation_tag') ?? 'CTT Cancelled',
      '#required' => TRUE,
      '#description' => $this->t('Tag to apply when a license subscription is cancelled (payment failed or manual cancellation).'),
    ];

    $form['subscription_settings']['default_source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default Source'),
      '#default_value' => $config->get('default_source') ?? 'License Subscription',
      '#description' => $this->t('The source value to send with contacts. This helps track where leads come from.'),
    ];

    $form['field_mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Mapping'),
      '#description' => $this->t('The module automatically maps Drupal user fields to GoHighLevel contact fields.'),
      '#collapsible' => FALSE,
    ];

    $form['field_mapping']['info'] = [
      '#markup' => '<div class="messages messages--info">' . 
        '<strong>' . $this->t('Default Field Mappings:') . '</strong><ul>' .
        '<li>' . $this->t('<strong>field_full_name</strong> → GoHighLevel firstName and lastName (automatically split)') . '</li>' .
        '<li>' . $this->t('<strong>field_phone_number</strong> → GoHighLevel phone') . '</li>' .
        '<li>' . $this->t('<strong>mail</strong> (email) → GoHighLevel email (required)') . '</li>' .
        '</ul></div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback to test API connection.
   */
  public function testApiConnection(array &$form, FormStateInterface $form_state) {
    // Temporarily save API key and Location ID to config for testing.
    $temp_config = $this->config('ctt_gohighlevel.settings');
    $original_key = $temp_config->get('api_key');
    $original_location_id = $temp_config->get('location_id');
    
    $temp_config
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('location_id', $form_state->getValue('location_id'))
      ->save();
    
    $connection_test = $this->gohighlevelApi->testConnection();
    
    // Restore original values.
    $temp_config
      ->set('api_key', $original_key)
      ->set('location_id', $original_location_id)
      ->save();

    if ($connection_test) {
      $form['api_settings']['connection_result']['#markup'] = 
        '<div class="messages messages--status">' . 
        $this->t('✓ Connection successful! API key and Location ID are valid.') . 
        '</div>';
    }
    else {
      $form['api_settings']['connection_result']['#markup'] = 
        '<div class="messages messages--error">' . 
        $this->t('✗ Connection failed. Please check your API key and Location ID, then try again.') . 
        '</div>';
    }

    return $form['api_settings']['connection_result'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $api_key = $form_state->getValue('api_key');
    
    // Basic validation for API key format.
    if (!empty($api_key) && strlen($api_key) < 20) {
      $form_state->setErrorByName('api_key', $this->t('The API key appears to be invalid. Please check and try again.'));
    }

    $location_id = $form_state->getValue('location_id');
    
    // Basic validation for Location ID.
    if (empty($location_id)) {
      $form_state->setErrorByName('location_id', $this->t('Location ID is required for GoHighLevel integration.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ctt_gohighlevel.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('location_id', $form_state->getValue('location_id'))
      ->set('subscription_tag', $form_state->getValue('subscription_tag'))
      ->set('cancellation_tag', $form_state->getValue('cancellation_tag'))
      ->set('default_source', $form_state->getValue('default_source'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}