<?php

declare(strict_types=1);

namespace Drupal\headless_progress;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\headless_access\PortalRoles;
use Drupal\user\Entity\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Recalculates existing logs in date order without replaying notifications. */
final class ProgressLogSync {

  private const BATCH_SIZE = 20;
  private const TTL = 3600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly TrackingCalculator $calculator,
    private readonly KeyValueExpirableFactoryInterface $jobs,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
  ) {}

  /** Starts/resumes a patient-owned job, or processes the next bounded batch. */
  public function step(User $patient, ?string $token, int $processed): array {
    if (!$patient->hasRole(PortalRoles::PATIENT_ENROLLED)) {
      throw new HttpException(403, 'Only enrolled patients can sync logs.');
    }
    $key = (string) $patient->id();
    $lock_name = 'headless_progress:log_sync:' . $key;
    if (!$this->lock->acquire($lock_name, 120)) {
      throw new HttpException(409, 'Your logs are already being synced. Please try again.');
    }
    try {
      $store = $this->jobs->get('headless_progress.log_sync');
      $job = $store->get($key);
      if ($token === NULL) {
        // A closed tab or failed response can resume the unfinished job.
        if (!$job || $job['processed'] === count($job['ids'])) {
          $job = [
            'token' => bin2hex(random_bytes(32)),
            'ids' => $this->entryIds((int) $patient->id()),
            'processed' => 0,
            'summary' => [],
            'baseline' => $this->baseline($patient),
          ];
        }
        $store->setWithExpire($key, $job, self::TTL);
        return $this->status($job);
      }
      if (!$job || !hash_equals($job['token'], $token)) {
        throw new HttpException(409, 'This sync has expired. Please start again.');
      }
      // Repeating a request after a lost response must not process another batch.
      if ($processed < $job['processed'] || $job['processed'] === count($job['ids'])) {
        return $this->status($job);
      }
      if ($processed !== $job['processed']) {
        throw new HttpException(409, 'Sync progress changed. Please try again.');
      }
      if ($this->baseline($patient) !== $job['baseline'] || $this->entryIds((int) $patient->id()) !== $job['ids']) {
        $store->delete($key);
        throw new HttpException(409, 'Your logs or program settings changed during sync. Please start again.');
      }
      $ids = array_slice($job['ids'], $job['processed'], self::BATCH_SIZE);
      $storage = $this->entities->getStorage('contact_message');
      $messages = $storage->loadMultiple($ids);
      $transaction = $this->database->startTransaction();
      try {
        foreach ($ids as $id) {
          $message = $messages[$id] ?? NULL;
          // Never trust even a stored job to grant access to another account.
          if (!$message || $message->bundle() !== 'tracking_weight' || (int) $message->get('uid')->target_id !== (int) $patient->id()) {
            throw new HttpException(409, 'A log changed during sync. Please start again.');
          }
          $derived = $this->calculator->computeAll($message, [], $patient);
          $this->calculator->applyToMessage($message, $derived['message']);
          $message->save();
          // A later measurement-only entry must not erase the latest weight.
          $job['summary'] = array_replace($job['summary'], $derived['user']);
          $job['processed']++;
        }
        if ($job['processed'] === count($job['ids'])) {
          $this->calculator->applyToUser($patient, $job['summary']);
        }
        // The state and batch writes commit together; a retry is safe.
        $store->setWithExpire($key, $job, self::TTL);
      }
      catch (\Throwable $error) {
        $transaction->rollBack();
        $storage->resetCache($ids);
        $this->entities->getStorage('user')->resetCache([$patient->id()]);
        throw $error;
      }
      unset($transaction);
      // Do not call runEvaluations(): existing questions must not be sent again.
      return $this->status($job);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  private function entryIds(int $uid): array {
    return array_values($this->entities->getStorage('contact_message')->getQuery()
      ->accessCheck(FALSE)->condition('contact_form', 'tracking_weight')->condition('uid', $uid)
      ->sort('field_date', 'ASC')->sort('created', 'ASC')->sort('id', 'ASC')->execute());
  }

  /** Preserve the existing starting-value rules; historical reset is deferred. */
  private function baseline(User $patient): array {
    $values = [];
    foreach (['field_program_start_date', 'field_program_start_weight', 'field_program_start_inches', 'field_goal_weight'] as $field) {
      $values[$field] = $patient->get($field)->getValue();
    }
    return $values;
  }

  private function status(array $job): array {
    $total = count($job['ids']);
    return ['token' => $job['token'], 'processed' => $job['processed'], 'total' => $total, 'done' => $job['processed'] === $total];
  }

}
