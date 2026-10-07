<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_intake\Unit;

/**
 * Stands in for a field item list.
 *
 * `value` is a public property on Drupal's own FieldItemListInterface, so it
 * cannot be reached through PHPUnit's mock API at all: a mock of the interface
 * has no property to assert on and no setter to drive it. A two-line value
 * object is the only way to hand the service a field with a known value without
 * booting a kernel, and it is what the patients module's tests already do for
 * the same reason.
 */
final class FakeFieldList {

  /**
   * The field's value.
   */
  public mixed $value;

  public function __construct(mixed $value = NULL) {
    $this->value = $value;
  }

  /**
   * Whether the field counts as empty.
   */
  public function isEmpty(): bool {
    return $this->value === NULL || $this->value === '';
  }

}