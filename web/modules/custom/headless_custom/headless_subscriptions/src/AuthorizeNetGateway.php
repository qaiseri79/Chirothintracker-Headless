<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\Core\Site\Settings;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/** No legacy gateway credentials are read, and no raw card information is accepted. */
final class AuthorizeNetGateway implements BillingGatewayInterface {
  public function __construct(private readonly ClientInterface $http, private readonly ConfigFactoryInterface $config) {}
  private function credentials(): array {
    $credentials = Settings::get('headless_subscriptions_authorize_net', []);
    $environment = $credentials['environment'] ?? 'sandbox';
    if (!in_array($environment, ['sandbox', 'live'], TRUE)) throw new SubscriptionException('billing_configuration', 'Invalid billing environment.', 503);
    if (empty($credentials['api_login_id']) || empty($credentials['transaction_key'])) {
      throw new SubscriptionException('billing_unavailable', 'Subscription payments have not been configured.', 503);
    }
    $credentials['environment'] = $environment;
    return $credentials;
  }
  public function context(): string {
    $c = $this->credentials();
    return hash('sha256', $c['environment'] . ':' . $c['api_login_id']);
  }
  public function publicConfiguration(): array { return $this->configuration(TRUE); }
  public function paymentConfiguration(): array { return $this->configuration(FALSE); }
  private function configuration(bool $requireCheckout): array {
    try {
      $c = $this->credentials();
      if (($requireCheckout && !$this->config->get('headless_subscriptions.settings')->get('checkout_enabled')) || empty($c['public_client_key'])) return ['provider' => 'authorize_net', 'available' => FALSE];
      return ['provider' => 'authorize_net', 'available' => TRUE, 'environment' => $c['environment'], 'apiLoginId' => $c['api_login_id'], 'publicClientKey' => $c['public_client_key'], 'acceptJsUrl' => $c['environment'] === 'sandbox' ? 'https://jstest.authorize.net/v1/Accept.js' : 'https://js.authorize.net/v1/Accept.js'];
    }
    catch (SubscriptionException $e) { return ['provider' => 'authorize_net', 'available' => FALSE]; }
  }
  private function call(string $operation, array $payload, string $reference = ''): array {
    $c = $this->credentials();
    $request = ['merchantAuthentication' => ['name' => $c['api_login_id'], 'transactionKey' => $c['transaction_key']]];
    if ($reference !== '') $request['refId'] = $reference;
    $request += $payload;
    try {
      $response = $this->http->request('POST', $c['environment'] === 'sandbox' ? 'https://apitest.authorize.net/xml/v1/request.api' : 'https://api.authorize.net/xml/v1/request.api', ['json' => [$operation => $request], 'connect_timeout' => 5, 'timeout' => 30, 'headers' => ['Accept' => 'application/json']]);
      $data = json_decode(ltrim((string) $response->getBody(), "\xEF\xBB\xBF"), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($data) || !isset($data['messages']['resultCode'])) throw new \UnexpectedValueException('Invalid provider response.');
      return $data;
    }
    catch (\Throwable $e) {
      // Guzzle exceptions may contain credential-bearing request bodies. Do not log them.
      throw new SubscriptionException('payment_uncertain', 'The provider response could not be confirmed. This attempt requires reconciliation; do not submit another payment.', 503);
    }
  }
  private function success(array $data): void {
    if (($data['messages']['resultCode'] ?? '') !== 'Ok') throw new SubscriptionException('provider_rejected', 'The billing provider could not complete this operation.', 422);
  }
  public static function validateOpaque(array $data): array {
    if (($data['dataDescriptor'] ?? '') !== 'COMMON.ACCEPT.INAPP.PAYMENT' || !is_string($data['dataValue'] ?? NULL) || $data['dataValue'] === '' || strlen($data['dataValue']) > 8192) {
      throw new SubscriptionException('payment_token_required', 'Provide a valid Authorize.Net payment token.', 422);
    }
    return ['dataDescriptor' => $data['dataDescriptor'], 'dataValue' => $data['dataValue']];
  }
  public function charge(array $purchase, array $opaqueData, array $billing, string $email): string {
    $opaqueData = self::validateOpaque($opaqueData);
    $billTo = [];
    foreach (['firstName' => 50, 'lastName' => 50, 'company' => 50, 'address' => 60, 'city' => 40, 'state' => 40, 'zip' => 20, 'country' => 60] as $key => $max) {
      if (isset($billing[$key]) && is_string($billing[$key])) $billTo[$key] = mb_substr(trim($billing[$key]), 0, $max);
    }
    if (empty($billTo['firstName']) || empty($billTo['lastName']) || empty($billTo['zip'])) throw new SubscriptionException('billing_address_required', 'First name, last name, and billing postal code are required.', 422);
    $request = [
      'transactionType' => 'authCaptureTransaction', 'amount' => number_format($purchase['plan']['amountMinor'] / 100, 2, '.', ''),
      'payment' => ['opaqueData' => $opaqueData],
      'order' => ['invoiceNumber' => $purchase['reference'], 'description' => 'Portal subscription'],
      'customer' => ['type' => 'individual', 'id' => (string) $purchase['uid'], 'email' => $email],
      'billTo' => $billTo,
      'transactionSettings' => ['setting' => [['settingName' => 'duplicateWindow', 'settingValue' => '600']]],
    ];
    $data = $this->call('createTransactionRequest', ['transactionRequest' => $request], $purchase['reference']);
    return $this->approvedTransaction($data);
  }
  /** Customer-authorized mid-cycle charge against the existing saved profile. */
  public function chargeProfile(array $purchase, int $amountMinor, string $reference): string {
    if ($amountMinor <= 0 || empty($purchase['profile_id']) || empty($purchase['payment_profile_id'])) throw new SubscriptionException('payment_profile_missing', 'A saved payment method is required for the upgrade.', 409);
    return $this->approvedTransaction($this->call('createTransactionRequest', ['transactionRequest' => [
      'transactionType' => 'authCaptureTransaction',
      'amount' => number_format($amountMinor / 100, 2, '.', ''),
      'profile' => ['customerProfileId' => $purchase['profile_id'], 'paymentProfile' => ['paymentProfileId' => $purchase['payment_profile_id']]],
      'order' => ['invoiceNumber' => $reference, 'description' => 'Prorated portal upgrade'],
      'customer' => ['type' => 'individual', 'id' => (string) $purchase['uid']],
      'transactionSettings' => ['setting' => [['settingName' => 'duplicateWindow', 'settingValue' => '600']]],
    ]], $reference));
  }
  private function approvedTransaction(array $data): string {
    $transaction = $data['transactionResponse'] ?? [];
    $code = (string) ($transaction['responseCode'] ?? '');
    if ($code === '1' && ctype_digit((string) ($transaction['transId'] ?? '')) && (string) $transaction['transId'] !== '0') return (string) $transaction['transId'];
    if ($code === '4') throw new SubscriptionException('payment_uncertain', 'The payment is held for provider review. Do not submit another payment.', 409, ['transactionId' => (string) ($transaction['transId'] ?? '')]);
    if (in_array($code, ['2', '3'], TRUE) || ($data['messages']['resultCode'] ?? '') === 'Error') {
      // A duplicate transaction can refer to a prior successful charge, not a decline.
      foreach (($transaction['errors'] ?? []) as $error) if ((string) ($error['errorCode'] ?? '') === '11') throw new SubscriptionException('payment_uncertain', 'A duplicate transaction needs reconciliation.', 409);
      throw new SubscriptionException('payment_declined', 'Payment was not approved. Check your payment details.', 422);
    }
    throw new SubscriptionException('payment_uncertain', 'The payment could not be confirmed.', 503);
  }
  public function profile(string $transactionId, int $uid, string $email): array {
    $data = $this->call('createCustomerProfileFromTransactionRequest', ['transId' => $transactionId, 'customer' => ['merchantCustomerId' => (string) $uid, 'description' => 'Portal subscription account', 'email' => $email]]);
    $this->success($data);
    $ids = $data['customerPaymentProfileIdList'] ?? [];
    $payment = $ids[0] ?? $ids['numericString'][0] ?? NULL;
    if (!$payment || empty($data['customerProfileId'])) throw new SubscriptionException('provider_rejected', 'The recurring payment profile could not be created.', 422);
    return ['profile_id' => (string) $data['customerProfileId'], 'payment_profile_id' => (string) $payment];
  }
  public function schedule(array $purchase): string {
    $data = $this->call('ARBCreateSubscriptionRequest', ['subscription' => [
      'name' => 'Portal ' . $purchase['reference'],
      'paymentSchedule' => ['interval' => ['length' => $purchase['plan']['intervalMonths'], 'unit' => 'months'], 'startDate' => BillingCalendar::date((int) $purchase['paid_until']), 'totalOccurrences' => 9999],
      'amount' => number_format($purchase['plan']['amountMinor'] / 100, 2, '.', ''),
      'order' => ['invoiceNumber' => $purchase['reference'], 'description' => 'Portal renewal'],
      'profile' => ['customerProfileId' => $purchase['profile_id'], 'customerPaymentProfileId' => $purchase['payment_profile_id']],
    ]], $purchase['reference']);
    $this->success($data);
    if (empty($data['subscriptionId'])) throw new SubscriptionException('payment_uncertain', 'The recurring subscription needs reconciliation.', 503);
    return (string) $data['subscriptionId'];
  }
  public function subscription(string $id): array {
    $data = $this->call('ARBGetSubscriptionRequest', ['subscriptionId' => $id, 'includeTransactions' => TRUE]);
    $this->success($data);
    $subscription = $data['subscription'] ?? [];
    if (isset($subscription['profile']['paymentProfile']['customerPaymentProfileId'])) {
      $subscription['profile']['customerPaymentProfileId'] = $subscription['profile']['paymentProfile']['customerPaymentProfileId'];
    }
    if (isset($subscription['arbTransactions']['arbTransaction'])) $subscription['arbTransactions'] = $subscription['arbTransactions']['arbTransaction'];
    return $subscription;
  }
  public function transaction(string $id): array {
    $data = $this->call('getTransactionDetailsRequest', ['transId' => $id]);
    $this->success($data); return $data['transaction'] ?? [];
  }
  public function cancel(string $id): void {
    $data = $this->call('ARBCancelSubscriptionRequest', ['subscriptionId' => $id]);
    $this->success($data);
  }
  public function updatePayment(array $purchase, array $opaqueData): void {
    $opaqueData = self::validateOpaque($opaqueData);
    $current = $this->call('getCustomerPaymentProfileRequest', ['customerProfileId' => $purchase['profile_id'], 'customerPaymentProfileId' => $purchase['payment_profile_id']]);
    $this->success($current);
    $profile = [];
    // Preserve the existing billing address; omitted profile fields can be cleared.
    foreach (['customerType', 'billTo'] as $field) if (isset($current['paymentProfile'][$field])) $profile[$field] = $current['paymentProfile'][$field];
    $profile['payment'] = ['opaqueData' => $opaqueData];
    $profile['customerPaymentProfileId'] = $purchase['payment_profile_id'];
    $data = $this->call('updateCustomerPaymentProfileRequest', ['customerProfileId' => $purchase['profile_id'], 'paymentProfile' => $profile, 'validationMode' => 'liveMode']);
    $this->success($data);
  }
  public function paymentMethod(array $purchase): array {
    $data = $this->call('getCustomerPaymentProfileRequest', ['customerProfileId' => $purchase['profile_id'], 'customerPaymentProfileId' => $purchase['payment_profile_id']]);
    $this->success($data);
    $card = $data['paymentProfile']['payment']['creditCard'] ?? [];
    // Only explicitly masked provider responses may supply the last four digits.
    $lastFour = preg_match('/^[X*]+(\d{4})$/i', (string) ($card['cardNumber'] ?? ''), $matches) ? $matches[1] : NULL;
    $expiry = preg_match('/^\d{4}-\d{2}$/', (string) ($card['expirationDate'] ?? '')) ? $card['expirationDate'] : NULL;
    return ['brand' => (string) ($card['cardType'] ?? 'Card'), 'lastFour' => $lastFour, 'expires' => $expiry];
  }
  public function updateRecurringAmount(string $id, int $amountMinor): void {
    if ($amountMinor <= 0) throw new SubscriptionException('invalid_plan', 'Invalid recurring amount.', 422);
    $data = $this->call('ARBUpdateSubscriptionRequest', ['subscriptionId' => $id, 'subscription' => ['amount' => number_format($amountMinor / 100, 2, '.', '')]]);
    $this->success($data);
  }
  public function verifySignature(string $body, string $signature): bool {
    try { $c = $this->credentials(); } catch (SubscriptionException $e) { return FALSE; }
    $key = $c['signature_key'] ?? '';
    if (!is_string($key) || strlen($key) !== 128 || !ctype_xdigit($key) || !preg_match('/^sha512=([a-f0-9]{128})$/i', $signature, $matches)) return FALSE;
    return hash_equals(hash_hmac('sha512', $body, hex2bin($key)), strtolower($matches[1]));
  }
}
