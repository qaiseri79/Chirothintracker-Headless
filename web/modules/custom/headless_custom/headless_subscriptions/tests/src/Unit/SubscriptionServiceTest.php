<?php

declare(strict_types=1);
namespace Drupal\Tests\headless_subscriptions\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\headless_subscriptions\{BillingGatewayInterface, BillingCalendar, Entitlements, PlanCatalog, SubscriptionRepository, SubscriptionService, SubscriptionException};
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\{ConfigFactoryInterface, ImmutableConfig};
use Drupal\Core\Entity\{EntityTypeManagerInterface, EntityStorageInterface};
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\KeyValueStore\{KeyValueExpirableFactoryInterface, KeyValueStoreExpirableInterface};
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\{LoggerChannelFactoryInterface, LoggerChannelInterface};
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\user\UserInterface;

/** @group headless_subscriptions */
final class SubscriptionServiceTest extends UnitTestCase {
  private function fixture(): array {
    $repo = new MemoryRepository(); $gateway = new FixtureGateway();
    $plan = ['id' => 1, 'variationId' => 901, 'name' => 'Newbie', 'amountMinor' => 9900, 'price' => 99, 'currency' => 'USD', 'intervalMonths' => 1, 'patientLimit' => 4, 'ecommerce' => FALSE, 'laser' => FALSE, 'currency' => 'USD'];
    $catalog = $this->createMock(PlanCatalog::class); $catalog->method('require')->willReturnCallback(function($id) use($plan) {
      if ($id===1) return $plan;
      $terms = [2=>['Rookie',19900,1,10,FALSE],3=>['Veteran',28900,1,NULL,FALSE],4=>['All Pro',33800,1,NULL,TRUE],72=>['Annual',200000,12,NULL,FALSE]][$id];
      return array_replace($plan,['id'=>$id,'name'=>$terms[0],'amountMinor'=>$terms[1],'price'=>$terms[1]/100,'intervalMonths'=>$terms[2],'patientLimit'=>$terms[3],'ecommerce'=>TRUE,'laser'=>$terms[4]]);
    });
    $users = $this->createMock(EntityStorageInterface::class);
    $clinics = $this->createMock(EntityStorageInterface::class); $clinics->method('load')->willReturn(NULL);
    $user = $this->createMock(UserInterface::class); $user->method('getEmail')->willReturn('new-test-doctor@example.invalid'); $user->method('isActive')->willReturn(TRUE);
    $user->method('hasRole')->willReturn(FALSE); $user->method('removeRole')->willReturnSelf(); $user->method('addRole')->willReturnSelf();
    $users->method('load')->willReturn($user);
    $count = new \ArrayObject(['value' => 0]); $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'count'] as $method) $query->method($method)->willReturnSelf();
    $query->method('execute')->willReturnCallback(fn() => $count['value']); $users->method('getQuery')->willReturn($query);
    $entities = $this->createMock(EntityTypeManagerInterface::class); $entities->method('getStorage')->willReturnMap([['user', $users], ['clinic', $clinics], ['user_role', $clinics]]);
    $values = new \ArrayObject(); $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);
    $store->method('setWithExpire')->willReturnCallback(function($key, $value) use($values) { $values[$key] = $value; });
    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class); $factory->method('get')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class); $lock->method('acquire')->willReturn(TRUE);
    $now = new \ArrayObject(['value' => strtotime('2026-01-31T12:00:00-08:00')]);
    $time = $this->createMock(TimeInterface::class); $time->method('getCurrentTime')->willReturnCallback(fn() => $now['value']);
    $settings = $this->createMock(ImmutableConfig::class); $settings->method('get')->willReturnMap([['grace_days', 0], ['cart_ttl', 3600]]);
    $config = $this->createMock(ConfigFactoryInterface::class); $config->method('get')->willReturn($settings);
    $container = new ContainerBuilder(); $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class); $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class)); $container->set('logger.factory', $loggerFactory); \Drupal::setContainer($container);
    $service = new SubscriptionService($repo, $catalog, $gateway, $entities, $factory, $lock, $time, $config);
    return compact('service','repo','gateway','plan','now','count','values','lock');
  }
  private function body(array $cart, string $key = 'test-attempt-key-0001'): array {
    return ['cartId' => $cart['id'], 'idempotencyKey' => $key, 'opaqueData' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'fixture-nonce-not-a-real-payment'], 'billing' => ['firstName' => 'Test', 'lastName' => 'Doctor', 'zip' => '90001']];
  }
  public function testLateEnrollmentOutsideHeadlessApiCannotActivateAnOverLimitDowngrade(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(2,301)));
    $count['value']=4;$q=$service->planChangeQuote(301,1);$service->changePlan(301,1,$q['id']);
    $p=$repo->current(301);$count['value']=5;
    $gateway->transactions['92001']=['transId'=>'92001','transactionStatus'=>'settledSuccessfully','authAmount'=>'99.00','order'=>['invoiceNumber'=>$p['reference']],'subscription'=>['id'=>$p['subscription_id'],'payNum'=>1]];
    $event=['eventType'=>'net.authorize.payment.authcapture.created','payload'=>['id'=>'92001']];
    try{$service->processEvent($event);$this->fail('Over-limit renewal activated.');}catch(SubscriptionException $e){$this->assertSame('downgrade_patients_required',$e->error);}
    $this->assertSame(2,$repo->current(301)['plan']['id']);
    $this->assertSame('downgrade_enrollment_exceeded',$repo->current(301)['review_reason']);
    $this->assertSame(9900,$repo->ledger['92001']['amount_minor']);
    $count['value']=4;$service->processEvent($event);
    $this->assertSame(1,$repo->current(301)['plan']['id']);
    $this->assertSame('',$repo->current(301)['review_reason']);
  }
  public function testRefundOfAnUpgradeIsRecordedForReviewWithoutGrantingAnotherPeriod(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2);$service->changePlan(301,2,$q['id']);$end=$repo->current(301)['paid_until'];
    $gateway->transactions['93001']=['transId'=>'93001','refTransId'=>'91001','transactionStatus'=>'refundPendingSettlement'];
    $service->processEvent(['eventType'=>'net.authorize.payment.refund.created','payload'=>['id'=>'93001']]);
    $this->assertSame('upgrade_refund_received',$repo->current(301)['review_reason']);
    $this->assertSame($end,$repo->current(301)['paid_until']);
    $this->assertSame(1,$gateway->profileCharges);
  }
  public function testZeroChargeUpgradeScheduleCanBeRecoveredWithoutInventingAPayment(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(2,301)));
    $q=$service->planChangeQuote(301,72);$this->assertSame(0,$q['amountMinor']);$gateway->scheduleFailure=TRUE;
    try{$service->changePlan(301,72,$q['id']);}catch(SubscriptionException $e){}
    $p=$repo->current(301);
    $gateway->remote['70002']=['status'=>'active','amount'=>'2000.00','name'=>'Portal '.$p['reference'],'profile'=>['customerProfileId'=>$p['profile_id'],'customerPaymentProfileId'=>$p['payment_profile_id']],'paymentSchedule'=>['startDate'=>BillingCalendar::date($p['paid_until'])]];
    $result=$service->recoverPlanChange($q['id'],'none','70002');
    $this->assertSame(72,$result['subscription']['plan']['id']);$this->assertSame(0,$gateway->profileCharges);$this->assertCount(1,$repo->ledger);
  }
  public function testConsecutiveUpgradesUseCurrentPriceAndOriginalPaidPeriod(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(1,301)));
    $p=$repo->current(301);$now['value']=(int)(($p['anchor']+$p['paid_until'])/2);
    $q=$service->planChangeQuote(301,2);$service->changePlan(301,2,$q['id']);
    $next=$service->planChangeQuote(301,3);
    $this->assertSame(4500,$next['amountMinor']);
    $result=$service->changePlan(301,3,$next['id']);
    $this->assertNull($result['capabilities']['patientLimit']);
    $this->assertSame($p['paid_until'],$result['subscription']['paidThrough']);
    $this->assertSame(2,$gateway->profileCharges);
  }
  public function testMonthlyAnnualMonthlyUpgradeDoesNotPriceAWholeYearForRemainingMonth(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,72);$service->changePlan(301,72,$q['id']);
    $next=$service->planChangeQuote(301,4);
    $this->assertSame(17133,$next['amountMinor']);
    $result=$service->changePlan(301,4,$next['id']);
    $this->assertTrue($result['capabilities']['laser']);
    $this->assertSame(1,$repo->current(301)['paid_interval']);
    $this->assertSame($q['renewalAt'],$result['subscription']['paidThrough']);
  }
  public function testLowerRateWithMoreCapacityUnlocksWithoutRefundOrAdditionalCharge(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(2,301)));
    $q=$service->planChangeQuote(301,72);
    $this->assertSame('upgrade',$q['kind']);$this->assertSame(0,$q['amountMinor']);
    $result=$service->changePlan(301,72,$q['id']);
    $this->assertNull($result['capabilities']['patientLimit']);
    $this->assertSame(0,$gateway->profileCharges);
  }
  public function testUncertainUpgradeScheduleRequiresVerifiedOperatorRecovery(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,72);$gateway->scheduleFailure=TRUE;
    try{$service->changePlan(301,72,$q['id']);}catch(SubscriptionException $e){$this->assertSame('payment_uncertain',$e->error);}
    $p=$repo->current(301);
    try{$service->recoverPlanChange($q['id'],'91001','70001');$this->fail('Wrong schedule recovered.');}catch(SubscriptionException $e){$this->assertSame('provider_mismatch',$e->error);}
    $this->assertNull($repo->current(301)['pending_change']['newSubscriptionId']);
    $gateway->remote['70002']=['status'=>'active','amount'=>'2000.00','name'=>'Portal '.$p['reference'],'profile'=>['customerProfileId'=>$p['profile_id'],'customerPaymentProfileId'=>$p['payment_profile_id']],'paymentSchedule'=>['startDate'=>BillingCalendar::date($p['paid_until'])]];
    $result=$service->recoverPlanChange($q['id'],'91001','70002');
    $this->assertSame(72,$result['subscription']['plan']['id']);
    $this->assertSame(2,$gateway->schedules);$this->assertSame(1,$gateway->profileCharges);
  }
  public function testHeldUpgradeUnlocksOnlyAfterReceiptApproval(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2);
    $gateway->chargeFailure=new SubscriptionException('payment_uncertain','Held.',409,['transactionId'=>'91099']);
    try{$service->changePlan(301,2,$q['id']);}catch(SubscriptionException $e){}
    $c=$repo->change($q['id']);
    $gateway->transactions['91099']=['transId'=>'91099','transactionStatus'=>'FDSPendingReview','authAmount'=>'100.00','order'=>['invoiceNumber'=>$c['reference']],'customer'=>['id'=>'301']];
    try{$service->reconcile($c['purchase_id']);$this->fail('Held payment unlocked.');}catch(SubscriptionException $e){$this->assertSame('payment_uncertain',$e->error);}
    $this->assertSame(1,$service->status(301)['subscription']['plan']['id']);
    $gateway->transactions['91099']['transactionStatus']='capturedPendingSettlement';
    $service->reconcile($c['purchase_id']);
    $this->assertSame(2,$service->status(301)['subscription']['plan']['id']);
    $this->assertSame(1,$gateway->profileCharges);
  }
  public function testImmediateUpgradeChargesProratedDifferenceOnceAndKeepsRenewal(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1,301)));
    $p=$repo->current(301); $end=$p['paid_until'];
    $now['value']=(int)(($p['anchor']+$end)/2);
    $quote=$service->planChangeQuote(301,2);
    $this->assertSame('upgrade',$quote['kind']); $this->assertSame(5000,$quote['amountMinor']);
    $result=$service->changePlan(301,2,$quote['id']);
    $this->assertSame(2,$result['subscription']['plan']['id']);
    $this->assertSame(10,$result['capabilities']['patientLimit']);
    $this->assertTrue($result['capabilities']['store']);
    $this->assertSame($end,$result['subscription']['paidThrough']);
    $this->assertNull($result['subscription']['pendingPlan']);
    $this->assertSame('upgrade',$repo->ledger['91001']['kind']);
    $this->assertSame(5000,$repo->ledger['91001']['amount_minor']);
    $service->changePlan(301,2,$quote['id']);
    $this->assertSame(1,$gateway->profileCharges);
    $this->assertSame(1,$gateway->amountUpdates);
  }
  public function testQuoteExpiryCannotCharge(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2); $now['value']+=601;
    try {$service->changePlan(301,2,$q['id']); $this->fail('Expired quote charged.');} catch(SubscriptionException $e) {$this->assertSame('quote_expired',$e->error);}
    $this->assertSame(0,$gateway->profileCharges);
  }
  public function testQuoteIsBoundToOwnerAndPlan(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2);
    try {$service->changePlan(302,2,$q['id']); $this->fail('Foreign quote charged.');} catch(SubscriptionException $e) {$this->assertSame('quote_not_found',$e->error);}
    try {$service->changePlan(301,72,$q['id']); $this->fail('Quote price applied to wrong plan.');} catch(SubscriptionException $e) {$this->assertSame('quote_mismatch',$e->error);}
    $this->assertSame(0,$gateway->profileCharges);
  }
  public function testChangedPurchaseInvalidatesUpgradeQuote(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2); $p=$repo->current(301); $p['paid_until']+=86400; $repo->save($p);
    try {$service->changePlan(301,2,$q['id']); $this->fail('Stale period charged.');} catch(SubscriptionException $e) {$this->assertSame('quote_changed',$e->error);}
    $this->assertSame(0,$gateway->profileCharges);
  }
  public function testDowngradeRequiresArchivingAndRechecksCountAtConfirmation(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(2,301)));
    $count['value']=7; $q=$service->planChangeQuote(301,1);
    $this->assertSame('downgrade',$q['kind']); $this->assertSame(3,$q['mustArchive']); $this->assertFalse($q['eligible']);
    try {$service->changePlan(301,1,$q['id']); $this->fail('Over-limit downgrade scheduled.');} catch(SubscriptionException $e) {$this->assertSame('downgrade_patients_required',$e->error);}
    $this->assertSame(0,$gateway->amountUpdates);
    $count['value']=4; $result=$service->changePlan(301,1,$q['id']);
    $this->assertSame(2,$result['subscription']['plan']['id']);
    $this->assertSame(1,$result['subscription']['pendingPlan']['plan']['id']);
    $this->assertSame(0,$gateway->profileCharges);
    $count['value']=4; $this->assertSame(2,$service->quotaForClinic(401)['enrollment_status']);
    $this->assertSame(4,$service->enrollmentAllowanceForClinic(401,4)['limit']);
    $service->cancel(301);
    $this->assertSame(10,$service->enrollmentAllowanceForClinic(401,4)['limit']);
  }
  public function testDeclinedUpgradeDoesNotChangePlanAndCannotReuseAttempt(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2); $gateway->chargeFailure=new SubscriptionException('payment_declined','Declined.',422);
    try {$service->changePlan(301,2,$q['id']);} catch(SubscriptionException $e) {$this->assertSame('payment_declined',$e->error);}
    $this->assertSame(1,$service->status(301)['subscription']['plan']['id']);
    $this->assertNull($repo->current(301)['pending_change']);
    try {$service->changePlan(301,2,$q['id']); $this->fail('Declined attempt retried.');} catch(SubscriptionException $e) {$this->assertSame('payment_declined',$e->error);}
    $this->assertSame(1,$gateway->profileCharges);
  }
  public function testUpgradeWithLostRenewalUpdateResponseRecoversWithoutRecharging(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2); $gateway->loseAmountResponse=TRUE;
    try {$service->changePlan(301,2,$q['id']); $this->fail('Lost response reported success.');} catch(SubscriptionException $e) {$this->assertSame('payment_uncertain',$e->error);}
    $this->assertSame(1,$service->status(301)['subscription']['plan']['id']);
    $service->reconcile($repo->current(301)['id']);
    $this->assertSame(2,$service->status(301)['subscription']['plan']['id']);
    $this->assertSame(1,$gateway->profileCharges); $this->assertSame(1,$gateway->amountUpdates);
  }
  public function testUpgradeChargeTimeoutRecoversFromVerifiedWebhookWithoutSecondCharge(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2); $gateway->loseProfileResponse=TRUE;
    try {$service->changePlan(301,2,$q['id']);} catch(SubscriptionException $e) {$this->assertSame('payment_uncertain',$e->error);}
    try {$service->cancel(301); $this->fail('Uncertain payment discarded.');} catch(SubscriptionException $e) {$this->assertSame('upgrade_payment_review',$e->error);}
    $service->processEvent(['eventType'=>'net.authorize.payment.authcapture.created','payload'=>['id'=>'91001']]);
    $this->assertSame(2,$service->status(301)['subscription']['plan']['id']);
    $this->assertSame(1,$gateway->profileCharges);
    $service->changePlan(301,2,$q['id']);
    $this->assertSame(1,$gateway->profileCharges);
  }
  public function testWrongCustomerReceiptCannotUnlockUpgrade(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,2); $gateway->wrongUpgradeCustomer=TRUE;
    try {$service->changePlan(301,2,$q['id']); $this->fail('Foreign receipt unlocked.');} catch(SubscriptionException $e) {$this->assertSame('payment_uncertain',$e->error);}
    $this->assertSame(1,$service->status(301)['subscription']['plan']['id']);
    $this->assertSame(0,$gateway->amountUpdates);
  }
  public function testAnnualUpgradeKeepsPaidPeriodAndReplacesScheduleOnce(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    $q=$service->planChangeQuote(301,72); $end=$repo->current(301)['paid_until'];
    $this->assertSame(6767,$q['amountMinor']);
    $result=$service->changePlan(301,72,$q['id']);
    $this->assertSame(72,$result['subscription']['plan']['id']);
    $this->assertSame($end,$result['subscription']['paidThrough']);
    $this->assertSame('70002',$repo->current(301)['subscription_id']);
    $this->assertSame('canceled',$gateway->subscription('70001')['status']);
    $service->changePlan(301,72,$q['id']);
    $this->assertSame(2,$gateway->schedules); $this->assertSame(1,$gateway->profileCharges);
  }
  public function testUpgradeCannotConfirmWithoutExplicitQuote(): void {
    extract($this->fixture()); $service->checkout(301,$this->body($service->cart(1,301)));
    try {$service->changePlan(301,2); $this->fail('Charge without quoted consent.');} catch(SubscriptionException $e) {$this->assertSame('quote_not_found',$e->error);}
    $this->assertSame(0,$gateway->profileCharges);
  }
  public function testNewAccountHasBillingOnlyAccess(): void {
    extract($this->fixture()); $status = $service->status(301);
    $this->assertTrue($status['capabilities']['billingOnly']); $this->assertFalse($status['capabilities']['portalRead']); $this->assertFalse($status['capabilities']['portalWrite']);
  }
  public function testCheckoutChargesOnceAndSchedulesNextPeriod(): void {
    extract($this->fixture()); $body = $this->body($service->cart(1, 301));
    $result = $service->checkout(301, $body); $repeat = $service->checkout(301, $body);
    $this->assertSame(1, $gateway->charges); $this->assertSame(1, $gateway->schedules);
    $this->assertSame('active', $result['subscription']['status']); $this->assertTrue($result['capabilities']['portalWrite']);
    $this->assertFalse($result['capabilities']['store']); $this->assertSame(4, $result['capabilities']['patientLimit']);
    $this->assertSame($result['subscription']['id'], $repeat['subscription']['id']);
    $this->assertSame('2026-02-28', BillingCalendar::date($gateway->scheduled['paid_until']));
  }
  public function testCrossDoctorCartIsDeniedBeforePayment(): void {
    extract($this->fixture()); $body = $this->body($service->cart(1, 301));
    try { $service->checkout(302, $body); $this->fail('Foreign cart was accepted.'); }
    catch (SubscriptionException $e) { $this->assertSame(404, $e->status); }
    $this->assertSame(0, $gateway->charges);
  }
  public function testIdempotencyKeyCannotBeReusedForAnotherCart(): void {
    extract($this->fixture()); $service->checkout(301, $this->body($service->cart(1, 301)));
    try { $service->checkout(301, $this->body($service->cart(1, 301))); $this->fail('Key conflict was accepted.'); }
    catch (SubscriptionException $e) { $this->assertSame('idempotency_conflict', $e->error); }
    $this->assertSame(1, $gateway->charges);
  }
  public function testUncertainPaymentBlocksRetriesAndNewAttempts(): void {
    extract($this->fixture()); $gateway->chargeFailure = new SubscriptionException('payment_uncertain', 'Unknown result.', 503);
    $body = $this->body($service->cart(1, 301));
    try { $service->checkout(301, $body); } catch (SubscriptionException $e) { $this->assertSame('payment_uncertain', $e->error); }
    $this->assertSame('payment_review', $service->checkout(301, $body)['subscription']['status']);
    try { $service->checkout(301, $this->body($service->cart(1, 301), 'another-attempt-0002')); $this->fail('Another charge was attempted.'); }
    catch (SubscriptionException $e) { $this->assertSame('subscription_exists', $e->error); }
    $this->assertSame(1, $gateway->charges); $this->assertFalse($service->status(301)['capabilities']['portalWrite']);
  }
  public function testScheduleFailureKeepsPaidAccessWithoutAnotherCharge(): void {
    extract($this->fixture()); $gateway->scheduleFailure = TRUE; $body = $this->body($service->cart(1, 301));
    $result = $service->checkout(301, $body); $service->checkout(301, $body);
    $this->assertSame('renewal_review', $result['subscription']['status']); $this->assertTrue($result['capabilities']['portalWrite']);
    $this->assertTrue($result['subscription']['needsReview']); $this->assertSame(1, $gateway->charges); $this->assertSame(1, $gateway->schedules);
  }
  public function testCancellationRetainsPaidAccessThenBecomesReadOnly(): void {
    extract($this->fixture()); $service->checkout(301, $this->body($service->cart(1, 301))); $result = $service->cancel(301);
    $this->assertTrue($result['subscription']['cancelAtPeriodEnd']); $this->assertTrue($result['capabilities']['portalWrite']); $this->assertSame(1, $gateway->cancels);
    $now['value'] = $result['subscription']['paidThrough']; $result = $service->status(301);
    $this->assertFalse($result['capabilities']['portalWrite']); $this->assertTrue($result['capabilities']['portalRead']);
  }
  public function testDuplicateRenewalCannotExtendAccessTwice(): void {
    extract($this->fixture()); $result = $service->checkout(301, $this->body($service->cart(1, 301))); $p = $repo->current(301);
    $gateway->transactions['90002'] = ['transId' => '90002', 'transactionStatus' => 'settledSuccessfully', 'authAmount' => '99.00', 'order' => ['invoiceNumber' => $p['reference']], 'subscription' => ['id' => $p['subscription_id'], 'payNum' => 1]];
    $event = ['eventType' => 'net.authorize.payment.authcapture.created', 'payload' => ['id' => '90002']];
    $service->processEvent($event); $until = $service->status(301)['subscription']['paidThrough']; $service->processEvent($event);
    $this->assertSame($until, $service->status(301)['subscription']['paidThrough']); $this->assertSame('2026-03-28', BillingCalendar::date($until));
  }
  public function testWrongRenewalAmountNeverGrantsAnotherPeriod(): void {
    extract($this->fixture()); $service->checkout(301, $this->body($service->cart(1, 301))); $p = $repo->current(301);
    $gateway->transactions['90002'] = ['transId' => '90002', 'transactionStatus' => 'settledSuccessfully', 'authAmount' => '1.00', 'order' => ['invoiceNumber' => $p['reference']], 'subscription' => ['id' => $p['subscription_id'], 'payNum' => 1]];
    try { $service->processEvent(['eventType' => 'net.authorize.payment.authcapture.created', 'payload' => ['id' => '90002']]); $this->fail('Wrong amount accepted.'); }
    catch (SubscriptionException $e) { $this->assertSame('provider_mismatch', $e->error); }
    $this->assertSame($p['paid_until'], $repo->current(301)['paid_until']);
  }
  public function testLegacyAccountIsNotAdopted(): void {
    extract($this->fixture()); $this->assertFalse($service->managed(999));
    try { $service->status(999); $this->fail('Legacy account adopted.'); }
    catch (SubscriptionException $e) { $this->assertSame('account_not_managed', $e->error); }
  }
  public function testPlanPatientCapBlocksAtLimit(): void {
    extract($this->fixture()); $service->checkout(301, $this->body($service->cart(1, 301)));
    $count['value'] = 3; $this->assertSame(0, $service->quotaForClinic(401)['enrollment_status']);
    $count['value'] = 4; $this->assertSame(2, $service->quotaForClinic(401)['enrollment_status']);
    $this->assertNull($service->quotaForClinic(999));
  }
  public function testRepeatedVoidRevokesAccessAndCancelsOnlyOnce(): void {
    extract($this->fixture());$service->checkout(301,$this->body($service->cart(1,301)));
    $gateway->transactions['90001']=['transId'=>'90001','transactionStatus'=>'voided'];
    $event=['eventType'=>'net.authorize.payment.void.created','payload'=>['id'=>'90001']];
    $service->processEvent($event);$service->processEvent($event);
    $this->assertSame(1,$gateway->cancels);$this->assertFalse($service->status(301)['capabilities']['portalWrite']);$this->assertTrue($service->status(301)['capabilities']['portalRead']);
  }
  public function testDeclinedAttemptReturnsSameFailureWithoutAnotherCharge(): void {
    extract($this->fixture()); $gateway->chargeFailure=new SubscriptionException('payment_declined','Declined.',422);$body=$this->body($service->cart(1,301));
    foreach([1,2] as $attempt){try{$service->checkout(301,$body);$this->fail('Decline returned success.');}catch(SubscriptionException $e){$this->assertSame('payment_declined',$e->error);}}
    $this->assertSame(1,$gateway->charges);
  }
  public function testGuestCartBecomesOwnedAfterCheckout(): void {
    extract($this->fixture());$cart=$service->cart(1,0);$service->checkout(301,$this->body($cart));
    try{$service->getCart($cart['id'],302);$this->fail('Claimed cart leaked.');}catch(SubscriptionException $e){$this->assertSame('cart_not_found',$e->error);}
  }
  public function testProviderUnavailableCannotCreatePaymentAttempt(): void {
    extract($this->fixture()); $gateway->available = FALSE;
    try { $service->checkout(301, $this->body($service->cart(1, 301))); $this->fail('Unavailable provider was used.'); }
    catch (SubscriptionException $e) { $this->assertSame('billing_unavailable', $e->error); }
    $this->assertSame([], $repo->purchases);
  }
  public function testCalendarClampsMonthEndsWithoutLosingOriginalAnchor(): void {
    $anchor = strtotime('2024-01-31T12:00:00-08:00');
    $this->assertSame('2024-02-29', BillingCalendar::date(BillingCalendar::anniversary($anchor, 1)));
    $this->assertSame('2024-03-31', BillingCalendar::date(BillingCalendar::anniversary($anchor, 1, 2)));
    $leap = strtotime('2024-02-29T12:00:00-08:00');
    $this->assertSame('2025-02-28', BillingCalendar::date(BillingCalendar::anniversary($leap, 12)));
  }
  public function testStoragePrecisionIsAcceptedButFractionalCentsAreRejected(): void {
    $this->assertSame(9900, SubscriptionService::minor('99.000000'));
    $this->expectException(SubscriptionException::class); SubscriptionService::minor('99.001');
  }
  public function testLostInitialResponseCanRecoverFromVerifiedReceiptWithoutChargingAgain(): void {
    extract($this->fixture()); $gateway->chargeFailure = new SubscriptionException('payment_uncertain', 'Unknown result.', 503);
    try { $service->checkout(301, $this->body($service->cart(1, 301))); } catch (SubscriptionException $e) {}
    $p = $repo->current(301);
    $gateway->transactions['90001'] = ['transId'=>'90001', 'transactionStatus'=>'settledSuccessfully', 'authAmount'=>'99.00', 'order'=>['invoiceNumber'=>$p['reference']], 'customer'=>['id'=>'301']];
    $result = $service->recover($p['id'], '90001');
    $this->assertSame(1,$gateway->charges); $this->assertSame('active',$result['subscription']['status']); $this->assertTrue($result['capabilities']['portalWrite']);
  }
  public function testRecoveryRejectsAnotherDoctorsReceipt(): void {
    extract($this->fixture()); $gateway->chargeFailure = new SubscriptionException('payment_uncertain', 'Unknown result.', 503);
    try { $service->checkout(301, $this->body($service->cart(1, 301))); } catch (SubscriptionException $e) {}
    $p = $repo->current(301);
    $gateway->transactions['90001'] = ['transId'=>'90001', 'transactionStatus'=>'settledSuccessfully', 'authAmount'=>'99.00', 'order'=>['invoiceNumber'=>$p['reference']], 'customer'=>['id'=>'302']];
    try { $service->recover($p['id'],'90001'); $this->fail('Foreign receipt accepted.'); } catch(SubscriptionException $e) { $this->assertSame('provider_mismatch',$e->error); }
    $this->assertFalse($service->status(301)['capabilities']['portalWrite']);
  }
  public function testGraceIsExplicitAndCancelledSubscriptionsDoNotGetIt(): void {
    $p = ['state' => 'past_due', 'paid_until' => 1000, 'plan' => ['patientLimit' => NULL, 'ecommerce' => TRUE]];
    $this->assertFalse(Entitlements::evaluate($p, TRUE, 1100, 0)['portalWrite']);
    $this->assertTrue(Entitlements::evaluate($p, TRUE, 1100, 3)['inGracePeriod']);
    $p['state'] = 'cancelled'; $this->assertFalse(Entitlements::evaluate($p, TRUE, 1100, 3)['portalWrite']);
  }

  public function testPlanChangeLeavesCurrentPermissionsUntilVerifiedRenewal(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $result = $service->schedulePlanChange(301, 2);
    $this->assertSame(1, $result['subscription']['plan']['id']);
    $this->assertSame(2, $result['subscription']['pendingPlan']['plan']['id']);
    $this->assertFalse($result['capabilities']['store']);
    $this->assertSame(1, $gateway->charges);
    $this->assertSame(1, $gateway->amountUpdates);
    $service->schedulePlanChange(301, 2);
    $this->assertSame(1, $gateway->amountUpdates);
    $p = $repo->current(301);
    $gateway->transactions['90002'] = ['transId'=>'90002','transactionStatus'=>'settledSuccessfully','authAmount'=>'199.00','order'=>['invoiceNumber'=>$p['reference']],'subscription'=>['id'=>$p['subscription_id'],'payNum'=>1]];
    $service->processEvent(['eventType'=>'net.authorize.payment.authcapture.created','payload'=>['id'=>'90002']]);
    $result = $service->status(301);
    $this->assertSame(2, $result['subscription']['plan']['id']);
    $this->assertNull($result['subscription']['pendingPlan']);
    $this->assertTrue($result['capabilities']['store']);
    $this->assertSame(10, $result['capabilities']['patientLimit']);
    $this->assertCount(2, $repo->ledger);
  }
  public function testFrequencyChangeReplacesScheduleWithoutAnImmediatePayment(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $result = $service->schedulePlanChange(301, 72);
    $p = $repo->current(301);
    $this->assertSame(2, $gateway->schedules);
    $this->assertSame(1, $gateway->charges);
    $this->assertSame('canceled', $gateway->subscription('70001')['status']);
    $this->assertSame('active', $gateway->subscription('70002')['status']);
    $this->assertSame('70002', $p['subscription_id']);
    $this->assertSame(1, $result['subscription']['plan']['id']);
    $gateway->transactions['90002'] = ['transId'=>'90002','transactionStatus'=>'settledSuccessfully','authAmount'=>'2000.00','order'=>['invoiceNumber'=>$p['reference']],'subscription'=>['id'=>'70002','payNum'=>1]];
    $service->processEvent(['eventType'=>'net.authorize.payment.authcapture.created','payload'=>['id'=>'90002']]);
    $result = $service->status(301);
    $this->assertSame(72, $result['subscription']['plan']['id']);
    $this->assertSame('2027-02-28', BillingCalendar::date($result['subscription']['paidThrough']));
    $this->assertSame(9900, $repo->current(301)['initial_plan']['amountMinor']);
  }
  public function testLostPlanUpdateResponseIsRecoveredWithoutAnotherMutation(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $gateway->loseAmountResponse = TRUE;
    try { $service->schedulePlanChange(301, 2); $this->fail('Uncertain change reported success.'); } catch (SubscriptionException $e) { $this->assertSame('payment_uncertain', $e->error); }
    $this->assertTrue($service->status(301)['subscription']['needsReview']);
    $this->assertSame(1, $service->status(301)['subscription']['plan']['id']);
    $service->reconcile($repo->current(301)['id']);
    $this->assertSame('scheduled', $service->status(301)['subscription']['pendingPlan']['state']);
    $this->assertFalse($service->status(301)['subscription']['needsReview']);
    $this->assertSame(1, $gateway->amountUpdates);
  }
  public function testPlanChangeCancellationRetainsOriginalPaidAccessAndCanRefresh(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $service->schedulePlanChange(301, 2);
    $result = $service->cancel(301);
    $this->assertNull($result['subscription']['pendingPlan']);
    $this->assertTrue($result['capabilities']['portalWrite']);
    $this->assertFalse($result['capabilities']['store']);
    $service->reconcile($repo->current(301)['id']);
    $this->assertSame('cancelled', $service->status(301)['subscription']['status']);
  }
  public function testPendingFrequencyChangeCancellationStopsBothKnownSchedules(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $service->schedulePlanChange(301, 72);
    $result = $service->cancel(301);
    $this->assertNull($result['subscription']['pendingPlan']);
    $this->assertSame('canceled', $gateway->subscription('70001')['status']);
    $this->assertSame('canceled', $gateway->subscription('70002')['status']);
    $this->assertTrue($result['capabilities']['portalWrite']);
    $service->reconcile($repo->current(301)['id']);
    $this->assertSame('cancelled', $service->status(301)['subscription']['status']);
  }
  public function testPlanChangesAreBlockedInsideRenewalWindow(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $now['value'] = $repo->current(301)['paid_until'] - 3600;
    try { $service->schedulePlanChange(301, 2); $this->fail('In-flight renewal window was changed.'); } catch (SubscriptionException $e) { $this->assertSame('renewal_too_close', $e->error); }
    $this->assertSame(0, $gateway->amountUpdates);
  }
  public function testManagementHistoryIsScopedToTheDoctor(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $result = $service->management(301);
    $other = $service->management(302);
    $this->assertCount(1, $result['history']);
    $this->assertCount(1, $result['payments']);
    $this->assertSame('1111', $result['paymentMethod']['lastFour']);
    $this->assertSame([], $other['history']);
    $this->assertSame([], $other['payments']);
    $this->assertNull($other['paymentMethod']);
    $this->assertTrue($other['actions']['resubscribe']);
  }
  public function testUpdatingPaymentDoesNotGrantAnExpiredAccountPaidAccess(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $service->cancel(301);
    $now['value'] = $repo->current(301)['paid_until'];
    $service->updatePayment(301, ['opaqueData'=>['dataDescriptor'=>'COMMON.ACCEPT.INAPP.PAYMENT','dataValue'=>'fixture']]);
    $this->assertSame(1, $gateway->paymentUpdates);
    $this->assertFalse($service->status(301)['capabilities']['portalWrite']);
    $this->assertTrue($service->status(301)['capabilities']['portalRead']);
  }

  public function testFailureReadingBackAnAcceptedChangeKeepsThePendingCheckpoint(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $gateway->rejectConfirmRead = TRUE;
    try { $service->schedulePlanChange(301, 2); $this->fail('Read failure reported success.'); } catch (SubscriptionException $e) { $this->assertSame('provider_rejected', $e->error); }
    $this->assertSame('review', $repo->current(301)['pending_change']['state']);
    $this->assertTrue($service->status(301)['subscription']['needsReview']);
    $gateway->rejectConfirmRead = FALSE;
    $service->reconcile($repo->current(301)['id']);
    $this->assertSame('scheduled', $service->status(301)['subscription']['pendingPlan']['state']);
    $this->assertSame(1, $gateway->amountUpdates);
  }
  public function testUncertainReplacementCannotBeCreatedAgainOrBlindlyCancelled(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $gateway->scheduleFailure = TRUE;
    try { $service->schedulePlanChange(301, 72); } catch (SubscriptionException $e) { $this->assertSame('payment_uncertain', $e->error); }
    try { $service->schedulePlanChange(301, 72); $this->fail('Ambiguous schedule was repeated.'); } catch (SubscriptionException $e) { $this->assertSame('plan_change_pending', $e->error); }
    try { $service->cancel(301); $this->fail('Unknown replacement was ignored.'); } catch (SubscriptionException $e) { $this->assertSame('schedule_confirmation_required', $e->error); }
    $this->assertSame(2, $gateway->schedules);
    $this->assertSame(0, $gateway->cancels);
    $this->assertTrue($service->status(301)['capabilities']['portalWrite']);
  }
  public function testOperatorCanRecoverOnlyTheVerifiedReplacementSchedule(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $gateway->scheduleFailure = TRUE;
    try { $service->schedulePlanChange(301, 72); } catch (SubscriptionException $e) {}
    $p = $repo->current(301);
    $gateway->transactions['90001'] = ['transId'=>'90001','transactionStatus'=>'settledSuccessfully','authAmount'=>'99.00','order'=>['invoiceNumber'=>$p['reference']],'customer'=>['id'=>'301']];
    try { $service->recover($p['id'],'90001','70001'); $this->fail('Old schedule attached as replacement.'); } catch (SubscriptionException $e) { $this->assertSame('provider_mismatch',$e->error); }
    $this->assertNull($repo->current(301)['pending_change']['newSubscriptionId']);
    $gateway->remote['70002'] = ['status'=>'active','amount'=>'2000.00','name'=>'Portal '.$p['reference'],'profile'=>['customerProfileId'=>$p['profile_id'],'customerPaymentProfileId'=>$p['payment_profile_id']],'paymentSchedule'=>['startDate'=>BillingCalendar::date($p['paid_until'])]];
    $result = $service->recover($p['id'],'90001','70002');
    $this->assertSame('scheduled',$result['subscription']['pendingPlan']['state']);
    $this->assertSame(1,$result['subscription']['plan']['id']);
    $this->assertSame('70002',$repo->current(301)['subscription_id']);
    $this->assertSame('canceled',$gateway->subscription('70001')['status']);
    $this->assertSame(1,$gateway->charges);
  }
  public function testReconciliationCannotReactivateAVoidedPurchase(): void {
    extract($this->fixture());
    $service->checkout(301, $this->body($service->cart(1, 301)));
    $gateway->transactions['90001']=['transId'=>'90001','transactionStatus'=>'voided'];
    $p=$repo->current(301);
    $service->reconcile($p['id']);
    $service->reconcile($p['id']);
    $this->assertSame('revoked',$repo->current(301)['state']);
    $this->assertFalse($service->status(301)['capabilities']['portalWrite']);
    $this->assertSame('voided',$repo->ledger['90001']['status']);
    $this->assertSame(1,$gateway->cancels);
  }

}

