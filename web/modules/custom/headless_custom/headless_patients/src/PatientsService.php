<?php

declare(strict_types=1);

namespace Drupal\headless_patients;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\custom_module\Controller\EnrollmentLimit;
use Drupal\custom_module\Controller\UserCurrentProgramDay;
use Drupal\eck\EckEntityInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_mail\Mailer;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\user\UserInterface;

/**
 * Reads and writes the clinic's patient roster.
 *
 * A "patient" here is a user entity, "archived" is a role rather than a status,
 * and every query in this class is scoped to a single clinic by
 * {@see \Drupal\headless_patients\ClinicScope}. The queries use
 * `accessCheck(FALSE)` because the clinic condition *is* the authorisation: a
 * caller may read any account in their own clinic regardless of who owns it,
 * which entity-level access control cannot express. Dropping the clinic
 * condition while keeping the access check bypass would be a cross-tenant leak,
 * so the two always travel together.
 */
class PatientsService {

  /** Enrolled patients are the roster's default member. */
  public const ROLE_ENROLLED = 'enrolled_patient';

  /** Archiving swaps this role in; nothing else records archived status. */
  public const ROLE_ARCHIVED = 'archived_patient';

  /**
   * Chiropractors. Neither form appears on the roster — see
   * {@see self::ROSTER_EXCLUDED_ROLES}.
   */
  public const ROLE_CHIROPRACTOR_ACTIVE = 'chiropractor_active_';

  public const ROLE_CHIROPRACTOR_INACTIVE = 'chiropractor_inactive_';

  /** Roles the design calls a Health Coach, which this codebase has not named. */
  private const ROLE_STAFF_FALLBACK = 'coach';

  /**
   * Roles dropped from both roster tabs before anything is read off the account.
   *
   * These are people who run the clinic rather than appear on it.
   *
   * `administrator` and `system_manager` are site-level staff and normally hold
   * no `field_clinic`, so the clinic condition already keeps them out; naming
   * them makes the rule true on its own terms instead of incidentally, and it
   * holds if one is ever given a clinic. `chiropractor_white_label` is a real
   * role — custom_module pairs it with `patient_white_label`.
   *
   * Both chiropractor forms are listed, and the inactive one matters most: an
   * account is created `chiropractor_inactive_` (Registration) and only becomes
   * `chiropractor_active_` once a subscription grants portal write
   * (SubscriptionService), so excluding only the active form left a brand-new
   * doctor as the sole row on the roster of an otherwise empty clinic. A clinic
   * with no patients should read as empty rather than as containing its own
   * practitioner.
   *
   * One consequence worth knowing: no roster row can now be a doctor, so
   * {@see self::rosterRole()} returns `doctor` only through {@see
   * self::toRosterRow()}'s other callers — the single-patient detail views and
   * PatientSummaryService, which already narrows to `enrolled_patient`. The
   * branch is left in place rather than deleted for that reason.
   */
  private const ROSTER_EXCLUDED_ROLES = [
    'administrator',
    'system_manager',
    self::ROLE_CHIROPRACTOR_ACTIVE,
    self::ROLE_CHIROPRACTOR_INACTIVE,
    'chiropractor_white_label',
  ];

  /**
   * The program phase a brand-new patient starts in.
   *
   * `D`, Loading Phase, which is what `createNewPatient()` hardcoded in
   * custom_module, and it is the *code* rather than the stored value because it
   * goes through cleanPhase() and stored() like any client-supplied phase. (The
   * stored value happens to be `phase-1`; writing that here instead would be
   * silently invalid, since `stored()` maps codes and would hand back NULL.)
   */
  private const DEFAULT_PHASE = 'D';

  /** Vocabulary behind `field_laser_patient_status`. */
  private const LASER_STATUS_VOCAB = 'laser_patient_status';

  /** Vocabulary behind the intake submission that enrolled a patient. */
  private const INTAKE_FLAG = 'intake_processed_cs';

  /**
   * The heaviest weight, in pounds, that may be written.
   *
   * Set well above the heaviest human ever documented (~635lbs) so that it rejects
   * keying errors and nothing else. The archive shows why it is needed at all: real
   * goal weights of 5155770000, 3434340, 434343 and 8485 are stored on patient
   * accounts, every one of them a digit out of place rather than a person. A bound
   * that stopped those being entered again does not retroactively fix them, which is
   * why {@see self::cleanWeight()} tolerates a value already on the record.
   */
  private const MAX_WEIGHT_LBS = 1000;

  /**
   * The ECK bundle a "clinic location" is stored as.
   *
   * `clinic` is one entity type with two bundles: `clinic` for the practices
   * themselves and `clinic_location` for their branches. Both are `clinic`
   * entities, which is why this has to be named rather than inferred from the type
   * — and why the bundle is worth checking on input as well as in the query.
   */
  private const CLINIC_LOCATION_BUNDLE = 'clinic_location';

  /**
   * Payload field => entity field, for everything a client is allowed to write.
   *
   * This documents the write surface. {@see self::applyFields()} applies it by
   * hand rather than looping, because every field needs a `hasField()` guard and
   * a different set() shape (date, decimal, boolean, entity reference), so a
   * generic loop would still need a case per field. Keep this list and that method
   * in step.
   *
   * A key not in this list is dropped, not rejected, so a client sending a newer
   * version of the form does not fail against an older backend.
   *
   * Deliberately absent: `clinic` and `chiropractor`. Those are resolved from the
   * session and never from a payload, because a client that could name its own
   * clinic could enrol a patient into someone else's. `clinicLocation` is allowed
   * but only for a clinic the caller already belongs to; see
   * {@see self::cleanClinicLocation()}.
   *
   * ## The two "status" fields
   *
   * The Add-patient form and the legacy `patient_account` webform both had a
   * control labelled "Patient status" and they meant different things, which is
   * worth stating because collapsing them would corrupt data:
   *
   *   - `phase`      → `field_weight_loss_phase`, a string key `phase-0`..`phase-4`.
   *                    What the roster's Phase column reads.
   *   - `laserStatus`→ `field_laser_patient_status`, a term in the "Laser Patient
   *                    Status" vocabulary: Red Light Only / Red Light + Weight Loss /
   *                    Weight Loss. Whether the patient is getting red-light therapy.
   *
   * The webform's select was the second one and it pinned the phase to
   * {@see self::DEFAULT_PHASE}, so it never needed both. This endpoint takes them as
   * separate keys rather than pretending one select meant one thing.
   */
  private const WRITABLE = [
    'name' => 'field_full_name',
    'mail' => 'mail',
    'phone' => 'field_phone_number',
    'fullName' => 'field_full_name',
    'programStart' => 'field_program_start_date',
    'startWeight' => 'field_program_start_weight',
    'goalWeight' => 'field_goal_weight',
    'emailNotifications' => 'field_email_optout',
    'phase' => 'field_weight_loss_phase',
    'laserStatus' => 'field_laser_patient_status',
    'clinicLocation' => 'field_clinic_location',
    'intakeSubmission' => 'field_intake_form',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClinicScope $clinicScope,
    private readonly PatientPhaseMap $phaseMap,
    private readonly LoggerChannelInterface $logger,
    private readonly FlagServiceInterface $flag,
    private readonly Mailer $mailer,
    private readonly EnrollmentLimit $enrollmentLimit,
    private readonly UserCurrentProgramDay $programDay,
  ) {}

  /**
   * The clinic roster: everyone in the clinic who is not archived and does not
   * hold an excluded staff role.
   *
   * Chiropractors are excluded, so this is patients plus any other staff. See
   * {@see self::ROSTER_EXCLUDED_ROLES} for who is dropped.
   *
   * @return array<int, array<string, mixed>>
   *   Roster rows, by display name then id.
   */
  public function active(int $clinicId): array {
    return $this->partition($clinicId)['active'];
  }

  /**
   * Archived patients: the role swap, not core status and not a field.
   *
   * Archiving deliberately leaves the program fields alone, so this is able to
   * show the start date and day for someone who left six months ago.
   *
   * @return array<int, array<string, mixed>>
   *   Archived rows, by display name then id.
   */
  public function archived(int $clinicId): array {
    return $this->partition($clinicId)['archived'];
  }

  /**
   * The whole clinic, split into the roster's two tabs in one query.
   *
   * Archived status is a role, and there is no SQL "not in the roles join" that
   * is safe here: a user with no archived flag still has rows in that join, so a
   * NOT IN against it excludes everybody rather than the archived. Drupal's
   * entity query has no notCondition() either. So the split happens in PHP over
   * one clinic-scoped load, which is also what lets the page render both tabs
   * from a single query.
   *
   * A clinic's user count is one practice's worth of accounts, so loading them
   * is cheap; the clinic condition is the only thing narrowing the result.
   *
   * {@see self::ROSTER_EXCLUDED_ROLES} is applied here for the same reason the
   * archived split is: a role "does not have" is not expressible in this query
   * either, so it is filtered in PHP over the one clinic-scoped load. It runs
   * before the archived test so excluded staff reach neither tab.
   *
   * @return array{active: array<int, array<string, mixed>>, archived: array<int, array<string, mixed>>}
   *   The two tabs.
   */
  public function partition(int $clinicId): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_clinic', $clinicId)
      // Blocked accounts are excluded from both tabs. `status` is core's blocked
      // flag and has nothing to do with archiving — archiving is the role swap
      // below, and the two are independent, so a description of a live patient
      // needs both. Without this condition a blocked patient is listed, and then
      // every action available on that row fails: a blocked account cannot log
      // in, cannot be messaged, and cannot be enrolled.
      ->condition('status', 1)
      ->sort('name')
      ->sort('uid')
      ->execute();

