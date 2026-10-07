<?php

namespace Drupal\Tests\filter_perms\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Contains Filter Perms functionality tests.
 *
 * @group filter_perms
 */
class FilterPermsTest extends BrowserTestBase {

  /**
   * The admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $admin;

  /**
   * The user manager user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $userManager;

  /**
   * The author user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $author;

  /**
   * The user manager role ID.
   *
   * @var string
   */
  protected string $userManagerRole;

  /**
   * The author role ID.
   *
   * @var string
   */
  protected string $authorRole;

  /**
   * The user manager role Label.
   *
   * @var string
   */
  protected string $userManagerRoleLabel = 'User manager role';

  /**
   * The author role Label.
   *
   * @var string
   */
  /**
   * Define label.
   */
  protected string $authorRoleLabel = 'Author role';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'filter_perms',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create user manager role and user without 'administer permissions'.
    $user_manager_permissions = [
      'access administration pages',
      'view the administration theme',
      'access user profiles',
      'administer users',
      'view user email addresses',
    ];
    // Use the label defined above when creating the role.
    $this->userManagerRole = $this->drupalCreateRole($user_manager_permissions, 'user_manager_role', $this->userManagerRoleLabel);
    $this->userManager = $this->drupalCreateUser([], 'user_manager_test');
    $this->userManager->addRole($this->userManagerRole);
    $this->userManager->save();

    // Create author role and user without 'administer permissions'.
    $author_permissions = [
      'access administration pages',
      'view the administration theme',
    ];
    // Use the label defined above when creating the role.
    $this->authorRole = $this->drupalCreateRole($author_permissions, 'author_role', $this->authorRoleLabel);
    $this->author = $this->drupalCreateUser([], 'author_test');
    $this->author->addRole($this->authorRole);
    $this->author->save();

