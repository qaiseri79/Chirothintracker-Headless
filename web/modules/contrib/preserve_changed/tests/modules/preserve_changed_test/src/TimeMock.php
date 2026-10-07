<?php

namespace Drupal\preserve_changed_test;

use Drupal\Component\Datetime\Time;

/**
 * Mocks the original `datetime.time` service class.
 *
 * The original \Drupal::time()->getRequestTime() returned value is immutable
 * during the tests making it hard to be tested. Mocking the ::getRequestTime()
 * in order to get time deltas as in real life.
 */
class TimeMock extends Time {

  /**
   * Keep track about the last returned time.
   *
   * @var int
   */
  protected int $lastTimestamp;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    if (!isset($this->lastTimestamp)) {
      $this->lastTimestamp = parent::getRequestTime();
    }
    // Increment before returning the value, in order to guarantee different
    // timestamps each time \Drupal::time()->getRequestTime() is called as, in
    // tests, the request time is immutable, but we want to simulate different
    // changed times.
    return ++$this->lastTimestamp;
  }

}
