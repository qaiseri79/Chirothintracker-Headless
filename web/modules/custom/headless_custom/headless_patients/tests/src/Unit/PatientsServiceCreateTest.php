<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\custom_module\Controller\EnrollmentLimit;
use Drupal\custom_module\Controller\UserCurrentProgramDay;
use Drupal\eck\Entity\EckEntity;
use Drupal\flag\FlagInterface;
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
 * Stands in for a field item list.
 *
 * Deliberately not a mock of FieldItemListInterface: `value` is a public property
 * on that class, not a method, so it cannot be asserted on or replaced through
 * PHPUnit's mock API. A two-property stub is the only way to fake a field's value
 * without booting a kernel.
 */
final class StubItemList {

  /** The field's value, as Drupal stores it. */
  public mixed $value;

  /**
   * The magic property an entity reference is usually read through.
   *
   * Mirrors `value` because for an entity reference the two name the same
   * column, and Drupal serves both off the first item.
   */
  public mixed $target_id;

  public function __construct(mixed $value = NULL) {
    $this->value = $value;
    $this->target_id = $value;
  }

  /**
   * A reference list shaped the way Drupal actually stores one.
   *
   * The plain constructor mirrors `target_id` from `value`, which is convenient but
   * hides a real bug: an entity reference keeps its id in `target_id` and has a NULL
   * computed `value`, even when a reference is definitely present. Code that reads
   * `->value` off a reference therefore passes here and returns nothing on the site.
   * This constructor models the real split so a test can catch that.
   */
  public static function reference(?int $id): self {
    $list = new self(NULL);
    $list->target_id = $id;
    return $list;
  }

  /**
   * Whether Drupal would consider this field unset.
   */
  public function isEmpty(): bool {
    // target_id counts as content: for a reference list the value is legitimately
    // NULL while the field holds something, and treating that as unset is exactly
    // what would make the location invisible.
    return ($this->value === NULL || $this->value === '') && $this->target_id === NULL;
  }

}

/**
 * @coversDefaultClass \Drupal\headless_patients\PatientsService
 *
 * @covers \Drupal\headless_patients\PatientsService::create
 */
class PatientsServiceCreateTest extends TestCase {

  /** The chiropractor's clinic, fixed so the tenancy assertions are readable. */
  private const CLINIC_ID = 13;

  /** The chiropractor enrolling the patient. */
  private const ACTOR_ID = 7;

  /** The id the created account is given. */
  private const NEW_UID = 42;

  /**
   * A date relative to today, as `Y-m-d`.
   *
   * Every date assertion in this file goes through here. A literal is only correct
   * until the day it passes, and the suite that enforces a past-date rule cannot be
   * allowed to fail because a fixture aged out — that failure is indistinguishable
   * from a real regression and gets "fixed" by editing the rule.
   *
   * @param int $days
   *   Positive for the future, negative for the past, 0 for today.
   */
  private static function inDays(int $days): string {
    return (new \DateTimeImmutable('today'))
      ->modify(sprintf('%+d days', $days))
      ->format('Y-m-d');
  }

  private EntityTypeManagerInterface $entityTypeManager;

  private EntityStorageInterface $userStorage;

  /**
   * Ids the user query reports as matching.
   *
   * Mutable rather than re-stubbed per test, because `getQuery()` is configured once
   * in {@see self::setUp()} and a test that tries to configure it again is silently
   * ignored by PHPUnit — which reads exactly like "the duplicate check did not run"
   * rather than like a mistake in the test. Writing into this from a test cannot be
   * ignored.
   *
   * @var array<int, int>
   */
  private array $userQueryMatches = [];

  /**
   * Conditions passed to the user query, in order.
   *
   * Lets a test assert *which* query ran, not just that one did — which is the only
   * way to tell an email uniqueness check that excluded the patient from one that
   * was never made.
   *
   * @var array<int, array{0: string, 1: mixed, 2?: string}>
   */
  private array $userQueryConditions = [];

  /**
   * How many times the user query's execute() was reached.
   */
  private int $userQueryRuns = 0;

  /**
   * Fields that must behave like entity references rather than scalars.
   *
   * `field_clinic_location` is a reference, and Drupal keeps its id in target_id
   * with a NULL computed value — a distinction a plain stub hides.
   *
   * @var array<string, int>
   */
  private array $referenceFields = [];

  private EntityStorageInterface $termStorage;

  private EntityStorageInterface $clinicStorage;

  private EntityStorageInterface $messageStorage;

  private UserInterface $user;

  private LoggerChannelInterface $logger;

  private FlagServiceInterface $flag;

  private Mailer $mailer;

  private EnrollmentLimit $enrollmentLimit;

  private UserCurrentProgramDay $programDay;

  /**
   * Shared rather than built inline in service(), so a test can say which account
   * requireUser() hands back when it exercises re-enrolment.
   */
  private ClinicScope $clinicScope;

  /** Field values the fake account reports, keyed by field name. */
  private array $fields = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->user = $this->createMock(UserInterface::class);
    $this->user->method('id')->willReturn(self::NEW_UID);
    $this->user->method('getEmail')->willReturn('patient@example.com');
    $this->user->method('getAccountName')->willReturn('patient@example.com');
    $this->user->method('getRoles')->willReturn(['enrolled_patient']);

