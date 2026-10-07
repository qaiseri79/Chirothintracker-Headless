<?php

namespace Drupal\eca_webform\Event;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eca\Event\AccessEventInterface;

/**
 * Provides element access event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ElementAccessAlter extends WebformBaseEvent implements AccessEventInterface {

  /**
   * An element create, view, or update operation.
   *
   * @var string
   */
  protected string $operation;

  /**
   * Webform specific element properties.
   *
   * @var array
   */
  protected array $element;

  /**
   * The user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $account;

  /**
   * The element's current context.
   *
   * @var array
   */
  protected array $context;

  /**
   * The access result.
   *
   * @var \Drupal\Core\Access\AccessResultInterface|null
   */
  protected ?AccessResultInterface $accessResult = NULL;

  /**
   * Constructs the ElementAccessAlter event.
   *
   * @param string $operation
   *   An element create, view, or update operation.
   * @param array $element
   *   Webform specific element properties.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param array $context
   *   The element's current context which include the webform and
   *   webform submission entity.
   */
  public function __construct(string $operation, array &$element, AccountInterface $account, array $context = []) {
    $this->operation = $operation;
    $this->element = &$element;
    $this->account = $account;
    $this->context = $context;
  }

  /**
   * The operation.
   *
   * @return string
   *   An element create, view, or update operation.
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * The element.
   *
   * @return array
   *   Webform specific element properties.
   */
  public function &getElement(): array {
    return $this->element;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccount(): AccountInterface {
    return $this->account;
  }

  /**
   * The context.
   *
   * @return array
   *   The element's current context.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessResult(): ?AccessResultInterface {
    return $this->accessResult;
  }

  /**
   * {@inheritdoc}
   */
  public function setAccessResult(AccessResultInterface $result): ElementAccessAlter {
    $this->accessResult = $result;
    return $this;
  }

}
