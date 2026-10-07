<?php

namespace Drupal\fillpdf;

/**
 * Common methods used on admin screens.
 *
 * @package Drupal\fillpdf
 */
interface FillPdfAdminFormHelperInterface {

  /**
   * Returns render array for a link to a token tree shown as a dialog.
   *
   * @param string[]|string $token_types
   *   (optional) Array of token types. Defaults to 'all'. Note that it's the
   *   caller's duty to translate entity types into token types.
   *
   * @return array
   *   Render array.
   */
  public function getAdminTokenForm($token_types = 'all');

  /**
   * Returns available file storage options for use with FAPI radio buttons.
   *
   * Any visible, writeable wrapper can potentially be used.
   *
   * @param array $label_templates
   *   (optional) Associative array of label templates keyed by scheme name.
   *
   * @return array
   *   Stream wrapper descriptions, keyed by scheme.
   */
  public function schemeOptions(array $label_templates = []);

  /**
   * Returns all FillPdfForms with template PDFs stored in a particular scheme.
   *
   * @return string
   *   Scheme of the templates PDFs.
   */
  public function getFormsByTemplateScheme($scheme);

  /**
   * Returns the help text for FillPDF replacements.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The help text.
   */
  public static function getReplacementsDescription();

}
