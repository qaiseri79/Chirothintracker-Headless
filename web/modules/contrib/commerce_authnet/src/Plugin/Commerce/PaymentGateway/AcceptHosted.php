<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use CommerceGuys\AuthNet\AuthenticateTestRequest;
use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\CreateCustomerProfileFromTransactionRequest;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\CreditCard as CreditCardDataType;
use CommerceGuys\AuthNet\DataTypes\HostedPaymentSettings;
use CommerceGuys\AuthNet\DataTypes\MerchantAuthentication;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\DataTypes\Profile;
use CommerceGuys\AuthNet\DataTypes\Setting;
use CommerceGuys\AuthNet\DataTypes\ShipTo;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\DeleteCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\GetCustomerProfileRequest;
use CommerceGuys\AuthNet\GetHostedPaymentPageRequest;
use CommerceGuys\AuthNet\GetTransactionDetailsRequest;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_authnet\ErrorHelper;
use Drupal\commerce_authnet\Event\AuthorizeNetEvents;
use Drupal\commerce_authnet\Event\HostedPaymentSettingsEvent;
use Drupal\commerce_authnet\Event\TransactionRequestEvent;
use Drupal\commerce_checkout\Event\CheckoutEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Accept Hosted payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorizenet_accept_hosted",
 *   label = "Authorize.net (Accept Hosted)",
 *   display_label = "Authorize.net (Accept Hosted)",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa", "unionpay"
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class AcceptHosted extends OffsitePaymentGatewayBase implements SupportsRefundsInterface, SupportsVoidsInterface, SupportsAuthorizationsInterface, SupportsStoredPaymentMethodsInterface {

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The payment order updater service.
   *
   * @var \Drupal\commerce_payment\PaymentOrderUpdaterInterface
   */
  protected $paymentOrderUpdater;

  /**
   * The checkout order manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   */
  protected $checkoutOrderManager;

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
    $instance->logger = $container->get('commerce_authnet.logger');
    $instance->authnetConfiguration = new Configuration([
      'sandbox' => ($instance->getMode() == 'test'),
      'api_login' => $instance->configuration['api_login'],
      'transaction_key' => $instance->configuration['transaction_key'],
      'client_key' => $instance->configuration['client_key'],
    ]);
    $instance->messenger = $container->get('messenger');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->paymentOrderUpdater = $container->get('commerce_payment.order_updater');
    $instance->checkoutOrderManager = $container->get('commerce_checkout.checkout_order_manager');
    $instance->paymentGatewayUtility = $container->get('commerce_authnet.payment_gateway_utility');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'api_login' => '',
      'transaction_key' => '',
      'client_key' => '',
      'captcha_security' => FALSE,
      'card_code_required' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
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
    $form['captcha_security'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CAPTCHA security'),
      '#default_value' => $this->configuration['captcha_security'],
    ];
    $form['card_code_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Card code required'),
      '#default_value' => $this->configuration['card_code_required'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    if (!empty($values['api_login']) && !empty($values['transaction_key'])) {
      $request = new AuthenticateTestRequest($this->authnetConfiguration, $this->httpClient);
      $request->setMerchantAuthentication(new MerchantAuthentication([
        'name' => $values['api_login'],
        'transactionKey' => $values['transaction_key'],
      ]));
      $response = $request->execute();

      if ($response->getResultCode() != 'Ok') {
        ErrorHelper::logResponse($response);
        $this->messenger->addError(ErrorHelper::describeResponse($response));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_login'] = $values['api_login'];
      $this->configuration['transaction_key'] = $values['transaction_key'];
      $this->configuration['client_key'] = $values['client_key'];
      $this->configuration['captcha_security'] = $values['captcha_security'];
      $this->configuration['card_code_required'] = $values['card_code_required'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    $order = $payment->getOrder();
    $owner = $payment_method->getOwner();

    // Transaction request.
    $transaction_request = new TransactionRequest([
      'transactionType' => $capture ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
      'amount' => $payment->getAmount()->getNumber(),
    ]);

    // Initializing the profile to charge and adding it to the transaction.
    $customer_profile_id = $this->getRemoteCustomerId($owner);
    if (empty($customer_profile_id)) {
      $customer_profile_id = $this->paymentGatewayUtility->getPaymentMethodCustomerId($payment_method);
    }
    $payment_profile_id = $this->paymentGatewayUtility->getRemoteProfileId($payment_method);
    $profile_to_charge = new Profile(['customerProfileId' => $customer_profile_id]);
    $profile_to_charge->addData('paymentProfile', ['paymentProfileId' => $payment_profile_id]);
    $transaction_request->addData('profile', $profile_to_charge->toArray());
    $profiles = $order->collectProfiles();
    $shipping_address = isset($profiles['shipping']) ? $this->paymentGatewayUtility->getFormattedAddress($profiles['shipping'], 'shipping') : NULL;
    if (!empty($shipping_address)) {
      $transaction_request->addDataType(new ShipTo($shipping_address));
    }

    // Adding order information to the transaction.
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
    ]));
    $transaction_request->addData('customerIP', $order->getIpAddress());

    // Adding line items.
    $line_items = $this->paymentGatewayUtility->getOrderLineItems($order);
    foreach ($line_items as $line_item) {
      $transaction_request->addLineItem($line_item);
    }

    // Adding tax information to the transaction.
    $transaction_request->addData('tax', $this->paymentGatewayUtility->getOrderTax($order)->toArray());
    $transaction_request->addData('shipping', $this->paymentGatewayUtility->getOrderShipping($order)->toArray());

    $event = new TransactionRequestEvent($order, $transaction_request);
    $this->eventDispatcher->dispatch($event, AuthorizeNetEvents::CREATE_TRANSACTION_REQUEST);

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() !== 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      switch ($message->getCode()) {
        case 'E00040':
          $payment_method->delete();
          throw PaymentGatewayException::createForPayment($payment, 'The provided payment method is no longer valid');

        case 'E00042':
          $payment_method->delete();
          throw PaymentGatewayException::createForPayment($payment, 'You cannot add more than 10 payment methods.');

        default:
          throw PaymentGatewayException::createForPayment($payment, $message->getText());
      }
    }

    if (!empty($response->getErrors())) {
      $message = $response->getErrors()[0];
      throw HardDeclineException::createForPayment($payment, $message->getText());
    }

    // Select the next state based on fraud detection results.
    $code = $response->getMessageCode();
    $expires = 0;
    $next_state = 'authorization';
    if ($code == 1 && $capture) {
      $next_state = 'completed';
    }
    // Do not authorize, but hold for review.
    elseif ($code == 252) {
      $next_state = 'unauthorized_review';
      $expires = strtotime('+5 days');
    }
    // Authorized, but hold for review.
    elseif ($code == 253) {
      $next_state = 'authorization_review';
      $expires = strtotime('+5 days');
    }
    $payment->setExpiresTime($expires);
    $payment->setState($next_state);
    $payment->setRemoteId($response->transactionResponse->transId);
    $payment->setAvsResponseCode($response->transactionResponse->avsResultCode);
    // @todo Find out how long an authorization is valid, set its expiration.
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request): void {
    if ($request->request->has('error')) {
      $error = $request->request->get('error');
      throw new HardDeclineException($error);
    }

    $response = Json::decode($request->query->get('response'));
    if ($response['responseCode'] != 1) {
      $this->logger->error('The Accept Hosted payment transaction for order ID: @order_id was failed with response: %response', [
        '@order_id' => $order->id(),
        '%response' => $request->query->get('response'),
      ]);
      throw new InvalidResponseException($this->t('This transaction has been declined.'));
    }

    // Fetch detailed transaction information from Authorize.net.
    $transaction = $this->getPaymentTransactionDetails($response['transId']);
    if (!$transaction || empty($transaction->transactionType) || empty($transaction->authAmount)) {
      throw new InvalidResponseException($this->t('Unable to retrieve transaction details.'));
    }

    // Create a payment method and payment.
    $payment_method = $this->createPaymentMethod($order, $response);
    $order->set('payment_method', $payment_method);
    $this->createPaymentFromTransaction($order, $transaction);

    // Completes the order.
    if ($order->getState()->getId() == 'draft') {
      $step_id = $this->placeOrder($order);
      throw new NeedsRedirectException(Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => $step_id,
      ])->toString());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    // Reacts to Webhook events.
    $request_body = Json::decode($request->getContent());

    $this->logger->debug('Incoming webhook request: <pre>@data</pre>', [
      '@data' => print_r($request_body, TRUE),
    ]);

    $supported_events = [
      'net.authorize.payment.authcapture.created',
      'net.authorize.payment.refund.created',
      'net.authorize.payment.void.created',
    ];

    // Ignore unsupported events.
    if (!in_array($request_body['eventType'], $supported_events, TRUE)) {
      return NULL;
    }

    // Ignore failed requests.
    $payload = $request_body['payload'];
    if ($payload['responseCode'] != 1) {
      $this->logger->warning('The request failed with response code %response_code.', [
        '%response_code' => $payload['responseCode'],
      ]);
      return NULL;
    }

    $transaction = $this->getPaymentTransactionDetails($payload['id']);
    if (!$transaction) {
      $this->logger->warning('Unable to retrieve transaction (%trans_id) details.', [
        '%trans_id' => $payload['id'],
      ]);
      return NULL;
    }

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    switch ($request_body['eventType']) {
      case 'net.authorize.payment.authcapture.created':
        $order_id = $payload['invoiceNumber'];
        $order_storage = $this->entityTypeManager->getStorage('commerce_order');
        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $order_storage->load($order_id);

        if (!$order) {
          $this->logger->warning('Order with order id (%order_id) does not exist.', [
            '%order_id' => $payload['invoiceNumber'],
          ]);
          return NULL;
        }

        $payment = $payment_storage->loadByRemoteId($payload['id']);
        if (!$payment) {
          // Complete the draft order.
          if ($order->getState()->getId() === 'draft') {
            $payment_details = [
              'transId' => $transaction->transId,
              'createPaymentProfileResponse' => [
                'success' => 'true',
              ],
              'accountNumber' => $transaction->payment->creditCard->cardNumber,
              'accountType' => $transaction->payment->creditCard->cardType,
            ];
            // Create a payment method and payment.
            $payment_method = $this->createPaymentMethod($order, $payment_details);
            $order->set('payment_method', $payment_method);
            $this->createPaymentFromTransaction($order, $transaction);
            // Completes the order.
            $this->placeOrder($order);
          }
        }
        break;

      case 'net.authorize.payment.refund.created':
        // Ignore requests made through Drupal.
        if (str_starts_with($payload['invoiceNumber'], 'REFUND#')) {
          return;
        }

        // Reference payment.
        $payment = $payment_storage->loadByRemoteId($transaction->refTransId);
        if (!$payment) {
          $this->logger->warning('Payment with remote id (%remote_id) does not exist.', [
            '%remote_id' => $transaction->refTransId,
          ]);
          return NULL;
        }

        $order = $payment->getOrder();
        // Gets the currency code from the order, as it is not in the response.
        $currency_code = $order->getTotalPrice()->getCurrencyCode();
        // Calculate the refund amount.
        $refund_amount = new Price($transaction->authAmount, $currency_code);
        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($refund_amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
          $transition_id = 'partially_refund';
        }
        else {
          $transition_id = 'refund';
        }
        if ($payment->getState()->isTransitionAllowed($transition_id)) {
          $payment->getState()->applyTransitionById($transition_id);
        }
        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();
        break;

      case 'net.authorize.payment.void.created':
        $payment = $payment_storage->loadByRemoteId($payload['id']);

        if (!$payment) {
          $this->logger->warning('Payment with remote id (%remote_id) does not exist.', [
            '%remote_id' => $payload['id'],
          ]);
          return NULL;
        }

        if ($payment->getState()->getId() !== 'authorization_voided') {
          $payment->setState('authorization_voided');
          $payment->save();
        }
        break;
    }
  }

  /**
   * Place the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The next step id.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function placeOrder(OrderInterface $order): string {
    $this->paymentOrderUpdater->updateOrder($order);
    // Redirect to the next step after 'payment'.
    $checkout_flow = $this->checkoutOrderManager->getCheckoutFlow($order);
    $checkout_flow_plugin = $checkout_flow->getPlugin();
    $step_id = $checkout_flow_plugin->getNextStepId('payment');
    $order->set('checkout_step', $step_id);
    if ($step_id === 'complete') {
      // Notify other modules.
      $event = new OrderEvent($order);
      $this->eventDispatcher->dispatch($event, CheckoutEvents::COMPLETION);
      if ($order->getState()->isTransitionAllowed('place')) {
        $order->getState()->applyTransitionById('place');
      }
    }
    $order->save();
    return $step_id;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest(new TransactionRequest([
      'transactionType' => TransactionRequest::PRIOR_AUTH_CAPTURE,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      throw PaymentGatewayException::createForPayment($payment, $message->getText());
    }

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization', 'completed']);
    $order = $payment->getOrder();

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::VOID,
      'refTransId' => $payment->getRemoteId(),
    ]);

    $event = new TransactionRequestEvent($order, $transaction_request);
    $this->eventDispatcher->dispatch($event, AuthorizeNetEvents::VOID_TRANSACTION_REQUEST);

    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      throw PaymentGatewayException::createForPayment($payment, $message->getText());
    }

    // Remove the completed timestamp on the order and force an order update
    // to properly recalculate the balance of the order until commerce core
    // handles this properly.
    // @see https://www.drupal.org/project/commerce/issues/3356936
    $payment->set('completed', NULL);
    $this->paymentOrderUpdater->requestUpdate($order);

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $order = $payment->getOrder();
    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::REFUND,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]);

    // Adding order information to the transaction.
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => 'REFUND#' . $order->getOrderNumber() ?: $order->id(),
    ]));
    $payment_method = $payment->getPaymentMethod();
    $transaction_request->addPayment(new CreditCardDataType([
      'cardNumber' => $payment_method->card_number->value,
      'expirationDate' => 'XXXX',
    ]));

    $event = new TransactionRequestEvent($order, $transaction_request);
    $this->eventDispatcher->dispatch($event, AuthorizeNetEvents::REFUND_TRANSACTION_REQUEST);

    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() !== 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      throw PaymentGatewayException::createForPayment($payment, $message->getText());
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function canVoidPayment(PaymentInterface $payment): bool {
    $state = $payment->getState()->getId();
    $transaction = $this->getPaymentTransactionDetails($payment->getRemoteId());

    if (!$transaction) {
      $this->logger->warning('Unable to retrieve transaction (%trans_id) details.', [
        '%trans_id' => $payment->getRemoteId(),
      ]);
      return FALSE;
    }

    return $state === 'authorization' || ($transaction->transactionStatus === 'capturedPendingSettlement' && $state === 'completed');
  }

  /**
   * {@inheritdoc}
   */
  public function canRefundPayment(PaymentInterface $payment): bool {
    $state = $payment->getState()->getId();
    $transaction = $this->getPaymentTransactionDetails($payment->getRemoteId());

    if (!$transaction) {
      $this->logger->warning('Unable to retrieve transaction (%trans_id) details.', [
        '%trans_id' => $payment->getRemoteId(),
      ]);
      return FALSE;
    }

    $refund_states = ['completed', 'partially_refunded'];
    return $transaction->transactionStatus === 'settledSuccessfully' && in_array($state, $refund_states, TRUE);
  }

  /**
   * Creates a payment method with the given payment details.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $payment_details
   *   The gateway-specific payment details provided by the payment method form
   *   for on-site gateways, or the incoming request for off-site gateways.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentMethodInterface
   *   A new payment method object.
   */
  public function createPaymentMethod(OrderInterface $order, array $payment_details): PaymentMethodInterface {
    // Check if the payment profile has been created.
    $is_payment_profile_created = isset($payment_details['createPaymentProfileResponse']) && $payment_details['createPaymentProfileResponse']['success'] == 'true';
    $customer = $order->getCustomer();

    // Create the customer profile with transaction ID.
    $customer_id = NULL;
    if ($customer && $customer->isAuthenticated()) {
      $customer_id = $this->getRemoteCustomerId($customer);
      if (empty($customer_id)) {
        $customer_id = $this->createCustomerProfileFromTransaction($payment_details['transId']);
        // A payment profile will also be created with the customer.
        $is_payment_profile_created = TRUE;
        $this->setRemoteCustomerId($customer, $customer_id);
        $customer->save();
      }
    }

    // Create a payment method.
    $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
    $payment_method = $payment_method_storage->createForCustomer(
      'credit_card',
      $this->parentEntity->id(),
      $order->getCustomerId(),
      $order->getBillingProfile()
    );

    $payment_profile = $is_payment_profile_created && $customer_id ? $this->getCustomerPaymentProfileByPaymentDetails($customer_id, $payment_details) : NULL;
    if ($is_payment_profile_created && $payment_profile) {
      $payment = $payment_profile->payment;
      $payment_method->card_type = $this->paymentGatewayUtility->mapCreditCardType($payment->creditCard->cardType);
      $payment_method->card_number = substr($payment->creditCard->cardNumber, -4);
      $date_parts = explode('-', $payment->creditCard->expirationDate);
      $expiration_year = $date_parts[0];
      $expiration_month = $date_parts[1];
      $payment_method->card_exp_month = $expiration_month;
      $payment_method->card_exp_year = $expiration_year;
      $payment_method->setRemoteId($payment_profile->customerPaymentProfileId);
      $expires = CreditCard::calculateExpirationTimestamp($expiration_month, $expiration_year);
      $payment_method->setExpiresTime($expires);
    }
    else {
      $payment_method->card_type = $this->paymentGatewayUtility->mapCreditCardType($payment_details['accountType']);
      $payment_method->card_number = substr($payment_details['accountNumber'], -4);
      $payment_method->setReusable(FALSE);
    }

    $payment_method->save();

    return $payment_method;
  }

  /**
   * Creates a payment entity from a transaction.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param object $transaction
   *   The transaction object containing payment processing details.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The created payment.
   */
  public function createPaymentFromTransaction(OrderInterface $order, object $transaction): PaymentInterface {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'state' => $transaction->transactionType === TransactionRequest::AUTH_CAPTURE ? 'completed' : 'authorization',
      'amount' => new Price((string) $transaction->authAmount, $order->getBalance()->getCurrencyCode()),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $transaction->transId,
      'payment_method' => $order->get('payment_method')->getString(),
      'test' => $this->getMode() == 'test',
      'remote_state' => $transaction->transactionStatus ?? NULL,
      'avs_response_code' => $transaction->AVSResponse ?? NULL,
    ]);
    $payment->save();

    return $payment;
  }

  /**
   * Gets the customer's payment profile based on payment details.
   *
   * @param string $profile_id
   *   Customer profile ID.
   * @param array $payment_details
   *   Payment details.
   *
   * @return array|null
   *   Customer payment profile or NULL if not found.
   */
  protected function getCustomerPaymentProfileByPaymentDetails(string $profile_id, array $payment_details) {
    $payment_profiles = $this->getCustomerPaymentProfiles($profile_id);
    if (empty($payment_profiles)) {
      return NULL;
    }

    foreach ($payment_profiles as $payment_profile) {
      $payment = $payment_profile->payment;
      if (
        $payment->creditCard->cardNumber == $payment_details['accountNumber'] &&
        $payment->creditCard->cardType == $payment_details['accountType']
      ) {
        return $payment_profile;
      }
    }

    return NULL;
  }

  /**
   * Gets customer payment profiles.
   *
   * @param string $profile_id
   *   The customer profile ID.
   *
   * @return array
   *   List of customer payment profiles.
   */
  protected function getCustomerPaymentProfiles(string $profile_id): array {
    $payment_profiles = [];

    $request = new GetCustomerProfileRequest($this->authnetConfiguration, $this->httpClient, $profile_id);
    $request->setUnmaskExpirationDate(TRUE);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      // Do not throw an exception if the API is temporarily unavailable.
      ErrorHelper::logResponse($response);
      return $payment_profiles;
    }

    if (isset($response->profile->paymentProfiles)) {
      $payment_profiles = is_array($response->profile->paymentProfiles) ? $response->profile->paymentProfiles : [$response->profile->paymentProfiles];
    }

    return $payment_profiles;
  }

  /**
   * Creates a customer profile with a transaction ID.
   *
   * @param string $trans_id
   *   The transaction ID.
   *
   * @return string
   *   Customer profile ID.
   */
  protected function createCustomerProfileFromTransaction(string $trans_id): string {
    $request = new CreateCustomerProfileFromTransactionRequest($this->authnetConfiguration, $this->httpClient, $trans_id);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      throw PaymentGatewayException::createForPayment(NULL, $message->getText());
    }

    return $response->customerProfileId;
  }

  /**
   * Get transaction details from Authorize.net.
   *
   * @param string $transaction_id
   *   The Authorize.net payment transaction ID.
   *
   * @return mixed
   *   The payment transaction object, if successful.
   *
   * @see https://developer.authorize.net/api/reference/index.html#transaction-reporting-get-transaction-details
   */
  public function getPaymentTransactionDetails(string $transaction_id) {
    $request = new GetTransactionDetailsRequest($this->authnetConfiguration, $this->httpClient, $transaction_id);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      throw PaymentGatewayException::createForPayment(NULL, $message->getText());
    }

    if (!empty($response->getErrors())) {
      $message = $response->getErrors()[0];
      throw new HardDeclineException($message->getText());
    }

    return $response->transaction ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $owner = $payment_method->getOwner();
    $customer_id = $this->getRemoteCustomerId($owner);
    if (empty($customer_id)) {
      $customer_id = $this->paymentGatewayUtility->getPaymentMethodCustomerId($payment_method);
    }

    $request = new DeleteCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
    $request->setCustomerProfileId($customer_id);
    $request->setCustomerPaymentProfileId($this->paymentGatewayUtility->getRemoteProfileId($payment_method));
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      ErrorHelper::logResponse($response);
      $message = $response->getMessages()[0];
      // If the error is not "record not found" throw an error.
      if ($message->getCode() != 'E00040') {
        throw new InvalidResponseException("Unable to delete payment method");
      }
    }

    $payment_method->delete();
  }

  /**
   * Gets an Accept Payment page request token.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param bool $capture
   *   Whether the created payment should be captured (VS authorized only).
   * @param \Drupal\commerce_price\Price $amount
   *   The amount to capture or authorize.
   *
   * @return string|null
   *   The token value on success; null otherwise.
   */
  public function getPaymentPageRequestToken(OrderInterface $order, bool $capture, Price $amount): ?string {
    // Transaction request.
    $transaction_request = new TransactionRequest([
      'transactionType' => $capture ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
      'amount' => $amount->getNumber(),
    ]);
    // Create a Customer Profile from a Transaction.
    $customer = $order->getCustomer();
    if ($customer_id = $this->getRemoteCustomerId($customer)) {
      $transaction_request->addData('profile', (new Profile([
        'customerProfileId' => $customer_id,
      ]))->toArray());
    }

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
    $billing_address = $billing_profile ? $this->paymentGatewayUtility->getFormattedAddress($billing_profile) : NULL;
    if (!empty($billing_address)) {
      $transaction_request->addDataType(new BillTo($billing_address));
    }

    // Shipping details.
    $profiles = $order->collectProfiles();
    $shipping_address = isset($profiles['shipping']) ? $this->paymentGatewayUtility->getFormattedAddress($profiles['shipping'], 'shipping') : NULL;
    if (!empty($shipping_address)) {
      $transaction_request->addDataType(new ShipTo($shipping_address));
    }

    // Adding order information to the transaction.
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
    ]));
    $transaction_request->addData('customerIP', $order->getIpAddress());

    // Adding line items.
    $line_items = $this->paymentGatewayUtility->getOrderLineItems($order);
    foreach ($line_items as $line_item) {
      $transaction_request->addLineItem($line_item);
    }

    // Adding tax information to the transaction.
    $transaction_request->addData('tax', $this->paymentGatewayUtility->getOrderTax($order)->toArray());
    $transaction_request->addData('shipping', $this->paymentGatewayUtility->getOrderShipping($order)->toArray());

    $event = new TransactionRequestEvent($order, $transaction_request);
    $this->eventDispatcher->dispatch($event, AuthorizeNetEvents::CREATE_TRANSACTION_REQUEST);

    $request = new GetHostedPaymentPageRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest($transaction_request);
    $hosted_settings = new HostedPaymentSettings();
    $hosted_settings->addSetting(new Setting('hostedPaymentReturnOptions', [
      'showReceipt' => FALSE,
    ]));
    $hosted_settings->addSetting(new Setting('hostedPaymentPaymentOptions', [
      'cardCodeRequired' => (bool) $this->configuration['card_code_required'],
      'showCreditCard' => TRUE,
      'showBankAccount' => FALSE,
    ]));
    $hosted_settings->addSetting(new Setting('hostedPaymentSecurityOptions', [
      'captcha' => (bool) $this->configuration['captcha_security'],
    ]));
    $hosted_settings->addSetting(new Setting('hostedPaymentBillingAddressOptions', [
      'show' => FALSE,
    ]));
    $hosted_settings->addSetting(new Setting('hostedPaymentCustomerOptions', [
      'addPaymentProfile' => TRUE,
    ]));
    $hosted_settings->addSetting(new Setting('hostedPaymentOrderOptions', [
      'show' => FALSE,
    ]));
    $hosted_settings->addSetting(new Setting('hostedPaymentIFrameCommunicatorUrl', [
      'url' => Url::fromRoute('commerce_authnet.iframe_communicator', [], ['absolute' => TRUE, 'https' => TRUE])->toString(),
    ]));

    $event = new HostedPaymentSettingsEvent($order, $hosted_settings);
    $this->eventDispatcher->dispatch($event, AuthorizeNetEvents::HOSTED_PAYMENT_SETTINGS);

    $request->setHostedPaymentSettings($hosted_settings);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      ErrorHelper::logResponse($response);
      throw new InvalidResponseException('Unable to get payment page request token.');
    }

    return $response->token;
  }

  /**
   * Gets the Accept Hosted checkout form.
   *
   * @param array $form
   *   The checkout form element.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The context data used to build the form.
   *
   * @return array
   *   The Accept Hosted checkout form.
   */
  public function getAcceptHostedCheckoutForm(array $form, array $contexts): array {
    // Extract the order from the context.
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $contexts['order']->getContextValue();
    // Determine the transaction type from the context.
    if (isset($contexts['capture'])) {
      $capture = $contexts['capture']->getContextValue();
    }
    else {
      /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
      $checkout_flow = $order->get('checkout_flow')->entity;
      $configuration = $checkout_flow->getPlugin()->getConfiguration();
      $capture = isset($configuration['panes']['payment_process']) ? $configuration['panes']['payment_process']['capture'] : TRUE;
    }
    // Determine the translation amount from the context.
    if (isset($contexts['amount'])) {
      $amount = $contexts['amount']->getContextValue()->toPrice();
    }
    else {
      $amount = $order->getTotalPrice();
    }
    // Determine the 'continueUrl'.
    if (isset($contexts['continueUrl'])) {
      $continue_url = $contexts['continueUrl']->getContextValue();
    }
    else {
      $continue_url = Url::fromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $order->id(),
        'step' => 'review',
      ], ['absolute' => TRUE])->toString();
    }
    // Determine the 'cancelUrl'.
    if (isset($contexts['cancelUrl'])) {
      $cancel_url = $contexts['cancelUrl']->getContextValue();
    }
    else {
      $cancel_url = Url::fromRoute('commerce_payment.checkout.cancel', [
        'commerce_order' => $order->id(),
        'step' => 'review',
      ], ['absolute' => TRUE])->toString();
    }
    // Set the iframe wrapper.
    $iframe_wrapper_id = 'accept-hosted-iframe-wrapper';
    $iframe_target_id = 'accept-hosted-iframe';

    // Hide the submit button as we will be using the Pay button
    // inside the iframe.
    $form['actions']['next']['#attributes']['class'][] = 'js-hide';
    $form['#attributes']['class'][] = 'authorize-net-accept-hosted-form';
    $form['#attributes']['target'] = $iframe_target_id;
    $form['#attached']['library'][] = 'commerce_authnet/form-accept-hosted';
    $form['#attached']['drupalSettings']['commerceAuthorizeNet'] = [
      'iframeWrapperId' => $iframe_wrapper_id,
      'iframeTargetId' => $iframe_target_id,
      'tokenSubmitAction' => $this->getPaymentFormUrl(),
      'continueUrl' => $continue_url,
      'cancelUrl' => $cancel_url,
      'paymentToken' => $this->getPaymentPageRequestToken($order, $capture, $amount),
    ];
    $form['accept_hosted'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment'),
      '#open' => TRUE,
      '#collapsible' => FALSE,
    ];
    $form['accept_hosted']['hosted_iframe'] = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'src' => '',
        'id' => $iframe_target_id,
        'name' => $iframe_target_id,
        'width' => '100%',
        'frameborder' => '0',
        'scrolling' => 'no',
        'loading' => 'eager',
      ],
      '#prefix' => "<div id='$iframe_wrapper_id'>",
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * Gets the payment form URL.
   *
   * @return string
   *   Payment form URL.
   */
  public function getPaymentFormUrl(): string {
    if ($this->getMode() === 'test') {
      return 'https://test.authorize.net/payment/payment';
    }
    else {
      return 'https://accept.authorize.net/payment/payment';
    }
  }

}