    // The account is modelled as having every field this module writes to, so a
    // skipped write is a real failure rather than a missing field being quietly
    // tolerated. `hasField()` therefore answers from this map, and `set()` writes
    // back into it, which is what the assertions read.
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
      function (string $name) {
        // Fields modelled as entity references, keyed by name. Kept as state rather
        // than a per-test re-stub, because re-stubbing `get()` in a test is silently
        // ignored and the symptom of that is a field test that cannot fail.
        if (isset($this->referenceFields[$name])) {
          return StubItemList::reference($this->referenceFields[$name]);
        }
        return new StubItemList($this->fields[$name] ?? NULL);
      }
    );
    $this->user->method('set')->willReturnCallback(
      function (string $name, $value) {
        $this->fields[$name] = is_array($value) ? reset($value) : $value;
        return $this->user;
      }
    );

    // Configured once, here, and driven from the `$userQuery*` properties rather than
    // re-stubbed by a test: a second `getQuery()` configuration in a test is ignored
    // by PHPUnit, and the symptom of that is a duplicate-email check that appears
    // not to run at all — which is indistinguishable from a passing test.
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
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
    $this->userStorage->method('create')->willReturn($this->user);
    $this->userStorage->method('load')->willReturn(
      $this->createMock(UserInterface::class)
    );

    $this->termStorage = $this->createMock(EntityStorageInterface::class);
    $this->clinicStorage = $this->createMock(EntityStorageInterface::class);
    $this->messageStorage = $this->createMock(EntityStorageInterface::class);

    $storages = [
      'user' => $this->userStorage,
      'taxonomy_term' => $this->termStorage,
      'clinic' => $this->clinicStorage,
      'contact_message' => $this->messageStorage,
    ];
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn (string $type) => $storages[$type] ?? throw new \LogicException("No fake storage for $type.")
    );

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->flag = $this->createMock(FlagServiceInterface::class);
    $this->mailer = $this->createMock(Mailer::class);
    $this->enrollmentLimit = $this->createMock(EnrollmentLimit::class);
    $this->programDay = $this->createMock(UserCurrentProgramDay::class);
    $this->clinicScope = $this->createMock(ClinicScope::class);
  }

  /**
   * Builds the service under test with the shared mocks.
   */
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
      'name' => 'Dana Patient',
      'email' => 'patient@example.com',
    ];
  }

  /**
   * Makes the legacy quota helper report a status.
   *
   * Stubs limitForClinic() rather than Limit() because that is the entry point the
   * service now uses: it takes the clinic explicitly, so the quota is evaluated
   * against the clinic the request was scoped to rather than against whichever
   * account happens to be in the session. The `with()` assertion is what pins that
   * down — without it, a return to the session-derived method would still satisfy
   * these tests and quietly put the quota back in the chiropractor's hands instead
   * of the practice's.
   */
  private function quota(int $status): void {
    $this->enrollmentLimit->method('limitForClinic')
      ->with($this->identicalTo(self::CLINIC_ID))
      ->willReturn([
        'enrollment_status' => $status,
        'message' => $status === 0 ? '' : 'You have reached your enrollment limit.',
        'type' => 'warning',
      ]);
  }

  /**
   * A stand-in for a `clinic` entity.
   *
   * A PHPUnit mock of ECK's own base class rather than a hand-written object,
   * because the service now checks that what it loaded is an ECK entity of the
   * `clinic_location` bundle. A stub implementing only hasField() and get() would
   * fail that check for the wrong reason — it would not be an ECK entity at all —
   * and "a real location is accepted" would stop proving anything about locations.
   * Verified against the running site: real clinic and clinic_location entities are
   * both `EckEntity`, which implements the interface.
   *
   * @param int|null $parentClinicId
   *   The parent clinic, or NULL for an entity with no parent set.
   * @param string $bundle
   *   The bundle to report. Defaults to the location bundle; pass the main clinic
   *   bundle to test that a clinic is not accepted as one of its own locations.
   */
  private function clinicEntity(?int $parentClinicId, string $bundle = 'clinic_location'): EckEntity {
    $entity = $this->createMock(EckEntity::class);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('hasField')->willReturnCallback(
      fn (string $name): bool => $name === 'field_clinic'
    );
    $entity->method('get')->willReturnCallback(
      fn (string $name): StubItemList => new StubItemList(
        $name === 'field_clinic' ? $parentClinicId : NULL
      )
    );

    return $entity;
  }

  /**
   * The program phase must be stored as its value, not the form's code.
   *
   * This is the regression the generalised endpoint was supposed to fix: the old
   * create() wrote the code straight into the field, and because the roster reads
   * it back through the map — which reports an unrecognised value as NULL — every
   * phase ever written by the endpoint showed as a blank column rather than an
   * error.
   *
   * @covers ::create
   */
  public function testStoresTheProgramPhaseValueNotTheFormCode(): void {
    $this->quota(0);

    $result = $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['phase' => 'L']),
      self::ACTOR_ID,
    );

    $this->assertSame('phase-2', $this->fields['field_weight_loss_phase']);
    // And the value must survive the round trip back to the API, or the fix is
    // only half done: the roster still has to show the code the form sent.
    $this->assertSame('L', $result['patient']['phase']);
  }

  /**
   * Every phase code maps to its value, not just the one that was broken.
   *
   * @covers ::create
   * @dataProvider phaseProvider
   */
  public function testMapsEveryPhaseCodeToItsStoredValue(string $code, string $stored): void {
    $this->quota(0);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['phase' => $code]),
      self::ACTOR_ID,
    );

    $this->assertSame($stored, $this->fields['field_weight_loss_phase']);
  }

  /**
   * Phase codes and the values they must be stored as.
   *
   * @return array<string, array{string, string}>
   *   Cases keyed by the code.
   */
  public static function phaseProvider(): array {
    return [
      // Copied from PatientPhaseMap::PHASES rather than guessed, because the
      // whole point of the assertion is that these two columns are not the same.
      'Losing' => ['L', 'phase-2'],
      'Loading' => ['D', 'phase-1'],
      'Zero-Day' => ['Z', 'phase-0'],
      'Continuity' => ['C', 'phase-4'],
      'Cycling' => ['M', 'phase-3'],
    ];
  }

  /**
   * An absent phase lands on the legacy default rather than staying unset.
   *
   * @covers ::create
   */
  public function testAbsentPhaseFallsBackToLoadingPhase(): void {
    $this->quota(0);

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertSame('phase-1', $this->fields['field_weight_loss_phase']);
  }

  /**
   * The clinic and chiropractor come from the session, never the payload.
   *
   * @covers ::create
   */
  public function testClinicAndChiropractorAreNeverTakenFromThePayload(): void {
    $this->quota(0);

    $this->service()->create(
      self::CLINIC_ID,
      // Both of these are deliberately hostile: a client that could name either
      // could enrol a patient into a competitor's clinic.
      $this->payload(['clinic' => 999, 'chiropractor' => 4242]),
      self::ACTOR_ID,
    );

    $this->assertSame(self::CLINIC_ID, $this->fields['field_clinic']);
    $this->assertSame(self::ACTOR_ID, $this->fields['field_chiropractor']);
  }

  /**
   * A blank start date becomes the next Saturday.
   *
   * @covers ::create
   */
  public function testBlankProgramStartDefaultsToNextSaturday(): void {
    $this->quota(0);

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $stored = $this->fields['field_program_start_date'];
    $this->assertSame('6', (string) (new \DateTimeImmutable($stored))->format('w'));
    $this->assertGreaterThan(new \DateTimeImmutable('today'), new \DateTimeImmutable($stored));
  }

  /**
   * A supplied start date is left alone.
   *
   * @covers ::create
   */
  public function testSuppliedProgramStartIsKept(): void {
    $this->quota(0);
    // Relative rather than a literal: a hardcoded date is only "in the future" until
    // the day it passes, and this suite then fails for a reason that has nothing to
    // do with the code. `self::inDays()` keeps the intent ("a date the chiropractor
    // could pick") without the countdown.
    $start = self::inDays(21);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['programStart' => $start]),
      self::ACTOR_ID,
    );

    $this->assertSame($start, $this->fields['field_program_start_date']);
  }

  /**
   * A start date in the past is refused when creating a record.
   *
   * @covers ::create
   */
  public function testPastProgramStartIsRejectedOnCreate(): void {
    $this->quota(0);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['programStart' => self::inDays(-30)]),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertSame(
        'Program start cannot be in the past.',
        $e->getErrors()['programStart'] ?? NULL
      );
    }
  }

  /**
   * Today is not in the past, so it is accepted.
   *
   * The boundary case, and the one most likely to be got wrong by an off-by-one: a
   * patient starting this evening is starting today, not last week.
   *
   * @covers ::create
   */
  public function testProgramStartTodayIsAccepted(): void {
    $this->quota(0);
    $start = self::inDays(0);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['programStart' => $start]),
      self::ACTOR_ID,
    );

    $this->assertSame($start, $this->fields['field_program_start_date']);
  }

  /**
   * A malformed date is still a format error, not a past-date error.
   *
   * @covers ::create
   */
  public function testMalformedProgramStartIsStillAFormatError(): void {
    $this->quota(0);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['programStart' => '14-03-2026']),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame('Use a date in YYYY-MM-DD format.', $e->getErrors()['programStart'] ?? NULL);
    }
  }

  /**
   * The opt-out field is the inverse of what the form asks.
   *
   * @covers ::create
   */
  public function testEmailNotificationsAreStoredInverted(): void {
    $this->quota(0);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['emailNotifications' => FALSE]),
      self::ACTOR_ID,
    );

    $this->assertSame(1, $this->fields['field_email_optout']);
  }

  /**
   * The legacy webform's inverted spelling still works.
   *
   * @covers ::create
   */
  public function testLegacyDisableEmailNotificationsSpellingIsAccepted(): void {
    $this->quota(0);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['disableEmailNotifications' => TRUE]),
      self::ACTOR_ID,
    );

    $this->assertSame(1, $this->fields['field_email_optout']);
  }

  /**
   * Notifications are on unless a client says otherwise.
   *
   * @covers ::create
   */
  public function testNotificationsDefaultToOptedIn(): void {
    $this->quota(0);

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertSame(0, $this->fields['field_email_optout']);
  }

  /**
   * A reached hard limit refuses the patient without writing anything.
   *
   * @covers ::create
   */
  public function testHardEnrollmentLimitRejectsWithConflictAndWritesNothing(): void {
    $this->quota(2);
    $this->userStorage->expects($this->never())->method('create');

    try {
      $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(409, $e->getStatusCode());
      $this->assertStringContainsString('limit', $e->getMessage());
    }
  }

  /**
   * A nearly reached limit still enrols, and says so.
   *
   * @covers ::create
   */
  public function testSoftEnrollmentLimitStillCreatesAndWarns(): void {
    $this->quota(1);

    $result = $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertSame(self::NEW_UID, $result['patient']['id']);
    $this->assertSame('You have reached your enrollment limit.', $result['warning']);
  }

  /**
   * A clear quota produces no warning key noise.
   *
   * @covers ::create
   */
  public function testNoQuotaMeansNoWarning(): void {
    $this->quota(0);

    $result = $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertNull($result['warning']);
  }

  /**
   * A laser status from the wrong vocabulary is refused.
   *
   * @covers ::create
   */
  public function testLaserStatusFromAnotherVocabularyIsRejected(): void {
    $this->quota(0);
    $term = $this->createMock(TermInterface::class);
    $term->method('bundle')->willReturn('some_other_vocabulary');
    $this->termStorage->method('load')->willReturn($term);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['laserStatus' => '400']),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('laserStatus', $e->getErrors());
    }
  }

  /**
   * A genuine laser status is written.
   *
   * @covers ::create
   */
  public function testLaserStatusInItsVocabularyIsAccepted(): void {
    $this->quota(0);
    $term = $this->createMock(TermInterface::class);
    $term->method('bundle')->willReturn('laser_patient_status');
    $this->termStorage->method('load')->willReturn($term);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['laserStatus' => '402']),
      self::ACTOR_ID,
    );

    $this->assertSame('402', $this->fields['field_laser_patient_status']);
  }

  /**
   * A clinic location belonging to another clinic is refused.
   *
   * This is the cross-tenant check. `field_clinic` is safe because it is never
   * read from the payload; `clinicLocation` would not be, unless it is proven.
   *
   * @covers ::create
   */
  public function testClinicLocationFromAnotherClinicIsRejected(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(999));

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['clinicLocation' => 55]),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('clinicLocation', $e->getErrors());
    }
  }

  /**
   * A location of the caller's own clinic is accepted.
   *
   * @covers ::create
   */
  public function testOwnClinicLocationIsAccepted(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['clinicLocation' => 55]),
      self::ACTOR_ID,
    );

    $this->assertSame(55, $this->fields['field_clinic_location']);
  }

  /**
   * A location with no parent cannot be proven, so it is refused.
   *
   * @covers ::create
   */
  public function testClinicLocationWithoutAParentIsRejected(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(NULL));

    $this->expectException(PatientsException::class);
    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['clinicLocation' => 55]),
      self::ACTOR_ID,
    );
  }

  /**
   * Every legacy program-day step runs for the new account.
   *
   * @covers ::create
   */
  public function testRunsEveryProgramDayStepForTheNewAccount(): void {
    $this->quota(0);
    foreach (['userRoles', 'userProfile', 'UserClinician', 'Evaluations', 'CalculateGoal'] as $step) {
      $this->programDay->expects($this->once())
        ->method($step)
        ->with(self::NEW_UID);
    }

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);
  }

  /**
   * The welcome mail goes out through the shared mailer.
   *
   * @covers ::create
   */
  public function testSendsTheWelcomeMail(): void {
    $this->quota(0);
    $this->mailer->expects($this->once())
      ->method('sendAccountCreated')
      ->with($this->user);

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);
  }

  /**
   * field_locked is cleared so a new patient is editable.
   *
   * @covers ::create
   */
  public function testClearsFieldLocked(): void {
    $this->quota(0);

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertFalse($this->fields['field_locked']);
  }

  /**
   * One failing post-save step must not lose the patient.
   *
   * The account is already committed when these run, so throwing here would give
   * the caller a 500 for a patient who exists — and a chiropractor who retried
   * would hit the duplicate-email check instead.
   *
   * @covers ::create
   */
  public function testAFailingPostSaveStepDoesNotLoseThePatient(): void {
    $this->quota(0);
    $this->programDay->method('userRoles')
      ->willThrowException(new \RuntimeException('views exploded'));
    // Everything after the failure must still be attempted.
    $this->programDay->expects($this->once())->method('CalculateGoal')->with(self::NEW_UID);
    $this->mailer->expects($this->once())->method('sendAccountCreated');

    $result = $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertSame(self::NEW_UID, $result['patient']['id']);
  }

  /**
   * A failing welcome mail does not undo the enrolment.
   *
   * @covers ::create
   */
  public function testAFailedWelcomeMailDoesNotUndoTheEnrolment(): void {
    $this->quota(0);
    $this->mailer->method('sendAccountCreated')
      ->willThrowException(new \RuntimeException('no transport'));

    $result = $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);

    $this->assertSame(self::NEW_UID, $result['patient']['id']);
  }

  /**
   * An intake submission is linked and flagged for the chiropractor.
   *
   * @covers ::create
   */
  public function testLinksAndFlagsTheIntakeSubmission(): void {
    $this->quota(0);
    // FlagService::getFlagging() and ::flag() both type their entity argument as
    // EntityInterface, so a bare object would raise a TypeError here rather than
    // faking the "not flagged yet" case.
    $this->messageStorage->method('load')
      ->willReturn($this->createMock(EntityInterface::class));

    $flagObject = $this->createMock(FlagInterface::class);
    $this->flag->method('getFlagById')->with('intake_processed_cs')->willReturn($flagObject);
    $this->flag->method('getFlagging')->willReturn(NULL);
    $this->flag->expects($this->once())->method('flag');

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['intakeSubmission' => '77']),
      self::ACTOR_ID,
    );

    $this->assertSame(77, $this->fields['field_intake_form']);
  }

  /**
   * An already-flagged submission is not flagged a second time.
   *
   * Drupal rejects a duplicate flagging outright, so this would be a hard error
   * on a retry.
   *
   * @covers ::create
   */
  public function testDoesNotFlagAnAlreadyProcessedSubmission(): void {
    $this->quota(0);
    $this->messageStorage->method('load')
      ->willReturn($this->createMock(EntityInterface::class));

    $this->flag->method('getFlagById')->willReturn($this->createMock(FlagInterface::class));
    $this->flag->method('getFlagging')->willReturn(
      $this->createMock(\Drupal\flag\FlaggingInterface::class)
    );
    $this->flag->expects($this->never())->method('flag');

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['intakeSubmission' => '77']),
      self::ACTOR_ID,
    );
  }

  /**
   * Enrolling from the dashboard does no intake flagging at all.
   *
   * @covers ::create
   */
  public function testNoIntakeSubmissionMeansNoFlagWork(): void {
    $this->quota(0);
    $this->flag->expects($this->never())->method('flag');

    $this->service()->create(self::CLINIC_ID, $this->payload(), self::ACTOR_ID);
  }

  /**
   * An unknown intake submission is a client error.
   *
   * @covers ::create
   */
  public function testUnknownIntakeSubmissionIsRejected(): void {
    $this->quota(0);
    $this->messageStorage->method('load')->willReturn(NULL);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['intakeSubmission' => '77']),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertArrayHasKey('intakeSubmission', $e->getErrors());
    }
  }

  /**
   * A blank name or a malformed email is reported per field.
   *
   * @covers ::create
   */
  public function testValidationErrorsAreReportedTogether(): void {
    $this->quota(0);

    try {
      $this->service()->create(self::CLINIC_ID, ['name' => '', 'email' => 'nope'], self::ACTOR_ID);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('name', $e->getErrors());
      $this->assertArrayHasKey('email', $e->getErrors());
    }
  }

  /**
   * A second bad field does not discard the first.
   *
   * @covers ::create
   */
  public function testBadLaserStatusDoesNotHideOtherErrors(): void {
    $this->quota(0);
    $this->termStorage->method('load')->willReturn(NULL);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['laserStatus' => '400', 'goalWeight' => 'heavy']),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertArrayHasKey('laserStatus', $e->getErrors());
      $this->assertArrayHasKey('goalWeight', $e->getErrors());
    }
  }

  /**
   * An archived account, and a record of what was done to it.
   *
   * Re-enrolment mutates an existing account rather than creating one, so it
   * needs a fake that starts out holding `archived_patient` and records the role
   * swap and the save. The shared $user cannot be reused: its roles are stubbed
   * once in setUp and cannot be changed per test.
   *
   * @return array{0: UserInterface, 1: object}
   *   The fake account, and a recorder with `roles`, `saved` and `added`.
   */
  private function archivedAccount(): array {
    $recorder = new class {

      /** Role ids currently held, as the fake account reports them. */
      public array $roles = ['archived_patient'];

      /** How many times save() was called. */
      public int $saved = 0;

      /** The roles handed to addRole(). */
      public array $added = [];

      /**
       * The account's current email, read back by getEmail().
       *
       * Mutable because re-enrollment can change it, and both the before and the
       * after have to be assertable.
       */
      public string $email = 'returning@example.com';

      /** Every address handed to setEmail(). */
      public array $emailsSet = [];

    };

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(self::NEW_UID);
    // Mutable rather than a fixed willReturn(), because re-enrollment can now change
    // the address and a test has to see both the value it started with and the one
    // that was written.
    $user->method('getEmail')->willReturnCallback(fn (): string => $recorder->email);
    $user->method('setEmail')->willReturnCallback(
      function (string $mail) use ($recorder, $user) {
        $recorder->email = $mail;
        $recorder->emailsSet[] = $mail;
        return $user;
      }
    );
    $user->method('getAccountName')->willReturn('returning@example.com');
    $user->method('getRoles')->willReturnCallback(fn (): array => $recorder->roles);
    $user->method('removeRole')->willReturnCallback(
      function (string $role) use ($recorder, $user) {
        $recorder->roles = array_values(
          array_diff($recorder->roles, [$role])
        );
        return $user;
      }
    );
    $user->method('addRole')->willReturnCallback(
      function (string $role) use ($recorder, $user) {
        $recorder->roles[] = $role;
        $recorder->added[] = $role;
        return $user;
      }
    );
    $user->method('save')->willReturnCallback(
      function () use ($recorder) {
        $recorder->saved++;
        return 1;
      }
    );
    // The roster row reads these, so they have to answer for a successful enrol.
    $user->method('hasField')->willReturn(TRUE);
    // Read from the shared field map rather than answering NULL for everything, so
    // a location written during re-enrolment can be asserted, and a location that
    // was already on the account can be asserted as left untouched.
    $user->method('get')->willReturnCallback(
      function (string $name) {
        // Reference-shaped where a test asked for it, for the same reason the shared
        // fake does the same: `field_clinic_location` is a reference, and reading it
        // as a scalar succeeds against a naive stub and returns nothing on the site.
        if (isset($this->referenceFields[$name])) {
          return StubItemList::reference($this->referenceFields[$name]);
        }
        return new StubItemList($this->fields[$name] ?? NULL);
      }
    );
    $user->method('set')->willReturnCallback(
      function (string $name, $value) use ($user) {
        $this->fields[$name] = is_array($value) ? reset($value) : $value;
        return $user;
      }
    );

    return [$user, $recorder];
  }

  /**
   * Puts the fake archived account in the clinic and returns the recorder.
   */
  private function serviceWithArchivedPatient(): object {
    [$user, $recorder] = $this->archivedAccount();
    $this->clinicScope->method('requireUser')->willReturn($user);

    return $recorder;
  }

  /**
   * Re-enrolment spends a slot, so the hard limit must stop it.
   *
   * The cap is counted from the `enrolled_patient` role, which an archived
   * patient does not hold. Re-enrolling hands it back, so without this check the
   * limit could be stepped past by archiving and re-enrolling the same people.
   *
   * @covers ::enroll
   */
  public function testHardEnrollmentLimitBlocksReenrollment(): void {
    $this->quota(2);
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(409, $e->getStatusCode());
    }

    // Nothing may have been written: no save, and no role swap.
    $this->assertSame(0, $recorder->saved, 'A blocked re-enrolment must not save.');
    $this->assertSame([], $recorder->added, 'A blocked re-enrolment must not swap roles.');
    $this->assertSame(['archived_patient'], $recorder->roles, 'Must still be archived.');
  }

  /**
   * A soft limit does not block, it warns, exactly as on create.
   *
   * @covers ::enroll
   */
  public function testSoftEnrollmentLimitReenrollsAndWarns(): void {
    $this->quota(1);
    $recorder = $this->serviceWithArchivedPatient();

    $result = $this->service()->enroll(self::CLINIC_ID, self::NEW_UID);

    $this->assertNotNull($result['warning'], 'A soft limit must warn.');
    $this->assertSame(1, $recorder->saved);
    $this->assertContains('enrolled_patient', $recorder->roles);
    $this->assertNotContains('archived_patient', $recorder->roles);
    $this->assertSame('patient', $result['patient']['role']);
  }

  /**
   * Under the limit the response shape is unchanged.
   *
   * @covers ::enroll
   */
  public function testReenrollmentUnderTheLimitReturnsNoWarning(): void {
    $this->quota(0);
    $this->serviceWithArchivedPatient();

    $result = $this->service()->enroll(self::CLINIC_ID, self::NEW_UID);

    $this->assertNull($result['warning']);
    $this->assertArrayHasKey('patient', $result);
  }

  /**
   * The two 409s must not be confused for one another.
   *
   * An account that is not archived is a different problem from being over quota,
   * and reporting "over quota" for an account that was never archived would send
   * the chiropractor looking in the wrong place entirely.
   *
   * @covers ::enroll
   */
  public function testAnEnrolledAccountIsRejectedBeforeTheQuotaIsConsulted(): void {
    $this->quota(2);
    // Starts out already enrolled, so the archived-role guard trips first.
    [$user, $recorder] = $this->archivedAccount();
    $recorder->roles = ['enrolled_patient'];
    $this->clinicScope->method('requireUser')->willReturn($user);

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(409, $e->getStatusCode());
      $this->assertStringContainsString('not currently archived', $e->getMessage());
    }

    $this->assertSame(0, $recorder->saved);
  }

  /**
   * The quota message reaches the client as text.
   *
   * The legacy helper returns markup for a Drupal message that rendered HTML. It
   * is carried here as a JSON string a React client renders as text, so shipping
   * it raw would show the chiropractor a literal "<p>".
   *
   * @covers ::enroll
   * @covers ::create
   */
  public function testQuotaMessagesArriveAsPlainText(): void {
    $this->enrollmentLimit->method('limitForClinic')->willReturn([
      'enrollment_status' => 1,
      'message' => '<p>You have reached your limit. Email '
        . '<a href="mailto:support@chirothintracker.com">support@chirothintracker.com</a>.</p>',
      'type' => 'warning',
    ]);
    $this->serviceWithArchivedPatient();

    $warning = $this->service()->enroll(self::CLINIC_ID, self::NEW_UID)['warning'];

    $this->assertStringNotContainsString('<p>', $warning);
    $this->assertStringNotContainsString('<a ', $warning);
    $this->assertStringContainsString('support@chirothintracker.com', $warning);
  }

  /**
   * A blank helper message still produces something to show.
   *
   * The helper returns no `message` key at all when a clinic has no enrollment
   * package attached, so the fallback cannot assume one is there.
   *
   * @covers ::enroll
   */
  public function testAnEmptyQuotaMessageFallsBackToSomethingReadable(): void {
    $this->enrollmentLimit->method('limitForClinic')->willReturn(['enrollment_status' => 2]);
    $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertNotSame('', trim($e->getMessage()));
    }
  }

  /**
   * The clinic's own locations come back as id/name pairs, name-sorted.
   *
   * This is what the Add Patient dropdown and the re-enrollment step are built
   * from, so it has to be the same set of entities cleanClinicLocation() will
   * accept: the `clinic_location` bundle under this clinic. Sorting is asserted
   * because the query asks for it and the dropdown reads in list order — a list
   * that came back in database order would render differently on each page.
   *
   * @covers ::clinicLocations
   */
  public function testClinicLocationsAreTheClinicsOwnSortedByName(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([292, 240]);
    $this->clinicStorage->method('getQuery')->willReturn($query);

    $this->clinicStorage->method('loadMultiple')->willReturn([
      292 => $this->locationEntity(292, 'Site 2'),
      240 => $this->locationEntity(240, 'Satellite Clinic'),
    ]);

    $this->assertSame(
      [
        ['id' => 292, 'name' => 'Site 2'],
        ['id' => 240, 'name' => 'Satellite Clinic'],
      ],
      $this->service()->clinicLocations(self::CLINIC_ID),
      'Locations are returned in query order, which is name order.'
    );
  }

  /**
   * The location query conditions on `type`, not `bundle`, and asks for no `status`.
   *
   * Both of these were wrong on the first attempt and neither failed a unit test,
   * because a mocked query accepts any condition it is given. They only surfaced
   * against the running site:
   *
   * - `clinic` declares its bundle key as `type` and its base table has no `bundle`
   *   column, so `condition('bundle', ...)` raises a QueryException. `type` carries
   *   the same value `bundle()` reports.
   * - ECK adds no `status` base field to this entity type, so `condition('status', 1)`
   *   raises the same way.
   *
   * So the query's conditions are asserted here, against the real field names checked
   * against the live database, because the failure mode is a 500 on the Patients page
   * rather than anything the previous assertions could see.
   *
   * @covers ::clinicLocations
   */
  public function testTheLocationQueryUsesFieldsThatExistOnTheClinicEntity(): void {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $query->method('condition')->willReturnCallback(
      function (string $field, $value) use (&$conditions, $query) {
        $conditions[$field] = $value;
        return $query;
      }
    );
    $this->clinicStorage->method('getQuery')->willReturn($query);

    $this->service()->clinicLocations(self::CLINIC_ID);

    $this->assertSame(
      ['type' => 'clinic_location', 'field_clinic' => self::CLINIC_ID],
      $conditions,
      'The bundle is selected by `type`, scoped to the parent, and nothing else.'
    );
    $this->assertArrayNotHasKey(
      'status',
      $conditions,
      'This entity type has no `status` base field, so conditioning on one throws.'
    );
  }

  /**
   * A clinic with no locations is an empty list, not a failure.
   *
   * @covers ::clinicLocations
   */
  public function testClinicWithNoLocationsReturnsAnEmptyList(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->clinicStorage->method('getQuery')->willReturn($query);

    $this->assertSame([], $this->service()->clinicLocations(self::CLINIC_ID));
  }

  /**
   * An untitled location is still listed, labelled by id.
   *
   * Dropping it would make the dropdown silently disagree with the ids the
   * backend accepts; showing a blank option would give the chiropractor two
   * identical empty choices.
   *
   * @covers ::clinicLocations
   */
  public function testAnUntitledLocationIsListedRatherThanShownBlank(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([240]);
    $this->clinicStorage->method('getQuery')->willReturn($query);
    $this->clinicStorage->method('loadMultiple')->willReturn([
      240 => $this->locationEntity(240, '   '),
    ]);

    $this->assertSame(
      [['id' => 240, 'name' => 'Location 240']],
      $this->service()->clinicLocations(self::CLINIC_ID)
    );
  }

  /**
   * A location stand-in that can report a label and an id.
   */
  private function locationEntity(int $id, string $label): EckEntity {
    $entity = $this->createMock(EckEntity::class);
    $entity->method('id')->willReturn($id);
    $entity->method('label')->willReturn($label);
    $entity->method('bundle')->willReturn('clinic_location');

    return $entity;
  }

  /**
   * A clinic is not one of its own locations.
   *
   * Both bundles are `clinic` entities and the main clinic bundle is the one that
   * carries `field_clinic`, so on the parent check alone a clinic would satisfy
   * every condition and a chiropractor could point a patient at a different
   * practice. This is the check that stops it.
   *
   * @covers ::create
   */
  public function testAClinicIsNotAcceptedAsOneOfItsOwnLocations(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID, 'clinic'));

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['clinicLocation' => self::CLINIC_ID]),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('clinicLocation', $e->getErrors());
    }
  }

  /**
   * Re-enrolling can record which location the patient is returning to.
   *
   * Re-enrolling did not touch the location before, so a patient came back onto
   * whatever branch they had left — and most archived patients have no location at
   * all, so there was often nothing to come back onto. Only a chiropractor can
   * decide which branch they return to, which is why this is an input to the
   * action rather than a derived value.
   *
   * @covers ::enroll
   */
  public function testReenrollmentRecordsTheChosenLocation(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['clinicLocation' => 55]);

    $this->assertSame(55, $this->fields['field_clinic_location']);
  }

  /**
   * A location belonging to another clinic is refused, and nothing is written.
   *
   * The same cross-tenant rule as create, on a different entry point. Without it the
   * re-enrollment path would be a way around the create path's check: the same id
   * is refused on one and accepted on the other.
   *
   * @covers ::enroll
   */
  public function testReenrollmentRefusesAnotherClinicsLocation(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(999));
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['clinicLocation' => 55]);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('clinicLocation', $e->getErrors());
    }

    $this->assertSame(0, $recorder->saved, 'A refused location must not save.');
    $this->assertSame(['archived_patient'], $recorder->roles, 'Must still be archived.');
  }

  /**
   * Re-enrolling without naming a location leaves the existing one alone.
   *
   * Sending no location is not the same as clearing it. Most archived patients do
   * carry a location, and a client that has not been updated to offer the choice
   * would otherwise silently wipe it on every re-enrollment.
   *
   * @covers ::enroll
   */
  public function testReenrollmentWithoutALocationKeepsTheExistingOne(): void {
    $this->quota(0);
    $this->fields['field_clinic_location'] = 77;
    $recorder = $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID);

    $this->assertSame(77, $this->fields['field_clinic_location'], 'The existing location must survive.');
    $this->assertSame(1, $recorder->saved);
  }

  /**
   * An explicitly empty location is treated as "no choice made", not "clear it".
   *
   * @covers ::enroll
   */
  public function testReenrollmentWithABlankLocationKeepsTheExistingOne(): void {
    $this->quota(0);
    $this->fields['field_clinic_location'] = 77;
    $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['clinicLocation' => '']);

    $this->assertSame(77, $this->fields['field_clinic_location']);
  }

  /**
   * Every field the confirmation form offers is written when it is sent.
   *
   * The form is prefilled from the archived row, so an untouched confirmation sends
   * back exactly what was already stored; this asserts the whole set makes the round
   * trip rather than only the location the operation started as.
   *
   * @covers ::enroll
   */
  public function testReenrollmentWritesEveryFieldTheFormConfirms(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();
    $start = self::inDays(21);

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, [
      'name' => '  Dana Reeve  ',
      'email' => 'Dana.Reeve@Example.com',
      'phone' => '555-0142',
      'programStart' => $start,
      'startWeight' => 187.5,
      'goalWeight' => 162,
      'emailNotifications' => FALSE,
      'phase' => 'C',
      'clinicLocation' => 55,
    ]);

    $this->assertSame('Dana Reeve', $this->fields['field_full_name'], 'Name is trimmed.');
    $this->assertSame('555-0142', $this->fields['field_phone_number']);
    $this->assertSame($start, $this->fields['field_program_start_date']);
    $this->assertSame(187.5, $this->fields['field_program_start_weight']);
    $this->assertSame(162.0, $this->fields['field_goal_weight']);
    // Inverted on the way in: the form asks "send them", the field records "stop them".
    $this->assertSame(1, $this->fields['field_email_optout']);
    $this->assertSame(55, $this->fields['field_clinic_location']);
    // Lower-cased, because mail is stored that way and the uniqueness check compares
    // against a lower-cased value.
    $this->assertSame('dana.reeve@example.com', $recorder->email);
    $this->assertSame(1, $recorder->saved);
  }

  /**
   * An archived patient's own past start date does not block their re-enrollment.
   *
   * This is the exception the past-date rule has to carry, and it exists because of
   * what an archived record is: a patient who really did start in 2023 is still shown
   * as having started in 2023. Re-sending that unchanged date is not a choice, it is
   * the same fact arriving again, so it is tolerated. Without this, every returning
   * patient would need their real history corrected before they could come back.
   *
   * @covers ::enroll
   */
  public function testReenrollmentToleratesTheArchivedPatientsOwnPastStartDate(): void {
    $this->quota(0);
    $stored = self::inDays(-900);
    $this->fields['field_program_start_date'] = $stored;
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['programStart' => $stored]);

    $this->assertSame($stored, $this->fields['field_program_start_date'], 'The stored date survives.');
    $this->assertSame(1, $recorder->saved, 'Re-enrolment must go through.');
    $this->assertNotContains('archived_patient', $recorder->roles, 'Must no longer be archived.');
    $this->assertContains('enrolled_patient', $recorder->roles, 'Must be enrolled.');
  }

  /**
   * A different past date is still refused when re-enrolling.
   *
   * The counterpart to the tolerance above, and the one that keeps it honest: the
   * exception is for the date on file, not for past dates as a category. Replacing a
   * real start date with a different past one is a new choice and is judged as one.
   *
   * @covers ::enroll
   */
  public function testReenrollmentRejectsADifferentPastStartDate(): void {
    $this->quota(0);
    $this->fields['field_program_start_date'] = self::inDays(-900);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, [
        'programStart' => self::inDays(-30),
      ]);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertSame(
        'Program start cannot be in the past.',
        $e->getErrors()['programStart'] ?? NULL
      );
    }

    $this->assertSame(0, $recorder->saved, 'A rejected re-enrolment must not save.');
    $this->assertSame(['archived_patient'], $recorder->roles, 'Must still be archived.');
  }

  /**
   * A past date is refused for a patient who has none on file.
   *
   * With nothing stored there is no history to preserve, so the tolerance has nothing
   * to attach to and the plain rule applies. Most archived patients are in this state,
   * which makes it the common case rather than an edge one.
   *
   * @covers ::enroll
   */
  public function testReenrollmentRejectsAPastStartDateWhenNoneIsStored(): void {
    $this->quota(0);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, [
        'programStart' => self::inDays(-1),
      ]);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertArrayHasKey('programStart', $e->getErrors());
    }

    $this->assertSame(0, $recorder->saved, 'A rejected re-enrolment must not save.');
  }

  /**
   * A forward date is accepted when re-enrolling.
   *
   * @covers ::enroll
   */
  public function testReenrollmentAcceptsAFutureStartDate(): void {
    $this->quota(0);
    $start = self::inDays(14);
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['programStart' => $start]);

    $this->assertSame($start, $this->fields['field_program_start_date']);
  }

  /**
   * A payload naming one field leaves every other field on the account alone.
   *
   * This is the assertion that protects a returning patient's record. The obvious
   * implementation — validate the whole payload into a complete `$values` array and
   * hand it to `applyFields()` — writes NULL over `field_program_start_weight` and
   * `field_goal_weight` on every re-enrollment, because create fills those two keys
   * whether or not the caller sent them. A patient who has never recorded a weight is
   * unharmed; one who has is not, and they lose it by coming back.
   *
   * @covers ::enroll
   */
  public function testReenrollmentLeavesFieldsThePayloadDidNotMention(): void {
    $this->quota(0);
    $this->fields['field_program_start_weight'] = 187.5;
    $this->fields['field_goal_weight'] = 162.0;
    $this->fields['field_phone_number'] = '555-0100';
    $this->fields['field_program_start_date'] = '2025-11-01';
    $this->fields['field_email_optout'] = 0;
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();

    // Only the location is named, which is what a client that has not been updated
    // to send the rest would do.
    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['clinicLocation' => 55]);

    $this->assertSame(55, $this->fields['field_clinic_location'], 'The named field is written.');
    $this->assertSame(187.5, $this->fields['field_program_start_weight'], 'Start weight must survive.');
    $this->assertSame(162.0, $this->fields['field_goal_weight'], 'Goal weight must survive.');
    $this->assertSame('555-0100', $this->fields['field_phone_number'], 'Phone must survive.');
    $this->assertSame('2025-11-01', $this->fields['field_program_start_date'], 'Start date must survive.');
    $this->assertSame(0, $this->fields['field_email_optout'], 'The email preference must survive.');
    $this->assertSame(1, $recorder->saved);
  }

  /**
   * A blank weight in the payload does not clear the stored one.
   *
   * Distinct from the omitted case above: here the chiropractor has a weight on the
   * form, has deleted it, and confirmed anyway. The record is still left alone,
   * because "no longer recorded" is a deliberate edit to a clinical measurement and
   * not something to infer from an empty box on a form whose purpose is to confirm a
   * return.
   *
   * @covers ::enroll
   */
  public function testABlankWeightLeavesTheStoredOneAlone(): void {
    $this->quota(0);
    $this->fields['field_goal_weight'] = 162.0;
    $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['goalWeight' => '']);

    $this->assertSame(162.0, $this->fields['field_goal_weight']);
  }

  /**
   * A mistyped goal weight is refused when creating a record.
   *
   * The digit a key slips. 3434340 is really stored on an account in this clinic, and
   * 5155770000 on another, so this is not hypothetical: without a bound a slip becomes
   * the patient's goal.
   *
   * @covers ::create
   */
  public function testAbsurdGoalWeightIsRejectedOnCreate(): void {
    $this->quota(0);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['goalWeight' => 3434340]),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertSame('Enter a weight in pounds, up to 1000.', $e->getErrors()['goalWeight'] ?? NULL);
    }
  }

  /**
   * The bound applies to a start weight on the same terms.
   *
   * @covers ::create
   */
  public function testAbsurdStartWeightIsRejectedOnCreate(): void {
    $this->quota(0);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['startWeight' => 8485]),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertArrayHasKey('startWeight', $e->getErrors());
    }
  }

  /**
   * A negative weight is refused.
   *
   * Not a rounding question: -250 is stored as somebody's goal, and a negative body is
   * not a reading at all.
   *
   * @covers ::create
   */
  public function testNegativeWeightIsRejectedOnCreate(): void {
    $this->quota(0);

    try {
      $this->service()->create(
        self::CLINIC_ID,
        $this->payload(['goalWeight' => -250]),
        self::ACTOR_ID,
      );
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame('Weight cannot be negative.', $e->getErrors()['goalWeight'] ?? NULL);
    }
  }

  /**
   * A weight just inside the bound is accepted, so the limit is not off by much.
   *
   * @covers ::create
   */
  public function testWeightJustInsideTheBoundIsAccepted(): void {
    $this->quota(0);

    $this->service()->create(
      self::CLINIC_ID,
      $this->payload(['goalWeight' => 1000]),
      self::ACTOR_ID,
    );

    $this->assertSame(1000.0, $this->fields['field_goal_weight']);
  }

  /**
   * The frontend and the backend must agree on the weight ceiling.
   *
   * There is no way to share one constant between a TypeScript module and a PHP class,
   * so the bound is written down twice: `MAX_WEIGHT_LBS` in
   * `frontend/src/lib/patients/weight.ts`, and `PatientsService::MAX_WEIGHT_LBS` here.
   * Nothing at runtime connects them, which means raising the bound in one place and
   * forgetting the other produces a limit that quietly differs depending on which side
   * of the stack rejects the request — the form accepting what the endpoint refuses, or
   * the reverse.
   *
   * This reads the TypeScript file and compares the two, so the moment someone edits
   * one and not the other the suite says so. It is a text comparison, not an import:
   * the point is to catch the edit, not to depend on the frontend at runtime.
   *
   * @covers ::cleanWeight
   */
  public function testWeightCeilingMatchesTheFrontendConstant(): void {
    // tests/src/Unit -> up eight levels is the project root, beside web/ and frontend/.
    $ts = dirname(__DIR__, 8) . '/frontend/src/lib/patients/weight.ts';
    if (!is_file($ts)) {
      // A Drupal-only checkout has no frontend. Nothing to compare against, and no
      // drift to detect either.
      $this->markTestSkipped('Frontend not present: ' . $ts);
    }

    $source = (string) file_get_contents($ts);
    $matched = preg_match('/MAX_WEIGHT_LBS\s*=\s*(\d+(?:\.\d+)?)/', $source, $m);

    $this->assertSame(
      1,
      $matched,
      'Could not find a numeric MAX_WEIGHT_LBS in frontend/src/lib/patients/weight.ts.',
    );
    // Compared as a float because the constant may legitimately be a whole number
    // written without a decimal point, and 1000.0 === 1000 under assertSame on floats.
    $this->assertEqualsWithDelta(
      (float) $m[1],
      1000.0,
      0.0,
      'The weight ceiling differs between the frontend and the backend. Raise it in '
        . 'frontend/src/lib/patients/weight.ts and PatientsService::MAX_WEIGHT_LBS '
        . 'together, or the two will disagree about which weights are acceptable.',
    );
  }

  /**
   * Re-enrolling tolerates an absurd weight already on the record.
   *
   * The exception the bound has to carry, and the reason it is not simply "reject
   * anything outside 0-1000": the archive really does hold values like 3434340, and the
   * patients carrying them still have to be able to come back. Their number is left
   * exactly as it is until somebody corrects it on purpose.
   *
   * @covers ::enroll
   */
  public function testReenrollmentToleratesAnAbsurdStoredWeight(): void {
    $this->quota(0);
    $this->fields['field_goal_weight'] = 3434340.0;
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['goalWeight' => 3434340]);

    $this->assertSame(3434340.0, $this->fields['field_goal_weight'], 'The stored number is left alone.');
    $this->assertSame(1, $recorder->saved, 'Re-enrolment must go through.');
  }

  /**
   * A different absurd weight is refused when re-enrolling.
   *
   * Without this the tolerance would be a hole: any client could pass the bound by
   * sending a large number the record does not already hold.
   *
   * @covers ::enroll
   */
  public function testReenrollmentRejectsADifferentAbsurdWeight(): void {
    $this->quota(0);
    $this->fields['field_goal_weight'] = 162.0;
    $this->clinicStorage->method('load')->willReturn($this->clinicEntity(self::CLINIC_ID));
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['goalWeight' => 3334343]);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertArrayHasKey('goalWeight', $e->getErrors());
    }

    $this->assertSame(0, $recorder->saved, 'A rejected re-enrolment must not save.');
    $this->assertSame(162.0, $this->fields['field_goal_weight'], 'The stored weight must survive.');
  }

  /**
   * Re-confirming the patient's own address is accepted.
   *
   * The form prefills the email, so an untouched confirmation sends it back, and
   * without excluding this account from the uniqueness check the patient matches
   * themselves, the endpoint reports "A patient with that email already exists in
   * this clinic", and re-enrollment becomes impossible from the only screen that
   * offers it — a check that is accurate about the clinic and useless to the caller.
   *
   * @covers ::enroll
   */
  public function testReenrollmentAcceptsThePatientsOwnUnchangedEmail(): void {
    $this->quota(0);
    $recorder = $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, [
      'email' => 'returning@example.com',
    ]);

    $this->assertSame([], $recorder->emailsSet, 'An unchanged address is not rewritten.');
    $this->assertSame(1, $recorder->saved, 'The re-enrollment must go through.');
  }

  /**
   * An unchanged email is not checked against the clinic at all.
   *
   * The companion to the test above: that one would also pass if the query simply
   * found nothing. Here the storage query reports a hit, so the only way the
   * re-enrollment can succeed is if no uniqueness check ran for an unchanged address
   * — which is what keeps this off a query per confirmation.
   *
   * @covers ::enroll
   */
