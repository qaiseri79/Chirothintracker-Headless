<?php

namespace Drupal\Tests\commerce_shipping\Traits;

use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Provides common functionality for commerce_shipping test classes.
 */
trait ShippingTestHelperTrait {

  /**
   * Gets a card expiration year in the future.
   *
   * @return string
   *   The card expiration year.
   */
  protected function getCardExpirationYear(): string {
    $now = new DrupalDateTime();
    $now->modify('+1 year');
    return $now->format('Y');
  }

}
