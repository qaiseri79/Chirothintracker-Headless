<?php

namespace Drupal\headless_commerce\EventSubscriber;

use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PaymentGatewayFilterSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents() {
    return [
      PaymentEvents::FILTER_PAYMENT_GATEWAYS => 'onFilterPaymentGateways',
    ];
  }

  public function onFilterPaymentGateways(FilterPaymentGatewaysEvent $event) {
    $order = $event->getOrder();
    if (!$order) {
      return;
    }

    $store = $order->getStore();
    if (!$store || !$store->hasField('field_payment_gateway') || $store->get('field_payment_gateway')->isEmpty()) {
      return;
    }

    $allowed_gateway_id = $store->get('field_payment_gateway')->target_id;
    $gateways = $event->getPaymentGateways();

    foreach ($gateways as $gateway_id => $gateway) {
      if ($gateway_id !== $allowed_gateway_id) {
        unset($gateways[$gateway_id]);
      }
    }

    $event->setPaymentGateways($gateways);
  }

}
