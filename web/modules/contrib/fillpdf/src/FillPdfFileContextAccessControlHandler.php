<?php

namespace Drupal\fillpdf;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access controller for the FillPDF file context entity.
 *
 * @see \Drupal\fillpdf\Entity\FillPdfFileContext.
 */
class FillPdfFileContextAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The access helper.
   *
   * @var \Drupal\fillpdf\FillPdfAccessHelperInterface
   */
  protected $accessHelper;

  /**
   * The link manipulator.
   *
   * @var FillPdfLinkManipulatorInterface
   */
  protected $linkManipulator;

  /**
   * The FillPDF context manager.
   *
   * @var FillPdfContextManagerInterface
   */
  protected $contextManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeInterface $entity_type, FillPdfAccessHelperInterface $access_helper, FillPdfLinkManipulatorInterface $link_manipulator, FillPdfContextManagerInterface $context_manager) {
    parent::__construct($entity_type);

    $this->accessHelper = $access_helper;
    $this->linkManipulator = $link_manipulator;
    $this->contextManager = $context_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static($entity_type, $container->get('fillpdf.access_helper'), $container->get('fillpdf.link_manipulator'), $container->get('fillpdf.context_manager'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        $url_string = $entity->context->value;
        $decoded_url = rawurldecode($url_string);
        return $this->accessHelper->canGeneratePdfFromUrlString($decoded_url, $account);

      case 'update':
        return AccessResult::forbidden();

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'administer pdfs');
    }

    return AccessResult::allowed();
  }

}
