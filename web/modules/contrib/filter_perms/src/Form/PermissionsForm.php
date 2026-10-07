<?php

namespace Drupal\filter_perms\Form;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\user\Form\UserPermissionsForm;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an enhanced user permissions administration form.
 */
class PermissionsForm extends UserPermissionsForm {

  /**
   * Indicates that all options should be user for filter.
   */
  const ALL_OPTIONS = '-1';

  /**
   * The expirable key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueExpirable;

  /**
   * Constructs a new PermissionsForm.
   *
   * @param \Drupal\user\PermissionHandlerInterface $permission_handler
   *   The permission handler.
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *   The role storage.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   */
  public function __construct(PermissionHandlerInterface $permission_handler, RoleStorageInterface $role_storage, ModuleHandlerInterface $module_handler, ModuleExtensionList $moduleExtensionList, KeyValueStoreExpirableInterface $key_value_expirable) {
    parent::__construct($permission_handler, $role_storage, $module_handler, $moduleExtensionList);

    $this->keyValueExpirable = $key_value_expirable;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.permissions'),
      $container->get('entity_type.manager')->getStorage('user_role'),
      $container->get('module_handler'),
      $container->get('extension.list.module'),
      $container->get('keyvalue.expirable')->get('filter_perms_list')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Render role/permission overview:
    $hide_descriptions = system_admin_compact_mode();

    $form['system_compact_link'] = [
      '#id' => FALSE,
      '#type' => 'system_compact_link',
    ];

    $permissions = $this->permissionHandler->getPermissions();

    $providers = [];
    foreach ($permissions as $permission) {
      $providers[$permission['provider']] = $permission['provider'];
    }

    $roles = $this->getRoles();

    $defined_roles = [];
    foreach ($roles as $role_name => $role) {
      $defined_roles[$role_name] = $role->label();
    }

    $filter = $this->getFilterSettings();

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Permission Filters'),
      '#open' => TRUE,
    ];
    $form['filters']['container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];
    // Displays all user roles.
    $form['filters']['container']['roles'] = [
      '#title' => $this->t('Roles to display'),
      '#type' => 'select',
      '#options' => [self::ALL_OPTIONS => '--All Roles'] + $defined_roles,
      '#default_value' => $filter['roles'],
      '#size' => 8,
      '#multiple' => TRUE,
    ];
    // Displays all modules which define permissions.
    $form['filters']['container']['modules'] = [
      '#title' => $this->t('Modules to display'),
      '#type' => 'select',
      '#options' => [self::ALL_OPTIONS => '--All Modules'] + $providers,
      '#default_value' => $filter['modules'],
      '#size' => 8,
      '#multiple' => TRUE,
    ];
    $form['filters']['action'] = ['#type' => 'actions'];
    $form['filters']['action']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter Permissions'),
      '#submit' => ['::submitFormFilter'],
    ];

    $role_names = $role_permissions = $admin_roles = [];

    foreach ($roles as $role_name => $role) {
      if (in_array(self::ALL_OPTIONS, $filter['roles']) || in_array($role_name, $filter['roles'])) {
        // Retrieve role names for columns.
        $role_names[$role_name] = $role->label();
        // Fetch permissions for the roles.
        $role_permissions[$role_name] = $role->getPermissions();
        $admin_roles[$role_name] = $role->isAdmin();
      }
    }

    // Store $role_names for use when saving the data.
    $form['role_names'] = [
      '#type' => 'value',
      '#value' => $role_names,
    ];

    $permissions_by_provider = [];
    foreach ($permissions as $permission_name => $permission) {
      if (in_array(self::ALL_OPTIONS, $filter['modules']) || in_array($permission['provider'], $filter['modules'])) {
        $permissions_by_provider[$permission['provider']][$permission_name] = $permission;
      }
    }

    $form['permissions'] = [
      '#type' => 'table',
      '#header' => [$this->t('Permission')],
      '#id' => 'permissions',
      '#attributes' => ['class' => ['permissions', 'js-permissions']],
      '#sticky' => TRUE,
      '#empty' => $this->t('Please select at least one value from both the Roles and Modules select boxes above and then click the "Filter Permissions" button.'),
    ];

    // Only build the rest of the form if there are any filter settings.
    if (empty($role_names) || empty($permissions_by_provider)) {
      return $form;
    }

