<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\contact\Entity\Message;
use Drupal\user\Entity\User;
use Drupal\headless_progress\ProgressLogWriter;
use Drupal\headless_progress\TrackingCalculator;
use Drupal\headless_progress\Exception\ProgressValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Verifies owner/author separation and validation before calculated writes.
 *
 * @group headless_progress
 */
class ProgressLogWriterTest extends TestCase {

  private function fixture(): array {
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $entities->method('getStorage')->with('contact_message')->willReturn($storage);
    $calculator = $this->createMock(TrackingCalculator::class);
    $database = $this->createMock(Connection::class);
    $transaction = new class extends Transaction {
      public function __construct() {}
      public function __destruct() {}
    };
    $database->method('startTransaction')->willReturn($transaction);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $writer = new ProgressLogWriter($entities, $calculator, $database, $this->createMock(LoggerInterface::class), $lock);
    $patient = $this->createMock(User::class);
    $patient->method('id')->willReturn(12);
    $message = $this->createMock(Message::class);
    $message->method('id')->willReturn(67);
    $message->method('uuid')->willReturn('log-uuid');
    $message->method('hasField')->willReturn(TRUE);
    $values = new \ArrayObject();
    $message->method('set')->willReturnCallback(function ($field, $value) use ($values, $message) {
      $values[$field] = $value;
      return $message;
    });
    $message->method('get')->willReturnCallback(function ($field) use ($values) {
      $list = $this->createMock(FieldItemList::class);
      $list->method('__get')->with('value')->willReturn($values[$field] ?? NULL);
      $list->method('validate')->willReturn(new ConstraintViolationList());
      return $list;
    });
    return [$writer, $patient, $message, $storage, $calculator, $values, $database, $lock];
  }

  public function testDoctorSubmissionKeepsPatientOwnerAndCalculatedFields(): void {
    [$writer, $patient, $message, $storage, $calculator, $values] = $this->fixture();
    $fields = ['field_date' => '10/04/2026', 'field_weight' => 195, 'uid' => 99, 'field_author' => 99, 'field_program_day' => 999];
    $storage->expects($this->once())->method('create')->with($this->callback(fn($data) => $data['uid'] === 12 && $data['contact_form'] === 'tracking_weight'))->willReturn($message);
    $calculator->expects($this->once())->method('computeAll')->with($message, $fields, $patient)->willReturn(['message' => ['field_program_day' => 15], 'user' => ['field_net_weight_loss' => 5]]);
    $calculator->expects($this->once())->method('applyToMessage')->with($message, ['field_program_day' => 15]);
    $calculator->expects($this->once())->method('applyToUser')->with($patient, ['field_net_weight_loss' => 5]);
    $calculator->expects($this->once())->method('runEvaluations')->with($patient, $message, $fields);
    $message->expects($this->once())->method('save');
    $this->assertSame(['id' => 67, 'uuid' => 'log-uuid'], $writer->create($patient, $fields, 8));
    $this->assertSame('2026-10-04', $values['field_date']);
    $this->assertSame([['target_id' => '8']], $values['field_author']);
    $this->assertFalse($values->offsetExists('uid'));
    $this->assertFalse($values->offsetExists('field_program_day'));
  }

  /** @dataProvider invalidDoctorFields */
  public function testInvalidDoctorInputDoesNotWrite(array $fields, string $field): void {
    [$writer, $patient, $message, , $calculator, , $database] = $this->fixture();
    $message->expects($this->never())->method('save');
    $calculator->expects($this->never())->method('applyToUser');
    $database->expects($this->never())->method('startTransaction');
    try {
      $writer->createForDoctor($patient, $fields, 8);
      $this->fail('Invalid doctor input was accepted.');
    }
    catch (ProgressValidationException $error) {
      $this->assertContains($field, array_column($error->getIssues(), 'field'));
    }
  }

