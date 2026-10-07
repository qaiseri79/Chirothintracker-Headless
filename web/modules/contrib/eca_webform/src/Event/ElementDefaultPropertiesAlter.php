<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides element default properties alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementDefaultPropertiesAlter extends WebformBaseEvent {

  /**
   * An associative array containing an element's default properties.
   *
   * @var array
   */
  protected array $properties;

  /**
   * The webform element's definition.
   *
   * @var array
   */
  protected array $definition;

  /**
   * Constructs the ElementDefaultPropertiesAlter event.
   *
   * @param array &$properties
   *   An associative array containing an element's default properties.
   * @param array $definition
   *   The webform element's definition.
   */
  public function __construct(array &$properties, array $definition) {
    $this->properties = &$properties;
    $this->definition = $definition;
  }

  /**
   * The properties.
   *
   * @return array
   *   An associative array containing an element's default properties.
   */
  public function &getProperties(): array {
    return $this->properties;
  }

  /**
   * The definition.
   *
   * @return array
   *   The webform element's definition.
   */
  public function getDefinition(): array {
    return $this->definition;
  }

}
