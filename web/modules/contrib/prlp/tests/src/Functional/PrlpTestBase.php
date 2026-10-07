<?php

namespace Drupal\Tests\prlp\Functional;

use Drupal\Core\Database\Database;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Browser test base class for PRLP functional tests.
 *
 * @group prlp
 */
abstract class PrlpTestBase extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['user', 'prlp'];

  /**
   * The user used to test.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Make sure to complete the normal setup steps first.
    parent::setUp();

    // Create user with the appropriate admin role.
    $account = $this->createUser(['administer users']);
    $this->drupalLogin($account);

    $this->account = User::load($account->id());
    $this->account->passRaw = $account->passRaw;
    $this->drupalLogout();

    // Set the last login time that is used to generate the one-time link so
    // that it is definitely over a second ago.
    $account->login = \Drupal::time()->getRequestTime() - mt_rand(10, 100000);
    Database::getConnection()->update('users_field_data')
      ->fields(['login' => $account->getLastLoginTime()])
      ->condition('uid', $account->id())
      ->execute();
  }

}
