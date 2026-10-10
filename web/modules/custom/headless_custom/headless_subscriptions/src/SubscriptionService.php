<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\headless_access\PortalRoles;

final class SubscriptionService {
  use PlanChanges;
  public function __construct(private readonly SubscriptionRepository $repository, private readonly PlanCatalog $catalog, private readonly BillingGatewayInterface $gateway, private readonly EntityTypeManagerInterface $entities, private readonly KeyValueExpirableFactoryInterface $keyValue, private readonly LockBackendInterface $lock, private readonly TimeInterface $time, private readonly ConfigFactoryInterface $config) {}
  public function managed(int $uid): bool { return $this->repository->account($uid) !== NULL; }
  private function requireAccount(int $uid): array {
    $account = $uid > 0 ? $this->repository->account($uid) : NULL;
    if (!$account) throw new SubscriptionException('account_not_managed', 'This account is not enrolled in the new subscription system.', 403);
    return $account;
  }
  public function status(int $uid): array {
    $account = $this->requireAccount($uid);
    $purchase = $this->repository->current($uid);
    if ($purchase && $purchase['state'] === 'active' && (int) $purchase['paid_until'] <= $this->time->getCurrentTime()) $purchase['state'] = 'past_due';
    return ['subscription' => $purchase ? $this->serialize($purchase) : NULL, 'capabilities' => Entitlements::evaluate($purchase, (bool) $account['ever_paid'], $this->time->getCurrentTime(), (int) $this->config->get('headless_subscriptions.settings')->get('grace_days')), 'billing' => $this->gateway->publicConfiguration()];
  }
  private function serialize(array $purchase): array {
    $pending = $purchase['pending_change'] ?? NULL;
    return ['id' => $purchase['id'], 'status' => $purchase['state'], 'plan' => $purchase['plan'], 'startedAt' => (int) $purchase['created'],
      'paidThrough' => (int) $purchase['paid_until'] ?: NULL, 'cancelAtPeriodEnd' => (bool) $purchase['cancel_requested'],
      'autoRenew' => !empty($purchase['subscription_id']) && !$purchase['cancel_requested'] && $purchase['state'] === 'active',
      'needsReview' => in_array($purchase['state'], ['payment_review', 'renewal_review', 'paid_pending_schedule', 'scheduling'], TRUE) || !empty($purchase['review_reason']) || ($pending && $pending['state'] !== 'scheduled') || ($purchase['cancel_requested'] && !in_array($purchase['state'], ['cancelled','expired'], TRUE)),
      'pendingPlan' => $pending ? ['plan' => $pending['plan'], 'effectiveAt' => $pending['effectiveAt'], 'state' => $pending['state'], 'kind' => !empty($pending['immediate']) ? 'upgrade' : 'downgrade'] : NULL,
    ];
  }
  /** Full billing view, scoped to this managed doctor; status() avoids provider I/O. */
  public function management(int $uid): array {
    $result = $this->status($uid);
    $result['billing'] = $this->gateway->paymentConfiguration();
    $p = $this->repository->current($uid);
    $method = NULL; $unavailable = FALSE;
    if ($p && $p['profile_id'] && $p['payment_profile_id']) {
      try { $this->sameContext($p); $method = $this->gateway->paymentMethod($p); }
      catch (SubscriptionException $e) { $unavailable = TRUE; }
    }
    $result['paymentMethod'] = $method;
    $result['paymentMethodUnavailable'] = $unavailable;
    $result['payments'] = array_map(static fn(array $row) => ['id' => $row['transaction_id'], 'amountMinor' => (int) $row['amount_minor'], 'currency' => $row['currency'], 'planName' => $row['plan_name'], 'status' => $row['status'], 'paidAt' => (int) $row['paid_at'], 'renewal' => (int) $row['renewal_number'] > 0, 'kind' => (int) $row['renewal_number'] > 0 ? 'renewal' : (($row['kind'] ?? '') ?: 'initial')], $this->repository->payments($uid));
    $result['history'] = array_map(fn(array $row) => $this->serialize($row), $this->repository->history($uid));
    $result['actions'] = [
      'updatePayment' => $p && !empty($p['profile_id']) && !empty($p['payment_profile_id']) && !in_array($p['state'], ['charging', 'payment_review', 'revoked'], TRUE),
      'cancel' => $p && !empty($p['subscription_id']) && !$p['cancel_requested'],
      'changePlan' => $p && $p['state'] === 'active' && !$p['cancel_requested'] && empty($p['pending_change']) && empty($p['review_reason']) && (int) $p['paid_until'] > $this->time->getCurrentTime() + 86400,
      'resubscribe' => (!$p || ((int) $p['paid_until'] <= $this->time->getCurrentTime() && (empty($p['subscription_id']) || $p['cancel_requested']))) && !($result['subscription']['needsReview'] ?? FALSE),
      'resume' => $p && ($p['state'] === 'cancelled' || !empty($p['cancel_requested'])) && (int) $p['paid_until'] > $this->time->getCurrentTime(),
    ];
    return $result;
  }
  public function refresh(int $uid): array {
    $this->requireAccount($uid);
    if ($p = $this->repository->current($uid)) $this->reconcile($p['id']);
    return $this->management($uid);
  }
  /** Internal renewal schedule checkpoint; public confirmations require a quote. */
  public function schedulePlanChange(int $uid, int $planId, ?array $quote = NULL): array {
    $this->requireAccount($uid);
    $plan = $this->catalog->require($planId);
    $lock = 'headless_subscription.account.' . $uid;
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    $enrollmentLock = 'headless_subscription.enrollment.' . $this->requireAccount($uid)['clinic_id'];
    $enrollmentLocked = FALSE;
    try {
      if (!$this->lock->acquire($enrollmentLock, 180)) throw new SubscriptionException('busy', 'Another enrollment operation is in progress.', 409);
      $enrollmentLocked = TRUE;
      $p = $this->repository->current($uid);
      if (!$p || $p['state'] !== 'active' || $p['cancel_requested'] || !$p['subscription_id'] || !empty($p['review_reason'])) throw new SubscriptionException('plan_change_unavailable', 'An active, renewing subscription is required to change plans.', 409);
      $this->sameContext($p);
      if (!empty($p['pending_change'])) {
        if ((int) $p['pending_change']['plan']['id'] === $planId && $p['pending_change']['state'] === 'scheduled' && (!$quote || ($p['pending_change']['quoteId'] ?? '') === $quote['id'])) return $this->status($uid);
        throw new SubscriptionException('plan_change_pending', 'A plan change is already pending. Refresh its status before requesting another change.', 409);
      }
      if ((int) $p['plan']['id'] === $planId) return $this->status($uid);
      if ((int) $p['paid_until'] <= $this->time->getCurrentTime() + 86400) throw new SubscriptionException('renewal_too_close', 'Plan changes must be requested at least 24 hours before renewal. Try again after this renewal completes.', 409);
      if ($quote) $this->validateChangeQuote($p, $quote, $plan);
      $count = $this->clinicEnrolledCount((int) $this->requireAccount($uid)['clinic_id']);
      if ($plan['patientLimit'] !== NULL && $count > $plan['patientLimit']) throw new SubscriptionException('downgrade_patients_required', 'Archive selected enrolled patients until enrollment fits the lower plan.', 409);
      $remote = $this->gateway->subscription($p['subscription_id']);
      if (($remote['status'] ?? '') !== 'active' || self::minor((string) ($remote['amount'] ?? '')) !== $p['plan']['amountMinor']) throw new SubscriptionException('provider_mismatch', 'Refresh your subscription before changing plans.', 409);
      $p['initial_plan'] ??= $p['plan'];
      $p['pending_change'] = ['plan' => $plan, 'effectiveAt' => (int) $p['paid_until'], 'state' => 'updating', 'previousSubscriptionId' => $p['subscription_id'], 'newSubscriptionId' => NULL, 'replacement' => $p['plan']['intervalMonths'] !== $plan['intervalMonths'], 'quoteId' => $quote['id'] ?? NULL, 'immediate' => FALSE];
      $this->repository->save($p);
      $mutationAccepted = FALSE;
      try {
        if ($p['pending_change']['replacement']) {
          $replacement = $p; $replacement['plan'] = $plan;
          $p['pending_change']['newSubscriptionId'] = $this->gateway->schedule($replacement);
          $p['pending_change']['state'] = 'cancelling_original'; $this->repository->save($p);
        }
        else $this->gateway->updateRecurringAmount($p['subscription_id'], $plan['amountMinor']);
        $mutationAccepted = TRUE;
        $this->confirmPlanChange($p);
        $this->repository->save($p);
      }
      catch (SubscriptionException $e) {
        $rejected = $e->error === 'provider_rejected' && !$mutationAccepted && empty($p['pending_change']['newSubscriptionId']);
        if ($rejected) $p['pending_change'] = NULL;
        else $p['pending_change']['state'] = 'review';
        $this->repository->save($p);
        throw new SubscriptionException($e->error, $rejected ? 'The provider rejected this plan change. Your current plan remains in place.' : 'The plan change needs confirmation. Refresh its status; do not request another change.', $e->status);
      }
      if ($quote) { $quote['state'] = 'complete'; $this->repository->saveChange($quote); }
      return $this->status($uid);
    }
    finally { if ($enrollmentLocked) $this->lock->release($enrollmentLock); $this->lock->release($lock); }
  }
  private function confirmPlanChange(array &$p): void {
    $pending = $p['pending_change'] ?? NULL;
    if (!$pending || $pending['state'] === 'scheduled') return;
    $id = $pending['replacement'] ? $pending['newSubscriptionId'] : $p['subscription_id'];
    if (!$id) throw new SubscriptionException('schedule_confirmation_required', 'An uncertain replacement schedule requires administrator review.', 409);
    $remote = $this->gateway->subscription($id);
    if (($remote['status'] ?? '') !== 'active' || self::minor((string) ($remote['amount'] ?? '')) !== $pending['plan']['amountMinor']) throw new SubscriptionException('provider_mismatch', 'The plan change could not be verified with the provider.', 409);
    if ($pending['replacement']) {
      if (($remote['name'] ?? '') !== 'Portal ' . $p['reference'] || (string) ($remote['profile']['customerProfileId'] ?? '') !== (string) $p['profile_id'] || (string) ($remote['profile']['customerPaymentProfileId'] ?? '') !== (string) $p['payment_profile_id'] || substr((string) ($remote['paymentSchedule']['startDate'] ?? ''), 0, 10) !== BillingCalendar::date($pending['effectiveAt'])) throw new SubscriptionException('provider_mismatch', 'The replacement schedule does not match this subscription.', 409);
      $this->repository->save($p); // The candidate ID is persisted only after ownership checks pass.
      $old = $this->gateway->subscription($pending['previousSubscriptionId']);
      if (!in_array($old['status'] ?? '', ['canceled', 'cancelled', 'expired', 'terminated'], TRUE)) $this->gateway->cancel($pending['previousSubscriptionId']);
      $old = $this->gateway->subscription($pending['previousSubscriptionId']);
      if (!in_array($old['status'] ?? '', ['canceled', 'cancelled', 'expired', 'terminated'], TRUE)) throw new SubscriptionException('payment_uncertain', 'The previous schedule cancellation requires review.', 503);
      $p['subscription_id'] = $id; $p['schedule_start'] = $pending['effectiveAt']; $p['renewal_number'] = 0;
    }
    $p['pending_change']['state'] = 'scheduled';
  }
  public function cart(int $planId, int $uid): array {
    if ($uid > 0) $this->requireAccount($uid);
    $plan = $this->catalog->require($planId);
    $id = bin2hex(random_bytes(32));
    $ttl = max(300, min(86400, (int) $this->config->get('headless_subscriptions.settings')->get('cart_ttl')));
    $cart = ['plan' => $plan, 'uid' => $uid, 'expiresAt' => $this->time->getCurrentTime() + $ttl];
    $this->keyValue->get('headless_subscriptions.cart')->setWithExpire(hash('sha256', $id), $cart, $ttl);
    return ['id' => $id] + $cart;
  }
  public function getCart(string $id, int $uid): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $id)) throw new SubscriptionException('cart_not_found', 'The subscription cart is missing or expired.', 404);
    $cart = $this->keyValue->get('headless_subscriptions.cart')->get(hash('sha256', $id));
    if (!$cart || ($cart['uid'] > 0 && (int) $cart['uid'] !== $uid)) throw new SubscriptionException('cart_not_found', 'The subscription cart is missing or expired.', 404);
    return ['id' => $id] + $cart;
  }
  public function checkout(int $uid, array $body): array {
    $this->requireAccount($uid);
    $key = $body['idempotencyKey'] ?? '';
    $cartId = $body['cartId'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $key) || !is_string($cartId)) throw new SubscriptionException('invalid_checkout', 'A cart and a stable idempotency key are required.', 422);
    // Validate every field BEFORE creating the durable charging checkpoint.
    $opaque = AuthorizeNetGateway::validateOpaque(is_array($body['opaqueData'] ?? NULL) ? $body['opaqueData'] : []);
    $billing = is_array($body['billing'] ?? NULL) ? $body['billing'] : [];
    foreach (['firstName', 'lastName', 'zip'] as $field) if (!is_string($billing[$field] ?? NULL) || trim($billing[$field]) === '') throw new SubscriptionException('billing_address_required', 'First name, last name, and billing postal code are required.', 422);
    $attempt = hash('sha256', $key);
    $lock = 'headless_subscription.account.' . $uid;
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    $cartLocked = FALSE;
    try {
      if (!$this->lock->acquire('headless_subscription.cart.' . hash('sha256', $cartId), 180)) throw new SubscriptionException('busy', 'This cart is already being checked out.', 409);
      $cartLocked = TRUE;
      if ($existing = $this->repository->byAttempt($uid, $attempt)) {
        if (!hash_equals($existing['cart_hash'], hash('sha256', $cartId))) throw new SubscriptionException('idempotency_conflict', 'This idempotency key belongs to another cart.', 409);
        if ($existing['state'] === 'declined') throw new SubscriptionException('payment_declined', 'Payment was not approved. Check your payment details.', 422);
        return $this->status($uid);
      }
      $current = $this->repository->current($uid);
      if ($current && ((int) $current['paid_until'] > $this->time->getCurrentTime() || in_array($current['state'], ['charging', 'payment_review', 'paid_pending_schedule', 'scheduling', 'renewal_review'], TRUE) || (!empty($current['subscription_id']) && !$current['cancel_requested']))) {
        throw new SubscriptionException('subscription_exists', 'Manage your existing subscription before starting another one.', 409);
      }
      $cart = $this->getCart($cartId, $uid);
      $plan = $this->catalog->require((int) $cart['plan']['id']);
      if ($plan !== $cart['plan']) throw new SubscriptionException('cart_changed', 'The plan has changed. Refresh your subscription cart before paying.', 409);
      if (!$this->gateway->publicConfiguration()['available']) throw new SubscriptionException('billing_unavailable', 'Subscription payments have not been configured.', 503);
      $context = $this->gateway->context(); // Unconfigured payments do not create a charging record.
      $user = $this->entities->getStorage('user')->load($uid);
      if (!$user || !$user->isActive()) throw new SubscriptionException('account_unavailable', 'This account cannot check out.', 403);
      $now = $this->time->getCurrentTime();
      $purchase = ['id' => bin2hex(random_bytes(16)), 'uid' => $uid, 'attempt' => $attempt, 'cart_hash' => hash('sha256', $cartId), 'reference' => '', 'plan' => $plan, 'state' => 'charging', 'context' => $context, 'paid_interval' => $plan['intervalMonths'], 'paid_from' => $now, 'anchor' => $now, 'paid_until' => 0, 'renewal_number' => 0, 'cancel_requested' => 0, 'created' => $now, 'next_sync' => $now + 3600, 'transaction_id' => NULL, 'subscription_id' => NULL, 'profile_id' => NULL, 'payment_profile_id' => NULL, 'review_reason' => '', 'pending_change' => NULL, 'initial_plan' => $plan, 'schedule_start' => BillingCalendar::anniversary($now, $plan['intervalMonths'])];
      $purchase['reference'] = 'PS' . substr($purchase['id'], 0, 18);
      $this->repository->addPurchase($purchase);
      // Claim the bearer cart before sending a payment, preventing a second account from using it.
      $cart['uid'] = $uid;
      $this->keyValue->get('headless_subscriptions.cart')->setWithExpire($purchase['cart_hash'], $cart, max(1, $cart['expiresAt'] - $now));
      try {
        $purchase['transaction_id'] = $this->gateway->charge($purchase, $opaque, $billing, $user->getEmail());
      }
      catch (SubscriptionException $e) {
        $purchase['state'] = $e->error === 'payment_declined' ? 'declined' : 'payment_review';
        $this->repository->save($purchase); throw $e;
      }
      // Persist the paid checkpoint before profile/schedule calls; never charge again on retry.
      $purchase['paid_until'] = BillingCalendar::anniversary($now, $plan['intervalMonths']);
      $purchase['state'] = 'paid_pending_schedule';
      $this->repository->save($purchase); $this->repository->markPaid($uid);
      $this->repository->recordPayment($purchase, ['transId' => $purchase['transaction_id'], 'authAmount' => number_format($plan['amountMinor'] / 100, 2, '.', ''), 'transactionStatus' => 'capturedPendingSettlement'], $plan, $now);
      $this->syncRoles($uid);
      $this->finishSchedule($purchase, $user->getEmail());
      return $this->status($uid);
    }
    finally { if ($cartLocked) $this->lock->release('headless_subscription.cart.' . hash('sha256', $cartId)); $this->lock->release($lock); }
  }
  private function finishSchedule(array $purchase, string $email): void {
    try {
      if (empty($purchase['profile_id'])) {
        $profile = $this->gateway->profile($purchase['transaction_id'], (int) $purchase['uid'], $email);
        $purchase += $profile;
        $purchase['profile_id'] = $profile['profile_id']; $purchase['payment_profile_id'] = $profile['payment_profile_id'];
        $this->repository->save($purchase);
      }
      // A lost ARB response is ambiguous. Only an operator reconciliation may retry it.
      $purchase['state'] = 'scheduling'; $this->repository->save($purchase);
      $purchase['subscription_id'] = $this->gateway->schedule($purchase);
      $purchase['state'] = 'active'; $purchase['next_sync'] = $this->time->getCurrentTime() + 3600;
      $this->repository->save($purchase);
    }
    catch (\Throwable $e) {
      $purchase['state'] = 'renewal_review'; $this->repository->save($purchase);
      // The first period was paid. Return that truth instead of inviting another payment.
      \Drupal::logger('headless_subscriptions')->warning('Recurring setup requires review for purchase @id.', ['@id' => $purchase['id']]);
    }
    $this->syncRoles((int) $purchase['uid']);
  }
  private function sameContext(array $purchase): void {
    if (!hash_equals($purchase['context'], $this->gateway->context())) throw new SubscriptionException('billing_account_changed', 'This purchase belongs to a different billing environment or account.', 409);
  }
  public function cancel(int $uid): array {
    $this->requireAccount($uid);
    $lock = 'headless_subscription.account.' . $uid;
    if (!$this->lock->acquire($lock, 90)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->current($uid);
      if (!$p) throw new SubscriptionException('subscription_missing', 'There is no subscription to cancel.', 404);
      $this->sameContext($p);
      if ($p['state'] === 'cancelled') return $this->status($uid);
      if (!empty($p['pending_change']['immediate'])) throw new SubscriptionException('upgrade_payment_review', 'Confirm the pending upgrade payment before cancellation. Refresh its status or contact support.', 409);
      if (!empty($p['pending_change']) && $p['pending_change']['replacement'] && empty($p['pending_change']['newSubscriptionId'])) throw new SubscriptionException('schedule_confirmation_required', 'An uncertain replacement schedule must be reconciled before cancellation.', 409);
      if (!$p['subscription_id']) throw new SubscriptionException('renewal_review', 'Recurring setup must be reconciled before cancellation.', 409);
      $p['cancel_requested'] = 1; $this->repository->save($p);
      $this->stopSchedules($p);
      $p['state'] = 'cancelled'; $this->repository->save($p); $this->syncRoles($uid);
      return $this->status($uid);
    }
    finally { $this->lock->release($lock); }
  }
  private function stopSchedules(array &$p): void {
    if (!empty($p['pending_change']) && $p['pending_change']['replacement'] && empty($p['pending_change']['newSubscriptionId'])) throw new SubscriptionException('schedule_confirmation_required', 'The replacement schedule needs administrator review before cancellation.', 409);
    $ids = array_unique(array_filter([$p['subscription_id'], $p['pending_change']['newSubscriptionId'] ?? NULL, $p['pending_change']['previousSubscriptionId'] ?? NULL]));
    foreach ($ids as $id) {
      $state = $this->gateway->subscription($id)['status'] ?? '';
      if (!in_array($state, ['canceled','cancelled','terminated','expired'], TRUE)) $this->gateway->cancel($id);
    }
    $p['pending_change'] = NULL;
  }
  public function updatePayment(int $uid, array $body): array {
    $this->requireAccount($uid);
    $lock = 'headless_subscription.account.' . $uid;
    if (!$this->lock->acquire($lock, 90)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->current($uid);
      if (!$p || !$p['profile_id'] || !$p['payment_profile_id']) throw new SubscriptionException('payment_profile_missing', 'There is no saved billing profile.', 409);
      $this->sameContext($p);
      $this->gateway->updatePayment($p, is_array($body['opaqueData'] ?? NULL) ? $body['opaqueData'] : []);
      return ['updated' => TRUE];
    }
    finally { $this->lock->release($lock); }
  }
  public function syncRoles(int $uid): void {
    $account = $this->repository->account($uid);
    if (!$account) return; // Critical legacy boundary.
    $caps = $this->status($uid)['capabilities'];
    $user = $this->entities->getStorage('user')->load($uid);
    if (!$user) return;
    $desired = $caps['portalWrite'] ? PortalRoles::CHIROPRACTOR_ACTIVE : PortalRoles::CHIROPRACTOR_INACTIVE;
    $opposite = $caps['portalWrite'] ? PortalRoles::CHIROPRACTOR_INACTIVE : PortalRoles::CHIROPRACTOR_ACTIVE;
    $changed = !$user->hasRole($desired) || $user->hasRole($opposite);
    if ($changed) { $user->removeRole($opposite); $user->addRole($desired); }
    if ($caps['store'] && !$user->hasRole(PortalRoles::ECOMMERCE_MANAGER)
      && $this->entities->getStorage('user_role')->load(PortalRoles::ECOMMERCE_MANAGER)) {
      $user->addRole(PortalRoles::ECOMMERCE_MANAGER); $changed = TRUE;
    }
    elseif (!$caps['store'] && $user->hasRole(PortalRoles::ECOMMERCE_MANAGER)) {
      $user->removeRole(PortalRoles::ECOMMERCE_MANAGER); $changed = TRUE;
    }
    if ($changed) {
      $user->save();
    }
    $clinic = $this->entities->getStorage('clinic')->load((int) $account['clinic_id']);
    if ($clinic && $clinic->hasField('field_ecommerce_enabled') && (bool) $clinic->get('field_ecommerce_enabled')->value !== $caps['store']) {
      $clinic->set('field_ecommerce_enabled', $caps['store']);
      $clinic->save();

      // Handle the store and gateway toggle.
      $store_storage = $this->entities->getStorage('commerce_store');
      $stores = $store_storage->loadByProperties(['uid' => $clinic->getOwnerId()]);
      if (!empty($stores)) {
        $store = reset($stores);

        // commerce_store entities do not have a published status interface natively in standard setups
        // without custom code. Disabling the payment gateway and removing ecommerce roles is sufficient.

        // Also toggle the payment gateway.
        $gateway_storage = $this->entities->getStorage('commerce_payment_gateway');
        $gateway_id = 'clinic_' . $clinic->id() . '_authnet';
        $gateway = $gateway_storage->loadOverrideFree($gateway_id);
        if ($gateway) {
          $gateway_status = $caps['store'] ? TRUE : FALSE;
          if ((bool) $gateway->status() !== $gateway_status) {
            $gateway->setStatus($gateway_status);
            $gateway->save();
          }
        }
      }
    }
  }
  public function managesClinic(int $clinicId): bool {
    return $this->repository->accountForClinic($clinicId) !== NULL;
  }
  /** Public enrollment terms only; safe for the payer's covered doctors. */
  public function enrollmentAllowanceForClinic(int $clinicId, int $enrolled): ?array {
    $account = $this->repository->accountForClinic($clinicId);
    if (!$account) return NULL;
    $purchase = $this->repository->current((int) $account['uid']);
    if (!$purchase || !array_key_exists('patientLimit', $purchase['plan'])) return NULL;
    $limit = $this->effectiveEnrollmentLimit($purchase);
    return [
      'planName' => (string) $purchase['plan']['name'],
      'scheduledPlanName' => !empty($purchase['pending_change']) && empty($purchase['pending_change']['immediate']) ? $purchase['pending_change']['plan']['name'] : NULL,
      'limit' => $limit === NULL ? NULL : max(0, (int) $limit),
      'used' => $enrolled,
      'remaining' => $limit === NULL ? NULL : max(0, (int) $limit - $enrolled),
    ];
  }

  private function effectiveEnrollmentLimit(?array $p): ?int {
    $current = $p['plan']['patientLimit'] ?? NULL;
    $pending = $p['pending_change'] ?? NULL;
    if ($pending && empty($pending['immediate']) && $pending['plan']['patientLimit'] !== NULL) return $current === NULL ? (int) $pending['plan']['patientLimit'] : min((int) $current, (int) $pending['plan']['patientLimit']);
    return $current === NULL ? NULL : (int) $current;
  }
  public function quotaForClinic(int $clinicId): ?array {
    $account = $this->repository->accountForClinic($clinicId);
    if (!$account) return NULL;
    $caps = $this->status((int) $account['uid'])['capabilities'];
    if (!$caps['portalWrite']) return ['enrollment_status' => 2, 'message' => 'An active subscription is required to enroll patients.', 'type' => 'error'];
    $count = $this->clinicEnrolledCount($clinicId);
    $limit = $this->effectiveEnrollmentLimit($this->repository->current((int) $account['uid']));
    if ($limit !== NULL && $count >= $limit) {
      $p = $this->repository->current((int) $account['uid']);
      $message = !empty($p['pending_change']) && empty($p['pending_change']['immediate'])
        ? 'Your scheduled downgrade reserves the lower plan patient allowance, and that allowance has been reached.'
        : 'Your subscription patient limit has been reached.';
      return ['enrollment_status' => 2, 'message' => $message, 'type' => 'error'];
    }
    return ['enrollment_status' => 0];
  }
  /** Reconcile only this module's purchases. Never queries commerce_subscription. */
  public function reconcile(string $id): void {
    $p = $this->repository->purchase($id);
    if (!$p) return;
    $lock = 'headless_subscription.account.' . $p['uid'];
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->purchase($id); $this->sameContext($p);
      if ($p['state'] === 'revoked') { if ($p['subscription_id']) $this->stopSchedules($p); $this->repository->save($p); $this->syncRoles((int) $p['uid']); return; }
      if ($p['cancel_requested'] && !in_array($p['state'], ['cancelled','expired'], TRUE)) { $this->stopSchedules($p); $p['state'] = 'cancelled'; $this->repository->save($p); }
      if (!empty($p['pending_change']) && $p['pending_change']['state'] !== 'scheduled' && !$p['cancel_requested']) {
        if (!empty($p['pending_change']['immediate'])) $this->finishUpgrade($p);
        else { $this->confirmPlanChange($p); $this->repository->save($p); }
      }
      if ($p['transaction_id']) {
        $initial = $this->gateway->transaction($p['transaction_id']);
        if (($initial['transactionStatus'] ?? '') === 'voided' && (string) ($initial['transId'] ?? '') === (string) $p['transaction_id']) {
          $p['state'] = 'revoked'; $p['cancel_requested'] = 1;
          $this->repository->save($p);
          $this->repository->updatePaymentStatus($p, $p['transaction_id'], 'voided');
          if ($p['subscription_id']) $this->stopSchedules($p);
          $this->repository->save($p); $this->syncRoles((int) $p['uid']); return;
        }
        if (in_array($initial['transactionStatus'] ?? '', ['capturedPendingSettlement', 'settledSuccessfully'], TRUE)) { $this->assertInitialReceipt($p, $initial); $this->repository->recordPayment($p, $initial, $p['initial_plan'] ?? $p['plan'], $this->time->getCurrentTime()); }
      }
      if ($p['subscription_id'] && $p['state'] !== 'revoked') {
        $remote = $this->gateway->subscription($p['subscription_id']);
        if (!in_array($remote['status'] ?? '', ['canceled','cancelled','terminated','expired'], TRUE) && isset($remote['amount']) && self::minor((string) $remote['amount']) !== ($p['pending_change']['plan']['amountMinor'] ?? $p['plan']['amountMinor'])) throw new SubscriptionException('provider_mismatch', 'The recurring amount requires review.', 409);
        $transactions = $remote['arbTransactions'] ?? [];
        usort($transactions, static fn(array $a, array $b) => (int) ($a['payNum'] ?? 0) <=> (int) ($b['payNum'] ?? 0));
        foreach ($transactions as $t) {
          $txn = $t['transId'] ?? '';
          if ($txn && (int) ($t['payNum'] ?? 0) > (int) $p['renewal_number']) {
            $this->applyTransaction($p, $this->gateway->transaction((string) $txn));
          }
        }
        $state = $remote['status'] ?? '';
        if (in_array($state, ['canceled', 'cancelled', 'terminated', 'expired'], TRUE)) { $p['state'] = 'cancelled'; $p['cancel_requested'] = 1; }
        elseif ($state === 'suspended') $p['state'] = 'past_due';
        elseif ($state === 'active') $p['state'] = (int) $p['paid_until'] > $this->time->getCurrentTime() ? 'active' : 'past_due';
      }
      if ($p['cancel_requested'] && $p['subscription_id'] && $p['state'] !== 'cancelled') {
        $this->gateway->cancel($p['subscription_id']); $p['state'] = 'cancelled';
      }
      if ($p['state'] === 'cancelled' && (int) $p['paid_until'] <= $this->time->getCurrentTime()) $p['state'] = 'expired';
      $p['next_sync'] = $this->time->getCurrentTime() + 3600;
      $this->repository->save($p); $this->syncRoles((int) $p['uid']);
    }
    finally { $this->lock->release($lock); }
  }
  public static function minor(string $value): int {
    if (!preg_match('/^\\d+(?:\\.\\d{1,18})?$/', $value)) throw new SubscriptionException('provider_mismatch', 'Invalid provider amount.', 409);
    [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
    if (strlen($decimal) > 2 && trim(substr($decimal, 2), '0') !== '') throw new SubscriptionException('provider_mismatch', 'Unexpected provider amount precision.', 409);
    return (int) $whole * 100 + (int) str_pad(substr($decimal, 0, 2), 2, '0');
  }
  private function applyTransaction(array &$p, array $t): void {
    if (!in_array($t['transactionStatus'] ?? '', ['capturedPendingSettlement', 'settledSuccessfully'], TRUE)) return;
    $subscription = $t['subscription'] ?? [];
    if (empty($subscription['id'])) {
      $this->assertInitialReceipt($p, $t);
      $this->repository->recordPayment($p, $t, $p['initial_plan'] ?? $p['plan'], $this->time->getCurrentTime());
      return;
    }
    if ((string) $subscription['id'] !== (string) $p['subscription_id']) throw new SubscriptionException('provider_mismatch', 'The payment subscription requires review.', 409);
    $number = (int) ($subscription['payNum'] ?? 0);
    if ($number <= 0) throw new SubscriptionException('provider_mismatch', 'The renewal number requires review.', 409);
    if ($number <= (int) $p['renewal_number']) return;
    $pending = $p['pending_change'] ?? NULL;
    $plan = $pending['plan'] ?? $p['plan'];
    if ($pending && $pending['state'] !== 'scheduled') throw new SubscriptionException('plan_change_review', 'The plan change requires confirmation before applying a payment.', 409);
    if (self::minor((string) ($t['authAmount'] ?? $t['settleAmount'] ?? '')) !== $plan['amountMinor'] || ($t['order']['invoiceNumber'] ?? '') !== $p['reference']) throw new SubscriptionException('provider_mismatch', 'The renewal receipt does not match the subscription.', 409);
    if ($pending && empty($pending['immediate']) && $plan['patientLimit'] !== NULL) {
      $count = $this->clinicEnrolledCount((int) $this->requireAccount((int) $p['uid'])['clinic_id']);
      if ($count > $plan['patientLimit']) {
        // A legacy/admin write may bypass the headless enrollment lock. Preserve the receipt for review.
        $this->repository->recordPayment($p, $t, $plan, $this->time->getCurrentTime());
        $p['review_reason'] = 'downgrade_enrollment_exceeded'; $this->repository->save($p);
        throw new SubscriptionException('downgrade_patients_required', 'Enrollment exceeds the scheduled plan. Archive selected patients before applying this renewal.', 409);
      }
    }
    if (($p['review_reason'] ?? '') === 'downgrade_enrollment_exceeded') $p['review_reason'] = '';
    $firstRenewal = (int) ($p['schedule_start'] ?? 0) ?: BillingCalendar::anniversary((int) $p['anchor'], $p['plan']['intervalMonths']);
    $p['paid_interval'] = $plan['intervalMonths'];
    $p['paid_from'] = $number === 1 ? $firstRenewal : BillingCalendar::anniversary($firstRenewal, $plan['intervalMonths'], $number - 1);
    $p['renewal_number'] = $number;
    $p['paid_until'] = BillingCalendar::anniversary($firstRenewal, $plan['intervalMonths'], $number);
    $p['plan'] = $plan; $p['pending_change'] = NULL;
    $p['state'] = $p['cancel_requested'] ? 'cancelled' : 'active';
    $this->repository->recordPayment($p, $t, $plan, $this->time->getCurrentTime());
  }
  /** Operator recovery verifies the original receipt and any previously created ARB schedule. */
  public function recover(string $id, string $transactionId, ?string $subscriptionId = NULL): array {
    $p = $this->repository->purchase($id);
    if (!$p) throw new SubscriptionException('not_found', 'Purchase not found.', 404);
    $lock = 'headless_subscription.account.' . $p['uid'];
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->purchase($id); $this->sameContext($p);
      $t = $this->gateway->transaction($transactionId);
      $this->assertInitialReceipt($p, $t);
      $this->repository->recordPayment($p, $t, $p['initial_plan'] ?? $p['plan'], $this->time->getCurrentTime());
      if ($p['transaction_id'] && (string) $p['transaction_id'] !== $transactionId) throw new SubscriptionException('provider_mismatch', 'This purchase already has another transaction.', 409);
      $p['transaction_id'] = $transactionId;
      if (!(int) $p['paid_until']) $p['paid_until'] = BillingCalendar::anniversary((int) $p['anchor'], $p['plan']['intervalMonths']);
      $user = $this->entities->getStorage('user')->load((int) $p['uid']);
      if (!$user) throw new SubscriptionException('account_unavailable', 'The billing account is missing.', 409);
      if ($subscriptionId !== NULL && !empty($p['pending_change']) && $p['pending_change']['replacement']) {
        $p['pending_change']['newSubscriptionId'] = $subscriptionId;
        if (!empty($p['pending_change']['immediate'])) $this->finishUpgrade($p);
        else $this->confirmPlanChange($p);
        $this->repository->save($p); $this->syncRoles((int) $p['uid']);
        return $this->status((int) $p['uid']);
      }
      if ($subscriptionId !== NULL) {
        $remote = $this->gateway->subscription($subscriptionId);
        if (($remote['name'] ?? '') !== 'Portal ' . $p['reference'] || self::minor((string) ($remote['amount'] ?? '')) !== $p['plan']['amountMinor']
          || (string) ($remote['profile']['customerProfileId'] ?? '') !== (string) $p['profile_id']
          || (string) ($remote['profile']['customerPaymentProfileId'] ?? '') !== (string) $p['payment_profile_id']) {
          throw new SubscriptionException('provider_mismatch', 'The recurring schedule does not belong to this purchase.', 409);
        }
        $p['subscription_id'] = $subscriptionId;
        $p['state'] = in_array($remote['status'] ?? '', ['canceled', 'cancelled', 'expired', 'terminated'], TRUE) ? 'cancelled' : 'active';
        $p['cancel_requested'] = $p['state'] === 'cancelled' ? 1 : 0;
        $this->repository->save($p);
      }
      elseif (in_array($p['state'], ['charging', 'payment_review', 'paid_pending_schedule'], TRUE)) {
        $p['state'] = 'paid_pending_schedule'; $this->repository->save($p);
        $this->finishSchedule($p, $user->getEmail());
      }
      else throw new SubscriptionException('schedule_confirmation_required', 'An uncertain recurring schedule must be identified at the provider before recovery.', 409);
      $this->repository->markPaid((int) $p['uid']); $this->syncRoles((int) $p['uid']);
      return $this->status((int) $p['uid']);
    }
    finally { $this->lock->release($lock); }
  }
  private function assertInitialReceipt(array $p, array $t): void {
    if (!in_array($t['transactionStatus'] ?? '', ['capturedPendingSettlement', 'settledSuccessfully'], TRUE)
      || !empty($t['subscription']['id'])
      || self::minor((string) ($t['authAmount'] ?? $t['settleAmount'] ?? '')) !== ($p['initial_plan']['amountMinor'] ?? $p['plan']['amountMinor'])
      || ($t['order']['invoiceNumber'] ?? '') !== $p['reference']
      || (string) ($t['customer']['id'] ?? '') !== (string) $p['uid']) {
      throw new SubscriptionException('provider_mismatch', 'The original payment receipt could not be verified.', 409);
    }
  }
  public function processEvent(array $event): void {
    $type = $event['eventType']; $remoteId = (string) ($event['payload']['id'] ?? '');
    if (str_starts_with($type, 'net.authorize.customer.subscription.')) {
      if ($p = $this->repository->byProvider('subscription_id', $remoteId)) $this->reconcile($p['id']);
      return;
    }
    if (!str_starts_with($type, 'net.authorize.payment.')) return;
    $t = $this->gateway->transaction($remoteId);
    if ($this->processUpgradeTransaction($t)) return;
    if (!empty($t['refTransId']) && str_ends_with($type, '.refund.created')) {
      $original = $this->gateway->transaction((string) $t['refTransId']);
      $change = $this->repository->changeByReference((string) ($original['order']['invoiceNumber'] ?? ''));
      if ($change && $change['payload']['kind'] === 'upgrade') {
        $p = $this->repository->purchase($change['purchase_id']);
        $lock = 'headless_subscription.account.' . $p['uid'];
        if (!$this->lock->acquire($lock, 90)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
        try {
          $p = $this->repository->purchase($p['id']); $this->sameContext($p);
          $this->assertUpgradeReceipt($p, $change, $original);
          $p['review_reason'] = 'upgrade_refund_received'; $this->repository->save($p);
        } finally { $this->lock->release($lock); }
        return;
      }
      $p = !empty($original['subscription']['id'])
        ? $this->repository->byProvider('subscription_id', (string) $original['subscription']['id'])
        : $this->repository->byProvider('transaction_id', (string) $t['refTransId']);
      if ($p) {
        $lock = 'headless_subscription.account.' . $p['uid'];
        if (!$this->lock->acquire($lock, 90)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
        try {
          $p = $this->repository->purchase($p['id']); $this->sameContext($p);
          $p['review_reason'] = 'refund_received'; $this->repository->save($p);
        } finally { $this->lock->release($lock); }
      }
      return; // Refund access policy is reviewed, not guessed from a partial refund.
    }
    $subscription = (string) ($t['subscription']['id'] ?? '');
    $p = $subscription ? $this->repository->byProvider('subscription_id', $subscription) : $this->repository->byProvider('transaction_id', $remoteId);
    if (!$p && !$subscription && !empty($t['order']['invoiceNumber'])) {
      $p = $this->repository->byReference((string) $t['order']['invoiceNumber']);
      if ($p && in_array($p['state'], ['charging', 'payment_review'], TRUE)
        && in_array($t['transactionStatus'] ?? '', ['capturedPendingSettlement', 'settledSuccessfully'], TRUE)) {
        $this->recover($p['id'], $remoteId);
        return;
      }
    }
    if (!$p) return; // Payments outside this module never affect access.
    $lock = 'headless_subscription.account.' . $p['uid'];
    if (!$this->lock->acquire($lock, 90)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);
    try {
      $p = $this->repository->purchase($p['id']); $this->sameContext($p);
      $this->applyTransaction($p, $t);
      if (($t['transactionStatus'] ?? '') === 'voided' && (string) $t['transId'] === (string) $p['transaction_id']) {
        $p['state'] = 'revoked'; $p['cancel_requested'] = 1;
        $this->repository->updatePaymentStatus($p, $p['transaction_id'], 'voided');
        $this->repository->save($p); $this->syncRoles((int) $p['uid']);
        if ($p['subscription_id']) {
          $remoteStatus = $this->gateway->subscription($p['subscription_id'])['status'] ?? '';
          if (!in_array($remoteStatus, ['canceled', 'cancelled', 'expired', 'terminated'], TRUE)) $this->gateway->cancel($p['subscription_id']);
        }
      }
      $this->repository->save($p); $this->syncRoles((int) $p['uid']);
    }
    finally { $this->lock->release($lock); }
  }

  public function resume(int $uid): array {
    $p = $this->repository->current($uid);
    if (!$p) throw new SubscriptionException('not_found', 'No active or cancelled subscription found to resume.', 404);

    // Check if the subscription is in a state that can be resumed.
    // It must be cancelled but still active (paid_until in the future).
    if ($p['state'] !== 'cancelled' && $p['cancel_requested'] != 1) {
      throw new SubscriptionException('invalid_state', 'Subscription is not cancelled.', 400);
    }

    $current_time = $this->time->getCurrentTime();
    if ($p['paid_until'] < $current_time) {
      throw new SubscriptionException('expired', 'Cannot resume an expired subscription. Please purchase a new plan.', 400);
    }

    $lock = 'headless_subscription.account.' . $p['uid'];
    if (!$this->lock->acquire($lock, 180)) throw new SubscriptionException('busy', 'A subscription operation is already in progress.', 409);

    try {
      // To resume, we must re-establish an active ARB schedule with Authorize.Net.
      // If the gateway ARB was literally cancelled (which it is immediately upon our cancel request),
      // it cannot be "resumed" in the API. We must create a new one.
      if ($p['subscription_id']) {
          // The gateway's native method to create an ARB schedule is schedule()
          // It expects the full $p (purchase) array which contains plan, profile_id, paid_until, etc.
          // Because paid_until is in the future, it will correctly schedule the next charge then.
          $new_subscription_id = $this->gateway->schedule($p);
          $p['subscription_id'] = $new_subscription_id;
      }

      $p['state'] = 'active';
      $p['cancel_requested'] = 0;
      $this->repository->save($p);

      return $this->status($uid);
    } finally {
      $this->lock->release($lock);
    }
  }

}