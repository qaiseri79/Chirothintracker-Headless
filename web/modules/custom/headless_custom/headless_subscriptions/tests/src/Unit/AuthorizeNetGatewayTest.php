<?php

declare(strict_types=1);
namespace Drupal\Tests\headless_subscriptions\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\headless_subscriptions\{AuthorizeNetGateway, SubscriptionException};
use Drupal\Core\Config\{ConfigFactoryInterface, ImmutableConfig};
use Drupal\Core\Site\Settings;
use GuzzleHttp\{Client, HandlerStack};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

/** @group headless_subscriptions */
final class AuthorizeNetGatewayTest extends UnitTestCase {
  private function gateway(array $responses, array &$history, bool $enabled = TRUE): AuthorizeNetGateway {
    new Settings(['headless_subscriptions_authorize_net' => ['environment' => 'sandbox', 'api_login_id' => 'fixture-login', 'transaction_key' => 'fixture-key', 'public_client_key' => 'fixture-public-key', 'signature_key' => str_repeat('ab', 64)]]);
    $handler = HandlerStack::create(new MockHandler($responses)); $handler->push(Middleware::history($history));
    $config = $this->createMock(ConfigFactoryInterface::class); $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->with('checkout_enabled')->willReturn($enabled); $config->method('get')->willReturn($settings);
    return new AuthorizeNetGateway(new Client(['handler' => $handler]), $config);
  }
  public function testChargeUsesSandboxOpaqueTokenAndServerPrice(): void {
    $history = []; $gateway = $this->gateway([new Response(200, [], json_encode(['messages' => ['resultCode' => 'Ok'], 'transactionResponse' => ['responseCode' => '1', 'transId' => '12345']]))], $history);
    $id = $gateway->charge(['plan' => ['amountMinor' => 9900], 'uid' => 301, 'reference' => 'PSFIXTURE123'], ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'fake-nonce'], ['firstName' => 'Test', 'lastName' => 'Doctor', 'zip' => '90001'], 'new@example.invalid');
    $this->assertSame('12345', $id); $this->assertSame('apitest.authorize.net', $history[0]['request']->getUri()->getHost());
    $body = json_decode((string) $history[0]['request']->getBody(), TRUE)['createTransactionRequest']['transactionRequest'];
    $this->assertSame('99.00', $body['amount']); $this->assertArrayHasKey('opaqueData', $body['payment']); $this->assertArrayNotHasKey('creditCard', $body['payment']);
  }
  public function testUpgradeChargeUsesOnlyStoredProfileAndQuotedAmount(): void {
    $history=[];
    $gateway=$this->gateway([new Response(200,[],json_encode(['messages'=>['resultCode'=>'Ok'],'transactionResponse'=>['responseCode'=>'1','transId'=>'12345']]))],$history);
    $id=$gateway->chargeProfile(['uid'=>301,'profile_id'=>'80001','payment_profile_id'=>'80002'],5000,'PCFIXTURE123');
    $this->assertSame('12345',$id);
    $body=json_decode((string)$history[0]['request']->getBody(),TRUE)['createTransactionRequest']['transactionRequest'];
    $this->assertSame('50.00',$body['amount']);
    $this->assertSame(['customerProfileId'=>'80001','paymentProfile'=>['paymentProfileId'=>'80002']],$body['profile']);
    $this->assertSame('PCFIXTURE123',$body['order']['invoiceNumber']);
    $this->assertArrayNotHasKey('payment',$body);
  }
  public function testScheduleStartsAfterPaidFirstPeriod(): void {
    $history=[]; $gateway=$this->gateway([new Response(200, [], '{"messages":{"resultCode":"Ok"},"subscriptionId":"789"}')],$history);
    $id=$gateway->schedule(['reference'=>'PSFIXTURE','paid_until'=>strtotime('2026-02-28T00:00:00-08:00'),'plan'=>['intervalMonths'=>1,'amountMinor'=>9900],'profile_id'=>'123','payment_profile_id'=>'456']);
    $body=json_decode((string)$history[0]['request']->getBody(),TRUE)['ARBCreateSubscriptionRequest']['subscription'];
    $this->assertSame('789',$id);$this->assertSame('2026-02-28',$body['paymentSchedule']['startDate']);$this->assertSame('months',$body['paymentSchedule']['interval']['unit']);$this->assertSame('99.00',$body['amount']);
  }
  public function testSignatureRejectsTampering(): void {
    $history=[];$gateway=$this->gateway([],$history);$body='{"notificationId":"fixture"}';$signature='sha512='.hash_hmac('sha512',$body,hex2bin(str_repeat('ab',64)));
    $this->assertTrue($gateway->verifySignature($body,$signature));$this->assertFalse($gateway->verifySignature($body.' ',$signature));$this->assertFalse($gateway->verifySignature($body,'invalid'));
  }
  public function testDisablingNewCheckoutDoesNotDisableNotifications(): void {
    $history=[];$gateway=$this->gateway([],$history,FALSE);$body='{}';$signature='sha512='.hash_hmac('sha512',$body,hex2bin(str_repeat('ab',64)));
    $this->assertFalse($gateway->publicConfiguration()['available']);$this->assertTrue($gateway->verifySignature($body,$signature));
  }
  public function testHeldPaymentIsNotTreatedAsPaid(): void {
    $history=[];$gateway=$this->gateway([new Response(200,[], '{"messages":{"resultCode":"Ok"},"transactionResponse":{"responseCode":"4","transId":"123"}}')],$history);
    $this->expectException(SubscriptionException::class);
    $gateway->charge(['plan'=>['amountMinor'=>9900],'uid'=>301,'reference'=>'PSFIXTURE'],['dataDescriptor'=>'COMMON.ACCEPT.INAPP.PAYMENT','dataValue'=>'fake'],['firstName'=>'T','lastName'=>'D','zip'=>'90001'],'new@example.invalid');
  }
  public function testSubscriptionProfileIsNormalizedFromProviderShape(): void {
    $history=[];$gateway=$this->gateway([new Response(200,[],json_encode(['messages'=>['resultCode'=>'Ok'],'subscription'=>['name'=>'Portal PSFIXTURE','profile'=>['customerProfileId'=>'123','paymentProfile'=>['customerPaymentProfileId'=>'456']],'arbTransactions'=>[['transId'=>'789','payNum'=>1]]]]))],$history);
    $subscription=$gateway->subscription('70001');
    $this->assertSame('456',$subscription['profile']['customerPaymentProfileId']);$this->assertSame('789',$subscription['arbTransactions'][0]['transId']);
  }
  public function testPublicConfigurationNeverExposesSecretKeys(): void {
    $history=[];$gateway=$this->gateway([],$history);$public=$gateway->publicConfiguration();
    $this->assertSame('fixture-public-key',$public['publicClientKey']);$this->assertArrayNotHasKey('transaction_key',$public);$this->assertArrayNotHasKey('signature_key',$public);
  }
  public function testMaskedPaymentDetailsNeverReturnRawProviderFields(): void {
    $history=[];
    $gateway=$this->gateway([new Response(200,[],json_encode(['messages'=>['resultCode'=>'Ok'],'paymentProfile'=>['billTo'=>['address'=>'Private billing address'],'payment'=>['creditCard'=>['cardNumber'=>'XXXX1111','expirationDate'=>'XXXX','cardType'=>'Visa']]]]))],$history);
    $method=$gateway->paymentMethod(['profile_id'=>'123','payment_profile_id'=>'456']);
    $this->assertSame(['brand'=>'Visa','lastFour'=>'1111','expires'=>NULL],$method);
    $this->assertArrayNotHasKey('cardNumber',$method);
    $this->assertArrayNotHasKey('billTo',$method);
  }
  public function testRecurringAmountUpdateUsesServerAmountAndKeepsIntervalUnchanged(): void {
    $history=[];$gateway=$this->gateway([new Response(200,[],'{"messages":{"resultCode":"Ok"}}')],$history);
    $gateway->updateRecurringAmount('70001',19900);
    $body=json_decode((string)$history[0]['request']->getBody(),TRUE)['ARBUpdateSubscriptionRequest'];
    $this->assertSame('70001',$body['subscriptionId']);
    $this->assertSame(['amount'=>'199.00'],$body['subscription']);
  }
  public function testPaymentUpdatePreservesBillingAndUsesOnlyOpaqueToken(): void {
    $history=[];$gateway=$this->gateway([
      new Response(200,[],json_encode(['messages'=>['resultCode'=>'Ok'],'paymentProfile'=>['billTo'=>['firstName'=>'Test','zip'=>'46214'],'payment'=>['creditCard'=>['cardNumber'=>'XXXX1111']]]])),
      new Response(200,[],'{"messages":{"resultCode":"Ok"}}'),
    ],$history);
    $gateway->updatePayment(['profile_id'=>'123','payment_profile_id'=>'456'],['dataDescriptor'=>'COMMON.ACCEPT.INAPP.PAYMENT','dataValue'=>'nonce']);
    $profile=json_decode((string)$history[1]['request']->getBody(),TRUE)['updateCustomerPaymentProfileRequest']['paymentProfile'];
    $this->assertSame('46214',$profile['billTo']['zip']);
    $this->assertSame(['opaqueData'=>['dataDescriptor'=>'COMMON.ACCEPT.INAPP.PAYMENT','dataValue'=>'nonce']],$profile['payment']);
    $this->assertSame('456',$profile['customerPaymentProfileId']);
  }

  public function testDisablingNewCheckoutStillAllowsExistingCardManagement(): void {
    $history=[];$gateway=$this->gateway([],$history,FALSE);
    $this->assertFalse($gateway->publicConfiguration()['available']);
    $this->assertTrue($gateway->paymentConfiguration()['available']);
    $this->assertArrayNotHasKey('transaction_key',$gateway->paymentConfiguration());
  }

}
