<?php

declare(strict_types=1);

namespace Drupal\headless_access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_subscriptions\Entitlements;
use Drupal\headless_subscriptions\SubscriptionRepository;
use Drupal\user\UserInterface;

/**
 * Effective portal access. Enrollment and payment access are separate states.
 *
 * Reads only local account/paid-period data. It never archives patients, changes
 * roles, calls the payment provider, or grants ownership of a subscription.
 */
final class PortalAccessResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
    private readonly ?SubscriptionRepository $subscriptions = NULL,
    private readonly ?\Drupal\headless_subscriptions\Sponsorship $sponsorship = NULL,
  ) {}

  public function resolve(AccountInterface $account): ?array {
    if (!$account->isAuthenticated()) {
      return NULL;
    }
    $roles = $account->getRoles();
    $patient = array_intersect($roles, [PortalRoles::PATIENT_ENROLLED, PortalRoles::PATIENT_ARCHIVED]) !== [];
    $doctor = array_intersect($roles, PortalRoles::CHIROPRACTOR_READ) !== [];
    if (!$patient && !$doctor) {
      return NULL;
    }
    $user = $this->entities->getStorage('user')->load((int) $account->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return NULL;
    }
    // Patient wins when legacy role data contains both audiences.
    $audience = $patient ? 'patient' : 'chiropractor';
    $write = in_array($patient ? PortalRoles::PATIENT_ENROLLED : PortalRoles::CHIROPRACTOR_ACTIVE, $roles, TRUE);
    $result = [
      'audience' => $audience,
      'read' => TRUE,
      'write' => $write,
      'mode' => $write ? 'active' : 'inactive',
      'reason' => $write ? NULL : ($patient ? 'program_archived' : 'doctor_inactive'),
      'paidThrough' => NULL,
      'cancelAtPeriodEnd' => FALSE,
      'canManageBilling' => FALSE,
      'canManageDoctors' => !$patient && $write && (!$this->sponsorship || $this->sponsorship->primaryId($user) === NULL),
      'funding' => NULL,
      'enrollmentStatus' => $patient ? ($write ? 'enrolled' : 'archived') : NULL,
    ];

    if (!$patient) {
      if ($funding = $this->funding((int) $user->id())) {
        $caps = $funding['capabilities'];
        $result['read'] = $caps['portalRead'];
        $result['write'] = $caps['portalWrite'];
        $result['mode'] = $caps['portalWrite'] ? 'active' : ($caps['billingOnly'] ? 'billing_only' : 'inactive');
        $result['reason'] = $caps['portalWrite'] ? NULL : (!empty($funding['sponsored']) ? (empty($funding['available']) ? 'sponsor_unavailable' : 'sponsor_subscription_inactive') : 'subscription_inactive');
        $result['paidThrough'] = $caps['paidThrough'];
        $result['cancelAtPeriodEnd'] = empty($funding['sponsored']) && $funding['cancelled'];
        $result['canManageBilling'] = empty($funding['sponsored']);
        $result['canManageDoctors'] = empty($funding['sponsored']) && $caps['portalWrite'];
        $result['funding'] = empty($funding['sponsored']) ? 'self' : 'sponsored';
      }
      return $result;
    }

    // An archived program stays archived, regardless of a later payment.
    if (!$write) {
      return $result;
    }
    $doctorId = $this->reference($user, 'field_chiropractor');
    $funding = $doctorId ? $this->funding($doctorId) : NULL;
    if (!$funding) {
      return $result; // Preserve unmanaged legacy enrollment policy.
    }
    $provider = $this->entities->getStorage('user')->load($doctorId);
    $validProvider = ($funding['available'] ?? TRUE) && $provider instanceof UserInterface && $provider->isActive()
      && array_intersect($provider->getRoles(), PortalRoles::CHIROPRACTOR_READ) !== []
      && $this->reference($user, 'field_clinic') === (int) $funding['account']['clinic_id'];
    $result['write'] = $validProvider && $funding['capabilities']['portalWrite'];
    $result['mode'] = $result['write'] ? 'active' : 'inactive';
    $result['reason'] = $result['write'] ? NULL : ($validProvider ? 'provider_subscription_inactive' : 'provider_unavailable');
    $result['paidThrough'] = $funding['capabilities']['paidThrough'];
    // Do not expose the doctor's payment details or cancellation decision.
    return $result;
  }

  private function funding(int $doctorId): ?array {
    if ($this->sponsorship) return $this->sponsorship->funding($doctorId);
    $account = $this->subscriptions?->account($doctorId);
    if (!$account) {
      return NULL;
    }
    $purchase = $this->subscriptions->current($doctorId);
    $now = $this->time->getCurrentTime();
    if ($purchase && $purchase['state'] === 'active' && (int) $purchase['paid_until'] <= $now) {
      $purchase['state'] = 'past_due';
    }
    $caps = Entitlements::evaluate($purchase, (bool) $account['ever_paid'], $now,
      (int) $this->config->get('headless_subscriptions.settings')->get('grace_days'));
    return ['account' => $account, 'capabilities' => $caps, 'cancelled' => (bool) ($purchase['cancel_requested'] ?? FALSE)];
  }

  private function reference(UserInterface $user, string $field): ?int {
    $id = $user->hasField($field) ? (int) $user->get($field)->target_id : 0;
    return $id > 0 ? $id : NULL;
  }

}
