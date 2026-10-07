<?php

declare(strict_types=1);

namespace Drupal\headless_patients;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_intake\ClinicIntakeLinkService;
use Drupal\headless_intake\IntakeInviteService;
use Drupal\headless_patients\Exception\PatientsException;

/**
 * Reads the clinic's intake submissions and their review state.
 *
 * A submission is a `contact_message` on the `patient_intake` contact form.
 * Every one of them already carries `field_clinic`, set server-side by
 * IntakeApiController from the invite token that was spent, so the clinic
 * condition here has a trustworthy value to filter on. The token is the only
 * thing that ever decided the clinic, and a client never gets to say which
 * clinic a submission belongs to.
 *
 * ## Review state is the site's own `intake_processed_cs` flag
 *
 * Not a table, and not a field. The site has been recording this for years: the
 * flag `intake_processed_cs` on the submission itself, set by
 * `custom_module`'s `FlagIntakeForm()`, which is what the chiropractor's existing
 * intake screen writes. That history is the operational record of what has
 * already been dealt with, and it cannot be recomputed.
 *
 * This module originally kept a parallel `headless_patients_intake_review` table.
 * That was a second source of truth created without a plan for the flag data
 * already in the database, and it failed in the worst possible direction: the
 * table started empty, so every one of the clinic's 188 submissions reported as
 * `new` and the chiropractor was shown work that had already been done. Reading
 * the flag is what makes the two views agree.
 *
 * A flagging records *who* reviewed, so "is this reviewed" is "does any flagging
 * exist", not "is it flagged by me" — a clinic has to see its own history, and
 * chiropractor A reviewing something must not leave it looking unreviewed to
 * chiropractor B.
 */
class PatientIntakeService {

  /** Review states, as the roster's status select uses them. */
  public const STATUS_NEW = 'new';

  public const STATUS_CHECKED = 'checked';

  private const STATUSES = [self::STATUS_NEW, self::STATUS_CHECKED];

  /**
   * The contact form the public intake app submits against.
   */
  private const CONTACT_FORM = 'patient_intake';

  /**
   * The flag that means a chiropractor has reviewed this submission.
   *
   * Same machine name custom_module's FlagIntakeForm() uses. Changing it here
   * without changing that function would split the record in two again, which is
   * the mistake this class exists to avoid.
   */
  private const FLAG_PROCESSED = 'intake_processed_cs';

