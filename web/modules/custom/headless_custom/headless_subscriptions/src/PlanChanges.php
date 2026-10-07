<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\headless_access\PortalRoles;

/** Quoting, consent, enrollment eligibility and recoverable mid-cycle payments. */
trait PlanChanges {
  private function changeKind(array $current, array $target): string {
    $oldLimit = $current['patientLimit']; $newLimit = $target['patientLimit'];
    $losesCapacity = $newLimit !== NULL && ($oldLimit === NULL || $newLimit < $oldLimit);
    $losesFeature = ($current['ecommerce'] && !$target['ecommerce']) || ($current['laser'] && !$target['laser']);
    if ($losesCapacity || $losesFeature) return 'downgrade';
    $gainsCapacity = $oldLimit !== NULL && ($newLimit === NULL || $newLimit > $oldLimit);
    $gainsFeature = (!$current['ecommerce'] && $target['ecommerce']) || (!$current['laser'] && $target['laser']);
    $costsMore = $target['amountMinor'] * $current['intervalMonths'] > $current['amountMinor'] * $target['intervalMonths'];
    return $gainsCapacity || $gainsFeature || $costsMore ? 'upgrade' : 'downgrade';
  }
  private function changeFingerprint(array $p): string {
    return hash('sha256', json_encode([$p['id'], $p['plan'], $p['paid_until'], $p['subscription_id'], $p['renewal_number']], JSON_THROW_ON_ERROR));
  }
  private function changePurchase(int $uid): array {
    $this->requireAccount($uid);
    $p = $this->repository->current($uid);
    if (!$p || $p['state'] !== 'active' || $p['cancel_requested'] || !$p['subscription_id'] || !empty($p['review_reason'])) throw new SubscriptionException('plan_change_unavailable', 'An active, renewing subscription is required to change plans.', 409);
    $this->sameContext($p);
    if (!empty($p['pending_change'])) throw new SubscriptionException('plan_change_pending', 'A plan change is already pending. Refresh its status before requesting another change.', 409);
    if ((int) $p['paid_until'] <= $this->time->getCurrentTime() + 86400) throw new SubscriptionException('renewal_too_close', 'Plan changes must be requested at least 24 hours before renewal. Try again after this renewal completes.', 409);
    return $p;
  }
  private function clinicEnrolledCount(int $clinic): int {
    return (int) $this->entities->getStorage('user')->getQuery()->accessCheck(FALSE)
      ->condition('field_clinic', $clinic)->condition('roles', PortalRoles::PATIENT_ENROLLED)->condition('status', 1)->count()->execute();
  }
  private function paidPeriodStart(array $p): int {
    if (!empty($p['paid_from'])) return (int) $p['paid_from'];
    $number = (int) $p['renewal_number'];
    if ($number === 0) return (int) $p['anchor'];
    $start = (int) ($p['schedule_start'] ?? 0) ?: BillingCalendar::anniversary((int) $p['anchor'], $p['plan']['intervalMonths']);
    return $number === 1 ? $start : BillingCalendar::anniversary($start, $p['plan']['intervalMonths'], $number - 1);
  }
  public function planChangeQuote(int $uid, int $planId): array {
    $p = $this->changePurchase($uid); $plan = $this->catalog->require($planId);
    if ($plan['id'] === $p['plan']['id']) throw new SubscriptionException('same_plan', 'Select a different subscription plan.', 422);
    if ($plan['currency'] !== $p['plan']['currency']) throw new SubscriptionException('currency_change', 'Plans must use the same billing currency.', 409);
    $now = $this->time->getCurrentTime(); $start = $this->paidPeriodStart($p);
    if ($start >= $p['paid_until'] || $start > $now) throw new SubscriptionException('period_review', 'The paid billing period needs review before changing plans.', 409);
    $kind = $this->changeKind($p['plan'], $plan);
    // Convert the target price to the current period before prorating. No negative charge/refund.
    $periodInterval = (int) ($p['paid_interval'] ?? 0) ?: (int) $p['plan']['intervalMonths'];
    $difference = $periodInterval * ($plan['amountMinor'] * $p['plan']['intervalMonths'] - $p['plan']['amountMinor'] * $plan['intervalMonths']);
    $amount = $kind === 'upgrade' ? max(0, (int) round($difference * ($p['paid_until'] - $now) / ($p['plan']['intervalMonths'] * $plan['intervalMonths'] * ($p['paid_until'] - $start)))) : 0;
    $count = $this->clinicEnrolledCount((int) $this->requireAccount($uid)['clinic_id']);
    $excess = $plan['patientLimit'] === NULL ? 0 : max(0, $count - $plan['patientLimit']);
    $id = bin2hex(random_bytes(16));
    $payload = ['kind' => $kind, 'plan' => $plan, 'currentPlan' => $p['plan'], 'fingerprint' => $this->changeFingerprint($p),
      'amountMinor' => $amount, 'currency' => $plan['currency'], 'expiresAt' => $now + 600, 'periodStart' => $start, 'periodInterval' => $periodInterval,
      'effectiveAt' => $kind === 'upgrade' ? $now : (int) $p['paid_until'], 'renewalAt' => (int) $p['paid_until'],
      'enrolledCount' => $count, 'mustArchive' => $excess, 'eligible' => $excess === 0];
    $this->repository->addChange(['id' => $id, 'uid' => $uid, 'purchase_id' => $p['id'], 'reference' => 'PC' . substr($id, 0, 18), 'state' => 'quoted', 'transaction_id' => NULL, 'created' => $now, 'payload' => $payload]);
    unset($payload['fingerprint']);
    return ['id' => $id] + $payload;
  }
  private function ownedChange(int $uid, string $id): array {
    $this->requireAccount($uid);
    $c = preg_match('/^[a-f0-9]{32}$/', $id) ? $this->repository->change($id) : NULL;
    if (!$c || (int) $c['uid'] !== $uid) throw new SubscriptionException('quote_not_found', 'The plan-change quote was not found. Review the plan again.', 404);
    return $c;
  }
  public function changePlan(int $uid, int $planId, string $quoteId = ''): array {
    $c = $this->ownedChange($uid, $quoteId);
    if ((int) $c['payload']['plan']['id'] !== $planId) throw new SubscriptionException('quote_mismatch', 'The quote belongs to another plan.', 409);
    if ($c['state'] === 'complete') return $this->status($uid);
    if ($c['state'] === 'declined') throw new SubscriptionException('payment_declined', 'This upgrade payment was declined. Review the plan again to start a new attempt.', 422);
    if ($c['payload']['kind'] === 'downgrade') return $this->schedulePlanChange($uid, $planId, $c);
    $lock = 'headless_subscription.account.' . $uid;
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $c = $this->ownedChange($uid, $quoteId);
      if ($c['state'] === 'complete') return $this->status($uid);
      $p = $this->repository->current($uid);
      if ($c['state'] !== 'quoted') {
        if (($p['pending_change']['quoteId'] ?? '') !== $c['id']) throw new SubscriptionException('plan_change_review', 'This upgrade requires administrator review.', 409);
        $this->finishUpgrade($p);
        return $this->status($uid);
      }
      $p = $this->changePurchase($uid);
      $this->validateChangeQuote($p, $c, $this->catalog->require($planId));
      $remote = $this->gateway->subscription($p['subscription_id']);
      if (($remote['status'] ?? '') !== 'active' || self::minor((string) ($remote['amount'] ?? '')) !== $p['plan']['amountMinor']) throw new SubscriptionException('provider_mismatch', 'Refresh your subscription before changing plans.', 409);
      if ($c['payload']['amountMinor'] > 0 && (!$p['profile_id'] || !$p['payment_profile_id'])) throw new SubscriptionException('payment_profile_missing', 'Update your payment method before upgrading.', 409);
      $p['pending_change'] = ['plan' => $c['payload']['plan'], 'effectiveAt' => (int) $p['paid_until'], 'state' => 'charging',
        'previousSubscriptionId' => $p['subscription_id'], 'newSubscriptionId' => NULL,
        'replacement' => $p['plan']['intervalMonths'] !== $c['payload']['plan']['intervalMonths'],
        'immediate' => TRUE, 'quoteId' => $c['id']];
      $c['state'] = 'charging'; $this->repository->saveChange($c); $this->repository->save($p);
      try {
        if ($c['payload']['amountMinor'] > 0) $c['transaction_id'] = $this->gateway->chargeProfile($p, $c['payload']['amountMinor'], $c['reference']);
        $c['state'] = 'paid'; $this->repository->saveChange($c);
      }
      catch (SubscriptionException $e) {
        if ($e->error === 'payment_declined') { $c['state'] = 'declined'; $p['pending_change'] = NULL; $this->repository->save($p); }
        else {
          $c['state'] = 'review';
          $id = (string) ($e->fields['transactionId'] ?? '');
          if (ctype_digit($id) && $id !== '0') $c['transaction_id'] = $id;
        }
        $this->repository->saveChange($c); throw $e;
      }
      $this->finishUpgrade($p);
      return $this->status($uid);
    }
    finally { $this->lock->release($lock); }
  }
  private function validateChangeQuote(array $p, array $c, array $plan): void {
    $count = $this->clinicEnrolledCount((int) $this->requireAccount((int) $p['uid'])['clinic_id']);
    if ($plan['patientLimit'] !== NULL && $count > $plan['patientLimit']) throw new SubscriptionException('downgrade_patients_required', 'Archive selected enrolled patients until enrollment fits the target plan.', 409);
    if ($c['payload']['expiresAt'] <= $this->time->getCurrentTime()) throw new SubscriptionException('quote_expired', 'This quote expired. Review the plan again for the current amount.', 409);
    if ($c['purchase_id'] !== $p['id'] || !hash_equals($c['payload']['fingerprint'], $this->changeFingerprint($p)) || $c['payload']['plan'] !== $plan) throw new SubscriptionException('quote_changed', 'Your plan or billing period changed. Review the plan again.', 409);
  }
  /** Resume using the stored transaction. Never send another charge or uncertain schedule. */
  private function finishUpgrade(array &$p): void {
    $pending = $p['pending_change'];
    $c = $this->repository->change($pending['quoteId']);
    if (!$c || $c['purchase_id'] !== $p['id']) throw new SubscriptionException('plan_change_review', 'The upgrade checkpoint requires review.', 409);
    if ($c['payload']['amountMinor'] > 0) {
      if (!$c['transaction_id']) throw new SubscriptionException('payment_uncertain', 'The upgrade payment is awaiting confirmation. Refresh its status; do not submit another payment.', 409);
      $t = $this->gateway->transaction($c['transaction_id']);
      $this->assertUpgradeReceipt($p, $c, $t);
      $this->repository->recordPayment($p, $t, $c['payload']['plan'], $this->time->getCurrentTime(), 'upgrade');
    }
    if ($p['pending_change']['state'] === 'charging') {
      $p['pending_change']['state'] = 'updating'; $this->repository->save($p);
      $c['state'] = 'paid'; $this->repository->saveChange($c);
      if ($pending['replacement']) {
        $replacement = $p; $replacement['plan'] = $pending['plan'];
        $p['pending_change']['newSubscriptionId'] = $this->gateway->schedule($replacement);
        $this->repository->save($p);
      }
      else $this->gateway->updateRecurringAmount($p['subscription_id'], $pending['plan']['amountMinor']);
    }
    $this->confirmPlanChange($p);
    $p['paid_interval'] = $c['payload']['periodInterval'];
    $p['paid_from'] = $c['payload']['periodStart'];
    $p['plan'] = $c['payload']['plan']; $p['pending_change'] = NULL;
    $c['state'] = 'complete'; $this->repository->completeChange($p, $c);
    $this->syncRoles((int) $p['uid']);
  }
  private function assertUpgradeReceipt(array $p, array $c, array $t): void {
    if (!in_array($t['transactionStatus'] ?? '', ['capturedPendingSettlement', 'settledSuccessfully'], TRUE)
      || (string) ($t['transId'] ?? '') !== (string) $c['transaction_id'] || !empty($t['subscription']['id'])
      || self::minor((string) ($t['authAmount'] ?? $t['settleAmount'] ?? '')) !== $c['payload']['amountMinor']
      || ($t['order']['invoiceNumber'] ?? '') !== $c['reference']
      || (string) ($t['customer']['id'] ?? '') !== (string) $p['uid']) throw new SubscriptionException('payment_uncertain', 'The upgrade receipt has not been verified. Refresh its status; do not pay again.', 409);
  }
  /** Operator recovery requires a matching receipt and never repeats a charge. */
  public function recoverPlanChange(string $id, string $transactionId, ?string $subscriptionId = NULL): array {
    $c = $this->repository->change($id);
    if (!$c || $c['payload']['kind'] !== 'upgrade' || !in_array($c['state'], ['charging','review','paid','complete'], TRUE)) throw new SubscriptionException('plan_change_review', 'Only a submitted upgrade attempt can be recovered.', 409);
    $lock = 'headless_subscription.account.' . $c['uid'];
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->purchase($c['purchase_id']); $this->sameContext($p);
      if ($c['payload']['amountMinor'] > 0) {
        if ($c['transaction_id'] && $c['transaction_id'] !== $transactionId) throw new SubscriptionException('provider_mismatch', 'This upgrade already has another transaction.', 409);
        $c['transaction_id'] = $transactionId;
        $t = $this->gateway->transaction($transactionId); $this->assertUpgradeReceipt($p, $c, $t);
      }
      elseif ($transactionId !== 'none') throw new SubscriptionException('provider_mismatch', 'Use none for a zero-charge upgrade recovery.', 409);
      if ($c['state'] === 'complete') return $this->status((int) $c['uid']);
      if (($p['pending_change']['quoteId'] ?? '') !== $c['id']) throw new SubscriptionException('plan_change_review', 'The pending upgrade no longer matches.', 409);
      $this->repository->saveChange($c);
      if ($subscriptionId !== NULL) {
        if (!$p['pending_change']['replacement']) throw new SubscriptionException('provider_mismatch', 'This upgrade does not need a replacement schedule.', 409);
        $p['pending_change']['newSubscriptionId'] = $subscriptionId;
        $p['pending_change']['state'] = 'updating';
      }
      $this->finishUpgrade($p);
      return $this->status((int) $c['uid']);
    }
    finally { $this->lock->release($lock); }
  }
  /** Upgrade webhooks can also recover a lost response by the unique invoice. */
  private function processUpgradeTransaction(array $t): bool {
    $reference = (string) ($t['order']['invoiceNumber'] ?? '');
    $c = $this->repository->changeByReference($reference);
    if (!$c || $c['payload']['kind'] !== 'upgrade') return FALSE;
    $lock = 'headless_subscription.account.' . $c['uid'];
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->purchase($c['purchase_id']); $this->sameContext($p);
      if (!in_array($c['state'], ['charging','review','paid','complete'], TRUE)) return TRUE;
      $id = (string) ($t['transId'] ?? '');
      if ($c['transaction_id'] && $c['transaction_id'] !== $id) throw new SubscriptionException('provider_mismatch', 'Another transaction is already attached to this upgrade.', 409);
      if (($t['transactionStatus'] ?? '') === 'voided' && $c['transaction_id'] === $id) {
        $p['review_reason'] = 'upgrade_payment_voided'; $this->repository->save($p);
        $this->repository->updatePaymentStatus($p, $id, 'voided');
        return TRUE;
      }
      $c['transaction_id'] = $id; $this->assertUpgradeReceipt($p, $c, $t);
      $this->repository->saveChange($c);
      $this->repository->recordPayment($p, $t, $c['payload']['plan'], $this->time->getCurrentTime(), 'upgrade');
      if ($c['state'] !== 'complete' && ($p['pending_change']['quoteId'] ?? '') === $c['id']) $this->finishUpgrade($p);
      return TRUE;
    }
    finally { $this->lock->release($lock); }
  }
  public function planChangePatients(int $uid): array {
    $p = $this->changePurchase($uid); $clinic = (int) $this->requireAccount($uid)['clinic_id'];
    $storage = $this->entities->getStorage('user');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_clinic', $clinic)->condition('roles', PortalRoles::PATIENT_ENROLLED)->condition('status', 1)->sort('name')->execute();
    $patients = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      $row = \Drupal::service('headless_patients.patients')->toRosterRow($user);
      $patients[] = ['id' => (int) $user->id(), 'name' => $row['name'], 'email' => $row['email']];
    }
    return ['patients' => $patients];
  }
  /** Explicit doctor consent; reuse the same archival operation as the patient roster. */
  public function archivePlanChangePatients(int $uid, int $planId, array $ids): array {
    $accountLock = 'headless_subscription.account.' . $uid;
    if (!$this->lock->acquire($accountLock, 180)) throw new SubscriptionException('busy', 'Another subscription operation is in progress.', 409);
    try {
      $p = $this->changePurchase($uid); $plan = $this->catalog->require($planId);
      if ($plan['patientLimit'] === NULL || $this->clinicEnrolledCount((int) $this->requireAccount($uid)['clinic_id']) <= $plan['patientLimit']) throw new SubscriptionException('archive_unneeded', 'Patient archival is not needed for this plan change.', 409);
      if (!$ids || count($ids) > 100 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) throw new SubscriptionException('invalid_patients', 'Choose between 1 and 100 enrolled patients.', 422);
      $clinic = (int) $this->requireAccount($uid)['clinic_id'];
      $lock = 'headless_subscription.enrollment.' . $clinic;
      if (!$this->lock->acquire($lock, 120)) throw new SubscriptionException('busy', 'Another enrollment operation is in progress.', 409);
      try {
        // Validate the full selection before any roles change; no foreign clinic IDs.
        $storage = $this->entities->getStorage('user');
        foreach ($ids as $id) {
          if (!is_int($id) || $id <= 0) throw new SubscriptionException('invalid_patients', 'Choose valid patient IDs.', 422);
          $user = $storage->load($id);
          if (!$user || !$user->isActive() || !$user->hasRole(PortalRoles::PATIENT_ENROLLED) || !$user->hasField('field_clinic') || (int) $user->get('field_clinic')->target_id !== $clinic) throw new SubscriptionException('patient_not_enrolled', 'Every selected patient must be enrolled in your clinic.', 409);
        }
        foreach ($ids as $id) \Drupal::service('headless_patients.patients')->archive($clinic, $id);
      }
      finally { $this->lock->release($lock); }
      return $this->planChangeQuote($uid, $planId);
    }
    finally { $this->lock->release($accountLock); }
  }
}