    // Create admin user with all permissions.
    $this->admin = $this->drupalCreateUser([], 'admin_test', TRUE);
  }

  /**
   * Tests access to the permissions page.
   *
   * Users without 'administer permissions' should be denied access.
   */
  public function testFilterPermsAccess() {
    // Anonymous user should not have access.
    $this->drupalGet('admin/people/permissions');
    $this->assertSession()->statusCodeEquals(403);

    // User manager (without 'administer permissions') should not have access.
    $this->drupalLogin($this->userManager);
    $this->drupalGet('admin/people/permissions');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Author (without 'administer permissions') should not have access.
    $this->drupalLogin($this->author);
    $this->drupalGet('admin/people/permissions');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    // Admin user should have access.
    $this->drupalLogin($this->admin);
    $this->drupalGet('admin/people/permissions');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();
  }

  /**
   * Tests the filter permissions form functionality and saving permissions.
   */
  public function testFilterPermsForm() {
    $this->drupalLogin($this->admin);
    $this->drupalGet('admin/people/permissions');
    $this->assertSession()->statusCodeEquals(200);

    // Initially, no permissions table should be shown, only the help text.
    $this->assertSession()->pageTextContains('Please select at least one value from both the Roles and Modules select boxes above and then click the "Filter Permissions" button.');
    $this->assertSession()->elementTextNotContains('css', 'table.permissions', 'System');

    // Filter for User/System modules and Author/User Manager roles.
    $filter_edit_initial = [
      'roles[]' => [
        $this->userManagerRole,
        $this->authorRole,
      ],
      'modules[]' => [
        'user',
        'system',
      ],
    ];
    $this->submitForm($filter_edit_initial, 'Filter Permissions');

    // Assert that the permissions table now exists.
    $this->assertSession()->elementTextContains('css', 'table.permissions', 'System');

    // Assert table header has 3 columns: Permission name and the two selected
    // roles (using labels).
    $this->assertSession()->elementsCount('css', 'table.permissions thead tr th', 3);
    $this->assertSession()->elementTextContains('css', 'table.permissions thead tr th:nth-child(2)', $this->userManagerRoleLabel);
    $this->assertSession()->elementTextContains('css', 'table.permissions thead tr th:nth-child(3)', $this->authorRoleLabel);

    // Assert the correct modules are shown.
    $module_elements = $this->getSession()->getPage()->findAll('css', 'table.permissions tbody td.module');
    $found_modules = [];
    foreach ($module_elements as $element) {
      $found_modules[] = $element->getText();
    }
    sort($found_modules);
    $expected_modules = ['System', 'User'];
    sort($expected_modules);
    $this->assertEquals($expected_modules, $found_modules, 'The table initially shows exactly the System and User modules.');
    $this->assertNotContains('Filter', $found_modules, 'The Filter module is not listed initially.');

    // Check initial state and save first set of changes.
    // Check that 'view user email addresses' is initially checked for user
    // manager.
    $this->assertSession()->checkboxChecked($this->userManagerRole . '[view user email addresses]');

    // Define and save the first set of permission changes.
    $save_edit_1 = [
      $this->userManagerRole . '[access administration pages]' => TRUE,
      $this->authorRole . '[access administration pages]' => TRUE,
      $this->userManagerRole . '[view the administration theme]' => TRUE,
      $this->authorRole . '[view the administration theme]' => TRUE,
      $this->userManagerRole . '[access user profiles]' => TRUE,
      $this->userManagerRole . '[administer users]' => TRUE,
      // Explicitly uncheck this one.
      $this->userManagerRole . '[view user email addresses]' => FALSE,
    ];
    $this->submitForm($save_edit_1, 'Save permissions');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The changes have been saved.');

    // Step 3: Re-filter and check state after first save.
    // Re-apply the initial filter to see the results of the save.
    $this->submitForm($filter_edit_initial, 'Filter Permissions');
    $this->assertSession()->elementExists('css', 'table.permissions');

    // Check that 'view user email addresses' is now unchecked for user manager.
    $this->assertSession()->checkboxNotChecked($this->userManagerRole . '[view user email addresses]');

    // Check that 'view the administration theme' is still checked for author.
    $this->assertSession()->checkboxChecked($this->authorRole . '[view the administration theme]');

    // Re-filter for System module and Author role.
    $filter_edit_author_system = [
      'roles[]' => [$this->authorRole],
      'modules[]' => ['system'],
    ];
    $this->submitForm($filter_edit_author_system, 'Filter Permissions');
    $this->assertSession()->elementTextContains('css', 'table.permissions', 'System');

    // Assert table header now has 2 columns: Permission name and the author
    // role.
    $this->assertSession()->elementsCount('css', 'table.permissions thead tr th', 2);
    $this->assertSession()->elementTextContains('css', 'table.permissions thead tr th:nth-child(2)', $this->authorRoleLabel);

    // Assert only System module is shown.
    $module_elements_author = $this->getSession()->getPage()->findAll('css', 'table.permissions tbody td.module');
    $this->assertCount(1, $module_elements_author);
    $this->assertEquals('System', $module_elements_author[0]->getText());

    // Uncheck a permission for Author and save. Check that 'view the
    // administration theme' is currently checked for author.
    $this->assertSession()->checkboxChecked($this->authorRole . '[view the administration theme]');

    // Uncheck 'view the administration theme' for the author role and save.
    $save_edit_2 = [
      $this->authorRole . '[view the administration theme]' => FALSE,
    ];

    // Note: We only need to submit the changed value when saving.
    $this->submitForm($save_edit_2, 'Save permissions');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('The changes have been saved.');

    // Step 6: Reload original filter and verify final state.
    // Re-apply the initial filter again.
    $this->submitForm($filter_edit_initial, 'Filter Permissions');
    $this->assertSession()->elementExists('css', 'table.permissions');

    // Assert 'view the administration theme' is now unchecked for author.
    $this->assertSession()->checkboxNotChecked($this->authorRole . '[view the administration theme]');

    // Assert 'view user email addresses' is still unchecked for user manager
    // (unchanged by the last save).
    $this->assertSession()->checkboxNotChecked($this->userManagerRole . '[view user email addresses]');

    // Assert 'administer users' is still checked for user manager (unchanged
    // by the last save).
    $this->assertSession()->checkboxChecked($this->userManagerRole . '[administer users]');

    $this->drupalLogout();
  }

}