  /** The entity type both the flag is attached to and the flaggings point at. */
  private const ENTITY_TYPE = 'contact_message';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FlagServiceInterface $flagService,
    private readonly IntakeInviteService $invite,
    private readonly ClinicIntakeLinkService $clinicLinks,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * The clinic's intake submissions, newest first.
   *
   * The returned keys are a projection, chosen by hand rather than looped over
   * every field: putting a field on this screen is a decision that it belongs
   * here. A field added to the intake form later does not appear in this list
   * until someone decides it should. {@see self::get()} is the full view.
   *
   * @return array<int, array<string, mixed>>
   *   One row per submission.
   */
  public function list(int $clinicId): array {
    $storage = $this->entityTypeManager->getStorage('contact_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', self::CONTACT_FORM)
      ->condition('field_clinic', $clinicId)
      ->sort('created', 'DESC')
      // Tie-break on the entity's own ID, descending, so submissions created in
      // the same second still come back in a stable newest-first order.
      //
      // 'cid' is the *contact form* entity's key, not a field on the message.
      // contact_message has no cid: its fields are id, uuid, language, created,
      // changed, contact_form, name, mail, subject, message, copy and recipient.
      // addSort() resolves its field name eagerly, so the wrong name throws
      // "Field cid not found" out of the query builder at request time and takes
      // the whole snapshot down with it.
      ->sort('id', 'DESC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    // $ids is keyed by entity ID from loadMultiple(), so normalise the keys
    // rather than trusting them to be sequential.
    $review = $this->reviewStates(array_map('intval', array_values($ids)));
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $id => $message) {
      $row = [
        'id' => (int) $id,
        'firstName' => $this->stringOrNull($message, 'field_first_name'),
        'lastName' => $this->stringOrNull($message, 'field_last_name'),
        'email' => $this->stringOrNull($message, 'field_email_address'),
        'phone' => $this->stringOrNull($message, 'field_phone_number'),
        'goalWeight' => $this->floatOrNull($message, 'field_goal_weight'),
        'programStart' => $this->stringOrNull($message, 'field_program_start_date'),
        // Core `created` is when the submission landed, which is the only
        // trustworthy submitted-at value: it is set by the database, not the form.
        'submitted' => (int) $message->get('created')->value,
        'status' => $review[(int) $id] ?? self::STATUS_NEW,
      ];
      $row['name'] = trim(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? ''));
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * How many submissions are still unreviewed, for the tab's "new" badge.
   */
  public function newCount(int $clinicId): int {
    $rows = $this->list($clinicId);
    return count(array_filter($rows, static fn (array $row): bool => $row['status'] === self::STATUS_NEW));
  }

  /**
   * Counts submissions still unreviewed without loading the rows.
   *
   * A count in SQL rather than a filter over list(), so the tab badge does not
   * depend on there being a manageable number of submissions.
   *
   * A submission with no flagging is `new`, so the count is everything in the
   * clinic minus the ones carrying the processed flag. Subtracting rather than
   * counting the unflagged directly keeps this the exact complement of
   * {@see self::countChecked()} — the badge cannot come out negative, and the two
   * numbers always add back up to the tab's total.
   */
  public function countNew(int $clinicId): int {
    $storage = $this->entityTypeManager->getStorage('contact_message');
    $total = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', self::CONTACT_FORM)
      ->condition('field_clinic', $clinicId)
      ->count()
      ->execute();

    return max(0, $total - $this->countChecked($clinicId));
  }

  /**
   * How many of the clinic's submissions have actually been reviewed.
   *
   * Counts distinct submissions carrying the processed flag, scoped to the
   * clinic's own submissions rather than to the whole site — the flaggings table
   * has no clinic column, so the clinic can only be established by coming back to
   * contact_message. {@see self::clinicMessageIds()} and
   * {@see self::flaggedMessageIds()} do that in that order.
   *
   * Counting rather than filtering list() means the tab badge cannot become
   * wrong because the list got long enough to be paginated, and it cannot
   * disagree with the rows the chiropractor is looking at, because both are
   * derived from the same flag.
   */
  public function countChecked(int $clinicId): int {
    $clinicIds = $this->clinicMessageIds($clinicId);
    if ($clinicIds === []) {
      return 0;
    }
    return count($this->flaggedMessageIds($clinicIds));
  }

  /**
   * The clinic's intake submissions, as entity IDs.
   *
   * Used where the flagged set has to be intersected with the clinic's
   * submissions. The flaggings table has no clinic column — the flag lives on the
   * submission and inherits its clinic — so the clinic can only be established by
   * coming back to contact_message. Taking the clinic's IDs first and filtering
   * the flaggings by them is both correct and bounded: asking the other way round
   * means loading every flagged submission on the site, which is a different
   * order of magnitude.
   *
   * @return int[]
   *   Message IDs, possibly empty.
   */
  private function clinicMessageIds(int $clinicId): array {
    return array_map('intval', $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', self::CONTACT_FORM)
      ->condition('field_clinic', $clinicId)
      ->execute());
  }

  /**
   * Which of the given submissions carry the processed flag, deduplicated.
   *
   * Deduped because two chiropractors can each flag the same submission, and the
   * question being answered is "is it reviewed", not "how many people looked at
   * it". The filter on entity_id is what scopes this to one clinic, so the result
   * is not intersected again on the way out.
   *
   * @param int[] $messageIds
   *   Candidate submissions. Callers must not pass an empty array: `IN ()` is not
   *   something the query builder can express.
   *
   * @return int[]
   *   The subset that is flagged.
   */
  private function flaggedMessageIds(array $messageIds): array {
    $flaggings = $this->entityTypeManager->getStorage('flagging')->loadMultiple(
      $this->entityTypeManager->getStorage('flagging')->getQuery()
        ->accessCheck(FALSE)
        ->condition('flag_id', self::FLAG_PROCESSED)
        ->condition('entity_type', self::ENTITY_TYPE)
        ->condition('entity_id', $messageIds, 'IN')
        ->execute()
    );

    $ids = [];
    foreach ($flaggings as $flagging) {
      $ids[(int) $flagging->get('entity_id')->value] = TRUE;
    }
    return array_keys($ids);
  }

  /**
   * One submission, in full, for the roster's view action.
   *
   * Includes every field the intake form actually captured, not just the list
   * projection, because this is the screen where someone reads a patient's own
   * words before acting on them.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the submission is not in the caller's clinic.
   */
  public function get(int $clinicId, int $messageId): array {
    $storage = $this->entityTypeManager->getStorage('contact_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', self::CONTACT_FORM)
      ->condition('field_clinic', $clinicId)
      ->condition('id', $messageId)
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      // Same answer as a submission that does not exist, for the same reason as
      // everywhere else in this module.
      throw PatientsException::notFound('intake submission');
    }

    $message = $storage->load(reset($ids));
    $fields = [];
    foreach ($message->getFieldDefinitions() as $name => $definition) {
      if (str_starts_with($name, 'field_') && !$message->get($name)->isEmpty()) {
        $fields[$name] = $message->get($name)->getValue();
      }
    }

    $review = $this->reviewStates([$messageId]);

    return [
      'id' => $messageId,
      'submitted' => (int) $message->get('created')->value,
      'status' => $review[$messageId] ?? self::STATUS_NEW,
      'fields' => $fields,
    ];
  }

