<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\custom_module\Controller\EnrollmentLimit;
use Drupal\custom_module\Controller\UserCurrentProgramDay;
use Drupal\eck\Entity\EckEntity;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_mail\Mailer;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\headless_patients\PatientPhaseMap;
use Drupal\headless_patients\PatientsService;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\TestCase;

/**
 * The `action: account` update path: editing a patient's identity and program.
 *
 * Uses the same fake account as {@see PatientsServiceCreateTest} — every field
 * this module writes to modelled as present, so a skipped write is a real
 * failure rather than a missing field being quietly tolerated.
 *
 * @coversDefaultClass \Drupal\headless_patients\PatientsService
 *
 * @covers \Drupal\headless_patients\PatientsService::updateAccount
 */
final class PatientsServiceAccountTest extends TestCase {

  /** The chiropractor's clinic. */
  private const CLINIC_ID = 13;

  /** The patient being edited. */
  private const PATIENT_ID = 42;

  /** The chiropractor making the edit. */
  private const ACTOR_ID = 7;

  /**
   * A date relative to today, as `Y-m-d`, so fixtures never age out.
   */
  private static function inDays(int $days): string {
    return (new \DateTimeImmutable('today'))
      ->modify(sprintf('%+d days', $days))
      ->format('Y-m-d');
  }

  private EntityTypeManagerInterface $entityTypeManager;

  private EntityStorageInterface $userStorage;

  private EntityStorageInterface $termStorage;

  private EntityStorageInterface $clinicStorage;

  private UserInterface $user;

  private LoggerChannelInterface $logger;

  private FlagServiceInterface $flag;

  private Mailer $mailer;

  private EnrollmentLimit $enrollmentLimit;

  private UserCurrentProgramDay $programDay;

  private ClinicScope $clinicScope;

  /**
   * The roles the account reports, so a test can make it archived.
   *
   * @var array<int, string>
   */
  private array $roles = ['enrolled_patient'];

  /** The email the account reports. */
  private string $email = 'patient@example.com';

  /** Field values the fake account reports, keyed by field name. */
  private array $fields = [];

  /** Raw values passed to set(), in order, for assertions on clears. */
  private array $setCalls = [];

  /** The email handed to setEmail(). */
  private ?string $savedEmail = NULL;

  /**
   * Ids the user query reports as matching, for the email uniqueness check.
   *
   * @var array<int, int>
   */
  private array $userQueryMatches = [];

  /**
   * @var array<int, array{0: string, 1: mixed, 2?: string}>
   */
  private array $userQueryConditions = [];

  private int $userQueryRuns = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->user = $this->createMock(UserInterface::class);
    $this->user->method('id')->willReturn(self::PATIENT_ID);
    $this->user->method('getEmail')->willReturnCallback(fn (): string => $this->email);
    $this->user->method('getAccountName')->willReturn('patient@example.com');
    $this->user->method('getRoles')->willReturnCallback(fn (): array => $this->roles);

    $this->fields = array_fill_keys([
      'field_clinic',
      'field_chiropractor',
      'field_full_name',
      'field_phone_number',
      'field_program_start_date',
      'field_program_start_weight',
      'field_goal_weight',
      'field_email_optout',
      'field_weight_loss_phase',
      'field_laser_patient_status',
      'field_clinic_location',
      'field_intake_form',
      'field_locked',
    ], NULL);

    $this->user->method('hasField')->willReturnCallback(
      fn (string $name): bool => array_key_exists($name, $this->fields)
    );
    $this->user->method('get')->willReturnCallback(
      fn (string $name) => new StubItemList($this->fields[$name] ?? NULL)
    );
    $this->user->method('set')->willReturnCallback(
      function (string $name, $value) {
        $this->setCalls[] = [$name, $value];
        $this->fields[$name] = is_array($value) ? reset($value) : $value;
        return $this->user;
      }
    );
    $this->user->method('setEmail')->willReturnCallback(
      function (string $mail) {
        $this->savedEmail = $mail;
        return $this->user;
      }
    );

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      function (string $field, $value, ?string $operator = NULL) use ($query): QueryInterface {
        $this->userQueryConditions[] = [$field, $value, $operator];
        return $query;
      }
    );
    $query->method('execute')->willReturnCallback(
      function (): array {
        $this->userQueryRuns++;
        return $this->userQueryMatches;
      }
    );

    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->userStorage->method('getQuery')->willReturn($query);

    $laserStatus = $this->createMock(TermInterface::class);
    $laserStatus->method('bundle')->willReturn('laser_patient_status');
    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->termStorage->method('load')->willReturn($laserStatus);

    $this->clinicStorage = $this->createMock(EntityStorageInterface::class);
    $this->clinicStorage->method('load')->willReturnCallback(
      function (int $id): EckEntity {
        $entity = $this->createMock(EckEntity::class);
        $entity->method('bundle')->willReturn('clinic_location');
        $entity->method('hasField')->willReturnCallback(
          fn (string $name): bool => $name === 'field_clinic'
        );
        $entity->method('get')->willReturnCallback(
          fn (string $name): StubItemList => new StubItemList(
            $name === 'field_clinic' ? self::CLINIC_ID : NULL
          )
        );

        return $entity;
      }
    );

    $storages = [
      'user' => $this->userStorage,
      'taxonomy_term' => $this->termStorage,
      'clinic' => $this->clinicStorage,
    ];
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn (string $type) => $storages[$type] ?? $this->createMock(EntityStorageInterface::class)
    );

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->flag = $this->createMock(FlagServiceInterface::class);
    $this->mailer = $this->createMock(Mailer::class);
    $this->enrollmentLimit = $this->createMock(EnrollmentLimit::class);
    $this->programDay = $this->createMock(UserCurrentProgramDay::class);
    $this->clinicScope = $this->createMock(ClinicScope::class);
    $this->clinicScope->method('requireUser')->willReturn($this->user);
  }

  private function service(): PatientsService {
    return new PatientsService(
      $this->entityTypeManager,
      $this->clinicScope,
      new PatientPhaseMap(),
      $this->logger,
      $this->flag,
      $this->mailer,
      $this->enrollmentLimit,
      $this->programDay,
    );
  }

  /**
   * A payload that passes validation, for tests that vary one field at a time.
   */
  private function payload(array $overrides = []): array {
    return $overrides + [
      'firstName' => 'Ada',
      'lastName' => 'Patient',
      'email' => 'changed@example.com',
      'programStart' => self::inDays(30),
      'startWeight' => '200',
      'goalWeight' => '150',
      'clinicLocation' => '5',
      'laserStatus' => '3',
    ];
  }

  private function update(array $payload): array {
    return $this->service()->updateAccount(
      self::CLINIC_ID,
      self::PATIENT_ID,
      $payload,
      self::ACTOR_ID,
    );
  }

  /**
   * A valid edit writes every field the form sends, and reports the fresh row.
   */
