<?php

namespace Drupal\eca_webform\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Provides submission form alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class SubmissionFormAlter extends WebformBaseEvent {

  /**
   * Nested array of form elements that comprise the webform.
   *
   * @var array
   */
  protected array $form;

  /**
   * The current state of the form.
   *
   * @var \Drupal\Core\Form\FormStateInterface
   */
  protected FormStateInterface $formState;

  /**
   * String representing the webform's id.
   *
   * @var string
   */
  protected string $formId;

  /**
   * Constructs the SubmissionFormAlter event.
   *
   * @param array $form
   *   Nested array of form elements that comprise the webform.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $form_id
   *   String representing the webform's id.
   */
  public function __construct(array &$form, FormStateInterface $form_state, string $form_id) {
    $this->form = &$form;
    $this->formState = $form_state;
    $this->formId = $form_id;
  }

  /**
   * The form.
   *
   * @return array
   *   Nested array of form elements that comprise the webform.
   */
  public function &getForm(): array {
    return $this->form;
  }

  /**
   * The current state of the form.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The current state of the form.
   */
  public function getFormState(): FormStateInterface {
    return $this->formState;
  }

  /**
   * String representing the webform's id.
   *
   * @return string
   *   String representing the webform's id.
   */
  public function getFormId(): string {
    return $this->formId;
  }

}
