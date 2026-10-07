<?php

namespace Drupal\fillpdf;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines a custom access controller for the FillPDF generation route.
 */
class FillPdfAccessController implements ContainerInjectionInterface {

  use MessengerTrait;
  use LoggerChannelTrait;
  use StringTranslationTrait;

  /**
   * The FillPDF access helper.
   *
   * @var \Drupal\fillpdf\FillPdfAccessHelperInterface
   */
  protected $accessHelper;

  /**
   * The FillPDF link manipulator.
   *
   * @var \Drupal\fillpdf\FillPdfLinkManipulatorInterface
   */
  protected $linkManipulator;

  /**
   * The FillPDF context manager.
   *
   * @var \Drupal\fillpdf\FillPdfContextManagerInterface
   */
  protected $contextManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a FillPdfAccessManager object.
   *
   * @param \Drupal\fillpdf\FillPdfAccessHelperInterface $access_helper
   *   The FillPDF access helper.
   * @param \Drupal\fillpdf\FillPdfLinkManipulatorInterface $link_manipulator
   *   The FillPDF link manipulator.
   * @param \Drupal\fillpdf\FillPdfContextManagerInterface $context_manager
   *   The FillPDF context manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    FillPdfAccessHelperInterface $access_helper,
    FillPdfLinkManipulatorInterface $link_manipulator,
    FillPdfContextManagerInterface $context_manager,
    RequestStack $request_stack,
    AccountInterface $current_user,
  ) {
    $this->accessHelper = $access_helper;
    $this->linkManipulator = $link_manipulator;
    $this->contextManager = $context_manager;
    $this->requestStack = $request_stack;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('fillpdf.access_helper'),
      $container->get('fillpdf.link_manipulator'),
      $container->get('fillpdf.context_manager'),
      $container->get('request_stack'),
      $container->get('current_user')
    );
  }

  /**
   * Checks whether the current user has access to the current request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result value object.
   */
  public function checkLink() {
    try {
      $context = $this->linkManipulator->parseRequest($this->requestStack->getCurrentRequest());
    }
    catch (\InvalidArgumentException $exception) {
      $message = $exception->getMessage();
      $is_admin = $this->currentUser->hasPermission('administer pdfs');
      $this->messenger()->addError($is_admin ? $message : $this->t('An error occurred. Please notify the administrator.'));
      $this->getLogger('fillpdf')->error('InvalidArgumentException: %message', ['%message' => $message]);
      return AccessResult::forbidden();
    }

    $account = $this->currentUser;

    return $this->accessHelper->canGeneratePdfFromContext($context, $account);
  }

}
