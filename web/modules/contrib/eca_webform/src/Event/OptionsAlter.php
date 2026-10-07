<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides options alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class OptionsAlter extends WebformBaseEvent {

  /**
   * An associative array of options.
   *
   * @var array
   */
  protected array $options;

  /**
   * The webform element that the options is for.
   *
   * @var array
   */
  protected array $element;

  /**
   * The webform options id. Set to NULL if the options are custom.
   *
   * @var string|null
   */
  protected ?string $optionsId;

  /**
   * Constructs the OptionsAlter event.
   *
   * @param array $options
   *   An associative array of options.
   * @param array $element
   *   The webform element that the options is for.
   * @param string|null $options_id
   *   The webform options id. Set to NULL if the options are custom.
   */
  public function __construct(array &$options, array &$element, string $options_id = NULL) {
    $this->options = &$options;
    $this->element = &$element;
    $this->optionsId = $options_id;
  }

  /**
   * The options.
   *
   * @return array
   *   An associative array of options.
   */
  public function &getOptions(): array {
    return $this->options;
  }

  /**
   * The element.
   *
   * @return array
   *   The webform element that the options is for.
   */
  public function &getElement(): array {
    return $this->element;
  }

  /**
   * The option ID.
   *
   * @return string|null
   *   The webform options id. Set to NULL if the options are custom.
   */
  public function getOptionsId(): ?string {
    return $this->optionsId;
  }

}
