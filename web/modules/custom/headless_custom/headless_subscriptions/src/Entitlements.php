<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

/** Pure policy; provider status alone never proves that a period was paid. */
final class Entitlements {
  public static function evaluate(?array $purchase, bool $everPaid, int $now, int $graceDays = 0): array {
    $until = (int) ($purchase['paid_until'] ?? 0);
    $state = $purchase['state'] ?? 'none';
    $active = $until > $now && !in_array($state, ['revoked', 'declined', 'charging', 'payment_review'], TRUE);
    $grace = !$active && $state === 'past_due' && $until > 0 && $until + max(0, $graceDays) * 86400 > $now;
    $write = $active || $grace;
    $plan = $purchase['plan'] ?? [];
    return [
      'portalRead' => $write || $everPaid,
      'portalWrite' => $write,
      'store' => $write && !empty($plan['ecommerce']),
      'laser' => $write && !empty($plan['laser']),
      'patientLimit' => $write ? ($plan['patientLimit'] ?? NULL) : 0,
      'paidThrough' => $until ?: NULL,
      'inGracePeriod' => $grace,
      'billingOnly' => !$write && !$everPaid,
    ];
  }
}
