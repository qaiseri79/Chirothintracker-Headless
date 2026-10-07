<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\custom_module\Controller\EnrollmentLimit;
use Drupal\custom_module\Controller\UserCurrentProgramDay;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_access\PortalRoles;
use Drupal\headless_mail\Mailer;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\PatientPhaseMap;
use Drupal\headless_patients\PatientsService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * Which roles survive onto the patient roster.
 *
 * @group headless_patients
 *
 * @coversDefaultClass \Drupal\headless_patients\PatientsService
 *
 * @covers \Drupal\headless_patients\PatientsService::partition
 */
class PatientsServiceRosterRolesTest extends TestCase {

  /** The clinic the roster is read for. */
  private const CLINIC_ID = 13;

  /**
   * @dataProvider rosterRoles
   *
   * @param string[] $roles
   *   The account's roles.
   * @param string $expected
   *   The tab the account lands on, or 'none' to be dropped entirely.
   */
  public function testRosterRoles(array $roles, string $expected): void {
    $partition = $this->partitionFor([$this->account(5, $roles)]);

    $landed = [];
    if ($partition['active'] !== []) {
      $landed[] = 'active';
    }
    if ($partition['archived'] !== []) {
      $landed[] = 'archived';
    }

    $this->assertSame($expected === 'none' ? [] : [$expected], $landed);
  }

  /**
   * A brand-new clinic reads as empty rather than as containing its doctor.
   *
   * This is the reported case, kept as its own test rather than left to the
   * provider: the single row was the symptom, and a clinic whose only member is
   * its own practitioner is the one shape where the old behaviour was visible at
   * all. With real patients present the doctor sorted to the bottom and read as
   * one odd row; alone, it was the whole list.
   */
  public function testClinicWithOnlyItsDoctorHasAnEmptyRoster(): void {
    $doctor = $this->account(9, [PortalRoles::CHIROPRACTOR_INACTIVE]);

    $partition = $this->partitionFor([$doctor]);

    $this->assertSame([], $partition['active']);
    $this->assertSame([], $partition['archived']);
  }

  /**
   * A patient's row is unaffected by a doctor sitting in the same clinic.
   *
   * The exclusion drops accounts, not tabs, so the guard is that dropping the
   * doctor must not take the patients with it.
   */
  public function testPatientsRemainWhenADoctorIsAlsoInTheClinic(): void {
    $partition = $this->partitionFor([
      $this->account(5, [PortalRoles::CHIROPRACTOR_INACTIVE]),
      $this->account(6, ['enrolled_patient']),
      $this->account(7, ['archived_patient']),
    ]);

    $this->assertCount(1, $partition['active']);
    $this->assertSame(6, $partition['active'][0]['id']);
    $this->assertSame('patient', $partition['active'][0]['role']);
    $this->assertCount(1, $partition['archived']);
    $this->assertSame(7, $partition['archived'][0]['id']);
  }

  /**
   * The role boundary.
   *
   * Both chiropractor forms are dropped, and the inactive one is the entry that
   * actually changes the reported behaviour: a doctor is created inactive and
   * only becomes `chiropractor_active_` once a subscription grants portal write,
   * so excluding only the active form left a brand-new doctor as the sole row on
   * an otherwise empty clinic's roster.
   */
  public function rosterRoles(): array {
    return [
      'enrolled patient' => [['enrolled_patient'], 'active'],
      'archived patient' => [['archived_patient'], 'archived'],
      'pending chiropractor dropped' => [[PortalRoles::CHIROPRACTOR_INACTIVE], 'none'],
      'active chiropractor dropped' => [[PortalRoles::CHIROPRACTOR_ACTIVE], 'none'],
      'administrator dropped' => [[PortalRoles::ADMINISTRATOR], 'none'],
      'system manager dropped' => [['system_manager'], 'none'],
      'white label chiropractor dropped' => [['chiropractor_white_label'], 'none'],
      'other staff stays' => [[PortalRoles::ECOMMERCE_MANAGER], 'active'],
      // The exclusion is tested before the archived split, so a staff role wins
      // even when the account also carries a patient role.
      'enrolled patient who is also a chiropractor' => [
        ['enrolled_patient', PortalRoles::CHIROPRACTOR_ACTIVE],
        'none',
      ],
      'roleless account stays as staff' => [[], 'active'],
    ];
  }

  /**
   * An account with no field data, which is what a staff member looks like.
   *
   * Every field reader in the row builders guards on `hasField()` first, so
   * reporting no fields exercises the same empty-cell path a real staff account
   * takes without having to fake a single field value.
   */
  private function account(int $uid, array $roles): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($uid);
    $user->method('getRoles')->willReturn($roles);
    $user->method('getEmail')->willReturn("user$uid@example.com");
    $user->method('getAccountName')->willReturn("user$uid");
    $user->method('hasField')->willReturn(FALSE);
    return $user;
  }

  /**
   * Runs partition() over a fixed set of accounts.
   *
   * The query itself is stubbed to hand back every account: the clinic and status
   * conditions are Drupal's job and are not what this test is about. The roster
   * split and the role exclusion both happen in PHP, after the load, and that is
   * exactly what a stubbed query leaves under test.
   *
   * @param UserInterface[] $users
   *   The clinic's accounts.
   *
   * @return array{active: array<int, array<string, mixed>>, archived: array<int, array<string, mixed>>}
   *   The two tabs.
   */
  private function partitionFor(array $users): array {
    $uids = array_map(fn (UserInterface $user): int => (int) $user->id(), $users);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn($uids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($users);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($storage);

    $service = new PatientsService(
      $manager,
      new ClinicScope($manager),
      new PatientPhaseMap(),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(FlagServiceInterface::class),
      $this->createMock(Mailer::class),
      $this->createMock(EnrollmentLimit::class),
      $this->createMock(UserCurrentProgramDay::class),
    );

    return $service->partition(self::CLINIC_ID);
  }

}
