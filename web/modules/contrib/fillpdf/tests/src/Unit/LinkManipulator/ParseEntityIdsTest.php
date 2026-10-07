<?php

namespace Drupal\Tests\fillpdf\Unit\LinkManipulator;

use Drupal\fillpdf\Service\FillPdfLinkManipulator;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\fillpdf\Service\FillPdfLinkManipulator
 *
 * @group fillpdf
 */
class ParseEntityIdsTest extends UnitTestCase {

  /**
   * Tests parsing entity IDs from query parameters and back.
   *
   * @param array $input
   *   Input query parameters.
   * @param array $expected
   *   Expected output query parameters.
   *
   * @covers ::parseEntityIds
   * @covers ::prepareEntityIds
   *
   * @dataProvider providerTestEntityIds
   */
  public function testEntityIds(array $input, array $expected) {
    // Parse query parameters, creating a context.
    $context = FillPdfLinkManipulator::parseEntityIds($input);

    // Turn the context back into query parameters.
    $actual = FillPdfLinkManipulator::prepareEntityIds($context);

    $this->assertEquals($expected, $actual);
  }

  /**
   * Data provider for testEntityIds().
   *
   * @return array[]
   *   Array of test cases.
   */
  public static function providerTestEntityIds() {
    $cases = [];
    $cases[0] = [
      [],
      [],
    ];
    $cases[1] = [
      ['entity_ids' => ['node:1']],
      ['entity_id' => 'node:1'],
    ];
    $cases[2] = [
      ['entity_ids' => ['term:5']],
      ['entity_id' => 'term:5'],
    ];
    $cases[3] = [
      ['entity_ids' => ['node:1', 'node:2']],
      ['entity_ids' => ['node:1', 'node:2']],
    ];
    $cases[4] = [
      ['entity_ids' => ['node:1', 'node:1']],
      ['entity_id' => 'node:1'],
    ];
    $cases[5] = [
      ['entity_ids' => ['user:3', 'term:5']],
      ['entity_ids' => ['user:3', 'term:5']],
    ];
    $cases[6] = [
      ['entity_ids' => [], 'entity_type' => '', 'entity_id' => 1],
      ['entity_id' => 'node:1'],
    ];
    $cases[7] = [
      ['entity_id' => 1],
      ['entity_id' => 'node:1'],
    ];
    $cases[8] = [
      ['entity_type' => 'term', 'entity_id' => 5],
      ['entity_id' => 'term:5'],
    ];
    $cases[9] = [
      ['entity_ids' => ['1'], 'entity_type' => 'node'],
      ['entity_id' => 'node:1'],
    ];
    $cases[10] = [
      ['entity_ids' => ['1', '2'], 'entity_type' => 'node'],
      ['entity_ids' => ['node:1', 'node:2']],
    ];
    $cases[11] = [
      ['entity_ids' => ['3', '4'], 'entity_type' => 'user'],
      ['entity_ids' => ['user:3', 'user:4']],
    ];
    $cases[12] = [
      ['entity_ids' => ['3', '4'], 'entity_type' => 'user', 'entity_id' => '5'],
      ['entity_id' => 'user:5'],
    ];
    return $cases;
  }

}
