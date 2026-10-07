<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides element translatable properties alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementTranslatablePropertiesAlter extends WebformBaseEvent {

  /**
   * An indexed array containing an element's translatable properties.
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
   * Constructs the ElementTranslatablePropertiesAlter event.
   *
   * @param array &$properties
   *   An indexed array containing an element's translatable properties.
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
   *   An indexed array containing an element's translatable properties.
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
