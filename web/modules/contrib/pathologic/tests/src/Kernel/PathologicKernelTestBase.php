<?php

declare(strict_types=1);

namespace Drupal\Tests\pathologic\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\pathologic\Traits\PathologicFormatTrait;

/**
 * Base class for all Pathologic Kernel tests.
 */
abstract class PathologicKernelTestBase extends KernelTestBase {

  use PathologicFormatTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'filter',
    'pathologic',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'filter', 'pathologic']);
  }

  /**
   * Wrapper around url() which does HTML entity decoding and encoding.
   *
   * Since Pathologic works with paths in content, it needs to decode paths that
   * have been HTML-encoded, and re-encode them when done. This is a wrapper
   * around url() which does the same thing so that we can expect the results
   * from it and from Pathologic to still match in our tests.
   *
   * @see url()
   * @see http://drupal.org/node/1672932
   * @see http://www.w3.org/TR/xhtml1/guidelines.html#C_12
   */
  protected function pathologicContentUrl($path, $options) {
    // If we pretend this is a path to a file, make url() behave like clean
    // URLs are enabled.
    // @see _pathologic_replace()
    // @see http://drupal.org/node/1672430
    if (!empty($options['is_file'])) {
      $options['script_path'] = '';
    }
    if (parse_url($path, PHP_URL_SCHEME) === NULL) {
      if ($path == '<front>') {
        return Html::escape(Url::fromRoute('<front>', [], $options)->toString());
      }
      $path = 'base://' . $path;
    }
    return Html::escape(Url::fromUri(htmlspecialchars_decode($path, ENT_COMPAT), $options)->toString());
  }

}
