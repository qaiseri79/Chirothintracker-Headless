<?php

namespace Drupal\Tests\fillpdf\Unit\FieldMapping;

use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the TextFieldMapping class.
 *
 * @group fillpdf
 * @covers \Drupal\fillpdf\FieldMapping\TextFieldMapping
 */
class TextFieldMappingTest extends UnitTestCase {

  /**
   * Tests the constructor.
   */
  public function testConstruct() {
    // Test valid and invalid instantiations.
    $text_field_mapping = new TextFieldMapping('Dummy text');
    self::assertInstanceOf(TextFieldMapping::class, $text_field_mapping, 'Instantiation works.');
  }

  /**
   * Tests the getData() method.
   */
  public function testGetData() {
    $text_field_mapping = new TextFieldMapping('Dummy text');
    self::assertIsString($text_field_mapping->getData(), 'Data returned as string.');

    // Test conversion to string.
    $null_text_field_mapping = new TextFieldMapping(NULL);
    self::assertIsString($null_text_field_mapping->getData(), 'Conversion to string from null works.');
    self::assertEquals($null_text_field_mapping->getData(), '');

    $int_text_field_mapping = new TextFieldMapping(1234567890);
    self::assertIsString($int_text_field_mapping->getData(), 'Conversion to string from integer works.');
    self::assertEquals($int_text_field_mapping->getData(), '1234567890');
  }

}