  /**
   * Deletes one intake submission outright.
   *
   * A hard delete, and the only destructive thing this module does. The row is a
   * `contact_message`, which has no archived state to fall back to, so "hide it"
   * would mean inventing a field to hide it behind — a second source of truth of
   * exactly the kind the class docblock says this module stopped creating. The
   * UI confirms before calling.
   *
   * Deleting the entity cascades its field data, so the submission is gone rather
   * than blank. That is why the caller is expected to have asked a human first.
   *
   * ## The clinic check is the whole authorisation
   *
   * `ownedIds()` is the same query that scopes the list, so an id belonging to
   * another clinic, an id that is not an intake submission at all, and an id that
   * never existed are one indistinguishable answer: {@see PatientsException} with
   * a 404. A chiropractor cannot delete another clinic's patient data by guessing
   * an id, and cannot probe for which ids exist, because a miss looks exactly like
   * a hit on something they are not allowed to touch.
   *
   * Flaggings are removed with the message they point at rather than left behind:
   * the flag entity is not fieldable, so nothing else here would clean them up,
   * and an orphaned flagging would keep counting toward the flag's own totals.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the submission is not in the caller's clinic.
   */
  public function delete(int $clinicId, int $messageId): void {
    $owned = $this->ownedIds($clinicId, [$messageId]);
    if ($owned === []) {
      throw PatientsException::notFound('intake submission');
    }

    $id = reset($owned);
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);

    // Flaggings first. The flag itself is allowed to be absent here — a submission
    // can only have a flagging if the flag existed, so a missing flag means there
    // is nothing to clean up, not that the delete has failed.
    if ($this->flagService->getFlagById(self::FLAG_PROCESSED)) {
      $flaggings = $this->entityTypeManager->getStorage('flagging')->loadMultiple(
        $this->entityTypeManager->getStorage('flagging')->getQuery()
          ->accessCheck(FALSE)
          ->condition('flag_id', self::FLAG_PROCESSED)
          ->condition('entity_type', self::ENTITY_TYPE)
          ->condition('entity_id', (int) $id)
          ->execute()
      );
      $this->entityTypeManager->getStorage('flagging')->delete($flaggings);
    }

