<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\Core\Database\Connection;

/** Own-record lookups and durable payment checkpoints. No legacy subscription reads. */
class SubscriptionRepository {
  public function __construct(private readonly Connection $database) {}
  public function account(int $uid): ?array {
    $row = $this->database->select('headless_subscription_account', 'a')->fields('a')->condition('uid', $uid)->execute()->fetchAssoc();
    return $row ?: NULL;
  }
  public function member(int $uid): ?array {
    $row = $this->database->select('headless_subscription_member', 'm')->fields('m')->condition('uid', $uid)->execute()->fetchAssoc();
    return $row ?: NULL;
  }
  public function addMember(int $uid, int $primary, int $clinic, int $now): void {
    if ($uid === $primary || $this->account($uid) || $this->member($uid) || !$this->account($primary) || $this->member($primary)) throw new \LogicException('A doctor must have exactly one independent funding source.');
    $this->database->insert('headless_subscription_member')->fields(['uid' => $uid, 'primary_uid' => $primary, 'clinic_id' => $clinic, 'state' => 'active', 'created' => $now, 'changed' => $now, 'actor_uid' => $primary])->execute();
  }
  public function setMemberState(int $uid, int $primary, string $state, int $now): void {
    if (!in_array($state, ['active', 'blocked'], TRUE)) throw new \InvalidArgumentException('Unsupported member status.');
    $this->database->update('headless_subscription_member')->fields(['state' => $state, 'changed' => $now, 'actor_uid' => $primary])->condition('uid', $uid)->condition('primary_uid', $primary)->execute();
  }
  public function memberIds(int $primary, int $after = 0, int $limit = 50): array {
    return $this->database->select('headless_subscription_member', 'm')->fields('m', ['uid'])->condition('primary_uid', $primary)->condition('uid', $after, '>')->orderBy('uid')->range(0, $limit)->execute()->fetchCol();
  }
  public function accountForClinic(int $clinicId): ?array {
    $row = $this->database->select('headless_subscription_account', 'a')->fields('a')->condition('clinic_id', $clinicId)->execute()->fetchAssoc();
    return $row ?: NULL;
  }
  public function addAccount(int $uid, int $clinicId, int $now): void {
    $this->database->insert('headless_subscription_account')->fields(['uid' => $uid, 'clinic_id' => $clinicId, 'ever_paid' => 0, 'created' => $now])->execute();
  }
  public function markPaid(int $uid): void {
    $this->database->update('headless_subscription_account')->fields(['ever_paid' => 1])->condition('uid', $uid)->execute();
  }
  public function purchase(string $id): ?array {
    $row = $this->database->select('headless_subscription_purchase', 'p')->fields('p')->condition('id', $id)->execute()->fetchAssoc();
    return $row ? $this->decode($row) : NULL;
  }
  public function byAttempt(int $uid, string $attempt): ?array {
    $row = $this->database->select('headless_subscription_purchase', 'p')->fields('p')->condition('uid', $uid)->condition('attempt', $attempt)->execute()->fetchAssoc();
    return $row ? $this->decode($row) : NULL;
  }
  public function current(int $uid): ?array {
    $row = $this->database->select('headless_subscription_purchase', 'p')->fields('p')->condition('uid', $uid)->condition('state', ['declined'], 'NOT IN')->orderBy('created', 'DESC')->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchAssoc();
    return $row ? $this->decode($row) : NULL;
  }
  public function byProvider(string $field, string $id): ?array {
    if (!in_array($field, ['transaction_id', 'subscription_id'], TRUE)) throw new \InvalidArgumentException('Unsupported provider lookup.');
    $row = $this->database->select('headless_subscription_purchase', 'p')->fields('p')->condition($field, $id)->execute()->fetchAssoc();
    return $row ? $this->decode($row) : NULL;
  }
  public function byReference(string $reference): ?array {
    $row = $this->database->select('headless_subscription_purchase', 'p')->fields('p')->condition('reference', $reference)->execute()->fetchAssoc();
    return $row ? $this->decode($row) : NULL;
  }
  public function addPurchase(array $row): void {
    $this->database->insert('headless_subscription_purchase')->fields($this->encode($row))->execute();
  }
  public function save(array $row): void {
    $id = $row['id']; unset($row['id']);
    $this->database->update('headless_subscription_purchase')->fields($this->encode($row))->condition('id', $id)->execute();
  }
  public function change(string $id): ?array {
    $row = $this->database->select('headless_subscription_change', 'c')->fields('c')->condition('id', $id)->execute()->fetchAssoc();
    if (!$row) return NULL;
    $row['payload'] = json_decode($row['payload'], TRUE, 512, JSON_THROW_ON_ERROR);
    return $row;
  }
  public function changeByReference(string $reference): ?array {
    $id = $this->database->select('headless_subscription_change', 'c')->fields('c', ['id'])->condition('reference', $reference)->execute()->fetchField();
    return $id ? $this->change((string) $id) : NULL;
  }
  public function completeChange(array $purchase, array $change): void {
    $transaction = $this->database->startTransaction();
    try { $this->save($purchase); $this->saveChange($change); }
    catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
  }
  public function addChange(array $row): void {
    $row['payload'] = json_encode($row['payload'], JSON_THROW_ON_ERROR);
    $this->database->insert('headless_subscription_change')->fields($row)->execute();
  }
  public function saveChange(array $row): void {
    $id = $row['id']; unset($row['id']);
    $row['payload'] = json_encode($row['payload'], JSON_THROW_ON_ERROR);
    $this->database->update('headless_subscription_change')->fields($row)->condition('id', $id)->execute();
  }
  public function addEvent(string $id, array $event, int $now): bool {
    try {
      $this->database->insert('headless_subscription_event')->fields(['id' => $id, 'payload' => json_encode($event, JSON_THROW_ON_ERROR), 'status' => 'pending', 'created' => $now])->execute();
      return TRUE;
    }
    catch (\Drupal\Core\Database\IntegrityConstraintViolationException $e) { return FALSE; }
  }
  public function event(string $id): ?array {
    $row = $this->database->select('headless_subscription_event', 'e')->fields('e')->condition('id', $id)->execute()->fetchAssoc();
    if (!$row) return NULL;
    $row['payload'] = json_decode($row['payload'], TRUE, 512, JSON_THROW_ON_ERROR); return $row;
  }
  public function eventDone(string $id): void {
    $this->database->update('headless_subscription_event')->fields(['status' => 'done'])->condition('id', $id)->execute();
  }
  public function pendingEvents(): array {
    return $this->database->select('headless_subscription_event', 'e')->fields('e', ['id'])->condition('status', 'pending')->range(0, 50)->execute()->fetchCol();
  }
  public function duePurchases(int $now): array {
    $rows = $this->database->select('headless_subscription_purchase', 'p')->fields('p', ['id'])->condition('next_sync', $now, '<=')->condition('state', ['active', 'past_due', 'paid_pending_schedule', 'cancelled'], 'IN')->orderBy('next_sync')->range(0, 25)->execute()->fetchCol();
    return $rows;
  }
  public function history(int $uid, int $limit = 50): array {
    $rows = $this->database->select('headless_subscription_purchase', 'p')->fields('p')->condition('uid', $uid)->orderBy('created', 'DESC')->range(0, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    return array_map(fn(array $row) => $this->decode($row), $rows);
  }
  public function payments(int $uid, int $limit = 50): array {
    return $this->database->select('headless_subscription_payment', 'p')->fields('p')->condition('uid', $uid)->orderBy('paid_at', 'DESC')->range(0, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }
  public function recordPayment(array $p, array $t, array $plan, int $now, string $kind = 'initial'): void {
    $id = (string) ($t['transId'] ?? '');
    if ($id === '' || !ctype_digit($id)) return;
    $paid = isset($t['submitTimeUTC']) ? strtotime($t['submitTimeUTC']) : FALSE;
    $this->database->merge('headless_subscription_payment')->keys(['context' => $p['context'], 'transaction_id' => $id])->fields([
      'purchase_id' => $p['id'], 'uid' => $p['uid'], 'amount_minor' => SubscriptionService::minor((string) ($t['authAmount'] ?? $t['settleAmount'] ?? '')),
      'currency' => $plan['currency'], 'plan_name' => $plan['name'], 'status' => $t['transactionStatus'] ?? 'capturedPendingSettlement', 'paid_at' => $paid ?: $now,
      'renewal_number' => (int) ($t['subscription']['payNum'] ?? 0), 'kind' => !empty($t['subscription']['payNum']) ? 'renewal' : $kind,
    ])->execute();
  }
  public function updatePaymentStatus(array $p, string $transactionId, string $status): void {
    $this->database->update('headless_subscription_payment')->fields(['status' => $status])->condition('context', $p['context'])->condition('transaction_id', $transactionId)->condition('purchase_id', $p['id'])->execute();
  }
  /** One paginated query for admin rows, including each doctor's current purchase. */
  public function adminAccounts(string $search = '', string $state = ''): array {
    $q = $this->database->select('headless_subscription_account', 'a');
    $q->leftJoin('users_field_data', 'u', 'u.uid = a.uid AND u.default_langcode = 1');
    $q->leftJoin('headless_subscription_purchase', 'p', "p.uid = a.uid AND p.id = (SELECT p2.id FROM {headless_subscription_purchase} p2 WHERE p2.uid = a.uid AND p2.state <> 'declined' ORDER BY p2.created DESC, p2.id DESC LIMIT 1)");
    $q->fields('a', ['uid', 'clinic_id', 'created'])->fields('u', ['name', 'mail']);
    $now = (int) \Drupal::time()->getCurrentTime();
    $effectiveState = "CASE WHEN p.state = 'active' AND p.paid_until <= $now THEN 'past_due' WHEN p.state = 'cancelled' AND p.paid_until <= $now THEN 'expired' ELSE p.state END";
    $q->addExpression($effectiveState, 'purchase_state');
    foreach (['id','plan','paid_until','pending_change','review_reason','subscription_id','cancel_requested'] as $field) $q->addField('p', $field, 'purchase_' . $field);
    if ($search !== '') $q->condition($q->orConditionGroup()->condition('u.mail', '%' . $this->database->escapeLike($search) . '%', 'LIKE')->condition('u.name', '%' . $this->database->escapeLike($search) . '%', 'LIKE'));
    if ($state === 'none') $q->isNull('p.id');
    elseif ($state === 'review') $q->condition($q->orConditionGroup()->condition('p.state', ['payment_review','renewal_review','paid_pending_schedule','scheduling'], 'IN')->condition('p.review_reason', '', '<>')->isNotNull('p.pending_change'));
    elseif ($state !== '') $q->where($effectiveState . ' = :portal_state', [':portal_state' => $state]);
    $q->orderBy('a.created', 'DESC')->orderBy('a.uid', 'DESC');
    return $q->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(25)->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }
  private function decode(array $row): array {
    foreach (['plan', 'initial_plan', 'pending_change'] as $field) if (isset($row[$field])) $row[$field] = json_decode($row[$field], TRUE, 512, JSON_THROW_ON_ERROR);
    return $row;
  }
  private function encode(array $row): array {
    foreach (['plan', 'initial_plan', 'pending_change'] as $field) if (isset($row[$field]) && is_array($row[$field])) $row[$field] = json_encode($row[$field], JSON_THROW_ON_ERROR);
    return $row;
  }
}
