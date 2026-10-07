<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides source entity info alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class SourceEntityInfoAlter extends WebformBaseEvent {

  /**
   * The array of plugin definitions.
   *
   * @var array
   */
  protected array $definitions;

  /**
   * Constructs the SourceElementInfoAlter event.
   *
   * @param array $definitions
   *   The array of plugin definitions.
   */
  public function __construct(array &$definitions) {
    $this->definitions = &$definitions;
  }

  /**
   * The definitions.
   *
   * @return array
   *   The array of plugin definitions.
   */
  public function &getDefinitions(): array {
    return $this->definitions;
  }

}
