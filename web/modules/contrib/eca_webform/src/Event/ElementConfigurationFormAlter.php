<?php

namespace Drupal\eca_webform\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Provides element configuration form alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementConfigurationFormAlter extends WebformBaseEvent {

  /**
   * Nested array of form elements that comprise the webform element properties.
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
   * Constructs the ElementCoonfigurationFormAlter event.
   *
   * @param array $form
   *   Nested array of form elements that comprise the webform element
   *   properties.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function __construct(array &$form, FormStateInterface $form_state) {
    $this->form = &$form;
    $this->formState = $form_state;
  }

  /**
   * The form.
   *
   * @return array
   *   Nested array of form elements that comprise the webform element
   *   properties.
   */
  public function &getForm(): array {
    return $this->form;
  }

  /**
   * Tthe form state.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The current state of the form.
   */
  public function getFormState(): FormStateInterface {
    return $this->formState;
  }

}