public function testWritesEveryEditedField(): void {
    $result = $this->update($this->payload());

    $this->assertSame('Ada Patient', $this->fields['field_full_name']);
    $this->assertSame('changed@example.com', $this->savedEmail);
    $this->assertSame(self::inDays(30), $this->fields['field_program_start_date']);
    $this->assertSame(200.0, $this->fields['field_program_start_weight']);
    $this->assertSame(150.0, $this->fields['field_goal_weight']);
    $this->assertSame(5, $this->fields['field_clinic_location']);
    $this->assertSame('3', $this->fields['field_laser_patient_status']);
    // The roster must reflect the edit, or the table goes stale behind the toast.
    $this->assertSame('Ada Patient', $result['patient']['name']);
  }

  /**
   * The login name is not part of the account form.
   *
   * Renaming the patient's full name must not rename the Drupal account, which is
   * what the patient signs in with — the username is deliberately left alone.
   */
  public function testNeverRenamesTheUsername(): void {
    $this->user->expects($this->never())->method('setUsername');

    $this->update($this->payload());
  }

  /**
   * The email check excludes the account being edited.
   */
  public function testOwnEmailIsNotReportedAsDuplicate(): void {
    $this->update($this->payload(['email' => 'PATIENT@example.com']));

    $this->assertNull($this->savedEmail);
    // No query at all proves the self-address never reached the uniqueness check.
    $this->assertSame(0, $this->userQueryRuns);
  }

  /**
   * Another patient's email in the same clinic is rejected.
   */
  public function testRejectsAnotherPatientsEmail(): void {
    $this->userQueryMatches = [999];

    try {
      $this->update($this->payload(['email' => 'taken@example.com']));
      $this->fail('Expected the duplicate email to be rejected.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('email', $e->getErrors());
    }

    // The check must have been scoped and have excluded the patient themselves.
    $this->assertSame('mail', $this->userQueryConditions[0][0]);
    $this->assertSame(self::CLINIC_ID, $this->userQueryConditions[1][1]);
    $this->assertSame('uid', $this->userQueryConditions[2][0]);
    $this->assertSame(self::PATIENT_ID, $this->userQueryConditions[2][1]);
    $this->assertSame('<>', $this->userQueryConditions[2][2]);
  }

  /**
   * A blank clinic location is the "- None -" choice: it clears the reference.
   */
  public function testBlankClinicLocationClearsTheReference(): void {
    $this->update($this->payload(['clinicLocation' => '']));

    $clears = array_values(array_filter(
      $this->setCalls,
      fn (array $call): bool => $call[0] === 'field_clinic_location'
    ));
    $this->assertCount(1, $clears);
    $this->assertSame([], $clears[0][1]);
  }

  /**
   * Blank name, email and status are identity fields and must not be empty.
   */
  public function testBlankIdentityFieldsAreRejected(): void {
    foreach (['name' => 'firstName', 'email' => 'email', 'laserStatus' => 'laserStatus'] as $key => $field) {
      try {
        $this->update($this->payload([$field => '']));
        $this->fail("Expected the blank $field to be rejected.");
      }
      catch (PatientsException $e) {
        $this->assertSame(422, $e->getStatusCode());
        $this->assertArrayHasKey($key, $e->getErrors());
      }
    }
  }

  /**
   * A diverged program start date is rejected, not silently overwritten.
   */
  public function testRejectsAStartDateFromAnotherProgram(): void {
    // The patient is already mid-program on a past start date.
    $this->fields['field_program_start_date'] = self::inDays(-60);

    try {
      $this->update($this->payload(['programStart' => self::inDays(-10)]));
      $this->fail('Expected the foreign start date to be rejected.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('programStart', $e->getErrors());
    }
  }

  /**
   * An archived account is not editable through this path.
   */
  public function testRejectsAnArchivedAccount(): void {
    $this->roles = ['archived_patient'];

    try {
      $this->update($this->payload());
      $this->fail('Expected the archived account to be rejected.');
    }
    catch (PatientsException $e) {
      $this->assertSame(409, $e->getStatusCode());
    }
  }

}