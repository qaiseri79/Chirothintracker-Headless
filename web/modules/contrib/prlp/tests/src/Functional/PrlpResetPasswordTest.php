<?php

namespace Drupal\Tests\prlp\Functional;

use Drupal\Core\Test\AssertMailTrait;

/**
 * Tests the functionality of the module.
 *
 * @group prlp
 */
class PrlpResetPasswordTest extends PrlpTestBase {

  use AssertMailTrait {
    getMails as drupalGetMails;
  }

  /**
   * The PRLP module config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $moduleConfig;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Make sure to complete the normal setup steps first.
    parent::setUp();
    $this->moduleConfig = $this->config('prlp.settings');
  }

  /**
   * Test if the password reset field is present.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testPasswordResetFieldIsPresent(): void {
    $resetURL = $this->getResetUrl();
    $this->drupalGet($resetURL);

    $session_assert = $this->assertSession();
    // Make sure a 200 status code is observed.
    $session_assert->statusCodeEquals(200);
    // Make sure the expected passwork fields are present.
    $session_assert->fieldExists('pass[pass1]');
    $session_assert->fieldExists('pass[pass2]');
  }

  /**
   * Test if the user password is required.
   *
   * @throws \Behat\Mink\Exception\ElementHtmlException
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testUserPasswordIsRequired(): void {
    // Make sure that the field is required.
    // If not then set it as required.
    if (!$this->moduleConfig->get('password_required')) {
      $this->setConfig([
        'password_required' => 1,
      ]);
    }

    $resetURL = $this->getResetUrl();
    $this->drupalGet($resetURL);

    $session_assert = $this->assertSession();
    // Ensure that the fields are marked as required.
    $session_assert->elementAttributeExists('css', 'input[name="pass[pass1]"]', 'required');
    $session_assert->elementAttributeExists('css', 'input[name="pass[pass2]"]', 'required');

    $this->submitForm([], 'Log in');
    // Make sure we get an error if no password was given.
    $session_assert->pageTextContains('Error message Password field is required');
  }

  /**
   * Test if the user password is not required.
   *
   * @throws \Behat\Mink\Exception\ElementHtmlException
   */
  public function testUserPasswordIsNotRequired(): void {
    // Make sure that the field is not required.
    // If not then set it as not required.
    if ($this->moduleConfig->get('password_required')) {
      $this->setConfig([
        'password_required' => 0,
      ]);
    }

    $resetURL = $this->getResetUrl();
    $this->drupalGet($resetURL);

    $session_assert = $this->assertSession();
    // Ensure that the fields are NOT marked as required.
    $session_assert->elementAttributeNotExists('css', 'input[name="pass[pass1]"]', 'required');
    $session_assert->elementAttributeNotExists('css', 'input[name="pass[pass2]"]', 'required');
  }

  /**
   * Test if the password has been updated.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testUserPasswordDidUpdate(): void {
    // Get the one-time login link and use it.
    $resetURL = $this->getResetURL();
    $this->drupalGet($resetURL);

    // Generate a new random password.
    $newPass = \Drupal::service('password_generator')->generate();
    // Fill in the password fields using the password and submit the form.
    $this->submitForm([
      'pass[pass1]' => $newPass,
      'pass[pass2]' => $newPass,
    ], 'Log in', 'user-pass-reset');

    $session_assert = $this->assertSession();
    // Make sure we've successfully updated the account.
    $session_assert->pageTextContains('Your new password has been saved.');

    $this->drupalLogout();

    $this->drupalGet('/user/login');
    // Attempt to log in using the new password.
    $this->submitForm([
      'name' => $this->account->getAccountName(),
      'pass' => $newPass,
    ], 'Log in', 'user-login-form');

    // Make sure we're on the user profile page, meaning the login was
    // successful.
    $session_assert->addressEquals("/user/{$this->account->id()}");
    $session_assert->titleEquals($this->account->getAccountName() . ' | Drupal');
  }

  /**
   * Helper function to generate and retrieve the password reset link.
   *
   * @return mixed
   *   The password reset link URL.
   */
  protected function getResetUrl() {
    $this->drupalGet('user/password');

    $this->submitForm([
      'name' => $this->account->getEmail(),
    ], 'Submit');

    $_emails = $this->drupalGetMails();
    $email = end($_emails);
    $urls = [];
    preg_match('#.+user/reset/.+#', $email['body'], $urls);
    return $urls[0];
  }

  /**
   * Helper function to enable/disable the required status of the field.
   *
   * @param array $options
   *   The options array.
   */
  protected function setConfig(array $options): void {
    foreach ($options as $key => $value) {
      $this->moduleConfig->set($key, $value);
    }
    $this->moduleConfig->save();
  }

}
