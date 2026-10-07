<?php

namespace Drupal\prlp\Event;

use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when the password needs to be validated.
 */
class PrlpPasswordValidateEvent extends Event {

  /**
   * The password reset form state.
   *
   * @var \Drupal\Core\Form\FormStateInterface
   */
  protected FormStateInterface $formState;

  /**
   * The user resetting its password.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $user;

  /**
   * Constructor of the ResetPasswordValidationEvent class.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The reset password form state.
   * @param \Drupal\user\UserInterface $user
   *   The user resetting its password.
   */
  public function __construct(
    FormStateInterface &$form_state,
    UserInterface $user
  ) {
    $this->formState = &$form_state;
    $this->user = $user;
  }

  /**
   * Form state getter.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The reset password form state.
   */
  public function &getFormState(): FormStateInterface {
    return $this->formState;
  }

  /**
   * User getter.
   *
   * @return \Drupal\user\UserInterface
   *   The user resetting its password.
   */
  public function getUser(): UserInterface {
    return $this->user;
  }

}
