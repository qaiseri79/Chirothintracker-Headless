<?php

namespace Drupal\Tests\prlp\Functional;

/**
 * Tests the default admin settings functionality.
 *
 * @group prlp
 */
class PrlpSettingsFormTest extends PrlpTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Make sure to complete the normal setup steps first.
    parent::setUp();
    $this->drupalLogin($this->account);
  }

  /**
   * Test admin settings form.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testAdminSettingsForm(): void {
    $this->drupalGet('admin/config/people/accounts/prlp');

    $session_assert = $this->assertSession();

    // Confirm the settings form exists.
    $session_assert->statusCodeEquals(200);

    // Check if the expected fields are present.
    $session_assert->fieldExists('password_required');
    $session_assert->fieldExists('login_destination');

    // Assert that form is in the expected initial state.
    $session_assert->checkboxChecked('password_required');
    $session_assert->fieldValueEquals('login_destination', '/user/%user/edit');
  }

  /**
   * Test admin settings form submit.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testAdminSettingsFormSubmit(): void {
    $this->drupalGet('admin/config/people/accounts/prlp');

    $this->submitForm([
      'password_required' => 0,
      'login_destination' => '/user',
    ], 'Save configuration');

    $session_assert = $this->assertSession();
    // Confirm the changes have been saved.
    $session_assert->checkboxNotChecked('password_required');
    $session_assert->fieldValueEquals('login_destination', '/user');
  }

}
