<?php

namespace Drupal\Tests\commerce_recurring\Functional;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_recurring\Entity\BillingSchedule;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * Defines base class for commerce_recurring test cases.
 */
abstract class SubscriptionBrowserTestBase extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment_example',
    'commerce_product',
    'commerce_recurring',
  ];

  /**
   * The test billing schedule.
   *
   * @var \Drupal\commerce_recurring\Entity\BillingScheduleInterface
   */
  protected $billingSchedule;

  /**
   * The test billing profile.
   *
   * @var \Drupal\profile\Entity\ProfileInterface
   */
  protected $billingProfile;

  /**
   * The test payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $paymentGateway;

  /**
   * The test payment method.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentMethodInterface
   */
  protected $paymentMethod;

  /**
   * Holds the date pattern string for the "html_date" format.
   *
   * @var string
   */
  protected $dateFormat;

  /**
   * Holds the date pattern string for the "html_time" format.
   *
   * @var string
   */
  protected $timeFormat;

  /**
   * The test variation.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $variation;

  /**
   * The recurring order manager.
   *
   * @var \Drupal\commerce_recurring\RecurringOrderManagerInterface
   */
  protected $recurringOrderManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'number' => '39.99',
        'currency_code' => 'USD',
      ],
    ]);
    $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'My product',
      'variations' => [$this->variation],
      'stores' => [$this->store],
    ]);
    /** @var \Drupal\commerce_recurring\Entity\BillingScheduleInterface $billing_schedule */
    $this->billingSchedule = $this->createEntity('commerce_billing_schedule', [
      'id' => 'test_id',
      'label' => 'Hourly schedule',
      'displayLabel' => 'Hourly schedule',
      'billingType' => BillingSchedule::BILLING_TYPE_POSTPAID,
      'plugin' => 'fixed',
      'configuration' => [
        'interval' => [
          'number' => '1',
          'unit' => 'hour',
        ],
      ],
    ]);

    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'US',
      ],
    ]);
    $profile->save();
    $this->billingProfile = $this->reloadEntity($profile);

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = PaymentGateway::create([
      'id' => 'example',
      'label' => 'Example',
      'plugin' => 'example_onsite',
    ]);
    $payment_gateway->save();
    $this->paymentGateway = $this->reloadEntity($payment_gateway);

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => $this->paymentGateway,
      'card_type' => 'visa',
      'card_number' => 1,
      'uid' => $this->adminUser->id(),
      'billing_profile' => $this->billingProfile,
    ]);
    $payment_method->save();
    $this->paymentMethod = $this->reloadEntity($payment_method);

    $this->dateFormat = DateFormat::load('html_date')->getPattern();
    $this->timeFormat = DateFormat::load('html_time')->getPattern();

    $this->recurringOrderManager = $this->container->get('commerce_recurring.order_manager');
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [
      'administer commerce_subscription',
    ] + parent::getAdministratorPermissions();
  }

}