    foreach ($role_names as $role_id => $role_name) {
      $form['permissions']['#header'][] = [
        'data' => $role_name,
        'class' => ['checkbox'],
      ];
      // Handles problems with $form_state losing track of roles when multiple
      // tabs are loaded and submitted.
      $form['permissions']['displayed_roles'][$role_id] = [
        '#type' => 'hidden',
        '#value' => $role_name,
      ];
    }

    // Count inputs to avoid exceeding max_input_vars.
    $input_count = count($form['filters']['container']['modules']['#options']) + count($form['filters']['container']['roles']['#options']);

    foreach ($permissions_by_provider as $provider => $permissions) {
      // Module name.
      $form['permissions'][$provider] = [
        [
          '#wrapper_attributes' => [
            'colspan' => count($role_names) + 1,
            'class' => ['module'],
            'id' => 'module-' . $provider,
          ],
          '#markup' => $this->moduleExtensionList->getName($provider),
        ],
      ];
      foreach ($permissions as $perm => $perm_item) {
        // Fill in default values for the permission.
        $perm_item += [
          'description' => '',
          'restrict access' => FALSE,
          'warning' => !empty($perm_item['restrict access']) ? $this->t('Warning: Give to trusted roles only; this permission has security implications.') : '',
        ];
        $form['permissions'][$perm]['description'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
          '#context' => [
            'title' => $perm_item['title'],
          ],
        ];
        // Show the permission description.
        if (!$hide_descriptions) {
          $form['permissions'][$perm]['description']['#context']['description'] = $perm_item['description'];
          $form['permissions'][$perm]['description']['#context']['warning'] = $perm_item['warning'];
        }
        foreach ($role_names as $rid => $name) {
          $form['permissions'][$perm][$rid] = [
            '#title' => $name . ': ' . $perm_item['title'],
            '#title_display' => 'invisible',
            '#wrapper_attributes' => [
              'class' => ['checkbox'],
            ],
            '#type' => 'checkbox',
            '#default_value' => in_array($perm, $role_permissions[$rid]) ? 1 : 0,
            '#attributes' => ['class' => ['rid-' . $rid, 'js-rid-' . $rid]],
            '#parents' => [$rid, $perm],
          ];
          // Show a column of disabled but checked checkboxes.
          if ($admin_roles[$rid]) {
            $form['permissions'][$perm][$rid]['#disabled'] = TRUE;
            $form['permissions'][$perm][$rid]['#default_value'] = TRUE;
          }
          else {
            $input_count++;
          }
        }
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save permissions'),
      '#button_type' => 'primary',
      '#save_submit' => TRUE,
    ];

    // Count the form token, id, and build_id, as well as the two submits.
    $input_count += 5;

    if (empty($form_state->getUserInput()) && $input_count > ini_get('max_input_vars')) {
      $form['actions']['submit']['#disabled'] = TRUE;
      $form['actions']['submit']['#value'] = $this->t('Saving permissions disabled');
      $this->messenger()->addError($this->t('There are too many permissions to be saved safely with your current PHP configuration. Please filter the permissions.'));
    }

    $form['#attached']['library'][] = 'user/drupal.user.permissions';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $submit_button = $form_state->getTriggeringElement();
    // No need to run this validation when submitting filter changes.
    if (!empty($submit_button['#save_submit'])) {
      $submitted_roles = $form_state->getValue('role_names');
      $permissions_form = $form_state->getValue('permissions');
      // Check that the $form_state has not been updated since creation of this
      // submitted form.
      if (count(array_diff($permissions_form['displayed_roles'], $submitted_roles))) {
        $form_state->setError($form['filters']['container']['roles'], $this->t('The submitted form contains outdated permissions checkboxes and has not been saved. Please re-filter and try again.'));
      }
    }
  }

  /**
   * Saves the roles and modules selection.
   */
  public function submitFormFilter(array &$form, FormStateInterface $form_state) {
    $this->saveFilterSettings($form_state->getValue('roles'), $form_state->getValue('modules'));
  }

  /**
   * Saves the filter settings for the current user.
   *
   * @param array $roles
   *   The roles to filter by.
   * @param array $modules
   *   The modules to filter by.
   */
  protected function saveFilterSettings(array $roles, array $modules) {
    $values = ['roles' => $roles, 'modules' => $modules];
    $this->keyValueExpirable->setWithExpire($this->currentUser()->id(), $values, 3600);
  }

  /**
   * Retrieve the filter settings for the current user.
   *
   * @return array
   *   The filter setting for the current user.
   */
  protected function getFilterSettings() {
    $default = ['roles' => [], 'modules' => []];
    return $this->keyValueExpirable->get($this->currentUser()->id(), $default);
  }

}
