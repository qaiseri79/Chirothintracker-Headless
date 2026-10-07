<?php

namespace Drupal\Tests\time_field\Unit\Feeds\Target;

use Drupal\Tests\feeds\Unit\Feeds\Target\FieldTargetTestBase;
use Drupal\time_field\Feeds\Target\Time;

/**
 * @coversDefaultClass \Drupal\time_field\Feeds\Target\Time
 * @group time_field
 * @requires module feeds
 */
class TimeTest extends FieldTargetTestBase {

  /**
   * The ID of the plugin.
   *
   * @var string
   */
  protected static $pluginId = 'time_feeds_target';

  /**
   * {@inheritdoc}
   */
  protected function getTargetClass() {
    return Time::class;
  }

  /**
   * Tests preparing a time value.
   *
   * @param int $expected
   *   The expected result, timestamp.
   * @param string $value
   *   The input time value.
   *
   * @covers ::prepareValue
   * @dataProvider valueProvider
   */
  public function testPrepareValue(int $expected, string $value) {
    $target = $this->instantiatePlugin();
    $values = ['value' => $value];

    $method = $this->getProtectedClosure($target, 'prepareValue');
    $method(0, $values);
    $this->assertSame($expected, $values['value']);
  }

  /**
   * Data provider for testPrepareValue().
   */
  public static function valueProvider() {
    return [
      // Check short time format with only hours and minutes.
      [
        'expected' => 0,
        'value' => '00:00',
      ],
      [
        'expected' => 60,
        'value' => '00:01',
      ],
      [
        'expected' => 3720,
        'value' => '01:02',
      ],
      [
        'expected' => 86340,
        'value' => '23:59',
      ],
      // Check time format with hours, minutes and seconds.
      [
        'expected' => 0,
        'value' => '00:00:00',
      ],
      [
        'expected' => 3660,
        'value' => '01:01:00',
      ],
      [
        'expected' => 4800,
        'value' => '01:20:00',
      ],
      [
        'expected' => 4810,
        'value' => '01:20:10',
      ],
      [
        'expected' => 86399,
        'value' => '23:59:59',
      ],
    ];
  }

}