class MemoryRepository extends SubscriptionRepository {
  public array $changes = [];
  public array $purchases = [];
  public array $ledger = [];
  public array $accounts = [301 => ['uid' => 301, 'clinic_id' => 401, 'ever_paid' => 0], 302 => ['uid' => 302, 'clinic_id' => 402, 'ever_paid' => 0]];
  public function __construct() {}
  public function account(int $uid): ?array { return $this->accounts[$uid] ?? NULL; }
  public function accountForClinic(int $clinic): ?array { foreach($this->accounts as $a) if ($a['clinic_id'] === $clinic) return $a; return NULL; }
  public function markPaid(int $uid): void { $this->accounts[$uid]['ever_paid'] = 1; }
  public function purchase(string $id): ?array { return $this->purchases[$id] ?? NULL; }
  public function byAttempt(int $uid, string $attempt): ?array { foreach($this->purchases as $p) if ($p['uid'] === $uid && $p['attempt'] === $attempt) return $p; return NULL; }
  public function current(int $uid): ?array { foreach(array_reverse($this->purchases) as $p) if ($p['uid'] === $uid && $p['state'] !== 'declined') return $p; return NULL; }
  public function byProvider(string $field, string $id): ?array { foreach($this->purchases as $p) if ((string) ($p[$field] ?? '') === $id) return $p; return NULL; }
  public function byReference(string $reference): ?array { foreach($this->purchases as $p) if ($p['reference'] === $reference) return $p; return NULL; }
  public function history(int $uid, int $limit = 50): array { return array_values(array_filter($this->purchases, fn($p) => $p['uid'] === $uid)); }
  public function payments(int $uid, int $limit = 50): array { return array_values(array_filter($this->ledger, fn($p) => $p['uid'] === $uid)); }
  public function updatePaymentStatus(array $p, string $transactionId, string $status): void { if (isset($this->ledger[$transactionId])) $this->ledger[$transactionId]['status'] = $status; }
  public function recordPayment(array $p, array $t, array $plan, int $now, string $kind = 'initial'): void { $this->ledger[$t['transId']] = ['transaction_id'=>$t['transId'], 'uid'=>$p['uid'], 'amount_minor'=>SubscriptionService::minor($t['authAmount']), 'currency'=>$plan['currency'], 'plan_name'=>$plan['name'], 'status'=>$t['transactionStatus'], 'paid_at'=>$now, 'renewal_number'=>$t['subscription']['payNum'] ?? 0, 'kind'=>$kind]; }
  public function change(string $id): ?array { return $this->changes[$id] ?? NULL; }
  public function changeByReference(string $reference): ?array { foreach ($this->changes as $c) if ($c['reference'] === $reference) return $c; return NULL; }
  public function completeChange(array $purchase, array $change): void { $this->save($purchase); $this->saveChange($change); }
  public function addChange(array $row): void { $this->changes[$row['id']] = $row; }
  public function saveChange(array $row): void { $this->changes[$row['id']] = $row; }
  public function addPurchase(array $row): void { $this->purchases[$row['id']] = $row; }
  public function save(array $row): void { $this->purchases[$row['id']] = $row; }
}
class FixtureGateway implements BillingGatewayInterface {
  public int $charges = 0; public int $schedules = 0; public int $cancels = 0;
  public bool $available = TRUE; public bool $scheduleFailure = FALSE;
  public ?SubscriptionException $chargeFailure = NULL;
  public array $scheduled = []; public array $transactions = []; public array $remote = [];
  public int $amountUpdates = 0; public int $paymentUpdates = 0; public bool $loseAmountResponse = FALSE; public bool $rejectConfirmRead = FALSE;
  public function publicConfiguration(): array { return ['provider' => 'fixture', 'available' => $this->available]; }
  public function paymentConfiguration(): array { return $this->publicConfiguration(); }
  public function context(): string { return 'test-context'; }
  public function charge(array $purchase, array $opaque, array $billing, string $email): string { $this->charges++; if ($this->chargeFailure) throw $this->chargeFailure; return '90001'; }
  public int $profileCharges = 0; public bool $loseProfileResponse = FALSE; public bool $wrongUpgradeCustomer = FALSE;
  public function chargeProfile(array $p, int $amountMinor, string $reference): string {
    $this->profileCharges++;
    if ($this->chargeFailure) throw $this->chargeFailure;
    $id = (string) (91000 + $this->profileCharges);
    $this->transactions[$id] = ['transId'=>$id, 'transactionStatus'=>'capturedPendingSettlement', 'authAmount'=>number_format($amountMinor/100,2,'.',''), 'order'=>['invoiceNumber'=>$reference], 'customer'=>['id'=>$this->wrongUpgradeCustomer ? '999' : (string)$p['uid']]];
    if ($this->loseProfileResponse) throw new SubscriptionException('payment_uncertain','Charge response lost.',503);
    return $id;
  }
  public function profile(string $id, int $uid, string $email): array { return ['profile_id' => '80001', 'payment_profile_id' => '80002']; }
  public function schedule(array $purchase): string { $this->schedules++; $this->scheduled = $purchase; if($this->scheduleFailure) throw new SubscriptionException('payment_uncertain', 'Unknown schedule.', 503);  $id = (string) (70000 + $this->schedules); $this->remote[$id] = ['status'=>'active', 'amount'=>number_format($purchase['plan']['amountMinor']/100,2,'.',''), 'name'=>'Portal '.$purchase['reference'], 'profile'=>['customerProfileId'=>$purchase['profile_id'],'customerPaymentProfileId'=>$purchase['payment_profile_id']], 'paymentSchedule'=>['startDate'=>BillingCalendar::date($purchase['paid_until'])]]; return $id; }
  public function subscription(string $id): array { if ($this->amountUpdates && $this->rejectConfirmRead) throw new SubscriptionException('provider_rejected','Read rejected.',422); return $this->remote[$id] ?? []; }
  public function transaction(string $id): array { return $this->transactions[$id] ?? []; }
  public function cancel(string $id): void { $this->cancels++; $this->remote[$id]['status'] = 'canceled'; }
  public function updatePayment(array $p, array $opaque): void { $this->paymentUpdates++; }
  public function paymentMethod(array $p): array { return ['brand'=>'Visa','lastFour'=>'1111','expires'=>NULL]; }
  public function updateRecurringAmount(string $id, int $amountMinor): void { $this->amountUpdates++; $this->remote[$id]['amount'] = number_format($amountMinor/100,2,'.',''); if ($this->loseAmountResponse) throw new SubscriptionException('payment_uncertain','Response lost.',503); }
  public function verifySignature(string $body, string $signature): bool { return FALSE; }
}
