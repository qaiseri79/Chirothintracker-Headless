<?php

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_access\PortalRoles;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\PatientIntakeService;
use Drupal\headless_patients\PatientPhaseMap;
use Drupal\headless_patients\PatientSummaryService;
use Drupal\headless_patients\PatientsService;
use Drupal\headless_progress\ProgressService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Checks initial and subsequent log pages, offsets, and boundary deltas.
 *
 * @group headless_patients
 */
class PatientSummaryLogsTest extends TestCase {

  /** @dataProvider pages */
  public function testLogPages(int $offset, int $fetchCount, int $count, bool $hasMore): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(98);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getRoles')->willReturn([PortalRoles::PATIENT_ENROLLED]);
    $user->method('hasField')->with('field_clinic')->willReturn(TRUE);
    $clinic = $this->createMock(FieldItemListInterface::class);
    $clinic->method('__get')->with('target_id')->willReturn(44);
    $user->method('get')->with('field_clinic')->willReturn($clinic);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->with(98)->willReturn($user);
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'count'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn(10);
    $messages = $this->createMock(EntityStorageInterface::class);
    $messages->method('getQuery')->willReturn($query);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['user', $users], ['contact_message', $messages]]);
    $entries = [];
    for ($i = $offset; $i < min(10, $offset + $fetchCount); $i++) {
      $entries[] = ['id' => 100 - $i, 'date' => '10/04/2026', 'day' => 10 - $i,
        'adherence' => 10, 'weight' => 190 + $i, 'flags' => [], 'water' => 100,
        'sleep' => 8, 'measurements' => [], 'lunch' => '', 'dinner' => '', 'other' => ''];
    }
    $progress = $this->createMock(ProgressService::class);
    $progress->expects($this->once())->method('forAccount')->with($user, $fetchCount, $offset, TRUE)->willReturn(['entries' => $entries]);
    $unused = fn(string $class) => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    $service = new PatientSummaryService($manager, new ClinicScope($manager),
      $unused(PatientsService::class), new PatientPhaseMap(), $progress,
      $unused(PatientIntakeService::class), $this->createMock(FlagServiceInterface::class),
      $this->createMock(Connection::class), $this->createMock(FileSystemInterface::class),
      $this->createMock(LockBackendInterface::class));
    $page = $service->section(44, 98, 'logs', $offset);
    $this->assertCount($count, $page['logsList']);
    $this->assertSame(10, $page['logsTotal']);
    $this->assertSame($offset + $count, $page['nextOffset']);
    $this->assertSame($hasMore, $page['hasMore']);
    $this->assertSame($hasMore ? -1 : NULL, $page['logsList'][$count - 1]['weightDelta']);
    $this->assertSame($offset === 0 ? -1 : NULL, $page['dailyLoss']);
  }

  public static function pages(): array {
    return [
      'initial five plus lookahead' => [0, 6, 5, TRUE],
      'three older plus lookahead' => [5, 4, 3, TRUE],
      'final partial page' => [8, 4, 2, FALSE],
    ];
  }
}
