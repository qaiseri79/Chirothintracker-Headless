<?php

namespace Drupal\Tests\config_view\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Settings form test.
 *
 * @group config_view
 */
class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['config_view', 'block'];

  /**
   * The test admin user.
   *
   * @var \Drupal\User\UserInterface
   */
  protected $admin;

  /**
   * Test the settings form submits properly.
   */
  public function testForm() {
    $this->admin = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($this->admin);

    $this->drupalGet('/admin/structure/views/settings/config_view');
    $this->assertSession()->statusCodeEquals(200);

    // Check off "Block" configuration entity.
    $this->getSession()->getPage()->findById('edit-data-block')->check();

    // Find Submit button.
    $button_save = $this->getSession()->getPage()->findButton('Submit');

    // Submit form.
    $button_save->click();

    // Reload page.
    $this->drupalGet('/admin/structure/views/settings/config_view');
    $this->assertSession()->statusCodeEquals(200);

    // Check to make sure "Block" configuration entity is checked.
    $this->assertEquals(TRUE, $this->getSession()->getPage()->findById('edit-data-block')->isChecked(), 'Block checkbox is not checked, but it should be.');
  }

}
