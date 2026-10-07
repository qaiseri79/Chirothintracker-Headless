<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides help info alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class HelpInfoAlter extends WebformBaseEvent {

  /**
   * Webform help data as collected from hook_webform_help_info().
   *
   * @var array
   */
  protected array $help;

  /**
   * Constructs the HelpInfoAlter event.
   *
   * @param array $help
   *   Webform help data as collected from hook_webform_help_info().
   */
  public function __construct(array &$help) {
    $this->help = &$help;
  }

  /**
   * Webform help data as collected from hook_webform_help_info().
   *
   * @return array
   *   Webform help data as collected from hook_webform_help_info().
   */
  public function &getHelp(): array {
    return $this->help;
  }

}
