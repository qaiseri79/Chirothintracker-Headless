<?php

namespace Drupal\Tests\hide_preview\Unit;

use Drupal\hide_preview\Form\HidePreviewConfigForm;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Hide Preview module's functionality.
 *
 * @group hide_preview
 */
class HidePreviewTest extends UnitTestCase {

  /**
   * The mocked HidePreviewConfigForm.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\hide_preview\Form\HidePreviewConfigForm
   */
  private $configForm;

  /**
   * Sets up the test case with a mock of the HidePreviewConfigForm class.
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a mock object for HidePreviewConfigForm without the constructor.
    $this->configForm = $this->getMockBuilder(HidePreviewConfigForm::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['config', 't'])
      ->getMock();

    // Mock the 'config' method to avoid dependency issues.
    $this->configForm->method('config')
      ->willReturn($this->createMock('Drupal\Core\Config\Config'));

    // Mock the 't' method if translation is involved.
    $this->configForm->method('t')
      ->willReturnArgument(0);
  }

  /**
   * Ensure the multiline2Array method converts a multiline string to an array.
   */
  public function testMultiline2Array(): void {
    $multiline = "value1\r\nvalue2\r\nvalue3";
    $array = $this->configForm->multiline2Array($multiline);

    $this->assertCount(3, $array);
  }

  /**
   * Tests that the multiline2Array method handles single-line input correctly.
   */
  public function testEmptyMultiline2Array(): void {
    $multiline = "0";
    $array = $this->configForm->multiline2Array($multiline);

    $this->assertCount(1, $array);
  }

}
