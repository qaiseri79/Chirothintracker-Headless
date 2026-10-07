<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/** The billing audience is explicitly registered accounts, including unpaid doctors. */
final class BillingAccountAccess {
  public static function access(Route $route, AccountInterface $account): AccessResultInterface {
    $allowed = $account->isAuthenticated() && \Drupal::service('headless_subscriptions.subscription')->managed((int) $account->id());
    return AccessResult::allowedIf($allowed)->addCacheContexts(['user'])->setCacheMaxAge(0);
  }
}
