<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides element input masks alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementInputMasksAlter extends WebformBaseEvent {

  /**
   * An array of input masks for text based webform elements.
   *
   * @var array
   */
  protected array $inputMasks;

  /**
   * Constructs the ElementInputMasksAlter event.
   *
   * @param array $input_masks
   *   An array of input masks for text based webform elements.
   */
  public function __construct(array &$input_masks) {
    $this->inputMasks = &$input_masks;
  }

  /**
   * The input masks.
   *
   * @return array
   *   An array of input masks for text based webform elements.
   */
  public function &getInputMasks(): array {
    return $this->inputMasks;
  }

}
