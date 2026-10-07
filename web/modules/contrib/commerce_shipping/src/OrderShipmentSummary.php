<?php

namespace Drupal\commerce_shipping;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Default implementation of the order shipment summary.
 *
 * Renders the shipping profile, then the information for each shipment.
 * Assumes that all shipments share the same shipping profile.
 */
class OrderShipmentSummary implements OrderShipmentSummaryInterface {

  /**
   * Constructs a new OrderShipmentSummary object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_shipping\ShippingOrderManagerInterface $shippingOrderManager
   *   The shipping order manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ShippingOrderManagerInterface $shippingOrderManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function build(OrderInterface $order, $view_mode = 'user') {
    if (!$this->shippingOrderManager->hasShipments($order)) {
      return [];
    }
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();

    if (empty($shipments)) {
      return [];
    }
    $first_shipment = reset($shipments);
    $shipping_profile = $first_shipment->getShippingProfile();
    if (!$shipping_profile) {
      // Trying to generate a summary of incomplete shipments.
      return [];
    }
    $single_shipment = count($shipments) === 1;
    $profile_view_builder = $this->entityTypeManager->getViewBuilder('profile');
    $shipment_view_builder = $this->entityTypeManager->getViewBuilder('commerce_shipment');

    $summary = [];
    $summary['shipping_profile'] = $profile_view_builder->view($shipping_profile, 'default');
    foreach ($shipments as $index => $shipment) {
      $summary[$index] = [
        '#type' => $single_shipment ? 'container' : 'details',
        '#title' => $shipment->getTitle(),
        '#open' => TRUE,
      ];
      $summary[$index]['shipment'] = $shipment_view_builder->view($shipment, $view_mode);
      // The shipping profile is already shown above, so avoid duplication.
      $summary[$index]['shipment']['shipping_profile']['#access'] = FALSE;
    }

    return $summary;
  }

}
