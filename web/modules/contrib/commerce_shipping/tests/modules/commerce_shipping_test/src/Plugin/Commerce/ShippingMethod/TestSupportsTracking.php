<?php

namespace Drupal\commerce_shipping_test\Plugin\Commerce\ShippingMethod;

use Drupal\Core\Url;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\SupportsTrackingInterface;
use Drupal\commerce_shipping\ShippingRate;

/**
 * Provides the Test shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "test_supports_tracking",
 *   label = @Translation("Tests the SupportsTrackingInterface"),
 * )
 */
class TestSupportsTracking extends ShippingMethodBase implements SupportsTrackingInterface {

  /**
   * {@inheritdoc}
   */
  public function getTrackingUrl(ShipmentInterface $shipment) {
    $tracking_code = $shipment->getTrackingCode();
    if (!empty($tracking_code)) {
      return Url::fromUri('https://www.drupal.org/' . $tracking_code);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    return [
      new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => $this->services['default'],
        'amount' => new Price('0', 'USD'),
      ]),
    ];
  }

}
