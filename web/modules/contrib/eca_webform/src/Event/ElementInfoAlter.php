<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides element info alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementInfoAlter extends WebformBaseEvent {

  /**
   * Array of webform handlers, keyed on the machine-readable handler name.
   *
   * @var array
   */
  protected array $handlers;

  /**
   * Constructs the HandlerInfoAlter event.
   *
   * @param array $handlers
   *   Array of webform handlers, keyed on the machine-readable handler name.
   */
  public function __construct(array &$handlers) {
    $this->handlers = &$handlers;
  }

  /**
   * The handlers.
   *
   * @return array
   *   Array of webform handlers, keyed on the machine-readable handler name.
   */
  public function &getHandlers(): array {
    return $this->handlers;
  }

}
