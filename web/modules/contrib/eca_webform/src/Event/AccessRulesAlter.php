<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides access rules alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class AccessRulesAlter extends WebformBaseEvent {

  /**
   * Array of known access rules.
   *
   * @var array
   */
  protected array $accessRules;

  /**
   * Constructs the AccessRulesAlter event.
   *
   * @param array $access_rules
   *   Array of known access rules. Its structure is identical to the return of
   *   hook_webform_access_rules().
   */
  public function __construct(array &$access_rules) {
    $this->accessRules = &$access_rules;
  }

  /**
   * Array of known access rules.
   *
   * @return array
   *   Array of known access rules.
   */
  public function &getAccessRules(): array {
    return $this->accessRules;
  }

}
