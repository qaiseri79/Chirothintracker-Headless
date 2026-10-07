<?php

namespace Drupal\Tests\fillpdf\Unit\LinkManipulator;

use Drupal\fillpdf\Service\FillPdfLinkManipulator;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\fillpdf\Service\FillPdfLinkManipulator
 *
 * @group fillpdf
 */
class ParseBooleanFlagsTest extends UnitTestCase {

  /**
   * Tests &sample=, &download= and &flatten= query parameters.
   *
   * @covers ::parseBooleanFlags
   *
   * @dataProvider providerTestBooleanFlags
   */
  public function testBooleanFlags($input, $expected) {
    $context = FillPdfLinkManipulator::parseBooleanFlags($this->buildQuery($input));

    $this->assertEquals(is_null($expected) ? FALSE : $expected, $context['sample']);

    $this->assertEquals(is_null($expected) ? FALSE : $expected, $context['force_download']);

    $this->assertEquals(is_null($expected) ? TRUE : $expected, $context['flatten']);
  }

  /**
   * Input helper for testBooleanFlags().
   */
  public function buildQuery($input) {
    return [
      'fid' => 1,
      'entity_type' => 'node',
      'entity_id' => 1,
      'sample' => $input,
      'download' => $input,
      'flatten' => $input,
    ];
  }

  /**
   * Data provider for testBooleanFlags().
   */
  public static function providerTestBooleanFlags() {
    return [
      ['1', TRUE],
      ['true', TRUE],
      ['True', TRUE],
      ['TRUE', TRUE],
      ['on', TRUE],
      ['On', TRUE],
      ['ON', TRUE],
      ['yes', TRUE],
      ['Yes', TRUE],
      ['YES', TRUE],

      ['0', FALSE],
      ['false', FALSE],
      ['False', FALSE],
      ['FALSE', FALSE],
      ['off', FALSE],
      ['Off', FALSE],
      ['OFF', FALSE],
      ['no', FALSE],
      ['No', FALSE],
      ['NO', FALSE],

      // These three are important, so should always be obeyed:
      ['', NULL],
      ['foo', NULL],
      ['bar', NULL],

      // The following ones are less fortunate, so may be refactored:
      ['-1', NULL],
      ['2', NULL],
      ['y', NULL],
      ['Y', NULL],
      ['n', NULL],
      ['N', NULL],
    ];
  }

}
