<?php

namespace Drupal\eca_webform\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eca\Plugin\Action\ConfigurableActionBase;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Sets data in a webform submission.
 *
 * @Action(
 *   id = "eca_webform_submission_set_data",
 *   label = @Translation("Webform Submission: Set data"),
 *   type = "webform_submission"
 * )
 */
class SetSubmissionData extends ConfigurableActionBase {

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access_result = AccessResult::forbidden();
    $field_name = $this->tokenServices->replace($this->configuration['field_name']);
    if ($object instanceof WebformSubmission && $object->getWebform()->getElement($field_name) !== NULL) {
      $access_result = AccessResult::allowed();
    }
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function execute($webform_submission = NULL): void {
    $field_name = $this->tokenServices->replace($this->configuration['field_name']);
    $field_value = $this->tokenServices->replace($this->configuration['field_value']);
    /** @var \Drupal\webform\Entity\WebformSubmission $webform_submission */
    $webform_submission->setElementData($field_name, $field_value);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'field_name' => '',
      'field_value' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['field_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field name'),
      '#description' => $this->t('The machine name of the field, that holds the value. This property supports tokens.'),
      '#default_value' => $this->configuration['field_name'],
      '#required' => TRUE,
      '#weight' => -20,
    ];
    $form['field_value'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Field value'),
      '#description' => $this->t('The new field value. This property supports tokens.'),
      '#default_value' => $this->configuration['field_value'],
      '#weight' => -10,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['field_name'] = $form_state->getValue('field_name');
    $this->configuration['field_value'] = $form_state->getValue('field_value');
    parent::submitConfigurationForm($form, $form_state);
  }

}
