<?php

namespace Drupal\commerce_shipping;

use Drupal\commerce\CommerceEntityViewsData;

/**
 * Provides views data for product variations.
 */
class ShipmentViewsData extends CommerceEntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    $data['commerce_shipment']['shipping_profile'] = [
      'title' => $this->t('Shipping Profile'),
      'help' => $this->t('Reference to the shipping profile of a commerce shipment.'),
      'relationship' => [
        'group' => 'Shipment',
        'base' => 'profile',
        'base field' => 'profile_id',
        'field' => 'shipping_profile__target_id',
        'id' => 'standard',
        'label' => $this->t('Shipping Profile'),
      ],
    ];

    return $data;
  }

}
