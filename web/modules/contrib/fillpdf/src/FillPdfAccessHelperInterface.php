<?php

namespace Drupal\fillpdf;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Checks whether the configured permissions allow merging a PDF.
 *
 * @package Drupal\fillpdf
 */
interface FillPdfAccessHelperInterface {

  /**
   * Provides a way to pass in a FillPDF Link string to check access.
   *
   * Should ultimately pass control to self::canGeneratePdfFromContext().
   *
   * @param string $url
   *   The root-relative FillPDF URL that would be used to generate the PDF.
   *   e.g. /fillpdf?fid=1&entity_type=node&entity_id=1.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access results.
   *
   * @see \Drupal\fillpdf\FillPdfAccessHelperInterface::canGeneratePdfFromContext()
   */
  public function canGeneratePdfFromUrlString($url, AccountInterface $account);

  /**
   * Provides a way to check access from a link argument.
   *
   * This function should build a FillPdfLinkManipulator-compatible $context and
   * then pass control to self::canGeneratePdfFromLink().
   *
   * @param \Drupal\Core\Url $link
   *   The FillPDF Link containing the entities whose access to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user whose access is being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access results.
   *
   * @see \Drupal\fillpdf\FillPdfAccessHelperInterface::canGeneratePdfFromContext()
   */
  public function canGeneratePdfFromLink(Url $link, AccountInterface $account);

  /**
   * This is the main access checking function of this class.
   *
   * Method self::canGeneratePdfFromLinkUrl() should delegate to this one.
   *
   * @param array $context
   *   As returned by FillPdfLinkManipulator's parse functions.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user whose access is being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access results.
   *
   * @see \Drupal\fillpdf\FillPdfAccessHelperInterface::canGeneratePdfFromLink()
   */
  public function canGeneratePdfFromContext(array $context, AccountInterface $account);

}
