<?php

namespace Drupal\user_redirect\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Setting up the user redirect path.
 *
 * @package Drupal\user_redirect\Form
 */
class UserRedirectSettingsForm extends ConfigFormBase {

  /**
   * Set WalkMe config settings.
   *
   * @var string
   */
  const CONFIG_SETTINGS = 'user_redirect.settings';

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->pathValidator = $container->get('path.validator');
    $instance->entityTypeManager = $container->get('entity_type.manager');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $users_roles = $this->getUserRoles();
    $config = $this->config(static::CONFIG_SETTINGS);
    $form['login_table']['#tree'] = TRUE;

    // Login form.
    $form['login_table'] = [
      '#type' => 'details',
      '#title' => $this->t('Login'),
      '#open' => TRUE,
    ];
    $form['login_table']['login'] = [
      '#type' => 'table',
      '#caption' => $this->t("OPTIONAL: Enter path which redirect the user after login. Path should either be valid internal starting with / or external starting with http or https.<br> If you don't need any redirection after login on particular role, leave it empty"),
      '#header' => [
        $this->t('Role'),
        $this->t('Redirect URL'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No items.'),
      '#tableselect' => FALSE,
      '#tabledrag' => [
          [
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'draggable-weight',
          ],
      ],
    ];

    // Logout form.
    $form['logout_table'] = [
      '#type' => 'details',
      '#title' => $this->t('Logout'),
      '#open' => TRUE,
    ];
    $form['logout_table']['logout'] = [
      '#type' => 'table',
      '#caption' => $this->t("OPTIONAL: Enter path which redirect the user after logout. Path should either be valid internal starting with / or external starting with http or https.<br> If you don't need any redirection after logout on particular role, leave it empty"),
      '#header' => [
        $this->t('Role'),
        $this->t('Redirect URL'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No items.'),
      '#tableselect' => FALSE,
      '#tabledrag' => [
          [
            'action' => 'order',
            'relationship' => 'sibling',
            'group' => 'draggable-weight',
          ],
      ],
    ];

    // Login & logout draggable form.
    foreach ($users_roles as $role_id => $role_name) {
      $data = $config->get('login.' . $role_id);
      $form['login_table']['login'][$role_id]['#attributes']['class'][] = 'draggable';
      $form['login_table']['login'][$role_id]['#weight'] = $data['weight'] ?? NULL;

      $form['login_table']['login'][$role_id]['role'] = [
        '#markup' => $role_name,
      ];

      $form['login_table']['login'][$role_id]['redirect_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Redirect URL'),
        '#title_display' => 'invisible',
        '#default_value' => $data['redirect_url'] ?? '',
      ];
      $form['login_table']['login'][$role_id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @role', ['@role' => $role_name]),
        '#title_display' => 'invisible',
        '#default_value' => $data['weight'] ?? NULL,
        '#attributes' => ['class' => ['draggable-weight']],
      ];

      $data = $config->get('logout.' . $role_id);
      $form['logout_table']['logout'][$role_id]['#attributes']['class'][] = 'draggable';
      $form['logout_table']['logout'][$role_id]['#weight'] = $data['weight'] ?? NULL;

      $form['logout_table']['logout'][$role_id]['role'] = [
        '#markup' => $role_name,
      ];

      $form['logout_table']['logout'][$role_id]['redirect_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Redirect URL'),
        '#title_display' => 'invisible',
        '#default_value' => $data['redirect_url'] ?? '',
      ];
      $form['logout_table']['logout'][$role_id]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @role', ['@role' => $role_name]),
        '#title_display' => 'invisible',
        '#default_value' => $data['weight'] ?? NULL,
        '#attributes' => ['class' => ['draggable-weight']],
      ];

    }
    Element::children($form['login_table']['login'], TRUE);
    Element::children($form['logout_table']['logout'], TRUE);
    $form['ignore'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ignore paths'),
      '#rows' => 5,
      '#default_value' => $config->get('ignore') ? implode("\r\n", $config->get('ignore')) : '/user/reset/*',
    ];

    $form['ignore_for'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Ignore the above paths for'),
      '#options' => [
        'login' => $this->t('Login'),
        'logout' => $this->t('Logout'),
      ],
      '#default_value' => $config->get('ignore_for') ?? ['login'],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $login = $form_state->getValue('login');
    $logout = $form_state->getValue('logout');
    $users_roles = $this->getUserRoles();

    foreach ($users_roles as $role_id => $role_name) {
      $login_url = $login[$role_id]['redirect_url'];
      $logout_url = $logout[$role_id]['redirect_url'];
      if (!empty($login_url) && !$this->pathValidator->isValid($login_url)) {
        if (!UrlHelper::isValid($login_url, TRUE) && !UrlHelper::isExternal($login_url)) {
          $form_state->setErrorByName('login][' . $role_id . '][redirect_url', $this->t('<strong>Login Redirect URL:</strong> Redirect URL is invalid.'));
        }
      }

      if (!empty($logout_url) && !$this->pathValidator->isValid($logout_url)) {
        if (!UrlHelper::isValid($logout_url, TRUE) && !UrlHelper::isExternal($logout_url)) {
          $form_state->setErrorByName('logout][' . $role_id . '][redirect_url', $this->t('<strong>Logout Redirect URL:</strong> Redirect URL is invalid.'));
        }
      }

    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->config(static::CONFIG_SETTINGS)
      ->set('login', $form_state->getValue('login'))
      ->set('logout', $form_state->getValue('logout'))
      ->set('ignore', array_filter(explode("\r\n", $form_state->getValue('ignore'))))
      ->set('ignore_for', $form_state->getValue('ignore_for'))
      ->save();
  }

  /**
   * Return users role names except role Anonymous.
   *
   * @return array
   *   users role names.
   */
  protected function getUserRoles() {
    $roles_names = [];

    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();

    if (isset($roles[RoleInterface::ANONYMOUS_ID])) {
      unset($roles[RoleInterface::ANONYMOUS_ID]);
    }

    foreach ($roles as $role) {
      if ($role instanceof RoleInterface) {
        $roles_names[$role->id()] = $role->label();
      }
    }

    return $roles_names;
  }

}
