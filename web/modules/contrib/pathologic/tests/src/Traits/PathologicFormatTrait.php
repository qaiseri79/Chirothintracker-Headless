<?php

declare(strict_types=1);

namespace Drupal\Tests\pathologic\Traits;

use Drupal\filter\Entity\FilterFormat;

/**
 * Provides management of a text format configured for Pathologic.
 *
 * This trait is meant to be used only by test classes.
 */
trait PathologicFormatTrait {

  /**
   * The ID of a text format to be used in a test.
   *
   * @var string formatId
   */
  protected string $formatId = '';

  /**
   * Build a text format with Pathologic configured a certain way.
   *
   * @param array $settings
   *   An array of settings for the Pathologic instance on the format.
   *
   * @return string
   *   The randomly generated format machine name for the new format.
   */
  protected function buildFormat(array $settings) {
    $this->formatId = ($settings['local_settings']['protocol_style'] ?? 'unknown') . '_' . $this->randomMachineName(8);
    $format = FilterFormat::create([
      'format' => $this->formatId,
      'name' => $this->formatId,
    ]);
    $format->setFilterConfig('filter_pathologic', [
      'status' => 1,
      'settings' => $settings,
    ]);
    $format->save();
    return $this->formatId;
  }

  /**
   * Runs the given string through the Pathologic text filter.
   *
   * @param string $markup
   *   Raw markup to be processed.
   * @param string $langcode
   *   The optional language to render the text with.
   *
   * @return string
   *   A string of text-format-filtered markup.
   */
  protected function runFilter(string $markup, string $langcode = ''): string {
    return check_markup($markup, $this->formatId, $langcode)->__toString();
  }

}
