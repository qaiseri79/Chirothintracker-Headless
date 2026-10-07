<?php

namespace Drupal\commerce_shipping;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;

/**
 * Prepares shipments for the order refresh process.
 *
 * Runs before other order processors (promotion, tax, etc).
 * Packs the shipments, resets their amounts and adjustments.
 *
 * Once the other order processors perform their changes, the
 * LateOrderProcessor transfers the shipment adjustments to the order.
 *
 * @see \Drupal\commerce_shipping\LateOrderProcessor
 */
class EarlyOrderProcessor implements OrderProcessorInterface {

  /**
   * Constructs a new EarlyOrderProcessor object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_shipping\ShippingOrderManagerInterface $shippingOrderManager
   *   The shipping order manager.
   * @param \Drupal\commerce_shipping\ShipmentManagerInterface $shipmentManager
   *   The shipment manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ShippingOrderManagerInterface $shippingOrderManager,
    protected ShipmentManagerInterface $shipmentManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order) {
    if (!$this->shippingOrderManager->hasShipments($order)) {
      return;
    }
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();
    if ($shipments && $this->shouldRepack($order, $shipments)) {
      $shipping_profile = $this->shippingOrderManager->getProfile($order);
      // If the shipping profile does not exist, delete all shipments.
      if (!$shipping_profile) {
        $shipment_storage = $this->entityTypeManager->getStorage('commerce_shipment');
        $shipment_storage->delete($shipments);
        return;
      }
      $shipments = $this->shippingOrderManager->pack($order, $shipping_profile);
    }

    $should_refresh = $this->shouldRefresh($order);
    foreach ($shipments as $key => $shipment) {
      $original_amount = $shipment->getOriginalAmount();
      $pre_promotion_amount = $shipment->getData('pre_promotion_amount');
      if ($pre_promotion_amount) {
        if ($original_amount) {
          $shipment->setAmount($pre_promotion_amount);
        }
        else {
          $shipment->unsetData('pre_promotion_amount');
        }
      }
      $shipment->clearAdjustments();

      if (!$should_refresh) {
        continue;
      }

      $shipment->order_id->entity = $order;
      $rates = $this->shipmentManager->calculateRates($shipment);

      // There is no rates for shipping. "clear" the rate...
      // Note that we don't remove the shipment to prevent data loss (we're
      // mainly interested in preserving the shipping profile).
      if (empty($rates)) {
        $shipment->clearRate();
        continue;
      }
      $rate = $this->shipmentManager->selectDefaultRate($shipment, $rates);
      $this->shipmentManager->applyRate($shipment, $rate);
    }
    // Unset flag before returning updated shipments.
    if ($should_refresh) {
      $order->unsetData(ShippingOrderManagerInterface::FORCE_REFRESH);
    }

    $order->set('shipments', $shipments);
  }

  /**
   * Determines whether the given order's shipments should be repacked.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments
   *   The shipments.
   *
   * @return bool
   *   TRUE if the order should be repacked, FALSE otherwise.
   */
  protected function shouldRepack(OrderInterface $order, array $shipments) {
    // Skip repacking if there's at least one shipment that was created outside
    // of the packing process (via the admin UI, for example).
    foreach ($shipments as $shipment) {
      if (!$shipment->getData('owned_by_packer')) {
        return FALSE;
      }
    }

    // Flag used for force repacking shipments and possible recalculation
    // of rates.
    if ($this->shouldRefresh($order)) {
      return TRUE;
    }

    // Ideally repacking would happen only if the order items changed.
    // However, it is not possible to detect order item quantity changes,
    // because the order items are saved before the order itself.
    // Therefore, repacking runs on every refresh, but as a minimal
    // optimization, this processor ignores refreshes caused by moving
    // through checkout, unless an order item was added/removed along the way.
    if (isset($order->original) && $order->hasField('checkout_step')) {
      $previous_step = $order->original->get('checkout_step')->value;
      $current_step = $order->get('checkout_step')->value;
      $previous_order_item_ids = array_map(function ($value) {
        return $value['target_id'];
      }, $order->original->get('order_items')->getValue());
      $current_order_item_ids = array_map(function ($value) {
        return $value['target_id'];
      }, $order->get('order_items')->getValue());
      if ($previous_step != $current_step && $previous_order_item_ids == $current_order_item_ids) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Determines whether the order needs to be repacked and/or whether the
   * shipping rates should be recalculated.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool
   *   TRUE if it should refresh, FALSE otherwise.
   */
  protected function shouldRefresh(OrderInterface $order) {
    return (bool) $order->getData(ShippingOrderManagerInterface::FORCE_REFRESH, FALSE);
  }

}
