<?php

namespace Drupal\fillpdf;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * {@inheritdoc}
 *
 * @package Drupal\fillpdf
 */
class FillPdfAccessHelper implements FillPdfAccessHelperInterface {

  /**
   * Constructs a FillPdfAccessManager object.
   *
   * @param \Drupal\fillpdf\FillPdfLinkManipulatorInterface $linkManipulator
   *   The FillPDF link manipulator.
   * @param \Drupal\fillpdf\FillPdfContextManagerInterface $contextManager
   *   The FillPDF context manager.
   */
  public function __construct(
    protected FillPdfLinkManipulatorInterface $linkManipulator,
    protected FillPdfContextManagerInterface $contextManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function canGeneratePdfFromUrlString($url, AccountInterface $account) {
    $context = $this->linkManipulator->parseUrlString($url);
    return $this->canGeneratePdfFromContext($context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function canGeneratePdfFromLink(Url $link, AccountInterface $account) {
    $context = $this->linkManipulator->parseLink($link);
    return $this->canGeneratePdfFromContext($context, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function canGeneratePdfFromContext(array $context, AccountInterface $account) {
    $is_admin = $account->hasPermission('administer pdfs');
    $can_publish_all = $account->hasPermission('publish all pdfs');
    $cachedAllowed = AccessResult::allowed()
      ->cachePerUser()
      ->cachePerPermissions();
    if ($can_publish_all || $is_admin) {
      return $cachedAllowed;
    }

    $is_sample = $context['sample'];
    if ($is_sample && $is_admin) {
      return $cachedAllowed;
    }

    $cachedForbidden = AccessResult::forbidden()
      ->cachePerUser()
      ->cachePerPermissions();
    $can_publish = $account->hasPermission('publish own pdfs');
    if (!$is_sample && $can_publish) {
      $entities = $this->contextManager->loadEntities($context);

      /** @var \Drupal\Core\Entity\EntityInterface[] $type_entities */
      foreach ($entities as $type_entities) {
        foreach ($type_entities as $entity) {
          // If there are any entities in the context that the user can't view,
          // deny access.
          if (!$entity->access('view', $account)) {
            return $cachedForbidden;
          }
        }
      }

      return $cachedAllowed;
    }

    return $cachedForbidden;
  }

}