    $active = [];
    $archived = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      $roles = $user->getRoles();
      if (array_intersect(self::ROSTER_EXCLUDED_ROLES, $roles) !== []) {
        continue;
      }
      if (in_array(self::ROLE_ARCHIVED, $roles, TRUE)) {
        $archived[] = $this->toArchivedRow($user);
        continue;
      }
      $active[] = $this->toRosterRow($user);
    }

    return ['active' => $active, 'archived' => $archived];
  }

  /**
   * How many patients are enrolled, for the header pill.
   *
   * Counts the role rather than the roster rows: the roster still carries staff,
   * and the pill is about patients.
   *
   * The excluded staff roles are deliberately not repeated here. None of them is
   * {@see self::ROLE_ENROLLED}, so they cannot be counted here; the only way the
   * two figures could disagree is an account holding an enrolled role and an
   * excluded role at once, which is a bad-account-state problem rather than
   * something this query should paper over.
   *
   * The `status` condition is the same one {@see self::partition()} applies, and
   * it has to be repeated rather than shared because the two queries are built
   * separately. A blocked enrolled patient is invisible on the roster, so counting
   * them here would show a pill that disagrees with the list underneath it.
   */
  /** Reuse the snapshot's count; no additional patient query or billing disclosure. */
  public function enrollmentAllowance(int $clinicId, int $enrolled): ?array {
    if (\Drupal::hasService('headless_subscriptions.subscription')) {
      $subscriptions = \Drupal::service('headless_subscriptions.subscription');
      $allowance = $subscriptions->enrollmentAllowanceForClinic($clinicId, $enrolled);
      if ($allowance !== NULL || $subscriptions->managesClinic($clinicId)) return $allowance;
    }
    // Legacy clinics keep their configured package, without adopting new billing.
    $clinic = $this->entityTypeManager->getStorage('clinic')->load($clinicId);
    $packageId = $clinic && $clinic->hasField('field_enrollment_package')
      ? (int) $clinic->get('field_enrollment_package')->target_id : 0;
    if (!$packageId) return NULL;
    $package = $this->entityTypeManager->getStorage('enrollment_package')->load($packageId);
    if (!$package) return NULL;
    $hard = $package->hasField('field_enrollment_hard_limit')
      ? (int) $package->get('field_enrollment_hard_limit')->value : 0;
    $limit = $hard > 0 ? $hard : NULL;
    return [
      'planName' => (string) ($package->label() ?: 'Enrollment package'),
      'limit' => $limit,
      'used' => $enrolled,
      'remaining' => $limit === NULL ? NULL : max(0, $limit - $enrolled),
    ];
  }

  public function enrolledCount(int $clinicId): int {
    return (int) $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_clinic', $clinicId)
      ->condition('roles', self::ROLE_ENROLLED)
      ->condition('status', 1)
      ->count()
      ->execute();
  }

  /**
   * Creates an enrolled patient in the clinic.
   *
   * One endpoint for one form, used from two places. There is a single Add-patient
   * form; it is opened either blank from the Add New Patient tab, or prefilled
   * from an intake submission when the chiropractor presses Enroll on an intake
   * row. Both post here, and both send every field the form shows — the prefilled
   * case just sends values that were already known, including the start date,
   * rather than letting the backend guess them again.
   *
   * This matters because the two used to be separate paths that disagreed about
   * what "Patient status" meant, and produced accounts half-populated depending
   * on which one was used. One form, one endpoint, one set of rules.
   *
   * Everything the prefilled case can carry is optional here, so a blank form can
   * keep sending only what it has: `intakeSubmission`, `laserStatus` and
   * `clinicLocation` are all absent unless the form actually collected them.
   *
   * The clinic and the role are both set here, never read from the payload: a
   * client that can choose either can add a patient to a competitor's clinic.
   * `clinicLocation` is accepted but proven to belong to this clinic first.
   *
   * Re-enrolling an archived patient is a different operation and has its own
   * endpoint, {@see self::enroll()}.
   *
   * @param array<string, mixed> $payload
   *   Submitted form values. Unknown keys are ignored. See {@see self::WRITABLE}.
   * @param int $actorId
   *   The chiropractor creating this patient, recorded as their chiropractor.
   *
   * @return array{patient: array<string, mixed>, warning: string|null}
   *   The new roster row, and a soft-quota warning when the chiropractor is close
   *   to their enrollment limit. `patient` keeps its old shape so existing
   *   clients are unaffected.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   On a validation failure, a duplicate email, or when the chiropractor's
   *   hard enrollment limit has been reached (409).
   */
  public function create(int $clinicId, array $payload, int $actorId): array {
    return $this->withSubscriptionEnrollmentLock($clinicId, fn() => $this->createUnlocked($clinicId, $payload, $actorId));
  }

  private function createUnlocked(int $clinicId, array $payload, int $actorId): array {
    $errors = [];
    $values = $this->validate($payload, $clinicId, $errors);
    if ($errors !== []) {
      throw PatientsException::invalid($errors);
    }

    $this->assertEmailAvailable($values['mail'], $clinicId, $errors);
    if ($errors !== []) {
      throw PatientsException::invalid($errors);
    }

    // The quota is checked before anything is written, not after: the legacy
    // webform showed the warning on the form and let the chiropractor decide,
    // so recreating that ordering here means a blocked clinic leaves no
    // half-created account for anyone to clean up.
    $limit = $this->enrollmentQuota($clinicId);
    if ($limit['enrollment_status'] === 2) {
      throw new PatientsException($this->quotaMessage($limit), 409);
    }

    // Through the injected storage rather than `User::create()`. The static form
    // reaches into the global container, which makes every test of this method
    // need a booted kernel; storage->create() is the same call with the manager
    // already injected. This core has no entity.factory service, so the storage
    // is the seam.
    $user = $this->entityTypeManager->getStorage('user')->create([
      'name' => $values['name'],
      'mail' => $values['mail'],
      // A blocked account cannot log in, and a patient who cannot log in is not
      // enrolled. Set explicitly so it does not depend on a config default.
      'status' => 1,
    ]);
    // Storage::create() is declared to return the generic EntityInterface even
    // though the user storage always yields a User. Checked once here so every
    // method below can rely on UserInterface instead of re-asserting it.
    if (!$user instanceof UserInterface) {
      throw new PatientsException('The patient could not be created.', 500);
    }
    $user->addRole(self::ROLE_ENROLLED);

    if ($user->hasField('field_clinic')) {
      $user->set('field_clinic', $clinicId);
    }
    if ($user->hasField('field_chiropractor') && $actorId > 0) {
      $user->set('field_chiropractor', $actorId);
    }

    $this->applyFields($user, $values);

    try {
      $user->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Patient create failed for clinic @clinic: @message', [
        '@clinic' => $clinicId,
        '@message' => $e->getMessage(),
      ]);
      throw new PatientsException('The patient could not be created.', 422);
    }

    $this->logger->notice('Patient @uid created in clinic @clinic by @actor.', [
      '@uid' => $user->id(),
      '@clinic' => $clinicId,
      '@actor' => $actorId,
    ]);

    // After the save, because each of these loads the account by id and then
    // saves it again. Before the save, they would be working on an entity with
    // no id.
    $this->afterCreate($user, $values, $actorId);

    return [
      'patient' => $this->toRosterRow($user),
      // A soft-limit warning travels back with the success so the UI can show it
      // without a second round trip. It is not an error: the patient exists.
      'warning' => $limit['enrollment_status'] === 1 ? $this->quotaMessage($limit) : NULL,
    ];
  }

  /**
   * Runs the work the legacy forms did after the account was saved.
   *
   * Everything here is best-effort by design. The account is already committed
   * by the time this runs, so throwing would leave the caller holding a 500 for
   * a patient who genuinely exists — and a chiropractor who retries to "fix" it
   * gets a duplicate-email error instead. A missing program-day calculation is
   * recoverable by hand; a patient who lost their account is not. Failures are
   * logged at error level and the flow continues.
   *
   * These call the legacy helpers rather than reimplementing them. They are
   * entangled with `\Drupal::` statics, Views and each other's assumptions, and
   * a second copy of that logic is how the two paths silently diverge — which
   * is the problem this endpoint exists to solve.
   */
  private function afterCreate(UserInterface $user, array $values, int $actorId): void {
    $uid = (int) $user->id();

    // Roles, including `patient_mind_set`. Note this reads the *clinic's*
    // `field_mind_set_work` setting, whereas the legacy createNewPatient()
    // checked whether the chiropractor held the role. The clinic setting is the
    // authoritative one — the clinic is what sells the programme — so the
    // canonical helper's answer wins.
    $this->attempt('program day roles', fn () => $this->programDay->userRoles($uid));

    // field_locked is read by the message and checklist screens to know whether a
    // patient may be edited. Written unconditionally so a freshly enrolled
    // patient is not left in whatever state the field default says.
    if ($user->hasField('field_locked')) {
      try {
        $user->set('field_locked', FALSE);
        $user->save();
      }
      catch (\Throwable $e) {
        $this->logFailure('clear field_locked', $uid, $e);
      }
    }

    foreach (['userProfile', 'UserClinician', 'Evaluations', 'CalculateGoal'] as $step) {
      $this->attempt(
        'program day ' . $step,
        fn () => $this->programDay->{$step}($uid)
      );
    }

    if ($values['intakeSubmission'] !== NULL) {
      $this->attempt(
        'flag intake submission',
        fn () => $this->flagIntakeSubmission((int) $values['intakeSubmission'], $actorId)
      );
    }

    // Last, because it is the only step the chiropractor cannot do for themselves:
    // the patient needs this mail to get in at all.
    $this->attempt(
      'welcome mail',
      fn () => $this->mailer->sendAccountCreated($user)
    );
  }

  /**
   * Runs one best-effort step, swallowing and logging whatever it throws.
   */
  private function attempt(string $label, callable $step): void {
    try {
      $step();
    }
    catch (\Throwable $e) {
      $this->logger->error('Post-enrol step "@step" failed: @message', [
        '@step' => $label,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  private function logFailure(string $what, int $uid, \Throwable $e): void {
    $this->logger->error('Could not @what for patient @uid: @message', [
      '@what' => $what,
      '@uid' => $uid,
      '@message' => $e->getMessage(),
    ]);
  }

  /**
   * Marks an intake submission as turned into a patient.
   *
   * A reimplementation of the legacy FlagIntakeForm(), but through the injected
   * flag service instead of a `\Drupal::service('flag')` call, and idempotent:
   * Drupal rejects a second flagging of the same entity, which would have been a
   * hard error had it been called twice.
   *
   * The flag is owned by the *actor*, not the new patient. That reads backwards
   * but it is what the legacy function did and what the intake list filters
   * on: "which submissions have *I* processed".
   */
  private function flagIntakeSubmission(int $submissionId, int $actorId): void {
    $submission = $this->entityTypeManager->getStorage('contact_message')->load($submissionId);
    $actor = $this->entityTypeManager->getStorage('user')->load($actorId);
    if ($submission === NULL || $actor === NULL) {
      return;
    }

    $flag = $this->flag->getFlagById(self::INTAKE_FLAG);
    if ($flag === NULL) {
      $this->logger->error('Flag @flag does not exist; intake @id left unflagged.', [
        '@flag' => self::INTAKE_FLAG,
        '@id' => $submissionId,
      ]);
      return;
    }

    if ($this->flag->getFlagging($flag, $submission, $actor) === NULL) {
      // Three arguments, matching the legacy FlagIntakeForm(). FlagService::flag()
      // takes ($flag, $entity, $account, $session_id) and has no value argument:
      // this flag records the fact of processing, not a value.
      $this->flag->flag($flag, $submission, $actor);
    }
  }

  /** New subscription caps cannot be bypassed by simultaneous enrollment requests. */
  private function withSubscriptionEnrollmentLock(int $clinicId, callable $operation): array {
    if (!\Drupal::hasContainer() || !\Drupal::hasService('headless_subscriptions.repository')
      || !\Drupal::service('headless_subscriptions.repository')->accountForClinic($clinicId)) {
      return $operation();
    }
    $lock = \Drupal::lock();
    $key = 'headless_subscription.enrollment.' . $clinicId;
    if (!$lock->acquire($key, 120)) throw new PatientsException('Another enrollment is in progress. Try again.', 409);
    try { return $operation(); } finally { $lock->release($key); }
  }

  /**
   * The enrollment quota for a clinic, always with a status key.
   *
   * The legacy helper returns a warning or error shape for statuses 1 and 2 and a
   * bare `['enrollment_status' => 0]` otherwise, so the message key is filled in
   * here to give the caller one shape to read. It is also the one place this
   * class reads a View, which is inherited rather than chosen.
   *
   * The clinic is passed in rather than resolved from the session, so the quota a
   * patient is checked against is the one this service was already scoped to. Both
   * entry points ({@see self::create()} and {@see self::enroll()}) reach here with a
   * $clinicId that has already passed through {@see ClinicScope}, which is what
   * makes the clinic part of this trustworthy rather than merely current.
   *
   * @param int $clinicId
   *   The clinic whose Enrollment Package sets the cap.
   *
   * @return array<string, mixed>
   *   The limit shape with a normalised integer `enrollment_status`.
   */
  private function enrollmentQuota(int $clinicId): array {
    $limit = $this->enrollmentLimit->limitForClinic($clinicId);
    $limit['enrollment_status'] = (int) ($limit['enrollment_status'] ?? 0);

    return $limit + [
      'message' => '',
      'type' => 'warning',
    ];
  }

  /**
   * Normalises a quota message for the JSON API.
   *
   * The legacy helper returns markup - a <p> wrapper and a mailto link - because
   * it was written for a Drupal message that rendered HTML. Every caller of this
   * returns it as a JSON string field that the frontend renders as text, so
   * passing it through as-is would show the chiropractor a literal "<p>You have
   * reached...". Stripping keeps the words and the mailto address; only the link
   * decoration is lost, and nothing here is lost with it.
   *
   * Kept in one place so the hard-limit error and the soft-limit warning cannot
   * drift apart - they say the same thing and should read the same way.
   *
   * @param array<string, mixed> $quota
   *   A value from {@see self::enrollmentQuota()}.
   *
   * @return string
   *   Plain text, never empty, so a caller can hand it straight to a client.
   */
  private function quotaMessage(array $quota): string {
    $message = trim(strip_tags((string) ($quota['message'] ?? '')));

    return $message !== ''
      ? $message
      : 'You have reached your limit of enrolled patients.';
  }

  /**
   * Archives a patient: the role swap, matching ArchievedPatient::submitForm().
   *
   * The program fields are left in place on purpose, because the archived tab
   * reads them. `changed` is preserved for the same reason that form does it:
   * archiving is bookkeeping about a relationship, not a change to the
   * patient's own profile, and bumping it would make a six-month-old account
   * look freshly edited everywhere else.
   *
   * @return array<string, mixed>
   *   The archived row.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the patient is not in the clinic or is not currently enrolled.
   */
  public function archive(int $clinicId, int $userId): array {
    $user = $this->clinicScope->requireUser($clinicId, $userId);
    $roles = $user->getRoles();

    if (!in_array(self::ROLE_ENROLLED, $roles, TRUE)) {
      throw new PatientsException('That patient is not currently enrolled.', 409);
    }

    $user->removeRole(self::ROLE_ENROLLED);
    $user->addRole(self::ROLE_ARCHIVED);
    $user->save();

    $this->logger->notice('Patient @uid archived in clinic @clinic.', [
      '@uid' => $userId,
      '@clinic' => $clinicId,
    ]);

    // toArchivedRow() rather than a second copy of it, so the archive response
    // cannot drift from the archived tab the client just replaced this row from.
    return $this->toArchivedRow($user);
  }

  /**
   * Re-enrols an archived patient, the reverse swap.
   *
   * Enrolling from the archived tab is the design's "Enroll" action; the form
   * only confirms it. `changed` is preserved for the same reason as archiving.
   *
   * The enrollment limit applies here exactly as it does on {@see self::create()}.
   * The cap is counted off the `enrolled_patient` role (the
   * `rule_count_enrolled_patients` view), and an archived patient does not hold
   * that role, so they are not counted while archived. This swap gives it back,
   * which means re-enrolling consumes a slot exactly like creating a patient
   * does. Without the check here the cap could be walked straight past by
   * archiving and re-enrolling the same people.
   *
   * @param int $clinicId
   *   The chiropractor's clinic, already resolved from the session by the caller.
   * @param int $userId
   *   The archived patient to re-enroll.
   * @param array<string, mixed> $payload
   *   Optional body carrying any of the fields the Add Patient form collects:
   *   `name`, `email`, `phone`, `programStart`, `startWeight`, `goalWeight`,
   *   `emailNotifications`, `phase` and `clinicLocation`. Every one is optional, and
   *   the client prefills each from the archived row, so confirming without
   *   changing anything writes the same values back.
   *
   *   An absent or blank key leaves the stored value alone rather than clearing it,
   *   so a client that sends `{}` — or that sends only the one field it means to
   *   change — re-enrolls the patient as they were. That is deliberate:
   *   re-enrollment is fundamentally a role swap, and treating the body as a full
   *   replacement would let a field the browser happened not to render wipe a real
   *   measurement.
   *
   * @return array{patient: array<string, mixed>, warning: string|null}
   *   The new roster row, and a soft-quota warning when the chiropractor is close
   *   to their limit. Same shape as {@see self::create()}.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the patient is not in the clinic, is not currently archived, the
   *   clinic location is not one of the clinic's own, or the chiropractor's hard
   *   enrollment limit has been reached (409).
   */
  public function enroll(int $clinicId, int $userId, array $payload = []): array {
    return $this->withSubscriptionEnrollmentLock($clinicId, fn() => $this->enrollUnlocked($clinicId, $userId, $payload));
  }

  private function enrollUnlocked(int $clinicId, int $userId, array $payload = []): array {
    $user = $this->clinicScope->requireUser($clinicId, $userId);
    $roles = $user->getRoles();

    if (!in_array(self::ROLE_ARCHIVED, $roles, TRUE)) {
      throw new PatientsException('That patient is not currently archived.', 409);
    }

    // Validated before the role swap so a bad value leaves the patient archived, the
    // same reason the quota gate runs before it. Reuses the create path's cleaners,
    // which is what keeps a value that is legal on a new patient also legal here —
    // with absent-means-leave-it-alone in place of create's defaults, because this
    // account already holds a real program's worth of history that a default would
    // overwrite with a guess the chiropractor never made.
    $errors = [];
    $values = $this->validateEnroll($payload, $clinicId, $user, $errors);
    if ($errors !== []) {
      throw PatientsException::invalid($errors);
    }

    // Checked after the archived-role test, so a plain "not archived" 409 still
    // wins: telling someone they are over quota for an account that was never
    // archived would be the more confusing of the two answers.
    $quota = $this->enrollmentQuota($clinicId);
    if ($quota['enrollment_status'] === 2) {
      throw new PatientsException($this->quotaMessage($quota), 409);
    }

    $user->removeRole(self::ROLE_ARCHIVED);
    $user->addRole(self::ROLE_ENROLLED);

    // Only the keys the payload carried. A client that sends nothing re-enrolls the
    // patient exactly as they were, which is what matters because most archived
    // patients do carry a location from before they left and an unconditional
    // write would clear it.
    $this->applyEnrollFields($user, $values);

    $user->save();

    $this->logger->notice('Patient @uid re-enrolled in clinic @clinic.', [
      '@uid' => $userId,
      '@clinic' => $clinicId,
    ]);

    return [
      'patient' => $this->toRosterRow($user),
      'warning' => $quota['enrollment_status'] === 1 ? $this->quotaMessage($quota) : NULL,
    ];
  }

  /**
   * Updates an active patient's account data from the chiropractor's Edit Form.
   *
   * The account already exists and holds a real program's worth of history, so the
   * rules mirror {@see self::validateEnroll()} rather than {@see self::validate()}:
   * an omitted key leaves the value on the record alone, and a blank weight or
   * date reads as "no change" rather than "erase". The one deliberate exception is
   * `clinicLocation`: the form's "- None -" option is a choice, so an explicit
   * blank clears the reference. Identity fields (name, email, status) are the
   * shape of the account and are required when the form sends them.
   *
   * @param array<string, mixed> $payload
   *   Raw payload: `firstName`/`lastName` (or `name`/`fullName`), `email` (or
   *   `mail`), `programStart`, `startWeight`, `goalWeight`, `clinicLocation`,
   *   `laserStatus`. Same spellings as create/enroll.
   *
   * @return array{patient: array<string, mixed>}
   *   The updated roster row.
   */
  public function updateAccount(int $clinicId, int $userId, array $payload, int $actorId): array {
    $user = $this->clinicScope->requireUser($clinicId, $userId);

    if (!in_array(self::ROLE_ENROLLED, $user->getRoles(), TRUE)) {
      throw new PatientsException('That patient is not currently active.', 409);
    }

    $errors = [];
    $values = $this->validateAccount($payload, $clinicId, $user, $errors);
    if ($errors !== []) {
      throw PatientsException::invalid($errors);
    }

    $this->applyAccountFields($user, $values);

    try {
      $user->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Patient @uid account update failed for clinic @clinic: @message', [
        '@uid' => $userId,
        '@clinic' => $clinicId,
        '@message' => $e->getMessage(),
      ]);
      throw new PatientsException('The patient could not be updated.', 422);
    }

    $this->logger->notice('Patient @uid account updated in clinic @clinic by @actor.', [
      '@uid' => $userId,
      '@clinic' => $clinicId,
      '@actor' => $actorId,
    ]);

    return ['patient' => $this->toRosterRow($user)];
  }

  /**
   * Validates an account-edit payload, where every field is optional.
   *
   * The same `clean*` helpers as create and enroll — one definition of what is
   * legal — with the account-edit reading of a blank box:
   *
   * - **Absent means leave it alone**, the same rule as re-enrollment. The form
   *   sends the whole account, but a partial client update must not wipe what it
   *   did not send, and a blank weight or date on a patient mid-program is far
   *   more likely an untouched box than an order to erase a measurement.
   * - **`clinicLocation` is the one clearable field.** The form's "- None -"
   *   option is an explicit choice, so a blank here writes NULL and removes the
   *   reference, unlike a blank weight.
   * - **Name, email and status are identity, not measurements.** When the form
   *   sends them they must not be blank, the email must be unique within the
   *   clinic (the patient themselves excluded), and the status must be a term in
   *   the Laser Patient Status vocabulary.
   *
   * Only keys the caller sent end up in the result. {@see
   * self::applyAccountFields()} writes exactly those.
   *
   * @param array<string, mixed> $payload
   *   Raw payload.
   * @param int $clinicId
   *   The chiropractor's clinic.
   * @param \Drupal\user\UserInterface $user
   *   The patient being edited, read for the tolerated stored values and excluded
   *   from the email uniqueness check.
   * @param array<int, string> $errors
   *   Messages keyed by payload field name.
   *
   * @return array<string, mixed>
   *   Only the keys the payload supplied, already cleaned.
   */
  private function validateAccount(array $payload, int $clinicId, UserInterface $user, array &$errors): array {
    $values = [];

    if (array_key_exists('name', $payload) || array_key_exists('fullName', $payload)
      || array_key_exists('firstName', $payload) || array_key_exists('lastName', $payload)) {
      $first = trim((string) ($payload['firstName'] ?? ''));
      $last = trim((string) ($payload['lastName'] ?? ''));
      if ($first !== '') {
        $name = $last !== '' ? $first . ' ' . $last : $first;
      }
      else {
        $name = trim((string) ($payload['name'] ?? $payload['fullName'] ?? ''));
      }
      if ($name === '') {
        $errors['name'] = 'Enter the patient’s name.';
      }
      else {
        $values['fullName'] = $name;
      }
    }

    if (array_key_exists('email', $payload) || array_key_exists('mail', $payload)) {
      $mail = strtolower(trim((string) ($payload['email'] ?? $payload['mail'] ?? '')));
      if ($mail === '') {
        $errors['email'] = 'Enter the patient’s email address.';
      }
      elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
      }
      elseif ($mail !== strtolower((string) $user->getEmail())) {
        $this->assertEmailAvailable($mail, $clinicId, $errors, (int) $user->id());
        if ($errors === []) {
          $values['mail'] = $mail;
        }
      }
    }

    if (array_key_exists('programStart', $payload)) {
      $date = $this->cleanDate(
        $payload['programStart'],
        'programStart',
        $errors,
        $this->stringOrNull($user, 'field_program_start_date')
      );
      if ($date !== NULL) {
        $values['programStart'] = $date;
      }
    }
    if (array_key_exists('startWeight', $payload)) {
      $weight = $this->cleanWeight(
        $payload['startWeight'],
        'startWeight',
        $errors,
        $this->storedWeight($user, 'field_program_start_weight')
      );
      if ($weight !== NULL) {
        $values['startWeight'] = $weight;
      }
    }
    if (array_key_exists('goalWeight', $payload)) {
      $weight = $this->cleanWeight(
        $payload['goalWeight'],
        'goalWeight',
        $errors,
        $this->storedWeight($user, 'field_goal_weight')
      );
      if ($weight !== NULL) {
        $values['goalWeight'] = $weight;
      }
    }
    if (array_key_exists('clinicLocation', $payload)) {
      $location = $this->cleanClinicLocation($payload['clinicLocation'], $clinicId, $errors);
      // An explicit blank is the "- None -" choice, stored as an empty reference.
      if ($location === NULL && ($payload['clinicLocation'] === '' || $payload['clinicLocation'] === NULL)) {
        $values['clinicLocation'] = NULL;
      }
      elseif ($location !== NULL) {
        $values['clinicLocation'] = $location;
      }
    }
    if (array_key_exists('laserStatus', $payload)) {
      $status = trim((string) ($payload['laserStatus'] ?? ''));
      if ($status === '') {
        $errors['laserStatus'] = 'Choose a patient status.';
      }
      else {
        $term = $this->cleanLaserStatus($status, $errors);
        if ($term !== NULL) {
          $values['laserStatus'] = $term;
        }
      }
    }

    return $values;
  }

  /**
   * Writes the validated, allowlisted account-edit values onto the account.
   *
   * Identity writes (`fullName`, `mail`) happen whenever the form sends them. Every
   * other key is written only when present, and a present `clinicLocation` of NULL
   * clears the reference — the one field the account form can deliberately empty.
   *
   * The username is deliberately not touched: the login name is not part of the
   * account form, and renaming it here would not rename the Drupal account the
   * patient signs in with.
   *
   * `hasField` is checked for every field for the same reason {@see
   * self::applyFields()} does: this module ships no field config, so it cannot
   * promise at install time that the fields all exist.
   */
  private function applyAccountFields(UserInterface $user, array $values): void {
    if (array_key_exists('fullName', $values) && $user->hasField('field_full_name')) {
      $user->set('field_full_name', $values['fullName']);
    }
    if (array_key_exists('mail', $values)) {
      $user->setEmail($values['mail']);
    }
    if (array_key_exists('programStart', $values) && $user->hasField('field_program_start_date')) {
      $user->set('field_program_start_date', $values['programStart']);
    }
    if (array_key_exists('startWeight', $values) && $user->hasField('field_program_start_weight')) {
      $user->set('field_program_start_weight', $values['startWeight']);
    }
    if (array_key_exists('goalWeight', $values) && $user->hasField('field_goal_weight')) {
      $user->set('field_goal_weight', $values['goalWeight']);
    }
    if (array_key_exists('clinicLocation', $values) && $user->hasField('field_clinic_location')) {
      // An empty array clears an entity-reference field; NULL is not a value this
      // field type accepts.
      $user->set('field_clinic_location', $values['clinicLocation'] === NULL ? [] : $values['clinicLocation']);
    }
    if (array_key_exists('laserStatus', $values) && $user->hasField('field_laser_patient_status')) {
      $user->set('field_laser_patient_status', $values['laserStatus']);
    }
  }

  /**
   * Validates a re-enrollment payload, where every field is optional.
   *
   * The same `clean*` helpers as {@see self::validate()} — one definition of what a
   * legal clinic location or phone number is, so the two forms cannot drift — but
   * the defaulting rules are inverted, because the account already exists:
   *
   * - **Absent means "leave it alone".** Create defaults an omitted start date to
   *   next Saturday and an omitted phase to the first phase, because there is
   *   nothing else on a new account to fall back to. A returning patient has both
   *   set from their time on the programme, so defaulting them here would overwrite
   *   real history with a guess the chiropractor never made.
   * - **A blank value is not a clear command.** `cleanWeight()` and friends answer
   *   NULL for both "not sent" and "sent empty", so an emptied field reads as absent
   *   and the stored value stands. Erasing a patient's record is a deliberate act
   *   that belongs on the patient record, not a side effect of confirming a
   *   re-enrollment.
   * - **Name and email are not required**, because an omitted one is not an error.
   *   When an email *is* sent it is checked for uniqueness within the clinic with
   *   this patient excluded, so re-confirming their own address is not reported as a
   *   clash with themselves.
   *
   * Only keys the caller actually sent end up in the result. {@see
   * self::applyEnrollFields()} writes exactly those, which is what makes
   * preserve-on-absent hold without every caller having to send a full replacement
   * object.
   *
   * @param array<string, mixed> $payload
   *   Raw payload.
   * @param int $clinicId
   *   The chiropractor's clinic.
   * @param \Drupal\user\UserInterface $user
   *   The patient being re-enrolled, read for the email already on the account and
   *   excluded from the uniqueness check. Passed in rather than re-loaded so the
   *   comparison cannot race a concurrent edit into checking against a stale value.
   * @param array<int, string> $errors
   *   Messages keyed by payload field name.
   *
   * @return array<string, mixed>
   *   Only the keys the payload supplied, already cleaned.
   */
  private function validateEnroll(array $payload, int $clinicId, UserInterface $user, array &$errors): array {
    $values = [];

    if (array_key_exists('name', $payload) || array_key_exists('fullName', $payload)) {
      $name = trim((string) ($payload['name'] ?? $payload['fullName'] ?? ''));
      if ($name === '') {
        $errors['name'] = 'Enter the patient’s name.';
      }
      else {
        $values['fullName'] = $name;
      }
    }

    if (array_key_exists('email', $payload) || array_key_exists('mail', $payload)) {
      $mail = strtolower(trim((string) ($payload['email'] ?? $payload['mail'] ?? '')));
      if ($mail === '') {
        $errors['email'] = 'Enter the patient’s email address.';
      }
      elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
      }
      // An address identical to the one already on the account falls through to nothing
      // at all: it is neither checked nor written. Checking it would cost a query per
      // confirmation for an outcome the self-exclusion makes impossible, and writing
      // it back would mark the record changed for no reason.
      elseif ($mail !== strtolower((string) $user->getEmail())) {
        $this->assertEmailAvailable($mail, $clinicId, $errors, (int) $user->id());
        if ($errors === []) {
          $values['mail'] = $mail;
        }
      }
    }

    // Only when it yields something. `cleanPhone()` returns NULL for a blank box, and a
    // NULL in `$values` would satisfy the key-presence test in `applyEnrollFields()`
    // and wipe a phone number the patient already has — the opposite of what a blank
    // field means here. Same shape as the date and the weights below: this form
    // confirms a return, it does not clear measurements, so there is no way to erase
    // a phone through it. Removing one is a separate edit deserving its own
    // confirmation.
    if (array_key_exists('phone', $payload)) {
      $phone = $this->cleanPhone($payload['phone']);
      if ($phone !== NULL) {
        $values['phone'] = $phone;
      }
    }
    if (array_key_exists('programStart', $payload)) {
      // The stored date is handed to `cleanDate()` as the tolerated exception. It has
      // to be read from the record rather than assumed absent, because an archived
      // patient's start date is in the past by definition: refusing every past date
      // here would mean a returning patient could not be re-enrolled at all without
      // first having their real history corrected, which is the opposite of what this
      // endpoint is for. A date that differs from the stored one is a new choice and
      // is judged normally.
      $date = $this->cleanDate(
        $payload['programStart'],
        'programStart',
        $errors,
        $this->stringOrNull($user, 'field_program_start_date')
      );
      if ($date !== NULL) {
        $values['programStart'] = $date;
      }
    }
    // Both weights get the stored value as their tolerated exception, for the same
    // reason the start date does: the archive already holds values this bound
    // rejects — goal weights of 3434340 and -250 are really in there — and a
    // returning patient must not be locked out of re-enrolling over a number they
    // did not just type. The exception covers the value on file only.
    if (array_key_exists('startWeight', $payload)) {
      $weight = $this->cleanWeight(
        $payload['startWeight'],
        'startWeight',
        $errors,
        $this->storedWeight($user, 'field_program_start_weight')
      );
      if ($weight !== NULL) {
        $values['startWeight'] = $weight;
      }
    }
    if (array_key_exists('goalWeight', $payload)) {
      $weight = $this->cleanWeight(
        $payload['goalWeight'],
        'goalWeight',
        $errors,
        $this->storedWeight($user, 'field_goal_weight')
      );
      if ($weight !== NULL) {
        $values['goalWeight'] = $weight;
      }
    }
    if (array_key_exists('emailNotifications', $payload)
      || array_key_exists('disableEmailNotifications', $payload)) {
      $values['emailNotifications'] = $this->cleanOptOut($payload);
    }
    if (array_key_exists('phase', $payload) || array_key_exists('status', $payload)) {
      $phase = $this->cleanPhase($payload['phase'] ?? $payload['status'] ?? NULL, $errors);
      if ($phase !== NULL) {
        $values['phase'] = $phase;
      }
    }
    if (array_key_exists('clinicLocation', $payload)) {
      $location = $this->cleanClinicLocation($payload['clinicLocation'], $clinicId, $errors);
      if ($location !== NULL) {
        $values['clinicLocation'] = $location;
      }
    }

    return $values;
  }

  /**
   * Writes only the re-enrollment values the payload supplied.
   *
   * A separate method from {@see self::applyFields()} rather than a flag on it,
   * because the two differ in a way a flag would hide: create writes
   * `field_program_start_weight` and `field_goal_weight` unconditionally — a new
   * patient with no measurements should store empty, not inherit — whereas here an
   * absent key must leave whatever the patient already had. Reusing create's method
   * with a NULL-filled array would have quietly wiped both measurements on every
   * re-enrollment, which is the single most damaging thing this endpoint could do.
   *
   * @param array<string, mixed> $values
   *   Cleaned values, containing only keys the payload supplied.
   */
  private function applyEnrollFields(UserInterface $user, array $values): void {
    if (array_key_exists('programStart', $values) && $user->hasField('field_program_start_date')) {
      $user->set('field_program_start_date', $values['programStart']);
    }
    if (array_key_exists('phone', $values) && $user->hasField('field_phone_number')) {
      $user->set('field_phone_number', $values['phone']);
    }
    if (array_key_exists('fullName', $values) && $user->hasField('field_full_name')) {
      $user->set('field_full_name', $values['fullName']);
    }
    if (array_key_exists('startWeight', $values) && $user->hasField('field_program_start_weight')) {
      $user->set('field_program_start_weight', $values['startWeight']);
    }
    if (array_key_exists('goalWeight', $values) && $user->hasField('field_goal_weight')) {
      $user->set('field_goal_weight', $values['goalWeight']);
    }
    if (array_key_exists('emailNotifications', $values) && $user->hasField('field_email_optout')) {
      $user->set('field_email_optout', $values['emailNotifications']);
    }
    if (array_key_exists('phase', $values) && $user->hasField('field_weight_loss_phase')) {
      $user->set('field_weight_loss_phase', $this->phaseMap->stored($values['phase']));
    }
    if (array_key_exists('clinicLocation', $values) && $user->hasField('field_clinic_location')) {
      $user->set('field_clinic_location', $values['clinicLocation']);
    }

    // `mail` is a base property rather than a field, so it is handled apart from the
    // field loop above.
    if (array_key_exists('mail', $values)) {
      $user->setEmail($values['mail']);
    }
  }

  /**
   * Validates and normalises a create payload.
   *
   * @param array<string, mixed> $payload
   *   Raw payload.
   * @param array<int, string> $errors
   *   Collected messages, appended in place.
   *
   * @return array<string, mixed>
   *   Cleaned values. Every WRITABLE key is present, NULL when unset.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   Never thrown here; this only fills $errors so the caller can report them
   *   all at once instead of one per round trip.
   */
  private function validate(array $payload, int $clinicId, array &$errors): array {
    $name = trim((string) ($payload['name'] ?? $payload['fullName'] ?? ''));
    if ($name === '') {
      $errors['name'] = 'Enter the patient’s name.';
    }

    $mail = strtolower(trim((string) ($payload['email'] ?? $payload['mail'] ?? '')));
    if ($mail === '') {
      $errors['email'] = 'Enter the patient’s email address.';
    }
    elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = 'Enter a valid email address.';
    }

    $values = [
      'name' => $name,
      'mail' => $mail,
      'phone' => $this->cleanPhone($payload['phone'] ?? NULL),
      'fullName' => $name,
      // Blank means "starts next Saturday" rather than "unknown": the legacy form
      // defaulted it to next Saturday because the program runs in Saturday
      // cohorts, and an unset start date makes the roster's day count nonsense.
      //
      // No tolerated exception is passed, and that is the whole difference from
      // re-enrolling: a new record has no history to preserve, so every date here is
      // a choice and a past one is refused. A rejected date falls through to
      // nextSaturday the same way a malformed one always has — `$errors` is what ends
      // the request, not the fallback.
      'programStart' => $this->cleanDate($payload['programStart'] ?? NULL, 'programStart', $errors)
        ?? $this->nextSaturday(),
      'startWeight' => $this->cleanWeight($payload['startWeight'] ?? NULL, 'startWeight', $errors),
      'goalWeight' => $this->cleanWeight($payload['goalWeight'] ?? NULL, 'goalWeight', $errors),
      'emailNotifications' => $this->cleanOptOut($payload),
      // `status` is the older spelling of `phase`, kept because a client written
      // against the first version of this endpoint still sends it.
      'phase' => $this->cleanPhase($payload['phase'] ?? $payload['status'] ?? NULL, $errors)
        ?? self::DEFAULT_PHASE,
      'laserStatus' => $this->cleanLaserStatus($payload['laserStatus'] ?? NULL, $errors),
      'clinicLocation' => $this->cleanClinicLocation($payload['clinicLocation'] ?? NULL, $clinicId, $errors),
      'intakeSubmission' => $this->cleanIntakeSubmission($payload['intakeSubmission'] ?? NULL, $errors),
    ];

    return $values;
  }

  /**
   * The next Saturday, as Y-m-d.
   *
   * Strictly the *next* one, so a form submitted on a Saturday schedules the
   * following week rather than the day it was sent. Computed in the site's own
   * timezone rather than UTC, because "Saturday" is a local idea and a clinic in
   * Hawaii enrolling at 5pm local should not be handed the previous day's date
   * from a UTC calculation.
   */
  private function nextSaturday(): string {
    $timezone = new \DateTimeZone(date_default_timezone_get());
    $now = new \DateTimeImmutable('now', $timezone);
    // w() is 0 (Sunday) to 6 (Saturday); (6 - w) mod 7 is the days until Saturday,
    // and the ?: 7 turns "today" into next week.
    $days = (6 - (int) $now->format('w')) % 7;

    return $now->add(new \DateInterval('P' . ($days ?: 7) . 'D'))->format('Y-m-d');
  }

  /**
   * Daily notifications, stored inverted as the opt-out field.
   *
   * The field records whether to *stop* the emails and the Add-patient form asks
   * whether to *send* them, so one of the two is inverted. Inverting here means no
   * caller has to know which, and the two forms disagreeing about it is exactly
   * the class of bug this endpoint was built to prevent.
   *
   * Both spellings are accepted, because the legacy webform sent the inverted
   * flag under a different name and that payload is still in flight:
   *
   *   - `emailNotifications`            → true means send them.
   *   - `disableEmailNotifications`     → true means stop them.
   *
   * When both are present the positive form wins; it is the one this endpoint
   * documents, and "unset means leave them opted in" is the safer reading than
   * the other way round.
   */
  private function cleanOptOut(array $payload): int {
    if (array_key_exists('emailNotifications', $payload)) {
      return self::isTruthy($payload['emailNotifications']) ? 0 : 1;
    }
    if (array_key_exists('disableEmailNotifications', $payload)) {
      return self::isTruthy($payload['disableEmailNotifications']) ? 1 : 0;
    }
    return 0;
  }

  /**
   * A Laser Patient Status term id, checked against its vocabulary.
   *
   * Validated rather than trusted because it is an entity reference: writing an
   * arbitrary integer here would either fail at save time with an opaque error or,
   * worse, point at a term from some other vocabulary that happens to share the
   * id.
   */
  private function cleanLaserStatus(mixed $value, array &$errors): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!ctype_digit(trim((string) $value))) {
      $errors['laserStatus'] = 'Choose one of the listed laser statuses.';
      return NULL;
    }

    $term = $this->entityTypeManager->getStorage('taxonomy_term')
      ->load((int) $value);
    if ($term === NULL || $term->bundle() !== self::LASER_STATUS_VOCAB) {
      $errors['laserStatus'] = 'Choose one of the listed laser statuses.';
      return NULL;
    }

    return (string) $value;
  }

  /**
   * A clinic location the caller's clinic is entitled to.
   *
   * A clinic location is itself a `clinic` entity that points back at its parent
   * through its own `field_clinic`. That back-reference is what makes this
   * checkable: a submitted location is only accepted when it names that parent.
   *
   * Without it, `clinicLocation` would be the one field a caller could use to put
   * a patient in a stranger's clinic, which is the same cross-tenant hole this
   * class closes for `field_clinic` by never reading it from the payload. So an
   * unprovable location is rejected rather than trusted.
   */
  private function cleanClinicLocation(mixed $value, int $clinicId, array &$errors): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!ctype_digit(trim((string) $value))) {
      $errors['clinicLocation'] = 'Choose one of the listed clinic locations.';
      return NULL;
    }

    $location = $this->entityTypeManager->getStorage('clinic')->load((int) $value);
    if ($location === NULL || !$location->hasField('field_clinic')) {
      $errors['clinicLocation'] = 'Choose one of the listed clinic locations.';
      return NULL;
    }

    // The bundle is checked, not just the field. `field_clinic` exists on the
    // clinic_location bundle and nothing else today, but "only today" is not a
    // guarantee: adding it to the main clinic bundle later would let a chiropractor
    // point a patient at another practice and satisfy every other check.
    if (!$location instanceof EckEntityInterface || $location->bundle() !== self::CLINIC_LOCATION_BUNDLE) {
      $this->logger->warning('Rejected clinic @id as a clinic location: not a @bundle entity.', [
        '@id' => (int) $value,
        '@bundle' => self::CLINIC_LOCATION_BUNDLE,
      ]);
      $errors['clinicLocation'] = 'Choose one of the listed clinic locations.';
      return NULL;
    }

    $parent = $location->get('field_clinic')->target_id;
    if ($parent === NULL || (int) $parent !== $clinicId) {
      $this->logger->warning('Rejected clinic location @location for clinic @clinic.', [
        '@location' => (int) $value,
        '@clinic' => $clinicId,
      ]);
      $errors['clinicLocation'] = 'Choose one of the listed clinic locations.';
      return NULL;
    }

    return (int) $value;
  }

  /**
   * The clinic's own locations, for the dropdowns.
   *
   * A "location" is a `clinic` entity of the `clinic_location` bundle whose
   * `field_clinic` points at the parent clinic — the same shape the user entity's
   * `field_clinic_location` references, and the one the legacy `clinic_locations`
   * view resolves through. Queried here rather than through that view so the list
   * the form shows and the ids it accepts come from one definition.
   *
   * Scoped to the caller's clinic in the query itself, not filtered afterwards. The
   * clinic entity table holds every practice on the site, so an unscoped load would
   * pull all of them into a chiropractor's browser to then throw almost all away.
   *
   * @param int $clinicId
   *   The chiropractor's clinic.
   *
   * @return array<int, array{id: int, name: string}>
   *   Locations sorted by name. Empty for a clinic that has none, which is a real
   *   state rather than an error: the caller renders an empty dropdown and omits
   *   the field.
   */
  public function clinicLocations(int $clinicId): array {
    $ids = $this->entityTypeManager->getStorage('clinic')->getQuery()
      ->accessCheck(FALSE)
      // `type`, not `bundle`. `clinic` declares its bundle key as `type` and its base
      // table has no `bundle` column at all, so the obvious condition('bundle', ...)
      // is not valid here and raises a QueryException rather than quietly returning
      // nothing. `type` holds the same value `bundle()` reports, which is what makes
      // one constant safe to use for both.
      ->condition('type', self::CLINIC_LOCATION_BUNDLE)
      ->condition('field_clinic', $clinicId)
      // No published/unpublished condition, unlike most entity queries: ECK does not
      // add a `status` base field to this entity type, so filtering on one would
      // throw. There is no "unpublished location" to exclude.
      ->sort('title')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $locations = [];
    foreach ($this->entityTypeManager->getStorage('clinic')->loadMultiple($ids) as $location) {
      if ($location instanceof EckEntityInterface) {
        $locations[] = [
          'id' => (int) $location->id(),
          // ECK gives this entity type a title, which is the location's name as the
          // rest of the site knows it. An untitled location would render as an
          // unidentifiable option, so it is reported rather than shown blank.
          'name' => trim((string) $location->label()) !== ''
            ? (string) $location->label()
            : 'Location ' . $location->id(),
        ];
      }
    }

    return $locations;
  }

  /**
   * The Laser Patient Status vocabulary, for the account form's "Patient status" select.
   *
   * Every term in the vocabulary, not the subset any one clinic happens to use:
   * the roster derives its Program column from a patient's term, and assigning a
   * status no current patient has should still be possible. `field_laser_patient_status`
   * on the account holds one of these term ids, validated back against the same
   * vocabulary by {@see self::cleanLaserStatus()}.
   *
   * @return array<int, array{id: int, name: string}>
   *   Terms sorted by name. Empty only when the vocabulary itself is empty.
   */
  public function laserStatuses(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::LASER_STATUS_VOCAB)
      ->sort('name')
      ->execute();

    $terms = [];
    foreach ($storage->loadMultiple($ids) as $term) {
      $name = trim((string) $term->label());
      $terms[] = ['id' => (int) $term->id(), 'name' => $name !== '' ? $name : 'Status ' . $term->id()];
    }

    return $terms;
  }

  /**
   * An intake submission this enrolment came from.
   *
   * Only the existence and type are checked. Proving the submission belongs to
   * this chiropractor would mean a reverse lookup the intake data does not
   * obviously support, and guessing at it would reject legitimate submissions;
   * the flag below is what actually records who processed it.
   */
  private function cleanIntakeSubmission(mixed $value, array &$errors): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!ctype_digit(trim((string) $value))) {
      $errors['intakeSubmission'] = 'That intake submission could not be found.';
      return NULL;
    }

    $submission = $this->entityTypeManager->getStorage('contact_message')
      ->load((int) $value);
    if ($submission === NULL) {
      $errors['intakeSubmission'] = 'That intake submission could not be found.';
      return NULL;
    }

    return (int) $value;
  }

  /**
   * Rejects an email already used by an account in the clinic.
   *
   * Scoped to the clinic rather than checked globally: two clinics are
   * independent businesses and should not be able to block each other's
   * patients, and the uid space is shared so a global check would report
   * collisions that the caller cannot do anything about.
   */
  private function assertEmailAvailable(string $mail, int $clinicId, array &$errors, ?int $exceptUserId = NULL): void {
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', $mail)
      ->condition('field_clinic', $clinicId);

    // Excluding the account being edited is what makes this reusable. Re-enrollment
    // prefills the patient's own email, so without this the patient is reported as a
    // duplicate of themselves and re-enrolling becomes impossible from the one screen
    // that offers it — the check would be correct about the clinic and useless to the
    // caller. On create there is no such account and this stays NULL.
    if ($exceptUserId !== NULL) {
      $query->condition('uid', $exceptUserId, '<>');
    }

    // `range(0, 1)`, not `limit(1)`: this core's QueryInterface has no `limit()`, so
    // the shorthand is a fatal rather than a shorter name for the same query.
    if ($query->range(0, 1)->execute() !== []) {
      $errors['email'] = 'A patient with that email already exists in this clinic.';
    }
  }

  /**
   * Writes the validated, allowlisted values onto the account.
   *
   * `hasField` is checked for every field because this module ships no field
   * config and no module on the site creates these in code, so it cannot promise
   * at install time that they all exist. Skipping a missing field is better
   * than a fatal, and better than creating a field the rest of the system would
   * not see.
   */
  private function applyFields(UserInterface $user, array $values): void {
    if (isset($values['programStart']) && $user->hasField('field_program_start_date')) {
      $user->set('field_program_start_date', $values['programStart']);
    }
    if (isset($values['phone']) && $user->hasField('field_phone_number')) {
      $user->set('field_phone_number', $values['phone']);
    }
    if (isset($values['fullName']) && $user->hasField('field_full_name')) {
      $user->set('field_full_name', $values['fullName']);
    }
    if ($user->hasField('field_program_start_weight')) {
      $user->set('field_program_start_weight', $values['startWeight']);
    }
    if ($user->hasField('field_goal_weight')) {
      $user->set('field_goal_weight', $values['goalWeight']);
    }
    if ($user->hasField('field_email_optout')) {
      $user->set('field_email_optout', $values['emailNotifications']);
    }
    // The stored value, not the code. `phaseMap->stored()` is the single place
    // that knows `L` means `phase-2`; writing the code straight into the field
    // was a live bug, and it was invisible because the roster reads the field
    // back through `phaseMap->code()`, which reports an unrecognised value as
    // NULL. A wrong phase therefore showed as a blank column rather than an
    // error, on every patient the endpoint had ever created.
    if ($user->hasField('field_weight_loss_phase')) {
      $user->set('field_weight_loss_phase', $this->phaseMap->stored($values['phase']));
    }
    if ($values['laserStatus'] !== NULL && $user->hasField('field_laser_patient_status')) {
      $user->set('field_laser_patient_status', $values['laserStatus']);
    }
    if ($values['clinicLocation'] !== NULL && $user->hasField('field_clinic_location')) {
      $user->set('field_clinic_location', $values['clinicLocation']);
    }
    if ($values['intakeSubmission'] !== NULL && $user->hasField('field_intake_form')) {
      $user->set('field_intake_form', $values['intakeSubmission']);
    }
  }

  /**
   * One archived row: identity and where the program got to, nothing more.
   *
   * `role` is here even though the archived table has no Role column, because the
   * tab filters on it and the two tabs are read by one snapshot. It is resolved
   * through {@see self::rosterRole()} rather than hardcoded to `patient`, so an
   * archived account keeps reporting the same role it did before it was archived
   * and the filter cannot disagree with the Patient List's.
   */
  private function toArchivedRow(UserInterface $user): array {
    $roles = array_values($user->getRoles());

    return [
      'id' => (int) $user->id(),
      'role' => $this->rosterRole($roles),
      'name' => $this->displayName($user),
      'email' => (string) $user->getEmail(),
      'programStart' => $this->stringOrNull($user, 'field_program_start_date'),
      'programDay' => $this->intOrNull($user, 'field_user_current_program_day_c'),
      'phone' => $this->stringOrNull($user, 'field_phone_number'),
      // Carried on the archived row so re-enrollment can preselect it. Most archived
      // patients have none — the field was only populated partway through the
      // program's life — so the client treats this as a suggestion, not a default
      // it can rely on.
      'clinicLocation' => $this->referenceIdOrNull($user, 'field_clinic_location'),
      // The rest of the re-enroll form's prefill, carried on the row rather than
      // fetched on demand. Two reasons: the row is already loaded in order to render
      // the table, so a per-patient request would be a request per row; and the
      // confirmation step's whole purpose is to show what is already on the account
      // and let the chiropractor adjust it, which it cannot do from values it had to
      // go and ask for.
      //
      // `phase` is the code the client renders rather than the stored `phase-N`
      // string, and `emailNotifications` is the positive form the form's toggle uses
      // — both inverted back out of storage here, so the two forms cannot disagree
      // about the encoding. `intakeSubmission` is deliberately absent: it records
      // which submission a *new* patient was created from and has no meaning on a
      // return, so re-enrolling must not let it be rewritten.
      'phase' => $this->phaseMap->code($user->hasField('field_weight_loss_phase')
        ? $user->get('field_weight_loss_phase')->value
        : NULL),
      'startWeight' => $this->floatOrNull($user, 'field_program_start_weight'),
      'goalWeight' => $this->floatOrNull($user, 'field_goal_weight'),
      'emailNotifications' => $this->booleanOrTrue($user, 'field_email_optout'),
      'roles' => $roles,
    ];
  }

  /**
   * One roster row, in the shape the frontend's PatientRow expects.
   */
  public function toRosterRow(UserInterface $user): array {
    $roles = array_values($user->getRoles());
    $role = $this->rosterRole($roles);
    $stored = $user->hasField('field_weight_loss_phase')
      ? $user->get('field_weight_loss_phase')->value
      : NULL;

    return [
      'id' => (int) $user->id(),
      'role' => $role,
      'roles' => $roles,
      'name' => $this->displayName($user),
      'email' => (string) $user->getEmail(),
      'phone' => $this->stringOrNull($user, 'field_phone_number'),
      // Staff have no program, so they get no phase rather than a fake one.
      'phase' => $role === 'patient' ? $this->phaseMap->code($stored) : NULL,
      'phaseLabel' => $role === 'patient' ? $this->phaseMap->label($this->phaseMap->code($stored)) : NULL,
      'programStart' => $this->stringOrNull($user, 'field_program_start_date'),
      'programDay' => $this->intOrNull($user, 'field_user_current_program_day_c'),
      'startWeight' => $this->floatOrNull($user, 'field_program_start_weight'),
      'netLoss' => $this->floatOrNull($user, 'field_net_weight_loss'),
    ];
  }

  /**
   * Which column a role belongs in on the roster.
   *
   * Both patient roles answer `patient`, not just `enrolled_patient`. That matters
   * because archiving is a *swap*: an archived account holds `archived_patient`
   * and no longer holds `enrolled_patient`, so a resolver that only knew the
   * enrolled role would fall through to the staff default and report every
   * archived patient as a health coach. Archived status is what the tab is, not
   * what the person is.
   *
   * Anything in the clinic that is neither a patient nor a chiropractor is staff
   * as far as the roster is concerned. The site has no confirmed role ID for a
   * health coach, so inventing one here would be a guess that quietly returns
   * nobody; the raw `roles` array is on every row so the real ID is visible.
   */
  private function rosterRole(array $roles): string {
    if (in_array(self::ROLE_ENROLLED, $roles, TRUE)
      || in_array(self::ROLE_ARCHIVED, $roles, TRUE)) {
      return 'patient';
    }
    if (in_array(self::ROLE_CHIROPRACTOR_ACTIVE, $roles, TRUE)
      || in_array(self::ROLE_CHIROPRACTOR_INACTIVE, $roles, TRUE)) {
      return 'doctor';
    }
    return self::ROLE_STAFF_FALLBACK;
  }

  /**
   * Display name, preferring the full-name field over the account name.
   */
  private function displayName(UserInterface $user): string {
    $full = $this->stringOrNull($user, 'field_full_name');
    if ($full !== NULL && $full !== '') {
      return $full;
    }
    return (string) $user->getAccountName();
  }

  /**
   * A trimmed string field, or NULL when unset.
   */
  private function stringOrNull(FieldableEntityInterface $entity, string $field): ?string {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    $value = trim((string) $entity->get($field)->value);
    return $value === '' ? NULL : $value;
  }

  /**
   * An integer field, or NULL when unset or unparseable.
   */
  private function intOrNull(FieldableEntityInterface $entity, string $field): ?int {
    $value = $this->stringOrNull($entity, $field);
    if ($value === NULL || !is_numeric($value)) {
      return NULL;
    }
    return (int) $value;
  }

  /**
   * The target id of an entity reference, or NULL when unset.
   *
   * Separate from {@see self::intOrNull()} because a reference field does not answer
   * to `->value`: `field_clinic_location` holds a clinic entity id in its `target_id`
   * property, and its computed `value` is NULL even when a reference is definitely
   * there — verified against the site, not assumed. Reading it the scalar way is
   * silently wrong rather than loudly broken, so every reference field would come
   * back "not set" and the archive would show no location for any patient who has
   * one, while writes kept working and hid the discrepancy.
   *
   * Falls back to `->value` so a scalar-backed stub, where the id was stored as a
   * plain value, still reads back.
   */
  private function referenceIdOrNull(FieldableEntityInterface $entity, string $field): ?int {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }

    $itemList = $entity->get($field);
    $candidate = NULL;
    // Tested on the property rather than the item list's class, because what this is
    // really asking is "is there a target_id to read?" — a stub list that models a
    // reference can then answer honestly, where an instanceof check would force the
    // test to build a real EntityReferenceFieldItemList to say the same thing.
    if (isset($itemList->target_id)) {
      $candidate = $itemList->target_id;
    }
    if ($candidate === NULL) {
      $candidate = $itemList->value;
    }
    if ($candidate === NULL || $candidate === '' || !is_numeric($candidate)) {
      return NULL;
    }
    return (int) $candidate;
  }

  /**
   * A decimal field as a float, or NULL when unset.
   *
   * NULL means "not recorded". Zero is a real answer, so the check is against
   * NULL and not against a falsy value.
   */
  private function floatOrNull(FieldableEntityInterface $entity, string $field): ?float {
    $value = $this->stringOrNull($entity, $field);
    if ($value === NULL || !is_numeric($value)) {
      return NULL;
    }
    return (float) $value;
  }

  /**
   * A stored opt-out flag, read back as the positive "send them" form.
   *
   * {@see self::cleanOptOut()} writes `field_email_optout` inverted because the
   * legacy webform asked whether to stop the emails, while the forms here ask
   * whether to send them. This is the other half of that inversion, so a prefilled
   * toggle shows what the patient will actually get.
   *
   * An account with no value in the field defaults to TRUE — subscribed — which is
   * both how {@see self::cleanOptOut()} reads an absent flag and the safer of the
   * two answers: a patient who has never opted out should keep their reminders, and
   * the chiropractor can always untick the box.
   */
  private function booleanOrTrue(FieldableEntityInterface $entity, string $field): bool {
    $value = $this->stringOrNull($entity, $field);
    if ($value === NULL) {
      return TRUE;
    }
    return !in_array((int) $value, [1, TRUE, '1', 'true'], TRUE);
  }

  /**
   * Phone numbers are stored as text and kept mostly as typed.
   */
  private function cleanPhone(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $phone = trim((string) $value);
    return $phone === '' ? NULL : $phone;
  }

  /**
   * A date field, stored as Y-m-d. Collects its own error.
   *
   * A date before today is refused, because a programme start in the past is not
   * something anybody chooses. The exception is `$alreadyStored`: a date equal to the
   * one the record already holds is not a choice being made, it is the same date
   * arriving again, and refusing it would make the archived patient's own history
   * un-reenrollable. See {@see self::validateEnroll()} for why that case is real.
   *
   * @param string|null $alreadyStored
   *   The value currently on the record, or NULL when there is nothing stored. Only
   *   passed by the paths that read an existing record; a create has nothing to
   *   preserve and so never passes it.
   */
  private function cleanDate(mixed $value, string $key, array &$errors, ?string $alreadyStored = NULL): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $raw = trim((string) $value);
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
    if ($date === FALSE || $date->format('Y-m-d') !== $raw) {
      $errors[$key] = 'Use a date in YYYY-MM-DD format.';
      return NULL;
    }
    // Before today, but not the date already on file. A stored date is not compared
    // on its own merits — an archived patient who started in 2023 still started in
    // 2023 — it is only tolerated while it is unchanged.
    $today = new \DateTimeImmutable('today');
    if ($date < $today && $raw !== $alreadyStored) {
      $errors[$key] = 'Program start cannot be in the past.';
      return NULL;
    }
    return $raw;
  }

  /**
   * A weight in pounds. Collects its own error.
   *
   * Zero is kept: 0 is a real weight, and the difference between "0 lbs" and
   * "not recorded" is the whole reason this returns NULL rather than 0.
   *
   * @param float|null $alreadyStored
   *   The weight on the record, or NULL when there is none. See
   *   {@see self::cleanDate()} for why an unchanged legacy value has to be tolerated.
   */
  private function cleanWeight(mixed $value, string $key, array &$errors, ?float $alreadyStored = NULL): ?float {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!is_numeric($value)) {
      $errors[$key] = 'Enter a number of pounds.';
      return NULL;
    }
    $weight = round((float) $value, 1);

    // A body weighs more than nothing. Zero is still kept, because 0 is a reading the
    // archive genuinely holds ("measured, and the reading was 0") and telling that
    // apart from "never recorded" is why this returns NULL rather than 0 for a blank
    // box; a negative weight is not a reading at all.
    if ($weight < 0) {
      $errors[$key] = 'Weight cannot be negative.';
      return NULL;
    }

    // The upper bound is what stops a typo becoming a record: the archive contains
    // goal weights of 3434340 and 5155770000, all of them a mistyped digit rather than
    // a person. The limit sits far above the heaviest human ever documented, so it
    // rejects keying errors and nothing else.
    //
    // `$alreadyStored` exempts the value already on the record. That exemption is not
    // leniency about bad data — it is what stops this rule from locking those patients
    // out of re-enrolling. The data itself is cleaned up separately; until then a
    // returning patient keeps their own number and can still be brought back.
    if ($weight > self::MAX_WEIGHT_LBS && $weight !== $alreadyStored) {
      $errors[$key] = sprintf('Enter a weight in pounds, up to %d.', self::MAX_WEIGHT_LBS);
      return NULL;
    }

    return $weight;
  }

  /**
   * A weight currently on the account, rounded the way `cleanWeight()` rounds.
   *
   * Returned NULL when the field is absent or empty, which is what makes it useless as
   * an exception: there is then no stored value to tolerate, and the bound applies in
   * full. Most archived patients have no goal weight, which makes that the common case
   * rather than an edge one.
   */
  private function storedWeight(UserInterface $user, string $field): ?float {
    $value = $this->stringOrNull($user, $field);
    if ($value === NULL || !is_numeric($value)) {
      return NULL;
    }
    return round((float) $value, 1);
  }

  /**
   * The phase code, or NULL when the client sent something unknown.
   *
   * A bad code is a client error rather than a silent default, but it is
   * collected rather than thrown so one bad select does not discard a whole
   * form's other errors.
   */
  private function cleanPhase(mixed $value, array &$errors): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $code = strtoupper(trim((string) $value));
    if (!$this->phaseMap->isValidCode($code)) {
      $errors['phase'] = 'Choose one of the listed program phases.';
      return NULL;
    }
    return $code;
  }

  /**
   * Accepts the ways a checkbox or JSON body can express a boolean.
   */
  private static function isTruthy(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value)) {
      return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], TRUE);
    }
    return (bool) $value;
  }
}
