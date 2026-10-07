<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

/** Calendar dates at the provider billing boundary (02:00 America/Los_Angeles). */
final class BillingCalendar {
  public static function anniversary(int $anchor, int $months, int $period = 1): int {
    if (!in_array($months, [1, 12], TRUE) || $period < 1) {
      throw new \InvalidArgumentException('Unsupported billing interval.');
    }
    $date = (new \DateTimeImmutable('@' . $anchor))->setTimezone(new \DateTimeZone('America/Los_Angeles'));
    $first = $date->modify('first day of this month')->setTime(2, 0)->modify('+' . ($months * $period) . ' months');
    return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $date->format('d'), (int) $first->format('t')))->getTimestamp();
  }
  public static function date(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
  }
}
