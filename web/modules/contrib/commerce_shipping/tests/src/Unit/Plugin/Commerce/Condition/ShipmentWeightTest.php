<?php

namespace Drupal\Tests\commerce_shipping\Unit\Plugin\Commerce\Condition;

use Drupal\Tests\UnitTestCase;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Plugin\Commerce\Condition\ShipmentWeight;
use Drupal\physical\Weight;

/**
 * @coversDefaultClass \Drupal\commerce_shipping\Plugin\Commerce\Condition\ShipmentWeight
 * @group commerce
 */
class ShipmentWeightTest extends UnitTestCase {

  /**
   * ::covers evaluate.
   */
  public function testMismatchedUnits() {
    $condition = new ShipmentWeight([
      'operator' => '==',
      'weight' => [
        'number' => '1000.00',
        'unit' => 'g',
      ],
    ], 'shipment_weight', ['entity_type' => 'commerce_shipment']);
    $shipment = $this->prophesize(ShipmentInterface::class);
    $shipment->getEntityTypeId()->willReturn('commerce_shipment');
    $shipment->getWeight()->willReturn(new Weight('1.00', 'kg'));
    $shipment = $shipment->reveal();

    $this->assertTrue($condition->evaluate($shipment));
  }

  /**
   * ::covers evaluate.
   *
   * @dataProvider totalWeightProvider
   */
  public function testEvaluate($operator, $weight, $given_weight, $result) {
    $condition = new ShipmentWeight([
      'operator' => $operator,
      'weight' => [
        'number' => $weight,
        'unit' => 'kg',
      ],
    ], 'shipment_weight', ['entity_type' => 'commerce_shipment']);
    $shipment = $this->prophesize(ShipmentInterface::class);
    $shipment->getEntityTypeId()->willReturn('commerce_shipment');
    $shipment->getWeight()->willReturn(new Weight($given_weight, 'kg'));
    $shipment = $shipment->reveal();

    $this->assertEquals($result, $condition->evaluate($shipment));
  }

  /**
   * ::covers evaluate for between condition.
   *
   * @dataProvider betweenWeightProvider
   */
  public function testEvaluateBetween($operator, $weight, $max_weight, $given_weight, $result) {
    $condition = new ShipmentWeight([
      'operator' => $operator,
      'weight' => [
        'number' => $weight,
        'unit' => 'kg',
      ],
      'max_weight' => [
        'number' => $max_weight,
        'unit' => 'kg',
      ],
    ], 'shipment_weight', ['entity_type' => 'commerce_shipment']);
    $shipment = $this->prophesize(ShipmentInterface::class);
    $shipment->getEntityTypeId()->willReturn('commerce_shipment');
    $shipment->getWeight()->willReturn(new Weight($given_weight, 'kg'));
    $shipment = $shipment->reveal();

    $this->assertEquals($result, $condition->evaluate($shipment));
  }

  /**
   * Data provider for ::testEvaluate.
   *
   * @return array
   *   A list of testEvaluate function arguments.
   */
  public static function totalWeightProvider() {
    return [
      ['>', 10, 5, FALSE],
      ['>', 10, 10, FALSE],
      ['>', 10, 11, TRUE],

      ['>=', 10, 5, FALSE],
      ['>=', 10, 10, TRUE],
      ['>=', 10, 11, TRUE],

      ['<', 10, 5, TRUE],
      ['<', 10, 10, FALSE],
      ['<', 10, 11, FALSE],

      ['<=', 10, 5, TRUE],
      ['<=', 10, 10, TRUE],
      ['<=', 10, 11, FALSE],

      ['==', 10, 5, FALSE],
      ['==', 10, 10, TRUE],
      ['==', 10, 11, FALSE],
    ];
  }

  /**
   * Data provider for ::testEvaluateBetween.
   *
   * @return array
   *   A list of testEvaluateBetween function arguments.
   */
  public static function betweenWeightProvider(): array {
    return [
      ['> <', 5, 10, 7, TRUE],
      ['> <', 5, 10, 12, FALSE],
      ['> <', 5, 10, 10, FALSE],
      ['> <', 5, 10, 3, FALSE],
      ['> <', 5, 10, 5, FALSE],

      ['>= <=', 5, 10, 7, TRUE],
      ['>= <=', 5, 10, 12, FALSE],
      ['>= <=', 5, 10, 10, TRUE],
      ['>= <=', 5, 10, 3, FALSE],
      ['>= <=', 5, 10, 5, TRUE],

      ['>= <', 5, 10, 7, TRUE],
      ['>= <', 5, 10, 12, FALSE],
      ['>= <', 5, 10, 10, FALSE],
      ['>= <', 5, 10, 3, FALSE],
      ['>= <', 5, 10, 5, TRUE],

      ['> <=', 5, 10, 7, TRUE],
      ['> <=', 5, 10, 12, FALSE],
      ['> <=', 5, 10, 10, TRUE],
      ['> <=', 5, 10, 3, FALSE],
      ['> <=', 5, 10, 5, FALSE],
    ];
  }

}
