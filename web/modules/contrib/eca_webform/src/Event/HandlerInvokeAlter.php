<?php

namespace Drupal\eca_webform\Event;

use Drupal\webform\Plugin\WebformHandlerInterface;

/**
 * Provides handler invoke alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class HandlerInvokeAlter extends WebformBaseEvent {

  /**
   * A webform handler attached to a webform.
   *
   * @var \Drupal\webform\Plugin\WebformHandlerInterface
   */
  protected WebformHandlerInterface $handler;

  /**
   * The invoked method name converted to snake case.
   *
   * @var string
   */
  protected string $methodName;

  /**
   * Arguments being passed to the handler's method.
   *
   * @var array
   */
  protected array $args;

  /**
   * Constructs the HandlerInvokeAlter event.
   *
   * @param \Drupal\webform\Plugin\WebformHandlerInterface $handler
   *   A webform handler attached to a webform.
   * @param string $method_name
   *   The invoked method name converted to snake case.
   * @param array $args
   *   Arguments being passed to the handler's method.
   */
  public function __construct(WebformHandlerInterface $handler, string $method_name, array &$args) {
    $this->handler = $handler;
    $this->methodName = $method_name;
    $this->args = &$args;
  }

  /**
   * A webform handler attached to a webform.
   *
   * @return \Drupal\webform\Plugin\WebformHandlerInterface
   *   A webform handler attached to a webform.
   */
  public function getHandler(): WebformHandlerInterface {
    return $this->handler;
  }

  /**
   * The invoked method name converted to snake case.
   *
   * @return string
   *   The invoked method name converted to snake case.
   */
  public function getMethodName(): string {
    return $this->methodName;
  }

  /**
   * Arguments being passed to the handler's method.
   *
   * @return array
   *   Arguments being passed to the handler's method.
   */
  public function &getArgs(): array {
    return $this->args;
  }

}
