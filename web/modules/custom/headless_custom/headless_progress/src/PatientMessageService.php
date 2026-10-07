<?php

namespace Drupal\headless_progress;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\Message;

/**
 * Service for patient message operations.
 *
 * Centralizes creation of patient_message contact messages and
 * flagging patients for clinician review.
 */
class PatientMessageService {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\headless_progress\FlagService $flag_service
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected FlagService $flag_service,
  ) {}

  /**
   * Flags a patient for clinician review.
   *
   * Uses the 'reviewed_patients' flag. The chiropractor flags the patient
   * to indicate the submission needs review.
   *
   * @param int $patient_id
   *   The patient's user ID.
   *
   * @return bool
   *   TRUE if flag was set (or already existed), FALSE if chiropractor not found.
   */
  public function flagForReview(int $patient_id): bool {
    $chiropractor_id = $this->getChiropractorId($patient_id);
    if (!$chiropractor_id) {
      // No chiropractor assigned, so there is nobody to own the flag. Not an
      // error: the log is saved either way, and a clinician can still pick it
      // up from the patient list.
      return FALSE;
    }

    return $this->flag_service->flagEntity(
      'reviewed_patients',
      'user',
      $patient_id,
      'user',
      $chiropractor_id
    );
  }

  /**
   * Creates a patient_message contact message from a tracking question.
   *
   * Generic method that can be called from anywhere (REST submit, webform,
   * admin UI, etc.) when a patient submits a question with their tracking log.
   *
   * @param int $patient_id
   *   The patient's user ID.
   * @param string $question
   *   The question text.
   * @param string|null $date
   *   Optional date for the message (defaults to today).
   *
   * @return \Drupal\contact\Entity\Message|null
   *   The created message, or NULL if chiropractor not found.
   */
  public function createQuestionMessage(int $patient_id, string $question, ?string $date = NULL): ?Message {
    $patient = User::load($patient_id);
    if (!$patient) {
      return NULL;
    }

    $chiropractor_id = $this->getChiropractorId($patient_id);
    if (!$chiropractor_id) {
      return NULL;
    }

    $message_value = [
      'value' => $question,
      'format' => 'filtered_html',
    ];

    $message = Message::create([
      'contact_form' => 'patient_message',
      'uid' => $patient_id,
      'field_chiropractor' => $chiropractor_id,
      'field_message' => $message_value,
    ]);

    if ($date) {
      $message->set('created', strtotime($date));
    }

    $message->save();
    return $message;
  }

  /**
   * Gets the chiropractor ID for a patient.
   */
  protected function getChiropractorId(int $patient_id): ?int {
    $patient = User::load($patient_id);
    if (!$patient) {
      return NULL;
    }
    return $patient->get('field_chiropractor')->target_id ?? NULL;
  }
}