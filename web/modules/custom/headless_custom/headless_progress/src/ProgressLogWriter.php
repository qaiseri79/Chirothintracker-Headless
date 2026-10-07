<?php

namespace Drupal\headless_progress;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\contact\Entity\Message;
use Drupal\user\Entity\User;
use Drupal\headless_progress\Exception\ProgressValidationException;
use Psr\Log\LoggerInterface;

/**
 * Shared progress submission and field mapping for patient and doctor forms.
 */
final class ProgressLogWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly TrackingCalculator $calculator,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Saves a patient-owned log and updates the patient's calculated statistics.
   */
  public function create(User $patient, array $fields, int $author): array {
    return $this->save($patient, $fields, $author);
  }

  /** Validates a compact doctor log and prevents duplicate patient dates. */
  public function createForDoctor(User $patient, array $fields, int $author): array {
    $fields = array_intersect_key($fields, array_flip(['field_date', 'field_weight', 'field_water_intake', 'field_blood_sugar', 'field_blood_pressure']));
    $date = $fields['field_date'] ?? NULL;
    $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : FALSE;
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > gmdate('Y-m-d')) {
      throw new ProgressValidationException([['field' => 'field_date', 'message' => 'Choose a valid date that is not in the future.']]);
    }
    $issues = [];
    $water = $fields['field_water_intake'] ?? NULL;
    if (!is_numeric($water) || !is_finite((float) $water) || (float) $water < 0) {
      $issues[] = ['field' => 'field_water_intake', 'message' => "Enter yesterday's water intake (0 or more)."];
    }
    $sugar = $fields['field_blood_sugar'] ?? NULL;
    if ($sugar !== NULL && $sugar !== '' && (!is_numeric($sugar) || !is_finite((float) $sugar) || (float) $sugar <= 0)) {
      $issues[] = ['field' => 'field_blood_sugar', 'message' => 'Blood sugar must be a positive number.'];
    }
    $pressure = $fields['field_blood_pressure'] ?? '';
    if (!is_string($pressure) || ($pressure !== '' && !preg_match('/^\\d{2,3}\\/\\d{2,3}$/', $pressure))) {
      $issues[] = ['field' => 'field_blood_pressure', 'message' => 'Blood pressure should look like 120/80.'];
    }
    if ($issues) { throw new ProgressValidationException($issues); }
    $key = 'headless_progress:doctor_log:' . $patient->id() . ':' . $date;
    if (!$this->lock->acquire($key, 30)) {
      throw new ProgressValidationException([['field' => 'field_date', 'message' => 'A log is being saved for this date. Please try again.']]);
    }
    try {
      $storage = $this->entities->getStorage('contact_message');
      $duplicates = $storage->getQuery()->accessCheck(FALSE)->condition('contact_form', 'tracking_weight')->condition('uid', $patient->id())->condition('field_date', $date)->count()->execute();
      if ($duplicates) {
        throw new ProgressValidationException([['field' => 'field_date', 'message' => 'A log already exists for ' . $parsed->format('m/d/Y') . '.']]);
      }
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('contact_form', 'tracking_weight')->condition('uid', $patient->id())->sort('field_date', 'DESC')->range(0, 1)->execute();
      $latest = $ids ? $storage->load(reset($ids)) : NULL;
      $updateSummary = !$latest || $date >= (string) $latest->get('field_date')->value;
      return $this->save($patient, $fields, $author, $updateSummary, TRUE);
    }
    finally { $this->lock->release($key); }
  }

  private function save(User $patient, array $fields, int $author, bool $updateSummary = TRUE, bool $doctorLog = FALSE): array {
    if (!is_string($fields['field_date'] ?? NULL)) {
      throw new ProgressValidationException([['field' => 'field_date', 'message' => 'Choose a valid date.']]);
    }
    $message = $this->entities->getStorage('contact_message')->create([
      'contact_form' => 'tracking_weight',
      'uid' => (int) $patient->id(),
      'subject' => 'Tracking Weight Log',
      'message' => 'Daily progress log',
    ]);
    $numeric = ['field_weight', 'field_water_intake', 'field_grade', 'field_blood_sugar', 'field_sleep_hours',
      'field_neck', 'field_chest', 'field_shoulders', 'field_arm_left_bicep', 'field_arm_right_bicep',
      'field_abdomen', 'field_hips', 'field_thigh_left', 'field_thigh_right', 'field_calf_left', 'field_calf_right',
    ];
    $issues = [];
    foreach ($numeric as $field) {
      $value = $fields[$field] ?? NULL;
      if ($value !== NULL && $value !== '' && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0)) {
        $issues[] = ['field' => $field, 'message' => 'Enter a valid non-negative number.'];
      }
    }
    if (!isset($fields['field_weight']) || !is_numeric($fields['field_weight']) || (float) $fields['field_weight'] <= 0) {
      $issues[] = ['field' => 'field_weight', 'message' => 'Enter a valid weight.'];
    }
    if ($issues) {
      throw new ProgressValidationException($issues);
    }
    $this->mapFields($message, $fields, $author);
    if ($doctorLog) { $message->set('field_grade', NULL); }
    $date = (string) $message->get('field_date')->value;
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      throw new ProgressValidationException([['field' => 'field_date', 'message' => 'Choose a valid date.']]);
    }
    foreach ($fields as $name => $value) {
      if (str_starts_with((string) $name, 'field_') && $message->hasField($name)) {
        foreach ($message->get($name)->validate() as $violation) {
          $issues[] = ['field' => $name, 'message' => (string) $violation->getMessage()];
        }
      }
    }
    if ($issues) {
      throw new ProgressValidationException($issues);
    }
    $transaction = $this->database->startTransaction();
    try {
      $derived = $this->calculator->computeAll($message, $fields, $patient);
      $this->calculator->applyToMessage($message, $derived['message']);
      if ($updateSummary) {
        if ($doctorLog && $patient->hasField('field_last_seen')) { $derived['user']['field_last_seen'] = $date; }
        $this->calculator->applyToUser($patient, $derived['user']);
      }
      $message->save();
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      $this->entities->getStorage('user')->resetCache([$patient->id()]);
      throw $error;
    }
    unset($transaction);
    // A follow-up failure cannot turn a saved log into a failed submission.
    try {
      if ($updateSummary) { $this->calculator->runEvaluations($patient, $message, $fields); }
    }
    catch (\Throwable $error) {
      $this->logger->error('Log @id saved, but evaluations failed: @message', ['@id' => $message->id(), '@message' => $error->getMessage()]);
    }
    return ['id' => (int) $message->id(), 'uuid' => $message->uuid()];
  }

  /**
   * Maps form fields while protecting ownership and server-calculated fields.
   */
  public function mapFields(Message $message, array $fields, int $uid): void {
    $entityRefFields = [
      'field_breakfast_protein',
      'field_breakfast_fruit_sel',
      'field_lunch_protein',
      'field_lunch_fruit_sel',
      'field_lunch_vegetables',
      'field_lunch_vegetables_free',
      'field_lunch_bread',
      'field_dinner_protein',
      'field_dinner_fruit_sel',
      'field_dinner_vegetables',
      'field_dinner_vegetables_free',
      'field_dinner_bread',
      'field_flags',
      'field_chiropractor_flags',
    ];

    $booleanFields = [
      'field_mind_set_work',
      'field_chiroburst_bend',
    ];

    $dateFields = ['field_date'];

    // Everything the tracking form may submit. Deliberately excludes the
    // server-computed fields (`field_program_day`, `field_total_measurements`,
    // `field_weight_loss_*`) and the base fields.
    $writable = array_merge(
      $entityRefFields,
      $booleanFields,
      $dateFields,
      [
        'field_weight',
        'field_water_intake',
        'field_grade',
        'field_neck',
        'field_chest',
        'field_shoulders',
        'field_arm_left_bicep',
        'field_arm_right_bicep',
        'field_abdomen',
        'field_hips',
        'field_thigh_left',
        'field_thigh_right',
        'field_calf_left',
        'field_calf_right',
        'field_breakfast_other',
        'field_lunch_other',
        'field_dinner_other',
        'field_blood_pressure',
        'field_blood_sugar',
        'field_sleep_hours',
        'field_notes',
        'field_question',
        'field_other_food',
      ]
    );

    foreach ($fields as $key => $value) {
      if (!in_array($key, $writable, TRUE)) {
        continue;
      }
      if (!$message->hasField($key)) {
        continue;
      }
      if ($value === ""  ||  $value === NULL) {
        continue;
      }

      if (in_array($key, $dateFields, TRUE)) {
        $dateStr = (string) $value;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $match)) {
          $value = sprintf('%s-%s-%s', $match[3], str_pad($match[1], 2, '0', STR_PAD_LEFT), str_pad($match[2], 2, '0', STR_PAD_LEFT));
        }
        $message->set($key, $value);
        continue;
      }

      if (in_array($key, $booleanFields, TRUE)) {
        $value = ($value === TRUE  ||  $value === 'true'  ||  $value === 'on'  ||  $value === 1  ||  $value === '1') ? 1 : 0;
        $message->set($key, $value);
        continue;
      }

      if (in_array($key, $entityRefFields, TRUE)) {
        $vals = is_array($value) ? $value : [$value];
        $targets = [];
        foreach ($vals as $v) {
          if ($v !== ""  &&  $v !== NULL) {
            $targets[] = ['target_id' => (string) $v];
          }
        }
        if (!empty($targets)) {
          $message->set($key, $targets);
        }
        continue;
      }

      $message->set($key, $value);
    }

    $message->set('field_author', [['target_id' => (string) $uid]]);
  }

}
