<?php

namespace Drupal\commerce_authnet;

use CommerceGuys\AuthNet\DataTypes\LineItem;
use CommerceGuys\AuthNet\DataTypes\Shipping;
use CommerceGuys\AuthNet\DataTypes\Tax;
use Drupal\commerce_order\AdjustmentTransformerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_price\Calculator;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\String\UnicodeString;

/**
 * Provides a utility service for payment gateways.
 */
class PaymentGatewayUtility implements PaymentGatewayUtilityInterface {

  /**
   * Constructs a new PaymentGatewayUtility object.
   *
   * @param \Drupal\commerce_order\AdjustmentTransformerInterface $adjustmentTransformer
   *   The adjustment transformer.
   */
  public function __construct(
    protected AdjustmentTransformerInterface $adjustmentTransformer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getOrderLineItems(OrderInterface $order): array {
    $line_items = [];

    foreach ($order->getItems() as $order_item) {
      $name = $order_item->label();
      $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;

      $line_items[] = new LineItem([
        'itemId' => $order_item->id(),
        'name' => $name,
        'quantity' => $order_item->getQuantity(),
        'unitPrice' => $order_item->getUnitPrice()->getNumber(),
      ]);
    }

    return $line_items;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrderTax(OrderInterface $order): Tax {
    $amount = '0';
    $labels = [];

    $adjustments = $order->collectAdjustments();
    if ($adjustments) {
      $adjustments = $this->adjustmentTransformer->combineAdjustments($adjustments);
      $adjustments = $this->adjustmentTransformer->roundAdjustments($adjustments);
      foreach ($adjustments as $adjustment) {
        if ($adjustment->getType() !== 'tax') {
          continue;
        }
        $amount = Calculator::add($amount, $adjustment->getAmount()->getNumber());
        $labels[] = $adjustment->getLabel();
      }
    }

    // Determine whether multiple tax types are present.
    $labels = array_unique($labels);
    if (empty($labels)) {
      $name = '';
      $description = '';
    }
    elseif (count($labels) > 1) {
      $name = 'Multiple Tax Types';
      $description = implode(', ', $labels);
    }
    else {
      $name = $labels[0];
      $description = $labels[0];
    }

    // Limit name, description fields to 32, 255 characters.
    $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;
    $description = (strlen($description) > 255) ? substr($description, 0, 252) . '...' : $description;

    // If amount is negative, do not transmit any information.
    if ($amount < 0) {
      $amount = 0;
      $name = '';
      $description = '';
    }

    return new Tax([
      'amount' => $amount,
      'name' => $name,
      'description' => $description,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getOrderShipping(OrderInterface $order): Shipping {
    // Return empty if there is no shipments field.
    if (!$order->hasField('shipments')) {
      return new Shipping([
        'amount' => 0,
        'name' => '',
        'description' => '',
      ]);
    }

    $amount = '0';
    $labels = [];

    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
    $shipments = $order->get('shipments')->referencedEntities();
    if ($shipments) {
      foreach ($shipments as $shipment) {
        // Shipments without an amount are incomplete / unrated.
        if ($shipment_amount = $shipment->getAmount()) {
          $amount = Calculator::add($amount, $shipment_amount->getNumber());
          $labels[] = $shipment->label();
        }
      }
    }

    // Determine whether multiple tax types are present.
    $labels = array_unique($labels);
    if (empty($labels)) {
      $name = '';
      $description = '';
    }
    elseif (count($labels) > 1) {
      $name = 'Multiple shipments';
      $description = implode(', ', $labels);
    }
    else {
      $name = $labels[0];
      $description = $labels[0];
    }

    // Limit name, description fields to 32, 255 characters.
    $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;
    $description = (strlen($description) > 255) ? substr($description, 0, 252) . '...' : $description;
    return new Shipping([
      'amount' => $amount,
      'name' => $name,
      'description' => $description,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentMethodCustomerId(PaymentMethodInterface $payment_method): ?string {
    $remote_id = $payment_method->getRemoteId();

    if ($remote_id && strstr($remote_id, '|')) {
      $ids = explode('|', $remote_id);
      return reset($ids);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteProfileId(PaymentMethodInterface $payment_method): string {
    $remote_id = $payment_method->getRemoteId();
    $ids = explode('|', $remote_id);

    return end($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormattedAddress(ProfileInterface $profile, string $type = 'billing'): ?array {
    if ($profile->get('address')->isEmpty()) {
      return NULL;
    }

    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $profile->get('address')->first();
    // Convert the address to AuthorizeNet format.
    $formatted_address = array_filter([
      'firstName' => substr($address->getGivenName(), 0, 50),
      'lastName' => substr($address->getFamilyName(), 0, 50),
      'company' => substr($address->getOrganization(), 0, 50),
      'address' => substr($address->getAddressLine1() . ' ' . $address->getAddressLine2(), 0, 60),
      'country' => substr($address->getCountryCode(), 0, 60),
      'city' => substr($address->getLocality(), 0, 40),
      'state' => substr($address->getAdministrativeArea(), 0, 40),
      'zip' => substr($address->getPostalCode(), 0, 20),
    ]);

    return array_map([static::class, 'sanitizeAddressLine'], $formatted_address);
  }

  /**
   * Sanitizes an address line by removing invalid characters.
   *
   * @param string $address_line
   *   The address line to be sanitized.
   *
   * @return string
   *   The sanitized address line with only allowed characters.
   */
  protected static function sanitizeAddressLine(string $address_line): string {
    return trim(preg_replace('/[^a-z0-9 .-]/i', '', (new UnicodeString($address_line))->ascii()));
  }

  /**
   * {@inheritdoc}
   */
  public static function mapCreditCardType(string $card_type): string {
    $map = [
      'AmericanExpress' => 'amex',
      'DinersClub' => 'dinersclub',
      'Discover' => 'discover',
      'JCB' => 'jcb',
      'MasterCard' => 'mastercard',
      'Visa' => 'visa',
      'UnionChinaPay' => 'unionpay',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

}
