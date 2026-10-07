<?php

namespace Drupal\headless_progress;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Drupal\contact\Entity\Message;

/**
 * Centralized calculation service for tracking_weight contact messages.
 *
 * Replaces duplicated logic from custom_module hooks:
 * - entity_presave
 * - webform_submission_presave
 * - entity_insert
 * - entity_update
 */
class TrackingCalculator {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\headless_progress\PatientMessageService $patient_message_service
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected PatientMessageService $patient_message_service,
  ) {}

  /**
   * Compute all derived fields for a tracking_weight message.
   *
   * @param \Drupal\contact\Entity\Message $message
   *   The message entity (new or existing).
   * @param array $submitted_values
   *   Raw submitted field values from the form/API.
   * @param \Drupal\user\UserInterface $account
   *   The patient's user account.
   *
   * @return array
   *   Array with 'message' and 'user' keys containing derived fields for each entity.
   */
  public function computeAll(Message $message, array $submitted_values, User $account): array {
    $message_derived = [];
    $user_derived = [];

    // 1. Program Day (computed) - goes to message
    $program_day = $this->computeProgramDay($account, $submitted_values['field_date'] ?? $message->get('field_date')->getValue()[0]['value'] ?? null);
    if ($program_day !== null) {
      $message_derived['field_program_day_computed'] = $program_day;
      $message_derived['field_program_day'] = max(0, $program_day);
    }

    // 2. Weight Loss Computed
    $weight_loss = $this->computeWeightLoss($account, $submitted_values['field_weight'] ?? $message->get('field_weight')->getValue()[0]['value'] ?? null);
    if ($weight_loss !== null) {
      $message_derived['field_weight_loss_computed'] = $weight_loss;
      // Also set field_weight_loss_to_date on the message (used by ProgressService.mapEntry)
      $message_derived['field_weight_loss_to_date'] = $weight_loss;
    }

    // 3. Total Measurements (inches) - goes to message
    $total_inches = $this->computeTotalInches($message, $submitted_values);
    if ($total_inches !== null) {
      $message_derived['field_total_measurements'] = $total_inches;
    }

    // 4. Net Weight Loss (user field)
    $net_weight_loss = $this->computeNetWeightLoss($account, $weight_loss);
    if ($net_weight_loss !== null) {
      $user_derived['field_net_weight_loss'] = $net_weight_loss;
    }

    // 5. Net Inches Lost (user field)
    $net_inches_lost = $this->computeNetInchesLost($account, $total_inches);
    if ($net_inches_lost !== null) {
      $user_derived['field_net_inches_lost'] = $net_inches_lost;
    }

    // 6. Goal Achieved % (user field)
    $goal_achieved = $this->computeGoalAchieved($account, $weight_loss);
    if ($goal_achieved !== null) {
      $user_derived['field_goal_achieved'] = $goal_achieved;
    }

    // 7. Overall Weight Loss / Gross Weight Loss (user field)
    $overall_weight_loss = $this->computeOverallWeightLoss($account, $weight_loss);
    if ($overall_weight_loss !== null) {
      $user_derived['field_gross_weight_loss'] = $overall_weight_loss;
    }

    // 8. Current Program Day (user field)
    $current_program_day = $this->computeCurrentProgramDay($account, $program_day);
    if ($current_program_day !== null) {
      $user_derived['field_user_current_program_day_c'] = $current_program_day;
    }

    return [
      'message' => $message_derived,
      'user' => $user_derived,
    ];
  }

  /**
   * Apply computed derived fields to the message entity.
   */
  public function applyToMessage(Message $message, array $derived): void {
    foreach ($derived as $field_name => $value) {
      $message->set($field_name, $value);
    }
  }

  /**
   * Apply computed derived fields to the user entity.
   */
  public function applyToUser(User $account, array $derived): void {
    foreach ($derived as $field_name => $value) {
      $account->set($field_name, $value);
    }
    $account->save();
  }

  /**
   * Run full evaluation pipeline (goals, phases, clinician sync).
   * Called after insert/update.
   *
   * @see docs/headless/NON_ARCHIVE_BACKLOG.md for full backlog of non-migrated items
   */
  public function runEvaluations(User $account, Message $message, array $submitted_values = []): void {
    // Check for late submission (equivalent to ResetStartingValues::setLate)
    $this->checkLateSubmission($account, $message);

    // Flag patient for clinician review (equivalent to LogYourProgross::MarkasNew)
    $this->patient_message_service->flagForReview($account->id());

    // Create patient_message if field_question was submitted
    $question = $submitted_values['field_question'] ?? $message->get('field_question')->getValue()[0]['value'] ?? NULL;
    if (!empty($question)) {
      $date = $submitted_values['field_date'] ?? $message->get('field_date')->getValue()[0]['value'] ?? NULL;
      $this->patient_message_service->createQuestionMessage($account->id(), (string) $question, $date);
    }

    // The calculator already computes and saves:
    // - field_goal_achieved (via computeGoalAchieved)
    // - field_net_weight_loss, field_net_inches_lost, field_gross_weight_loss
    // - field_user_current_program_day_c
    // So CalculateGoal and program day evaluation are already handled.

    // NOT MIGRATED (see NON_ARCHIVE_BACKLOG.md):
    // - userRoles() - role management based on clinic settings
    // - userProfile() - ECK patient profile create/repair/sync
    // - UserClinician() - chiropractor/clinic assignment
    // - ResetStartingValues::resetStartingValue() - recalculates starting values from ALL submissions
    //   BLOCKS: field_net_inches_lost, field_program_start_inches, field_overall_start_weight,
    //           field_program_start_weight (full historical recalc)
    // RESOLUTION: Queue Worker + nightly cron (see backlog)
  }

  /**
   * Check if the submission is late and set field_late on the user.
   * Equivalent to ResetStartingValues::setLate().
   */
  protected function checkLateSubmission(User $account, Message $message): void {
    // Get the latest tracking submission for this user (excluding the one just saved)
    $ids = \Drupal::entityQuery('contact_message')
      ->accessCheck(FALSE)
      ->condition('contact_form', 'tracking_weight')
      ->condition('uid', $account->id())
      ->condition('id', $message->id(), '<>')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    $difference = 1; // Default: first submission
    if ($ids) {
      $last_msg = $this->entity_type_manager->getStorage('contact_message')->load(reset($ids));
      if ($last_msg) {
        $last_date = $last_msg->get('field_date')->getValue()[0]['value'] ?? null;
        if ($last_date) {
          $start = new \DateTime('now');
          $end = new \DateTime(substr($last_date, 0, 10));
          $days_difference = (int) $start->diff($end)->format('%r%a');
          $difference = ($days_difference < 0) ? 3 : 2;
        }
      }
    }

    if ($difference) {
      $account->set('field_late', $difference);
      $account->save();
    }
  }

  // ===== Individual computation methods =====

  /**
   * Compute program day from start date and log date.
   */
  protected function computeProgramDay(User $account, ?string $log_date): ?int {
    $start_date_value = $account->get('field_program_start_date')->getValue();
    if (empty($start_date_value) || empty($log_date)) {
      return null;
    }
    $start_date = $start_date_value[0]['value'] ?? null;
    if (!$start_date) {
      return null;
    }
    try {
      $start = new \DateTime(substr($start_date, 0, 10));
      $log = new \DateTime(substr($log_date, 0, 10));
      $diff = $start->diff($log);
      $days = $diff->days + 1;
      return $diff->invert ? 0 : $days;
    } catch (\Throwable) {
      return null;
    }
  }

  /**
   * Compute weight loss from program start weight.
   */
  protected function computeWeightLoss(User $account, ?string $current_weight): ?float {
    $start_weight_value = $account->get('field_program_start_weight')->getValue();
    if (empty($start_weight_value) || $current_weight === null || $current_weight === '') {
      return null;
    }
    $start_weight = $start_weight_value[0]['value'] ?? null;
    if ($start_weight === null) {
      return null;
    }
    $loss = bcsub((string) $start_weight, (string) $current_weight, 2);
    return max(0.0, (float) $loss);
  }

  /**
   * Compute total inches from measurement fields.
   */
  protected function computeTotalInches(Message $message, array $submitted_values): ?float {
    $measurement_fields = [
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
    ];

    $total = 0.0;
    $has_any = false;

    foreach ($measurement_fields as $field) {
      $value = $submitted_values[$field] ?? null;
      if ($value === null || $value === '') {
        // Try reading from existing message
        $field_obj = $message->get($field);
        if (!$field_obj->isEmpty()) {
          $value = $field_obj->getValue()[0]['value'] ?? null;
        }
      }
      if ($value !== null && $value !== '') {
        $total += (float) $value;
        $has_any = true;
      }
    }

    return $has_any ? round($total, 2) : null;
  }

  /**
   * Net weight loss for user profile (same as weight loss computed).
   */
  protected function computeNetWeightLoss(User $account, ?float $weight_loss): ?float {
    return $weight_loss;
  }

  /**
   * Net inches lost = program start inches - current total inches.
   */
  protected function computeNetInchesLost(User $account, ?float $total_inches): ?float {
    $start_inches_value = $account->get('field_program_start_inches')->getValue();
    if (empty($start_inches_value) || $total_inches === null) {
      return null;
    }
    $start_inches = $start_inches_value[0]['value'] ?? null;
    if ($start_inches === null) {
      return null;
    }
    return round(bcsub((string) $start_inches, (string) $total_inches, 2), 2);
  }

  /**
   * Goal achieved percentage.
   */
  protected function computeGoalAchieved(User $account, ?float $weight_loss): ?float {
    $goal_weight_value = $account->get('field_goal_weight')->getValue();
    $start_weight_value = $account->get('field_program_start_weight')->getValue();
    if (empty($goal_weight_value) || empty($start_weight_value) || $weight_loss === null) {
      return null;
    }
    $goal_weight = $goal_weight_value[0]['value'] ?? null;
    $start_weight = $start_weight_value[0]['value'] ?? null;
    if ($goal_weight === null || $start_weight === null || $start_weight <= $goal_weight) {
      return null;
    }
    $total_to_lose = $start_weight - $goal_weight;
    if ($total_to_lose <= 0) {
      return 100.0;
    }
    $percentage = ($weight_loss / $total_to_lose) * 100;
    return min(100.0, max(0.0, round($percentage, 2)));
  }

  /**
   * Overall/gross weight loss.
   */
  protected function computeOverallWeightLoss(User $account, ?float $weight_loss): ?float {
    return $weight_loss;
  }

  /**
   * Current program day for user profile.
   */
  protected function computeCurrentProgramDay(User $account, ?int $program_day): ?int {
    return $program_day ?? 0;
  }

}