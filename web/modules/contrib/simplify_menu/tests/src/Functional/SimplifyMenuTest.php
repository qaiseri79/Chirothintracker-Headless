<?php

declare(strict_types=1);

namespace Drupal\Tests\simplify_menu\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests Simplify Menu on contact pages.
 *
 * @group simplify_menu
 */
class SimplifyMenuTest extends BrowserTestBase {

  /**
   * The install profile used for this test.
   *
   * @var string
   */
  protected $profile = 'standard';

  /**
   * The admin user used for this test.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'simplify_menu', 'simplify_menu_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
    ], 'Admin User', TRUE);
  }

  /**
   * Test for Contact forms.
   */
  public function testTwigExtension() {
    // Test links with anonymous user.
    $this->drupalGet('node');
    $element = $this->xpath('//nav[@id="main"]//a[text()="Home" and @href[contains(., "/")]]');
    $this->assertTrue(count($element) === 1, 'The Main menu was rendered correctly');

    $element = $this->xpath('//nav[@id="account"]//a[text()="My account"]');
    $this->assertTrue(count($element) === 0, 'The Account menu is not visible');

    $element = $this->xpath('//nav[@id="account"]//a[text()="Log in" and @href[contains(., "/user/login")]]');
    $this->assertTrue(count($element) === 1, 'The Login menu is visible');

    $element = $this->xpath('//nav[@id="admin"]//a[text()="Administration"]');
    $this->assertTrue(count($element) === 0, 'The Admin menu is not visible');

    $element = $this->xpath('//a[text()="Inaccessible"]');
    $this->assertTrue(count($element) === 0, 'The text Inaccessible should not be on the links');

    // Test links with authenticated user.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('node');
    $element = $this->xpath('//nav[@id="main"]//a[text()="Home" and @href[contains(., "/")]]');
    $this->assertTrue(count($element) === 1, 'The Main menu was rendered correctly');

    $element = $this->xpath('//nav[@id="account"]//a[text()="My account" and @href[contains(., "/user")]]');
    $this->assertTrue(count($element) === 1, 'The Account menu is visible');

    $element = $this->xpath('//nav[@id="account"]//a[text()="Log out" and @href[contains(., "/user/logout")]]');
    $this->assertTrue(count($element) === 1, 'The Login menu is visible');

    $element = $this->xpath('//nav[@id="admin"]//a[text()="Administration" and @href[contains(., "/admin")]]');
    $this->assertTrue(count($element) === 1, 'The Admin menu was rendered correctly');
  }

}
