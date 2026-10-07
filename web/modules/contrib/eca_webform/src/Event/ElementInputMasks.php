<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides element input masks event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementInputMasks extends WebformBaseEvent {

  /**
   * The input masks.
   *
   * @var array
   */
  protected array $inputMasks = [];

  /**
   * The input masks.
   *
   * @return array
   *   The input masks.
   */
  public function getInputMasks(): array {
    return $this->inputMasks;
  }

  /**
   * The input masks.
   *
   * @param array $inputMasks
   *   The input masks.
   */
  public function setInputMasks(array $inputMasks): void {
    $this->inputMasks = $inputMasks;
  }

}
