<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_intake\Unit;

/**
 * Mutable holder for a clinic's token.
 *
 * The service is only interested in one value, but the tests need to observe it
 * changing across a call and to search across clinics. Holding the token in an
 * object rather than a local variable means the mock callbacks and the
 * assertions read the *current* value instead of a snapshot taken when the
 * callback was defined, which is the mistake that makes a mock-based field fake
 * silently report a stale token after the code under test writes to it.
 */
final class TokenHolder {

  public function __construct(private ?string $value = NULL) {}

  public function get(): ?string {
    return ($this->value === '') ? NULL : $this->value;
  }

  public function set(?string $value): void {
    $this->value = $value;
  }

}