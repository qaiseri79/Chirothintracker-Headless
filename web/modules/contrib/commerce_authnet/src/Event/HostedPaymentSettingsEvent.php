<?php

namespace Drupal\commerce_authnet\Event;

use CommerceGuys\AuthNet\DataTypes\HostedPaymentSettings;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Defines the creation of a hosted payment settings payload event.
 *
 * @see AuthorizeNetEvents
 */
class HostedPaymentSettingsEvent extends Event {

  /**
   * Constructs a new HostedPaymentSettingsEvent.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to be transacted upon.
   * @param \CommerceGuys\AuthNet\DataTypes\HostedPaymentSettings $hostedSettings
   *   An array of hosted payment settings.
   */
  public function __construct(protected OrderInterface $order, protected HostedPaymentSettings $hostedSettings) {
  }

  /**
   * Gets the order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets an array of hosted payment settings.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\HostedPaymentSettings
   *   An array of hosted payment settings.
   */
  public function getHostedPaymentSettings(): HostedPaymentSettings {
    return $this->hostedSettings;
  }

}
