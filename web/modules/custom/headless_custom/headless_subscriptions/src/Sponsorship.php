<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\headless_access\PortalRoles;
use Drupal\user\UserInterface;

/** Local funding and role policy for explicitly enrolled clinic doctors. */
final class Sponsorship {
  public function __construct(
    private readonly SubscriptionRepository $repository,
    private readonly EntityTypeManagerInterface $entities,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
    private readonly QueueFactory $queues,
  ) {}

  /** NULL means unmanaged legacy billing. Never traverses arbitrary user chains. */
  public function funding(int $doctorId): ?array {
    $account = $this->repository->account($doctorId);
    $member = $this->repository->member($doctorId);
    if (!$account && !$member) return NULL;
    $ownerId = $account ? $doctorId : (int) $member['primary_uid'];
    $account ??= $this->repository->account($ownerId);
    $doctor = $this->entities->getStorage('user')->load($doctorId);
    $owner = $ownerId === $doctorId ? $doctor : $this->entities->getStorage('user')->load($ownerId);
    $structural = $account && $this->isDoctor($doctor) && $this->isDoctor($owner)
      && $this->reference($doctor, 'field_clinic') === (int) $account['clinic_id']
      && $this->reference($owner, 'field_clinic') === (int) $account['clinic_id'];
    if ($member) {
      $structural = $structural && !$this->repository->account($doctorId)
        && $doctorId !== $ownerId && !$this->repository->member($ownerId)
        && (int) $member['clinic_id'] === (int) ($account['clinic_id'] ?? 0)
        && $this->primaryId($doctor) === $ownerId && $this->primaryId($owner) === NULL;
    }
    $valid = $structural && $doctor->isActive() && $owner->isActive() && (!$member || $member['state'] === 'active');
    $purchase = $account ? $this->repository->current($ownerId) : NULL;
    $now = $this->time->getCurrentTime();
    if ($purchase && $purchase['state'] === 'active' && (int) $purchase['paid_until'] <= $now) $purchase['state'] = 'past_due';
    $caps = Entitlements::evaluate($purchase, (bool) ($account['ever_paid'] ?? FALSE), $now,
      (int) $this->config->get('headless_subscriptions.settings')->get('grace_days'));
    if (!$structural) $caps['portalRead'] = FALSE;
    if ($member) $caps['billingOnly'] = FALSE;
    if (!$valid) {
      $caps['portalWrite'] = $caps['store'] = $caps['laser'] = FALSE;
      $caps['patientLimit'] = 0;
    }
    return [
      'account' => $account ?? ['uid' => $ownerId, 'clinic_id' => (int) ($member['clinic_id'] ?? 0)],
      'capabilities' => $caps, 'cancelled' => (bool) ($purchase['cancel_requested'] ?? FALSE),
      'ownerId' => $ownerId, 'sponsored' => $member !== NULL, 'available' => (bool) $valid,
    ];
  }

  public function isDoctor(mixed $user): bool {
    return $user instanceof UserInterface
      && array_intersect($user->getRoles(), PortalRoles::CHIROPRACTOR_READ) !== []
      && array_intersect($user->getRoles(), [PortalRoles::PATIENT_ENROLLED, PortalRoles::PATIENT_ARCHIVED]) === [];
  }

  /** Both legacy and treating-doctor references must agree for a doctor account. */
  public function primaryId(UserInterface $doctor): ?int {
    $legacy = $this->reference($doctor, 'field_chiropractor_subscribers');
    $current = $this->reference($doctor, 'field_chiropractor');
    // Legacy doctor saves use a self clinician reference; it is not sponsorship.
    if ($current === (int) $doctor->id()) $current = NULL;
    if ($legacy === (int) $doctor->id()) return -1;
    if ($legacy && $current && $legacy !== $current) return -1;
    return $legacy ?: $current;
  }

  public function reference(UserInterface $user, string $field): ?int {
    $id = $user->hasField($field) ? (int) $user->get($field)->target_id : 0;
    return $id > 0 ? $id : NULL;
  }

  /** Manual account blocks survive renewal; patient roles are never changed. */
  public function syncRole(int $uid): void {
    if (!$this->repository->member($uid)) return;
    $user = $this->entities->getStorage('user')->load($uid);
    if (!$this->isDoctor($user)) return;
    $funding = $this->funding($uid);
    $active = (bool) ($funding['capabilities']['portalWrite'] ?? FALSE);
    $desired = $active ? PortalRoles::CHIROPRACTOR_ACTIVE : PortalRoles::CHIROPRACTOR_INACTIVE;
    $opposite = $active ? PortalRoles::CHIROPRACTOR_INACTIVE : PortalRoles::CHIROPRACTOR_ACTIVE;
    $changed = !$user->hasRole($desired) || $user->hasRole($opposite);
    $user->removeRole($opposite);
    $user->addRole($desired);
    $store = (bool) ($funding['capabilities']['store'] ?? FALSE);
    if ($this->entities->getStorage('user_role')->load(PortalRoles::ECOMMERCE_MANAGER)) {
      if ($store !== $user->hasRole(PortalRoles::ECOMMERCE_MANAGER)) {
        $store ? $user->addRole(PortalRoles::ECOMMERCE_MANAGER) : $user->removeRole(PortalRoles::ECOMMERCE_MANAGER);
        $changed = TRUE;
      }
    }
    if ($changed) $user->save();
  }

  public function queueOwner(int $uid): void {
    $this->queues->get('headless_subscription_members')->createItem(['owner' => $uid, 'after' => 0]);
  }

  /** Bounded, resumable batches always re-evaluate the current paid period. */
  public function syncBatch(int $owner, int $after = 0): void {
    $ids = $this->repository->memberIds($owner, $after, 50);
    foreach ($ids as $id) $this->syncRole((int) $id);
    if (count($ids) === 50) $this->queues->get('headless_subscription_members')->createItem(['owner' => $owner, 'after' => (int) end($ids)]);
  }
}
