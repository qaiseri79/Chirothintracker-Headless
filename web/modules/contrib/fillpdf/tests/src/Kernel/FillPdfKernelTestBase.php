<?php

namespace Drupal\Tests\fillpdf\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Base class that can be inherited by FillPDF tests.
 */
abstract class FillPdfKernelTestBase extends EntityKernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'file',
    'link',
    'token',
    'options',
    'views',
    'serialization',
    'options',
    'fillpdf',
    'fillpdf_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('fillpdf_form');
    $this->installEntitySchema('fillpdf_form_field');
    $this->installConfig(['fillpdf']);
  }

}