  public static function invalidDoctorFields(): array {
    $base = ['field_date' => '2025-01-01', 'field_weight' => 195, 'field_water_intake' => 100];
    return [
      'future date' => [array_replace($base, ['field_date' => '2999-01-01']), 'field_date'],
      'invalid date' => [array_replace($base, ['field_date' => '2025-02-30']), 'field_date'],
      'missing water' => [array_diff_key($base, ['field_water_intake' => 1]), 'field_water_intake'],
      'negative water' => [array_replace($base, ['field_water_intake' => -1]), 'field_water_intake'],
      'zero sugar' => [$base + ['field_blood_sugar' => 0], 'field_blood_sugar'],
      'bad pressure' => [$base + ['field_blood_pressure' => 'wrong'], 'field_blood_pressure'],
    ];
  }

  public function testDuplicateDoctorDateIsRejectedAndLockReleased(): void {
    [$writer, $patient, $message, $storage, , , , $lock] = $this->fixture();
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'count'] as $method) { $query->method($method)->willReturnSelf(); }
    $query->method('execute')->willReturn(1);
    $storage->method('getQuery')->willReturn($query);
    $message->expects($this->never())->method('save');
    $lock->expects($this->once())->method('release')->with('headless_progress:doctor_log:12:2025-01-01');
    $this->expectException(ProgressValidationException::class);
    $writer->createForDoctor($patient, ['field_date' => '2025-01-01', 'field_weight' => 195, 'field_water_intake' => 100], 8);
  }

  public function testBackdatedDoctorLogLeavesLatestStatisticsUnchanged(): void {
    [$writer, $patient, $message, $storage, $calculator, $values] = $this->fixture();
    $duplicate = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'count'] as $method) { $duplicate->method($method)->willReturnSelf(); }
    $duplicate->method('execute')->willReturn(0);
    $latestQuery = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort', 'range'] as $method) { $latestQuery->method($method)->willReturnSelf(); }
    $latestQuery->method('execute')->willReturn([66]);
    $storage->method('getQuery')->willReturnOnConsecutiveCalls($duplicate, $latestQuery);
    $latest = $this->createMock(Message::class);
    $date = $this->createMock(FieldItemList::class);
    $date->method('__get')->with('value')->willReturn('2025-01-02');
    $latest->method('get')->with('field_date')->willReturn($date);
    $storage->method('load')->with(66)->willReturn($latest);
    $storage->method('create')->willReturn($message);
    $fields = ['field_date' => '2025-01-01', 'field_weight' => 195, 'field_water_intake' => 100];
    $calculator->expects($this->once())->method('computeAll')->with($message, $fields, $patient)->willReturn(['message' => [], 'user' => ['field_net_weight_loss' => 5]]);
    $calculator->expects($this->never())->method('applyToUser');
    $calculator->expects($this->never())->method('runEvaluations');
    $message->expects($this->once())->method('save');
    $writer->createForDoctor($patient, $fields + ['field_grade' => 10, 'field_notes' => 'injected'], 8);
    $this->assertNull($values['field_grade']);
    $this->assertFalse($values->offsetExists('field_notes'));
    $this->assertSame([['target_id' => '8']], $values['field_author']);
  }

  /** @dataProvider invalidFields */
  public function testInvalidInputDoesNotWriteOrCalculate(array $fields, string $field): void {
    [$writer, $patient, $message, $storage, $calculator, , $database] = $this->fixture();
    $storage->method('create')->willReturn($message);
    $calculator->expects($this->never())->method('computeAll');
    $calculator->expects($this->never())->method('applyToUser');
    $message->expects($this->never())->method('save');
    $database->expects($this->never())->method('startTransaction');
    try {
      $writer->create($patient, $fields, 8);
      $this->fail('Invalid input must be rejected.');
    }
    catch (ProgressValidationException $error) {
      $this->assertContains($field, array_column($error->getIssues(), 'field'));
    }
  }

  public static function invalidFields(): array {
    return [
      'impossible date' => [['field_date' => '2026-02-30', 'field_weight' => 195], 'field_date'],
      'missing date' => [['field_weight' => 195], 'field_date'],
      'negative weight' => [['field_date' => '2026-10-04', 'field_weight' => -1], 'field_weight'],
      'non numeric water' => [['field_date' => '2026-10-04', 'field_weight' => 195, 'field_water_intake' => 'oops'], 'field_water_intake'],
    ];
  }
}
