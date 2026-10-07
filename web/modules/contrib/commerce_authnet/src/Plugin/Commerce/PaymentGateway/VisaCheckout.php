<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\DataTypes\OpaqueData;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DecryptPaymentDataRequest;
use CommerceGuys\AuthNet\Request\XmlRequest;
use Drupal\commerce_authnet\ErrorHelper;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Authorize.net payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorizenet_visa_checkout",
 *   label = "Authorize.net (Visa Checkout) [LEGACY-DEPRECATED]",
 *   display_label = "Authorize.net (Visa Checkout)",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_authnet\PluginForm\VisaCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class VisaCheckout extends OffsitePaymentGatewayBase {

  /**
   * The Authorize.net API configuration.
   *
   * @var \CommerceGuys\AuthNet\Configuration
   */
  protected $authnetConfiguration;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The payment gateway utility service.
   *
   * @var \Drupal\commerce_authnet\PaymentGatewayUtilityInterface
   */
  protected $paymentGatewayUtility;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->messenger = $container->get('messenger');
    $instance->paymentGatewayUtility = $container->get('commerce_authnet.payment_gateway_utility');
    $instance->authnetConfiguration = new Configuration([
      'sandbox' => ($instance->getMode() == 'test'),
      'api_login' => $instance->configuration['api_login'],
      'transaction_key' => $instance->configuration['transaction_key'],
      'client_key' => $instance->configuration['client_key'],
    ]);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_login' => '',
      'transaction_key' => '',
      'client_key' => '',
      'visa_checkout_api_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_login'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login ID'),
      '#default_value' => $this->configuration['api_login'],
      '#required' => TRUE,
    ];

    $form['transaction_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction Key'),
      '#default_value' => $this->configuration['transaction_key'],
      '#required' => TRUE,
    ];

    $form['client_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Key'),
      '#description' => $this->t('Follow the instructions <a href="https://developer.authorize.net/api/reference/features/acceptjs.html#Obtaining_a_Public_Client_Key">here</a> to get a client key.'),
      '#default_value' => $this->configuration['client_key'],
      '#required' => TRUE,
    ];

    $form['visa_checkout_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Visa Checkout API Key'),
      '#description' => $this->t('Follow the instructions <a href="https://developer.authorize.net/api/reference/features/visa_checkout.html#Enrolling_in_Visa_Checkout">here</a> to get an Visa Checkout API key.'),
      '#default_value' => $this->configuration['visa_checkout_api_key'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    if (!empty($values['api_login']) && !empty($values['transaction_key'])) {
      $request = new XmlRequest(new Configuration([
        'sandbox' => ($values['mode'] == 'test'),
        'api_login' => $values['api_login'],
        'transaction_key' => $values['transaction_key'],
      ]), $this->httpClient, 'authenticateTestRequest');
      $request->addDataType(new MerchantAuthentication([
        'name' => $values['api_login'],
        'transactionKey' => $values['transaction_key'],
      ]));
      $response = $request->sendRequest();

      if ($response->getResultCode() != 'Ok') {
        ErrorHelper::logResponse($response);
        $this->messenger->addError(ErrorHelper::describeResponse($response));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['visa_checkout_api_key'] = $values['visa_checkout_api_key'];
      $this->configuration['api_login'] = $values['api_login'];
      $this->configuration['transaction_key'] = $values['transaction_key'];
      $this->configuration['client_key'] = $values['client_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    if ($request->request->has('error')) {
      $error = $request->request->get('error');
      return;
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $request->request->get('payment');
    $opaque_data_values = [
      'dataDescriptor' => OpaqueData::VISA_CHECKOUT,
      'dataValue' => $payment['encPaymentData'],
      'dataKey' => $payment['encKey'],
    ];
    $opaque_data = new OpaqueData($opaque_data_values);
    $decrypt_payment_data_request = new DecryptPaymentDataRequest($this->authnetConfiguration, $this->httpClient, $opaque_data, '', $payment['callid']);
    $response = $decrypt_payment_data_request->execute();
    if ($response->getResultCode() == 'Ok') {

      /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
      $checkout_flow = $order->get('checkout_flow')->entity;
      $capture = $checkout_flow->getPlugin()->getConfiguration()['panes']['payment_process']['capture'];

      $values = [
        'transactionType' => $capture ? 'authCaptureTransaction' : 'authOnlyTransaction',
        'amount' => $response->contents()->paymentDetails->amount,
        'callId' => $payment['callid'],
      ];
      $transaction_request = new TransactionRequest($values);
      $payment_data = [
        'opaqueData' => $opaque_data_values,
      ];
      $transaction_request->addData('payment', $payment_data);
      $retail = [
        'marketType' => 0,
        'deviceType' => 5,
      ];
      $transaction_request->addData('retail', $retail);

      $create_transaction_request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
      $create_transaction_request->setTransactionRequest($transaction_request);
      $create_transaction_response = $create_transaction_request->execute();

      if ($create_transaction_response->getResultCode() != 'Ok') {
        ErrorHelper::logResponse($create_transaction_response);
        $message = $create_transaction_response->getMessages()[0];
        throw PaymentGatewayException::createForPayment($payment, $message->getText());
      }

      if (!empty($create_transaction_response->getErrors())) {
        $message = $create_transaction_response->getErrors()[0];
        throw HardDeclineException::createForPayment($payment, $message->getText());
      }

      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      $payment = $payment_storage->create([
        'state' => $capture ? 'completed' : 'authorization',
        'amount' => new Price($response->contents()->paymentDetails->amount, $response->contents()->paymentDetails->currency),
        'payment_gateway' => $this->parentEntity->id(),
        'order_id' => $order->id(),
        'test' => $this->getMode() == 'test',
        'remote_id' => $create_transaction_response->contents()->transactionResponse->transId,
        'remote_state' => $create_transaction_response->contents()->messages->message->text,
        'authorized' => $this->time->getRequestTime(),
      ]);
      $payment->save();
    }
  }

}