public function testAnUnchangedEmailIsNotCheckedAgainstTheClinicAtAll(): void {
    $this->quota(0);
    // A self-match is queued, but for an unchanged address the check is skipped
    // before any query is issued, so what it would have returned is never read. The
    // companion test above would also pass if the query simply found nothing; this
    // one fails if a query runs at all.
    $this->userQueryMatches = [self::NEW_UID];

    $recorder = $this->serviceWithArchivedPatient();

    $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, [
      'email' => 'returning@example.com',
    ]);

    $this->assertSame(0, $this->userQueryRuns, 'No uniqueness query may be issued.');
    $this->assertSame(1, $recorder->saved);
  }

  /**
   * An address that belongs to a different patient in the same clinic is refused.
   *
   * The exclusion is scoped to the account being edited, not a blanket exemption: a
   * genuine clash elsewhere in the clinic still has to be caught, or re-enrollment
   * would become a way to collide two accounts that create would have stopped.
   *
   * @covers ::enroll
   */
public function testReenrollmentRefusesAnEmailBelongingToAnotherPatient(): void {
    $this->quota(0);
    $this->userQueryMatches = [4242];
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, [
        'email' => 'someone.else@example.com',
      ]);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('email', $e->getErrors());
    }

    $this->assertSame(1, $this->userQueryRuns, 'The clash has to be looked for.');
    $this->assertContains(
      ['uid', self::NEW_UID, '<>'],
      $this->userQueryConditions,
      'The exemption is for this patient only; a clash with anyone else still bites.',
    );
    $this->assertSame(0, $recorder->saved, 'A refused email must not save.');
    $this->assertSame([], $recorder->emailsSet, 'A refused email must not be written.');
    $this->assertSame(['archived_patient'], $recorder->roles, 'Must still be archived.');
  }

  /**
   * An explicitly emptied name is refused rather than silently ignored.
   *
   * The fields are optional, but a caller that sent one and sent it empty has said
   * something wrong — not "leave it alone", which is what omitting the key means.
   * Clearing a patient's name because the form rendered an empty box would be the
   * worst version of this feature.
   *
   * @covers ::enroll
   */
  public function testReenrollmentRefusesAnExplicitlyEmptiedName(): void {
    $this->quota(0);
    $recorder = $this->serviceWithArchivedPatient();

    try {
      $this->service()->enroll(self::CLINIC_ID, self::NEW_UID, ['name' => '   ']);
      $this->fail('Expected a PatientsException.');
    }
    catch (PatientsException $e) {
      $this->assertSame(422, $e->getStatusCode());
      $this->assertArrayHasKey('name', $e->getErrors());
    }

    $this->assertSame(0, $recorder->saved);
    $this->assertSame(['archived_patient'], $recorder->roles);
  }

  /**
   * The archived row reports the location off the reference, not off `->value`.
   *
   * `field_clinic_location` is an entity_reference: Drupal keeps the id in
   * `target_id` and leaves the computed `value` NULL even when a reference is
   * definitely there. Reading it the scalar way is silently wrong rather than loudly
   * broken — writes kept succeeding the whole time, so the only symptom was an
   * archive that showed no location for any patient who had one, and a
   * re-enrollment form that could not preselect where the patient actually was.
   *
   * @covers ::archived
   */
  public function testTheArchivedRowReportsAReferencedLocation(): void {
    // Reference-shaped: an id in target_id and NULL in value, which is what the
    // field really holds. A stub that mirrored one onto the other would have passed
    // against the broken code, which is how this went unnoticed in the first place.
    $this->referenceFields['field_clinic_location'] = 292;

    [$user] = $this->archivedAccount();
    $this->userStorage->method('loadMultiple')->willReturn([self::NEW_UID => $user]);

    $rows = $this->service()->archived(self::CLINIC_ID);

    $this->assertCount(1, $rows);
    $this->assertSame(292, $rows[0]['clinicLocation'], 'The location must reach the form.');
  }

  /**
   * An archived patient with no location reports none, not a zero.
   *
   * The other half of the reference contract: an absent reference is NULL, and 0 is
   * not a clinic. A cast that reached for `->value` and found nothing could easily
   * have produced a 0 here, which the form would then try to preselect.
   *
   * @covers ::archived
   */
  public function testAnArchivedRowWithoutALocationReportsNull(): void {
    [$user] = $this->archivedAccount();
    $this->userStorage->method('loadMultiple')->willReturn([self::NEW_UID => $user]);

    $rows = $this->service()->archived(self::CLINIC_ID);

    $this->assertCount(1, $rows);
    $this->assertArrayHasKey('clinicLocation', $rows[0]);
    $this->assertNull($rows[0]['clinicLocation']);
  }

}