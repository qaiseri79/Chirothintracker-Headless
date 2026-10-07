<?php

namespace Drupal\fillpdf\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a FillPDF PdfBackend plugin annotation object.
 *
 * @see \Drupal\fillpdf\Plugin\BackendServiceManager
 * @see plugin_api
 *
 * @Annotation
 */
class PdfBackend extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The plugin's label.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The plugin's description.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description = '';

  /**
   * The plugin's weight.
   *
   * @var int
   */
  public $weight = 0;

}