    // Loaded, not passed as the bare id. `EntityStorageBase::delete()` calls
    // get_class() on what it is given, so handing it the integer array `ownedIds()`
    // returns is a TypeError rather than a delete. The row was just fetched by the
    // scope query, so this is a second read rather than a new one.
    $storage->delete($storage->loadMultiple([(int) $id]));

    $this->logger->notice('Intake submission @id deleted from clinic @clinic.', [
      '@id' => $id,
      '@clinic' => $clinicId,
    ]);
  }

  /**
   * Sets the review state of one or more submissions.
   *
   * `checked` means a flagging exists, `new` means none does — so marking a
   * submission unreviewed removes the acting chiropractor's own flagging rather
   * than touching anyone else's. A submission another chiropractor flagged stays
   * `checked` for the clinic, which is the same answer the list gives, so the
   * toggle can never leave a row in a state the next page load disagrees with.
   *
   * The IDs are re-checked against the clinic before anything is written, so a
   * caller cannot mark another clinic's submission reviewed by guessing an id. An
   * ID outside the clinic is reported and skipped rather than written, and the
   * call tells the client which ones those were.
   *
   * @param array<int, int> $messageIds
   *   Submissions to change.
   * @param string $status
   *   One of self::STATUS_NEW, self::STATUS_CHECKED.
   * @param int $actorId
   *   The chiropractor performing the review. A flagging has to belong to
   *   somebody, and flagging as anonymous throws.
   *
   * @return array{updated: array<int, int>, unchanged: array<int, int>, rejected: array<int, int>}
   *   Which IDs changed, which were already correct, and which were refused.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the status is not one this module knows, or the review cannot be
   *   recorded at all.
   */
  public function setReview(int $clinicId, array $messageIds, string $status, int $actorId): array {
    if (!in_array($status, self::STATUSES, TRUE)) {
      throw PatientsException::invalid([
        'status' => sprintf('Review state must be one of: %s.', implode(', ', self::STATUSES)),
      ]);
    }

    $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
    if ($messageIds === []) {
      throw PatientsException::invalid(['ids' => 'Select at least one submission.']);
    }

    $owned = $this->ownedIds($clinicId, $messageIds);
    $rejected = array_values(array_diff($messageIds, $owned));

    $checked = ($status === self::STATUS_CHECKED);

    // Loaded for both directions, not just for flagging. Unflagging needs the flag
    // too: FlagService::getFlagging() and unflag() both take a FlagInterface as
    // their first argument, so passing NULL on the unflag path was a fatal type
    // error rather than a skipped write. `unflag` is not the mirror image of
    // `flag` in that API — both need the flag.
    $flag = $this->flagService->getFlagById(self::FLAG_PROCESSED);

    if (!$flag) {
      // The flag is the record. If it has gone missing the review state cannot be
      // stored at all, and reporting success would be a lie the chiropractor sees
      // on the next page load when the row still says `new`.
      throw new PatientsException(
        'The intake review flag is missing, so intake review state cannot be changed.',
        500
      );
    }

    $actor = $actorId > 0 ? $this->entityTypeManager->getStorage('user')->load($actorId) : NULL;
    if (!$actor) {
      // Same reasoning as FlagService::flagEntity(): flagging needs a flagger, and
      // flagging as anonymous throws a LogicException out of FlagCountManager.
      throw new PatientsException(
        'The review could not be recorded against a chiropractor.',
        500
      );
    }

    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $unchanged = [];
    foreach ($storage->loadMultiple($owned) as $id => $message) {
      $existing = $this->flagService->getFlagging($flag, $message, $actor);

      if ($checked && !$existing) {
        $this->flagService->flag($flag, $message, $actor);
      }
      elseif (!$checked && $existing) {
        $this->flagService->unflag($flag, $message, $actor);
      }
      else {
        // Already in the requested state. Not an error, but worth returning
        // separately so the caller can tell "I changed this" from "this was
        // already correct".
        $unchanged[] = (int) $id;
      }
    }

    if ($rejected !== []) {
      // Worth saying out loud: these were refused because of where they live,
      // not because the write failed.
      $this->logger->warning('Intake review refused for @count message(s) outside clinic @clinic.', [
        '@count' => count($rejected),
        '@clinic' => $clinicId,
      ]);
    }

    $this->logger->notice('Intake review set to @status for @count submission(s) in clinic @clinic.', [
      '@status' => $status,
      '@count' => count($owned) - count($unchanged),
      '@clinic' => $clinicId,
    ]);

    return [
      'updated' => array_values(array_diff(array_map('intval', $owned), $unchanged)),
      'unchanged' => $unchanged,
      'rejected' => $rejected,
    ];
  }

  /**
   * The shareable intake link for this clinic, or '' when there is not one.
   *
   * Built from the clinic's newest still-usable invite token, which is the only
   * thing that can actually admit a submission for this clinic. Returns the URL
   * the intake frontend serves at /intake/{token}, from headless_intake's own
   * config, rather than anything derived from the current request.
   */
  public function intakeLink(int $clinicId): string {
    // One permanent link per clinic, stored on the clinic itself. No scanning
    // for a "usable" invite any more: there is exactly one candidate and it
    // either exists or it does not.
    return $this->clinicLinks->urlFor($clinicId) ?? '';
  }

  /**
   * Which of the given IDs are intake submissions in this clinic.
   *
   * @param array<int, int> $messageIds
   *   Candidate IDs.
   *
   * @return array<int, int>
   *   The subset that belongs to the clinic.
   */
  private function ownedIds(int $clinicId, array $messageIds): array {
    return array_map('intval', $this->entityTypeManager->getStorage('contact_message')->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', self::CONTACT_FORM)
      ->condition('field_clinic', $clinicId)
      // 'id', not 'cid': see the note in list(). This query is the tenant check
      // for setReview(), so it has to be the same identity the IDs were issued as.
      ->condition('id', $messageIds, 'IN')
      ->execute());
  }

  /**
   * Review states for the given submissions, keyed by message ID.
   *
   * Only reviewed submissions appear. A submission with no flagging is `new`, and
   * every caller applies that default itself, so this stays a partial map rather
   * than inventing an entry per message.
   *
   * Queries flaggings directly rather than going through FlagServiceInterface,
   * whose getFlagging() is scoped to one flagger: "did *I* review this" is the
   * wrong question for a clinic-wide list. The `flag_id` condition is the flag's
   * bundle on the flagging entity, which is what FlaggingStorage itself queries.
   *
   * @param array<int, int> $messageIds
   *   Submissions to look up.
   *
   * @return array<int, string>
   *   Message ID => STATUS_CHECKED, for the ones that are flagged.
   */
  private function reviewStates(array $messageIds): array {
    if ($messageIds === []) {
      return [];
    }

    $flaggings = $this->entityTypeManager->getStorage('flagging')->loadMultiple(
      $this->entityTypeManager->getStorage('flagging')->getQuery()
        ->accessCheck(FALSE)
        ->condition('flag_id', self::FLAG_PROCESSED)
        ->condition('entity_type', self::ENTITY_TYPE)
        ->condition('entity_id', array_map('intval', $messageIds), 'IN')
        ->execute()
    );

    $states = [];
    foreach ($flaggings as $flagging) {
      $states[(int) $flagging->get('entity_id')->value] = self::STATUS_CHECKED;
    }
    return $states;
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
   * A decimal field as a float, or NULL when unset.
   */
  private function floatOrNull(FieldableEntityInterface $entity, string $field): ?float {
    $value = $this->stringOrNull($entity, $field);
    if ($value === NULL || !is_numeric($value)) {
      return NULL;
    }
    return (float) $value;
  }
}
