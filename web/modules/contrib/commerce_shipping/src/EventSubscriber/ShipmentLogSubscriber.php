<?php

namespace Drupal\commerce_shipping\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs shipment transitions to the order activity log.
 */
class ShipmentLogSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new ShipmentLogSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_shipment.post_transition' => ['onShipmentPostTransition'],
    ];
  }

  /**
   * Creates a log on shipment state update.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   */
  public function onShipmentPostTransition(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_log\LogStorageInterface $log_storage */
    $log_storage = $this->entityTypeManager->getStorage('commerce_log');
    $transition = $event->getTransition();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $shipment = $event->getEntity();
    $order = $shipment->getOrder();
    $log_storage->generate($order, 'shipment_state_updated', [
      'shipment_url' => $shipment->toUrl('canonical')->toString(),
      'shipment_label' => $shipment->label(),
      'transition_label' => $transition->getLabel(),
      'from_state' => $shipment->getState()->getOriginalLabel(),
      'to_state' => $shipment->getState()->getLabel(),
    ])->save();
  }

}
