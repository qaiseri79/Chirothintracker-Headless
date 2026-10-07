<?php

namespace Drupal\fillpdf\Component\Helper;

/**
 * {@inheritdoc}
 *
 * @package Drupal\fillpdf\Component\Helper
 */
class FillPdfMappingHelper implements FillPdfMappingHelperInterface {

  /**
   * Parses replacements.
   *
   * @param string $replacements_string
   *   The replacements string.
   *
   * @return string[]
   *   Associative array of replacement values, keyed by the value to be
   *   replaced.
   */
  public static function parseReplacements($replacements_string) {
    if (!empty($replacements_string)) {
      $standardized_replacements = str_replace([
        "\r\n",
        "\r",
      ], "\n", $replacements_string);
      $lines = explode("\n", $standardized_replacements);
      $return = [];
      foreach ($lines as $replacement) {
        if (!empty($replacement)) {
          $split = explode('|', $replacement);
          // Sometimes it isn't; don't know why.
          if (count($split) == 2) {
            $return[$split[0]] = preg_replace('|<br />|', "\n", $split[1]);
          }
        }
      }
      return $return;
    }
    else {
      return [];
    }
  }

}
