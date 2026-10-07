<?php

namespace Drupal\prlp\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\prlp\Event\PrlpPasswordBeforeSaveEvent;
use Drupal\prlp\Event\PrlpPasswordValidateEvent;
use Drupal\prlp\PrlpEvents;
use Drupal\user\Controller\UserController;
use Drupal\user\Form\UserPasswordResetForm;
use Drupal\user\UserDataInterface;
use Drupal\user\UserStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller routines for prlp routes.
 */
class PrlpController extends UserController {

  /**
   * Event dispatcher service.
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * Constructs a PrlpController object.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   * @param \Drupal\user\UserDataInterface $user_data
   *   The user data service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   */
  public function __construct(
    DateFormatterInterface $date_formatter,
    UserStorageInterface $user_storage,
    UserDataInterface $user_data,
    LoggerInterface $logger,
    FloodInterface $flood,
    EventDispatcherInterface $event_dispatcher
  ) {
    parent::__construct($date_formatter, $user_storage, $user_data, $logger, $flood);
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('entity_type.manager')->getStorage('user'),
      $container->get('user.data'),
      $container->get('logger.factory')->get('user'),
      $container->get('flood'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * Override resetPassLogin() to redirect to the configured path.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param int $uid
   *   User ID of the user requesting reset.
   * @param int $timestamp
   *   The current timestamp.
   * @param string $hash
   *   Login link hash.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Returns parent result object.
   */
  public function prlpResetPassLogin(Request $request, $uid, $timestamp, $hash) {
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->userStorage->load($uid);

    // Verify that the user exists and is active.
    if ($user === NULL || !$user->isActive()) {
      // Blocked or invalid user ID, so deny access. The parameters will be in
      // the watchdog's URL for the administrator to check.
      throw new AccessDeniedHttpException();
    }

    // Build form to call for validation and submit handlers.
    $timeout = $this->config('user.settings')->get('password_reset_timeout');
    $expiration_date = $user->getLastLoginTime() ? $this->dateFormatter->format($timestamp + $timeout) : NULL;

    $form_state = new FormState();
    $form_state->addBuildInfo('args', array_values([
      $user,
      $expiration_date,
      $timestamp,
      $hash,
    ]));

    // Dispatch password validate event to listeners.
    $event = new PrlpPasswordValidateEvent($form_state, $user);
    $this->eventDispatcher->dispatch(
      $event, PrlpEvents::PASSWORD_VALIDATE);

    // The form tries to redirect and returns an error due to this. Catch
    // the error in order to use the form state.
    try {
      $this->formBuilder()
        ->buildForm(UserPasswordResetForm::class, $form_state);
    }
    catch (\Exception $exception) {
    }

    if ($form_state->getErrors()) {
      // We have errors. Go back to the form.
      $session = $request->getSession();
      $session->set('pass_reset_hash', $hash);
      $session->set('pass_reset_timeout', $timestamp);
      return $this->redirect(
        'user.reset.form',
        ['uid' => $uid]
      );
    }

    // Carry on with the response checking if there are no form errors.
    $response = parent::resetPassLogin($uid, $timestamp, $hash, $request);

    try {
      // Deconstruct the redirect url from the response.
      $parsed_url = parse_url($response->getTargetUrl());
      $response_route = Url::fromUserInput($this->stripSubdirectories($parsed_url['path']));

      // Check that the response route matches the "success" route from core and
      // if it does apply the password change and update the redirect
      // destination.
      if ($response_route && $response_route->getRouteName() == 'entity.user.edit_form') {
        if ($request->request->has('pass') && $passwords = $request->request->all('pass')) {
          // $passwords should be an array, if that's not the case nothing
          // should be done to the user.
          $pass = is_array($passwords) ? reset($passwords) : NULL;
          if (!empty($pass)) {
            $user->setPassword($pass);

            $event = new PrlpPasswordBeforeSaveEvent($user);
            $this->eventDispatcher->dispatch(
              $event, PrlpEvents::PASSWORD_BEFORE_SAVE);

            $user->save();
            $this->messenger()->deleteByType(MessengerInterface::TYPE_STATUS);
            $this->messenger()->addStatus($this->t('Your new password has been saved.'));
          }
        }

        $login_destination = $this->config('prlp.settings')->get('login_destination');
        if (!$login_destination) {
          $login_destination = '/user/%user/edit';
        }
        $login_destination = str_replace('%user', $uid, $login_destination);
        $login_destination = str_replace('%front', $this->config('system.site')->get('page.front'), $login_destination);
        if (substr($login_destination, 0, 1) !== '/') {
          $login_destination = '/' . $login_destination;
        }
        $internal_redirect_url = Url::fromUri('internal:' . $login_destination);

        return new RedirectResponse($internal_redirect_url->toString());
      }
    }
    catch (\InvalidArgumentException $exception) {
      // This exception is an edge case scenario thrown by Url::fromUserInput()
      // Should fromUserInput() throw this treat it as a failed authentication
      // and log the user out then clear success messages and add a failure
      // message.
      user_logout();
      $this->messenger()->deleteAll();
      $this->messenger()->addError($this->t('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.'));
    }

    return $this->redirect('user.pass');
  }

  /**
   * Strips subdirectories from a URI.
   *
   * URIs created by \Drupal\Core\Url::toString() always contain the
   * subdirectories. When further processing needs to be done on a URI, the
   * subdirectories need to be stripped before feeding the URI to
   * \Drupal\Core\Url::fromUserInput().
   *
   * @param string $uri
   *   A plain-text URI that might contain a subdirectory.
   *
   * @return string
   *   A plain-text URI stripped of the subdirectories.
   *
   * @see \Drupal\Core\Url::fromUserInput()
   */
  private function stripSubdirectories($uri) {
    $current_request = \Drupal::requestStack()->getCurrentRequest();
    if ($current_request && !empty($current_request->getBasePath()) && strpos($uri, $current_request->getBasePath()) === 0) {
      return substr($uri, mb_strlen($current_request->getBasePath()));
    }
    return $uri;
  }

}
