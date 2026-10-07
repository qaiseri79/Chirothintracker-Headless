<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\contact\Entity\Message;
use Drupal\headless_progress\ProgressLogSync;
use Drupal\headless_progress\TrackingCalculator;
use Drupal\user\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @group headless_progress */
final class ProgressLogSyncTest extends TestCase {

  private array $ids = [];
  private array $baseline = [['value' => '200']];

  private function fixture(bool $enrolled = TRUE, bool $available = TRUE): array {
    $this->ids = range(1, 22);
    $storage = $this->createMock(EntityStorageInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['contact_message', $storage], ['user', $userStorage]]);
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort'] as $method) $query->method($method)->willReturnSelf();
    $query->method('execute')->willReturnCallback(fn() => $this->ids);
    $storage->method('getQuery')->willReturn($query);
    $patient = $this->createMock(User::class);
    $patient->method('id')->willReturn(12);
    $patient->method('hasRole')->with('enrolled_patient')->willReturn($enrolled);
    $list = $this->createMock(FieldItemList::class);
    $list->method('getValue')->willReturnCallback(fn() => $this->baseline);
    $patient->method('get')->willReturn($list);
    $messages = [];
    foreach ($this->ids as $id) {
      $message = $this->createMock(Message::class);
      $message->method('id')->willReturn($id);
      $message->method('bundle')->willReturn('tracking_weight');
      $owner = $this->createMock(FieldItemList::class);
      $owner->method('__get')->with('target_id')->willReturn(12);
      $message->method('get')->with('uid')->willReturn($owner);
      $messages[$id] = $message;
    }
    $storage->method('loadMultiple')->willReturnCallback(fn($ids) => array_intersect_key($messages, array_flip($ids)));
    $jobValues = new \ArrayObject();
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturnCallback(fn($key) => $jobValues[$key] ?? NULL);
    $store->method('setWithExpire')->willReturnCallback(function ($key, $job) use ($jobValues) { $jobValues[$key] = $job; });
    $store->method('delete')->willReturnCallback(function ($key) use ($jobValues) { unset($jobValues[$key]); });
    $jobs = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $jobs->method('get')->with('headless_progress.log_sync')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn($available);
    $transaction = new class extends Transaction {
      public bool $rollbackObserved = FALSE;
      public function __construct() {}
      public function __destruct() {}
      public function rollBack() { $this->rollbackObserved = TRUE; }
    };
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn($transaction);
    $calculator = $this->createMock(TrackingCalculator::class);
    $calculator->expects($this->never())->method('runEvaluations');
    $sync = new ProgressLogSync($entities, $calculator, $jobs, $lock, $database);
    return compact('sync', 'patient', 'calculator', 'jobValues', 'messages', 'storage', 'lock', 'query', 'transaction', 'userStorage');
  }

  public function testBatchesPreservePartialMeasurementAndWeightSummary(): void {
    extract($this->fixture());
    $calculator->method('computeAll')->willReturnCallback(function ($message) {
      $id = $message->id();
      return ['message' => [], 'user' => $id === 22 ? ['field_net_inches_lost' => 8] : ['field_net_weight_loss' => $id, 'field_net_inches_lost' => 3]];
    });
    $calculator->expects($this->exactly(22))->method('applyToMessage');
    $calculator->expects($this->once())->method('applyToUser')->with($patient, ['field_net_weight_loss' => 21, 'field_net_inches_lost' => 8]);
    foreach ($messages as $message) $message->expects($this->once())->method('save');
    $start = $sync->step($patient, NULL, 0);
    $this->assertSame(22, $start['total']);
    $this->assertSame(0, $start['processed']);
    $first = $sync->step($patient, $start['token'], 0);
    $this->assertSame(20, $first['processed']);
    $this->assertFalse($first['done']);
    $this->assertSame($first, $sync->step($patient, $start['token'], 0));
    $this->assertSame($first, $sync->step($patient, NULL, 0));
    $last = $sync->step($patient, $start['token'], 20);
    $this->assertTrue($last['done']);
    $this->assertSame(22, $last['processed']);
    $this->assertSame($last, $sync->step($patient, $start['token'], 20));
  }

  public function testQueryIsPatientScopedAndChronological(): void {
    extract($this->fixture());
    $query->expects($this->exactly(2))->method('condition')->withConsecutive(['contact_form', 'tracking_weight'], ['uid', 12]);
    $query->expects($this->exactly(3))->method('sort')->withConsecutive(['field_date', 'ASC'], ['created', 'ASC'], ['id', 'ASC']);
    $query->expects($this->once())->method('accessCheck')->with(FALSE);
    $calculator->expects($this->never())->method('computeAll');
    $sync->step($patient, NULL, 0);
  }

  public function testEmptyHistoryIsCompleteWithoutChangingSummary(): void {
    extract($this->fixture());$this->ids = [];
    $calculator->expects($this->never())->method('applyToUser');
    $result = $sync->step($patient, NULL, 0);
    $this->assertTrue($result['done']);$this->assertSame(0, $result['total']);
  }

  public function testArchivedPatientCannotStartAJob(): void {
    extract($this->fixture(FALSE));
    $query->expects($this->never())->method('execute');
    $this->expectException(HttpException::class);$this->expectExceptionCode(0);
    try { $sync->step($patient, NULL, 0); }
    catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode());throw $error; }
  }

  public function testOverlappingRequestIsRejectedBeforeQuerying(): void {
    extract($this->fixture(TRUE, FALSE));
    $query->expects($this->never())->method('execute');
    $this->expectException(HttpException::class);
    try { $sync->step($patient, NULL, 0); }
    catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode());throw $error; }
  }

  public function testForeignOrExpiredTokenCannotProcessLogs(): void {
    extract($this->fixture());$sync->step($patient, NULL, 0);
    $calculator->expects($this->never())->method('computeAll');
    $lock->expects($this->once())->method('release');
    $this->expectException(HttpException::class);
    $sync->step($patient, str_repeat('a', 64), 0);
  }

  public function testProgramSettingChangeInvalidatesJob(): void {
    extract($this->fixture());$start = $sync->step($patient, NULL, 0);
    $this->baseline = [['value' => '220']];
    $calculator->expects($this->never())->method('computeAll');
    try { $sync->step($patient, $start['token'], 0);$this->fail('Changed settings accepted'); }
    catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
    $this->assertFalse($jobValues->offsetExists('12'));
  }

  public function testNewLogInvalidatesJob(): void {
    extract($this->fixture());$start = $sync->step($patient, NULL, 0);
    $this->ids[] = 23;
    $calculator->expects($this->never())->method('computeAll');
    try { $sync->step($patient, $start['token'], 0);$this->fail('New log accepted'); }
    catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
    $this->assertFalse($jobValues->offsetExists('12'));
  }

  public function testSaveFailureRollsBackBatchWithoutAdvancingProgress(): void {
    extract($this->fixture());$start = $sync->step($patient, NULL, 0);
    $calculator->method('computeAll')->willReturn(['message' => [], 'user' => []]);
    $calculator->expects($this->never())->method('applyToUser');
    $messages[2]->method('save')->willThrowException(new \RuntimeException('Synthetic failure'));
    $storage->expects($this->once())->method('resetCache')->with(range(1, 20));
    $userStorage->expects($this->once())->method('resetCache')->with([12]);
    $lock->expects($this->once())->method('release');
    try { $sync->step($patient, $start['token'], 0);$this->fail('Failure ignored'); }
    catch (\RuntimeException $error) { $this->assertSame('Synthetic failure', $error->getMessage()); }
    $this->assertTrue($transaction->rollbackObserved);
    $this->assertSame(0, $jobValues['12']['processed']);
  }

}
