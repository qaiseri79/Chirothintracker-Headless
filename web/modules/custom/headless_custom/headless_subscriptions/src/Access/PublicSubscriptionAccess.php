<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;

/** Plans, guest carts, signup, and signature-protected webhooks are public. */
final class PublicSubscriptionAccess {
  public static function access(): AccessResultInterface {
    return AccessResult::allowed()->setCacheMaxAge(0);
  }
}
