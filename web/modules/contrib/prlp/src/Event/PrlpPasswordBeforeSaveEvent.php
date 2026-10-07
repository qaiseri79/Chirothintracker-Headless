<?php

namespace Drupal\prlp\Event;

use Drupal\user\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when password is updated.
 */
class PrlpPasswordBeforeSaveEvent extends Event {

  /**
   * The user resetting its password.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $user;

  /**
   * Constructor of the ResetPasswordUpdateEvent class.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user resetting its password.
   */
  public function __construct(UserInterface &$user) {
    $this->user = &$user;
  }

  /**
   * User getter.
   *
   * @return \Drupal\user\UserInterface
   *   The user resetting its password.
   */
  public function &getUser(): UserInterface {
    return $this->user;
  }

}
