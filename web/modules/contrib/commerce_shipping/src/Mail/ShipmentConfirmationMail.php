<?php

namespace Drupal\commerce_shipping\Mail;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce\MailHandlerInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;

class ShipmentConfirmationMail implements ShipmentConfirmationMailInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new ShipmentConfirmationMail object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce\MailHandlerInterface $mailHandler
   *   The mail handler.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailHandlerInterface $mailHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function send(ShipmentInterface $shipment, $to = NULL, $bcc = NULL) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $shipment->getOrder();
    $to = $to ?? $order->getEmail();
    if (!$to) {
      // The email should not be empty.
      return FALSE;
    }

    $subject = $this->formatPlural(
      $shipment->get('items')->count(),
      'An item for order #@number shipped!',
      'Items for your order #@number shipped!',
      ['@number' => $order->getOrderNumber()]
    );

    $profile_view_builder = $this->entityTypeManager->getViewBuilder('profile');
    $shipment_view_builder = $this->entityTypeManager->getViewBuilder('commerce_shipment');
    $body = [
      '#theme' => 'commerce_shipment_confirmation',
      '#order_entity' => $order,
      '#shipment_entity' => $shipment,
      '#shipping_profile' => $profile_view_builder->view($shipment->getShippingProfile()),
      '#tracking_code' => $shipment_view_builder->viewField($shipment->get('tracking_code'), 'default'),
    ];

    $params = [
      'id' => 'shipment_confirmation',
      'from' => $order->getStore()->getEmail(),
      'bcc' => $bcc,
      'order' => $order,
      'shipment' => $shipment,
    ];
    $customer = $order->getCustomer();
    if ($customer->isAuthenticated()) {
      $params['langcode'] = $customer->getPreferredLangcode();
    }

    return $this->mailHandler->sendMail($to, $subject, $body, $params);
  }

}
