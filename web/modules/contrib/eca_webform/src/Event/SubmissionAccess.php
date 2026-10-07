<?php

namespace Drupal\eca_webform\Event;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\eca\Event\AccessEventInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides submission access event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class SubmissionAccess extends WebformBaseEvent implements AccessEventInterface {

  /**
   * The webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected WebformSubmissionInterface $webformSubmission;

  /**
   * The operation.
   *
   * @var string
   */
  protected string $operation;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $account;

  /**
   * The access result.
   *
   * @var \Drupal\Core\Access\AccessResultInterface|null
   */
  protected ?AccessResultInterface $accessResult = NULL;

  /**
   * Constructs the SubmissionAccess event.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param string $operation
   *   The operation.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   */
  public function __construct(WebformSubmissionInterface $webform_submission, string $operation, AccountInterface $account) {
    $this->webformSubmission = $webform_submission;
    $this->operation = $operation;
    $this->account = $account;
  }

  /**
   * The webform submission.
   *
   * @return \Drupal\webform\WebformSubmissionInterface
   *   The webform submission.
   */
  public function getWebformSubmission(): WebformSubmissionInterface {
    return $this->webformSubmission;
  }

  /**
   * The operation.
   *
   * @return string
   *   The operation.
   */
  public function getOperation(): string {
    return $this->operation;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccount(): AccountInterface {
    return $this->account;
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
  public function setAccessResult(AccessResultInterface $result): SubmissionAccess {
    $this->accessResult = $result;
    return $this;
  }

}
