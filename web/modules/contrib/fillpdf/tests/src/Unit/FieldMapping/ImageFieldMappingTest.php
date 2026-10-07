<?php

namespace Drupal\Tests\fillpdf\Unit\FieldMapping;

use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\Tests\UnitTestCase;

/**
 * Test the ImageFieldMapping class.
 *
 * @group fillpdf
 * @covers \Drupal\fillpdf\FieldMapping\ImageFieldMapping
 */
class ImageFieldMappingTest extends UnitTestCase {

  /**
   * Test the constructor.
   */
  public function testConstruct() {
    // Test valid and invalid instantiations.
    $image_field_mapping = new ImageFieldMapping('Dummy image', 'jpg');
    self::assertInstanceOf(ImageFieldMapping::class, $image_field_mapping);

    $this->expectException(\InvalidArgumentException::class);
    new ImageFieldMapping('Dummy image', 'bmp');
  }

  /**
   * Test the getExtension() method.
   */
  public function testGetExtension() {
    $image_field_mapping = new ImageFieldMapping('Dummy image', 'jpg');
    self::assertEquals('jpg', $image_field_mapping->getExtension());
  }

}
