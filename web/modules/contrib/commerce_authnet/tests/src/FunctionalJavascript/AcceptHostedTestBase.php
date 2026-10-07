<?php

namespace Drupal\Tests\commerce_authnet\FunctionalJavascript;

use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\Request\JsonRequest;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;

/**
 * Base class for Authorize.net Accept Hosted checkout tests.
 *
 * @group commerce_authnet
 */
abstract class AcceptHostedTestBase extends CommerceWebDriverTestBase {

  /**
   * The product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected ProductInterface $product;

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $gateway;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_authnet',
    'commerce_authnet_test',
    'commerce_product',
  ];

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setUp(): void {
    parent::setUp();

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'number' => '9.99',
        'currency_code' => 'USD',
      ],
    ]);

    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'My product',
      'variations' => [$variation],
      'stores' => [$this->store->id()],
    ]);
    $this->product = $product;

    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::create([
      'id' => 'authorizenet_accept_hosted',
      'label' => 'Authorize.net Accept Hosted',
      'plugin' => 'authorizenet_accept_hosted',
    ]);
    $gateway->getPlugin()->setConfiguration([
      'api_login' => getenv('COMMERCE_AUTHNET_TEST_API_LOGIN'),
      'transaction_key' => getenv('COMMERCE_AUTHNET_TEST_TRANSACTION_KEY'),
      'client_key' => getenv('COMMERCE_AUTHNET_TEST_CLIENT_KEY'),
      'mode' => 'test',
      'payment_method_types' => ['credit_card'],
    ]);
    $gateway->save();
    $this->gateway = $gateway;

    // Cheat so we don't need JS to interact w/ Address field widget.
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $customer_form_display */
    $customer_form_display = EntityFormDisplay::load('profile.customer.default');
    $address_component = $customer_form_display->getComponent('address');
    $address_component['settings']['default_country'] = 'US';
    $customer_form_display->setComponent('address', $address_component);
    $customer_form_display->save();
    $this->drupalLogout();
  }

  /**
   * Creates an API configuration.
   *
   * @return \CommerceGuys\AuthNet\Configuration
   *   The API configuration.
   */
  protected function createApiConfiguration() {
    $configuration = $this->gateway->getPlugin()->getConfiguration();
    return new Configuration([
      'sandbox' => ($this->gateway->getPlugin()->getMode() == 'test'),
      'api_login' => $configuration['api_login'],
      'transaction_key' => $configuration['transaction_key'],
      'client_key' => $configuration['client_key'],
      'request_mode' => 'json',
    ]);
  }

  /**
   * Creates data descriptor information.
   *
   * Replicates the JS calls.
   *
   * @link https://community.developer.authorize.net/t5/Integration-and-Testing/Accept-JS-and-Integration-Testing/td-p/57232
   *
   * @return object
   *   The response.
   *
   * @throws \CommerceGuys\AuthNet\Exception\AuthNetException
   */
  protected function createDataDescriptor() {
    $configuration = $this->createApiConfiguration();
    $request = new JsonRequest(
      $configuration,
      $this->container->get('http_client'),
      'securePaymentContainerRequest'
    );
    $request->addDataType(new MerchantAuthentication([
      'name' => $configuration->getApiLogin(),
      'transactionKey' => $configuration->getTransactionKey(),
    ]));
    $request->addData('refId', '12345');
    $request->addData('data', [
      'type' => 'TOKEN',
      'id' => $this->randomString(),
      'token' => [
        'cardNumber' => '5424000000000015',
        'expirationDate' => '122027',
        'cardCode' => '900',
        'fullName' => 'Test Name',
      ],
    ]);

    $response = $request->sendRequest();
    $this->assertTrue($response->getResultCode() == 'Ok');
    $opaque_data = $response->opaqueData;
    $this->assertNotEmpty($opaque_data);
    return $opaque_data;
  }

  /**
   * Creates a payment transaction based on an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return object
   *   The response from the payment gateway, containing the transaction
   *   results and details.
   */
  public function createAcceptPaymentTransaction(OrderInterface $order) {
    $configuration = $this->createApiConfiguration();
    $opaque_data = $this->createDataDescriptor();
    /** @var \Drupal\commerce_authnet\PaymentGatewayUtilityInterface $payment_gateway_utility */
    $payment_gateway_utility = $this->container->get('commerce_authnet.payment_gateway_utility');

    // Transaction request.
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::AUTH_CAPTURE,
      'amount' => $order->getTotalPrice()->getNumber(),
    ]);

    $transaction_request->addData('payment', [
      'opaqueData' => [
        'dataDescriptor' => $opaque_data->dataDescriptor,
        'dataValue' => $opaque_data->dataValue,
      ],
    ]);

    $customer = $order->getCustomer();
    // Set the customer data.
    if ($customer->isAnonymous()) {
      // Guest Checkout.
      $customer_data = [
        'email' => $order->getEmail(),
      ];
    }
    else {
      // Authenticated Customer.
      $customer_data = [
        'id' => $order->getCustomerId(),
        'email' => $customer->getEmail(),
      ];
    }
    $transaction_request->addData('customer', $customer_data);

    // Billing details.
    $billing_profile = $order->getBillingProfile();
    $billing_address = $billing_profile ? $payment_gateway_utility->getFormattedAddress($billing_profile) : NULL;
    if (!empty($billing_address)) {
      $transaction_request->addDataType(new BillTo($billing_address));
    }

    // Adding line items.
    $line_items = $payment_gateway_utility->getOrderLineItems($order);
    foreach ($line_items as $line_item) {
      $transaction_request->addLineItem($line_item);
    }

    $request = new CreateTransactionRequest($configuration, $this->container->get('http_client'));
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $messages = [];
      foreach ($response->getMessages() as $message) {
        $messages[] = $message->getCode() . ': ' . $message->getText();
      }
      print_r($messages);
    }
    $this->assertTrue($response->getResultCode() == 'Ok');
    $transaction_response = $response->transactionResponse;
    $this->assertNotEmpty($transaction_response);
    return $transaction_response;
  }

  /**
   * Formats the transaction response.
   *
   * @param object $response
   *   The transaction response.
   * @param bool $authenticated
   *   Whether the customer is authenticated.
   *
   * @return array
   *   The formatted transaction response.
   */
  protected function formatTransactionResponse(object $response, bool $authenticated): array {
    $formatted_response = [
      'responseCode' => $response->responseCode,
      'transId' => $response->transId,
      'accountType' => $response->accountType,
      'accountNumber' => $response->accountNumber,
    ];
    if ($authenticated) {
      $formatted_response['createPaymentProfileResponse'] = [
        'success' => 'true',
      ];
    }

    return $formatted_response;
  }

  /**
   * Data provider to provide a pass or truthy data set.
   *
   * @return \Generator
   *   The data.
   */
  public static function dataProviderUserAuthenticated(): \Generator {
    yield [TRUE];
    yield [FALSE];
  }

}
