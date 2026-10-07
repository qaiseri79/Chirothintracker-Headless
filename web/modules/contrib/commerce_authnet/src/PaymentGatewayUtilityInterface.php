<?php

namespace Drupal\commerce_authnet;

use CommerceGuys\AuthNet\DataTypes\Shipping;
use CommerceGuys\AuthNet\DataTypes\Tax;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Provides an interface for the payment gateways utility.
 */
interface PaymentGatewayUtilityInterface {

  /**
   * Gets the line items from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\LineItem[]
   *   An array of line items.
   */
  public function getOrderLineItems(OrderInterface $order): array;

  /**
   * Gets the tax from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\Tax
   *   The total tax.
   */
  public function getOrderTax(OrderInterface $order): Tax;

  /**
   * Gets the shipping from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\Shipping
   *   The total shipping.
   */
  public function getOrderShipping(OrderInterface $order): Shipping;

  /**
   * Returns the customer identifier from a payment method's remote id.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   *
   * @return string|null
   *   The remote customer id or FALSE if it cannot be resolved.
   */
  public function getPaymentMethodCustomerId(PaymentMethodInterface $payment_method): ?string;

  /**
   * Returns the payment method remote id ensuring customer id is removed.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   *
   * @return string
   *   The remote id.
   */
  public function getRemoteProfileId(PaymentMethodInterface $payment_method): string;

  /**
   * Gets the formatted address from the profile.
   *
   * @param \Drupal\profile\Entity\ProfileInterface $profile
   *   The customer profile.
   * @param string $type
   *   The address type ("billing"|"shipping").
   *
   * @return array|null
   *   The formatted address or NULL if the address is empty.
   */
  public function getFormattedAddress(ProfileInterface $profile, string $type = 'billing'): ?array;

  /**
   * Maps the Authorize.Net credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Authorize.Net credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  public static function mapCreditCardType(string $card_type): string;

}
