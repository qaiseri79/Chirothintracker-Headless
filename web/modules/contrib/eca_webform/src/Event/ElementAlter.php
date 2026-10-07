<?php

namespace Drupal\eca_webform\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Provides element alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementAlter extends WebformBaseEvent {

  /**
   * Webform specific element properties.
   *
   * @var array
   */
  protected array $element;

  /**
   * The current state of the form.
   *
   * @var \Drupal\Core\Form\FormStateInterface
   */
  protected FormStateInterface $formState;

  /**
   * An associative array.
   *
   * @var array
   */
  protected array $context;

  /**
   * Constructs the ElementAlter event.
   *
   * @param array $element
   *   Webform specific element properties.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $context
   *   An associative array containing the following key-value pairs:
   *   - form: The form structure to which elements is being attached.
   */
  public function __construct(array &$element, FormStateInterface $form_state, array $context) {
    $this->element = &$element;
    $this->formState = $form_state;
    $this->context = $context;
  }

  /**
   * The element.
   *
   * @return array
   *   Webform specific element properties.
   */
  public function &getElement(): array {
    return $this->element;
  }

  /**
   * The form state.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The current state of the form.
   */
  public function getFormState(): FormStateInterface {
    return $this->formState;
  }

  /**
   * The context.
   *
   * @return array
   *   An associative array.
   */
  public function getContext(): array {
    return $this->context;
  }

}
