<?php

namespace Drupal\Tests\config_view\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Simple test to ensure that main page loads with module enabled.
 *
 * @group config_view
 */
class LoadTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['config_view'];

  /**
   * The test admin user.
   *
   * @var \Drupal\User\UserInterface
   */
  protected $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($this->admin);
  }

  /**
   * Tests that the home page loads with a 200 response.
   */
  public function testLoad() {
    $this->drupalGet(Url::fromRoute('<front>'));
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that the settings page loads with a 200 response.
   */
  public function testSettingsPage() {
    $this->drupalGet('/admin/structure/views/settings/config_view');
    $this->assertSession()->statusCodeEquals(200);
  }

}
