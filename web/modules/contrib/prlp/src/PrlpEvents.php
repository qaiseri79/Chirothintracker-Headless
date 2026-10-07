<?php

namespace Drupal\prlp;

/**
 * Defines events provided by the Password Reset Landing Page module.
 */
final class PrlpEvents {

  /**
   * Dispatches when password is being validated.
   *
   * Facilitates integration of other modules like Password Policy.
   *
   * @Event
   *
   * @see \Drupal\prlp\Controller\PrlpController::prlpResetPassLogin()
   *
   * @var string
   */
  public const PASSWORD_VALIDATE = 'prlp.password_validate';

  /**
   * Dispatches before saving the password that have been reset.
   *
   * Facilitates integration of other modules like Password Policy.
   *
   * @Event
   *
   * @see \Drupal\prlp\Controller\PrlpController::prlpResetPassLogin()
   *
   * @var string
   */
  public const PASSWORD_BEFORE_SAVE = 'prlp.password_before_save';

}
