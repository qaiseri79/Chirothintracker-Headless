<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\PatientIntakeService;
use Drupal\headless_patients\PatientPhaseMap;
use Drupal\headless_patients\PatientSummaryService;
use Drupal\headless_patients\PatientsService;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\headless_progress\ProgressService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless_patients */
class PatientSummaryScopeTest extends TestCase {

  /**
   * @dataProvider patientAccounts */
  public function testPatientBoundary(array $roles, bool $active, int $requestedClinic, bool $allowed): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn($active);
    $user->method('getRoles')->willReturn($roles);
    $user->method('hasField')->with('field_clinic')->willReturn(TRUE);
    $clinic = $this->createMock(FieldItemListInterface::class);
    $clinic->method('__get')->with('target_id')->willReturn(44);
    $user->method('get')->with('field_clinic')->willReturn($clinic);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(98)->willReturn($user);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($storage);
    // Detail services must remain unused until the patient boundary succeeds.
    $unused = fn(string $class) => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    $service = new PatientSummaryService($manager, new ClinicScope($manager),
      $unused(PatientsService::class), new PatientPhaseMap(), $unused(ProgressService::class),
      $unused(PatientIntakeService::class), $this->createMock(FlagServiceInterface::class),
      $this->createMock(Connection::class), $this->createMock(FileSystemInterface::class),
      $this->createMock(LockBackendInterface::class));
    if (!$allowed) {
      try {
        $service->requirePatient($requestedClinic, 98);
        $this->fail('An ineligible account was exposed.');
      }
      catch (PatientsException $e) {
        $this->assertSame(404, $e->getStatusCode());
      }
    }
    else {
      $this->assertSame($user, $service->requirePatient($requestedClinic, 98));
    }
  }

  public function patientAccounts(): array {
    return [
      'enrolled patient' => [['enrolled_patient'], TRUE, 44, TRUE],
      'archived patient' => [['archived_patient'], TRUE, 44, TRUE],
      'foreign clinic patient' => [['enrolled_patient'], TRUE, 45, FALSE],
      'blocked patient' => [['enrolled_patient'], FALSE, 44, FALSE],
      'doctor is not a patient' => [['chiropractor_active_'], TRUE, 44, FALSE],
      'retired patient role' => [['patient_chirothin'], TRUE, 44, FALSE],
      'roleless user' => [[], TRUE, 44, FALSE],
    ];
  }

}
