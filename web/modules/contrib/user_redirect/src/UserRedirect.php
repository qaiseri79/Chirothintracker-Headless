<?php

namespace Drupal\user_redirect;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Login And Logout Redirect Per Role helper service.
 */
class UserRedirect implements UserRedirectInterface {

  /**
   * The currently active request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * The users_target.settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The current active user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManager
   */
  protected $pathAliasManager;

  /**
   * The path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  protected $pathMatcher;

  /**
   * Constructs a new Login And Logout Redirect Per Role service object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current active user.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path stack.
   * @param \Drupal\path_alias\AliasManager $path_alias_manager
   *   The path alias manager.
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher.
   */
  public function __construct(
    RequestStack $request_stack,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    CurrentPathStack $current_path,
    AliasManagerInterface $path_alias_manager,
    PathMatcherInterface $path_matcher
  ) {
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->config = $config_factory->get('user_redirect.settings');
    $this->currentUser = $current_user;
    $this->currentPath = $current_path;
    $this->pathAliasManager = $path_alias_manager;
    $this->pathMatcher = $path_matcher;
  }

  /**
   * {@inheritdoc}
   */
  public function setLoginRedirection(AccountInterface $account = NULL) {
    if (($ignore = $this->config->get('ignore')) && $this->config->get('ignore_for.login')) {
      foreach ($ignore as $path) {
        $current_path = $this->currentPath->getPath();
        $current_alias = $this->pathAliasManager->getAliasByPath($current_path);
        if ($this->pathMatcher->matchPath($current_alias, $path)) {
          // The current path should be ignored, don't redirect.
          return;
        }
      }
    }
    $this->prepareDestination(UserRedirectInterface::KEY_LOGIN, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function setLogoutRedirection(AccountInterface $account = NULL) {
    if (($ignore = $this->config->get('ignore')) && $this->config->get('ignore_for.logout')) {
      foreach ($ignore as $path) {
        $current_path = $this->currentPath->getPath();
        $current_alias = $this->pathAliasManager->getAliasByPath($current_path);
        if ($this->pathMatcher->matchPath($current_alias, $path)) {
          // The current path should be ignored, don't redirect.
          return;
        }
      }
    }
    $this->prepareDestination(UserRedirectInterface::KEY_LOGOUT, $account);
  }

  /**
   * Set "destination" parameter to do redirect.
   *
   * @param string $key
   *   Configuration key (login or logout).
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   User account to set destination for.
   */
  protected function prepareDestination($key, AccountInterface $account = NULL) {
    $loggedin_user_roles = array_reverse($this->currentUser->getRoles());

    // Loop through user roles and find the first role with a valid redirect.
    foreach ($loggedin_user_roles as $role) {
      $config = $this->getConfig($key, $role);

      if ($config) {
        $redirect_url = $config['redirect_url'];
        if ($redirect_url) {
          if (UrlHelper::isExternal($redirect_url)) {
            $response = new TrustedRedirectResponse($redirect_url);
            $response->send();
          }
          else {
            $url = Url::fromUserInput($redirect_url);
            if ($url instanceof Url) {
              $this->currentRequest->query->set('destination', $url->toString());
              // Stop after the first valid redirect is found.
              return;
            }
          }
        }
      }
    }
  }

  /**
   * Return requested configuration items (login or logout) for a specific role.
   *
   * @param string $key
   *   Configuration key (login or logout).
   * @param string $role
   *   The role for which to fetch the configuration.
   *
   * @return array
   *   Requested configuration items (login or logout) for the given role.
   */
  protected function getConfig($key, $role) {
    $config = $this->config->get($key);
    if ($config && isset($config[$role])) {
      return $config[$role];
    }
    return [];
  }

}
