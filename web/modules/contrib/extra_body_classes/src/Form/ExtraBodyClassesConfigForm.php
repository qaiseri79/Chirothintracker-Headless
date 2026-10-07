<?php

namespace Drupal\extra_body_classes\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Configure where you want to append "extra body classes".
 */
class ExtraBodyClassesConfigForm extends ConfigFormBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['extra_body_classes'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'extra_body_classes_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('extra_body_classes.settings');
    $form['extra_body_classes_time'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra Body Classes Based On Time'),
      '#open' => TRUE,
    ];
    // Add current browser details.
    $form['extra_body_classes_browser_details_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra Body Classes Based Current Browser Details'),
      '#open' => TRUE,
    ];
    $form['extra_body_classes_browser_details_status']['extra_body_classes_browser_platform'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add current browser platform'),
      '#description' => $this->t('Add name of current browsers platform, Example: mac'),
      '#default_value' => $config->get('extra_body_classes_browser_platform'),
    ];
    $form['extra_body_classes_browser_details_status']['extra_body_classes_browser_name_version'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add current browser name and version'),
      '#description' => $this->t('Add current browser name and version, Example: chrom chrom46'),
      '#default_value' => $config->get('extra_body_classes_browser_name_version'),
    ];
    $form['extra_body_classes_browser_details_status']['extra_body_classes_browser_device'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add current device'),
      '#description' => $this->t('Add current device, Example: Desktop'),
      '#default_value' => $config->get('extra_body_classes_browser_device'),
    ];
    // Add current date as class.
    $form['extra_body_classes_time']['extra_body_classes_date'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Current Date Timestamp'),
      '#description' => $this->t('Add a current date timestamp as class to body tag'),
      '#default_value' => $config->get('extra_body_classes_date'),
    ];
    // Add current year as class.
    $form['extra_body_classes_time']['extra_body_classes_year'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Current Year'),
      '#description' => $this->t('Add a current year as class to body tag'),
      '#default_value' => $config->get('extra_body_classes_year'),
    ];
    // Add current month as class.
    $form['extra_body_classes_time']['extra_body_classes_month'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Current Month'),
      '#description' => $this->t('Add a current month as class to body tag'),
      '#default_value' => $config->get('extra_body_classes_month'),
    ];
    // Add current day as class.
    $form['extra_body_classes_time']['extra_body_classes_day'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Current Day'),
      '#description' => $this->t('Add a current day as class to body tag'),
      '#default_value' => $config->get('extra_body_classes_day'),
    ];
    // Add current user roles as class.
    $form['extra_body_classes_roles_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra Body Classes Based On Roles'),
      '#open' => TRUE,
    ];
    $form['extra_body_classes_roles_status']['extra_body_classes_roles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Current user roles'),
      '#description' => $this->t('Add a current user roles as class to body tag'),
      '#default_value' => $config->get('extra_body_classes_roles'),
    ];
    // Add Event For Single Day.
    $form['extra_body_classes_browser_single_day_event_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra Body Classes Based single day Event'),
      '#open' => TRUE,
    ];
    $form['extra_body_classes_browser_single_day_event_status']['extra_body_classes_browser_single_day_event'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add event class'),
      '#description' => $this->t('Add event Example: independence day'),
      '#default_value' => $config->get('extra_body_classes_browser_single_day_event'),
    ];
    $form['extra_body_classes_browser_single_day_event_status']['extra_body_classes_browser_single_day_event_begins'] = [
      '#type' => 'date',
      '#title' => $this->t('Event Day'),
      '#description' => $this->t('Add event date.'),
      '#default_value' => $config->get('extra_body_classes_browser_single_day_event_begins'),
    ];
    // Add Event For Multiple Days.
    $form['extra_body_classes_browser_multiple_days_event_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra Body Classes Based multiple day Event'),
      '#open' => TRUE,
    ];
    $form['extra_body_classes_browser_multiple_days_event_status']['extra_body_classes_event'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add current event'),
      '#description' => $this->t('Add current event Example: Drupal con asia 18 feb - 21 feb'),
      '#default_value' => $config->get('extra_body_classes_event'),
    ];

    $form['extra_body_classes_browser_multiple_days_event_status']['extra_body_classes_event_start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#description' => $this->t('Add start date of current event.'),
      '#default_value' => $config->get('extra_body_classes_event_start_date'),
    ];
    $form['extra_body_classes_browser_multiple_days_event_status']['extra_body_classes_event_end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
      '#description' => $this->t('Add end date of current event.'),
      '#default_value' => $config->get('extra_body_classes_event_end_date'),
    ];
    // Add custom classes.
    $form['extra_body_classes_custom_classes_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Extra Body Classes Based On Custom Classes'),
      '#open' => TRUE,
    ];
    $form['extra_body_classes_custom_classes_status']['extra_body_classes_custom_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add custom classes'),
      '#description' => $this->t('Add multiple custom classes in small letters separated by comma, Example: abc,xyz'),
      '#default_value' => $config->get('extra_body_classes_custom_classes'),
    ];
    $form['extra_body_classes_custom_classes_status']['extra_body_classes_custom_classes_path'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Add page url'),
      '#description' => $this->t("Specify pages by using their paths. Enter one path per line. Example: node/1 or about-us"),
      '#default_value' => $config->get('extra_body_classes_custom_classes_path'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validation for single day event.
    $extra_body_classes_browser_single_day_event = $form_state->getValue('extra_body_classes_browser_single_day_event');
    if (!preg_match("/^[a-zA-Z0-9-_,]*$/", $extra_body_classes_browser_single_day_event)) {
      $form_state->setErrorByName('extra_body_classes_browser_single_day_event', $this->t('Single day event class name you have provided is invalid.'));
    }
    // Validation for multiple day event.
    $extra_body_classes_event = $form_state->getValue('extra_body_classes_event');
    if (!preg_match("/^[a-zA-Z0-9-_,]*$/", $extra_body_classes_event)) {
      $form_state->setErrorByName('extra_body_classes_event', $this->t('Event class name you have provided is invalid.'));
    }
    $extra_body_classes_event_start_date = $form_state->getValue('extra_body_classes_event_start_date');
    $extra_body_classes_event_end_date = $form_state->getValue('extra_body_classes_event_end_date');
    $timestamp_current_date = date("Y-m-d");
    $timestamp_start_date = strtotime($extra_body_classes_event_start_date);
    $timestamp_end_date = strtotime($extra_body_classes_event_end_date);
    $timestamp_current_date = strtotime($timestamp_current_date);
    // Event start date must be less than end date.
    if ($timestamp_start_date > $timestamp_end_date) {
      $form_state->setErrorByName('timestamp_start_date', $this->t('Event start date must be less than end date.'));
    }
    // Validation for custom classes.
    $extra_body_classes_custom_classes = $form_state->getValue('extra_body_classes_custom_classes');
    if (!preg_match("/^[a-zA-Z0-9-_,]*$/", $extra_body_classes_custom_classes)) {
      $form_state->setErrorByName('extra_body_classes_custom_classes', $this->t('Custom class name you have provided is invalid.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('extra_body_classes.settings');
    // Current browser's platform.
    $config->set('extra_body_classes_browser_platform', $form_state->getValue('extra_body_classes_browser_platform'))->save();
    // Name and version of browser.
    $config->set('extra_body_classes_browser_name_version', $form_state->getValue('extra_body_classes_browser_name_version'))->save();
    // Check whether current device is desktop or mobile.
    $config->set('extra_body_classes_browser_device', $form_state->getValue('extra_body_classes_browser_device'))->save();
    // Current date.
    $config->set('extra_body_classes_date', $form_state->getValue('extra_body_classes_date'))->save();
    // Current year.
    $config->set('extra_body_classes_year', $form_state->getValue('extra_body_classes_year'))->save();
    // Current month.
    $config->set('extra_body_classes_month', $form_state->getValue('extra_body_classes_month'))->save();
    // Current day.
    $config->set('extra_body_classes_day', $form_state->getValue('extra_body_classes_day'))->save();
    // Current role.
    $config->set('extra_body_classes_roles', $form_state->getValue('extra_body_classes_roles'))->save();
    // Single day event.
    $config->set('extra_body_classes_browser_single_day_event', $form_state->getValue('extra_body_classes_browser_single_day_event'))->save();
    $config->set('extra_body_classes_browser_single_day_event_begins', $form_state->getValue('extra_body_classes_browser_single_day_event_begins'))->save();
    // Multiple day event.
    $config->set('extra_body_classes_event', $form_state->getValue('extra_body_classes_event'))->save();
    $config->set('extra_body_classes_event_start_date', $form_state->getValue('extra_body_classes_event_start_date'))->save();
    $config->set('extra_body_classes_event_end_date', $form_state->getValue('extra_body_classes_event_end_date'))->save();
    // Custom classes.
    $config->set('extra_body_classes_custom_classes', $form_state->getValue('extra_body_classes_custom_classes'))->save();
    $config->set('extra_body_classes_custom_classes_path', $form_state->getValue('extra_body_classes_custom_classes_path'))->save();
    parent::submitForm($form, $form_state);
  }

}
