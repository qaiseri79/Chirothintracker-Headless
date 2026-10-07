<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

final class SubscriptionException extends \RuntimeException {
  public function __construct(public readonly string $error, string $message, public readonly int $status = 400, public readonly array $fields = []) {
    parent::__construct($message);
  }
}
