<?php

namespace Drupal\custom_module\Plugin\QueueWorker;

use Drupal\views\Views;
use Drupal\Core\Render\Markup;
use Drupal\user\UserInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * QueueWorker to send standard daily messages to enrolled patients.
 *
 * SEQUENCING:
 * This queue only runs after set_user_current_program_day has fully completed,
 * controlled by the 'custom_module.ready_to_send_daily_messages' state gate.
 *
 * STATE KEYS (isolated — never shared with set_user_current_program_day):
 *   custom_module.daily_msg_total_users
 *   custom_module.daily_msg_users_processed
 *   custom_module.daily_msg_users_sent
 *   custom_module.daily_msg_users_skipped
 *   custom_module.daily_msg_skipped_no_custom
 *   custom_module.daily_msg_skipped_no_term
 *
 * COUNTER GUARANTEE:
 * processItem() wraps all logic in try/catch/finally so the processed counter
 * always increments exactly once per item — even on unhandled exceptions.
 *
 * VIEW EXECUTION:
 * $view->access() is NOT called before executing the custom daily message view.
 * Cron runs as an anonymous/system user so access() always returns FALSE,
 * which was causing all 310 custom message users to be silently skipped.
 * $view->preExecute() IS called — it is required for contextual filters and
 * exposed filter defaults to apply correctly when running views programmatically.
 *
 * LOGGING:
 * No per-user log rows. Results collected into state and flushed as exactly
 * 4 log entries once the full batch completes:
 *   [SUMMARY]                — overall totals with full skip breakdown
 *   [CUSTOM DAILY MESSAGE]   — per-email details for custom path
 *   [STANDARD DAILY MESSAGE] — per-email details for standard path
 *   [GENERAL SKIPPED]        — pre-category skips (already notified, etc.)
 *
 * @QueueWorker(
 *   id = "send_standard_daily_messages",
 *   title = @Translation("Send Standard Daily Messages"),
 *   cron = {"time" = 60}
 * )
 */
class SendStandardDailyMessages extends QueueWorkerBase implements ContainerFactoryPluginInterface
{

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel for this module.
   */
  protected $logger;

  /**
   * The mail manager service.
   */
  protected MailManagerInterface $mailManager;

  /**
   * Constructs a new SendStandardDailyMessages object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MailManagerInterface $mailManager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('daily_messages');
    $this->mailManager = $mailManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    // $counted tracks whether doProcessItem() already incremented the processed
    // counter. The finally block only fires the counter if it hasn't been
    // counted yet, ensuring exactly one increment per item no matter what.
    $counted = FALSE;

    try {
      $this->doProcessItem($data, $counted);
    } catch (\Exception $e) {
      $this->logger->error(
        'Unhandled exception in send_standard_daily_messages for UID @uid: @message',
        [
          '@uid'     => $data['uid'] ?? 'unknown',
          '@message' => $e->getMessage(),
        ]
      );
    } finally {
      // Only fires if an unexpected exception short-circuited the normal flow.
      if (!$counted) {
        $this->incrementStateCounter('custom_module.daily_msg_users_skipped');
        $this->incrementStateCounter('custom_module.daily_msg_users_processed');
        $this->maybeLogCompletion();
      }
    }
  }

  /**
   * Core processing logic for a single queue item.
   *
   * @param array $data
   *   Queue item data, expects key 'uid'.
   * @param bool $counted
   *   Passed by reference. Set to TRUE when this item has been counted so the
   *   finally block in processItem() knows not to double-count.
   */
  private function doProcessItem(array $data, bool &$counted): void
  {
    if (empty($data['uid'])) {
      $this->skipAndReturn('No UID in queue item.', null, $counted);
      return;
    }

    try {
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->entityTypeManager->getStorage('user')->load($data['uid']);
    } catch (\Exception $e) {
      $this->skipAndReturn("Failed to load user with UID {$data['uid']}: {$e->getMessage()}", null, $counted);
      return;
    }

    if (!$user instanceof UserInterface) {
      $this->skipAndReturn("User with UID {$data['uid']} is not a valid user.", null, $counted);
      return;
    }

    if ($user->get('field_program_start_date')->isEmpty()) {
      $this->skipAndReturn("User {$user->id()} missing field_program_start_date.", null, $counted);
      return;
    }

    // field_program_day is the reliable source of truth — written directly by
    // SetUserCurrentProgramDay and guaranteed populated before the gate opens.
    if ($user->get('field_program_day')->isEmpty()) {
      $this->skipAndReturn("User {$user->id()} has no value for field_program_day.", null, $counted);
      return;
    }

    $program_day  = (string) $user->get('field_program_day')->value;
    $notified_day = (string) ($user->get('field_std_dm_ntfd_pd')->value ?? '');

    if ($user->get('field_clinic')->isEmpty()) {
      $this->skipAndReturn("User {$user->id()} has no assigned clinic.", null, $counted);
      return;
    }

    // Strict string comparison — both sides are numeric strings e.g. "34".
    if ($notified_day === $program_day) {
      $this->skipAndReturn("User {$user->id()} already notified for program day {$program_day}.", null, $counted);
      return;
    }

    $clinic       = $user->get('field_clinic')->entity;
    $clinic_email = $clinic->get('field_clinic_mail')->value ?? NULL;
    $patient_email = $user->getEmail();

    $email_sent = FALSE;

    // -------------------------------------------------------------------------
    // CUSTOM DAILY MESSAGE
    // -------------------------------------------------------------------------
    if ((bool)(int) $clinic->get('field_custom_daily_messages')->value) {

      $custom_daily_message = $this->getCustomDailyMessages($program_day, $clinic->id(), $user->id());

      // NULL means the view itself failed — already logged, skip this user.
      if ($custom_daily_message === NULL) {
        $this->skipAndReturn(null, null, $counted);
        return;
      }

      // Empty array means view ran fine but no message exists for this day/clinic.
      if (empty($custom_daily_message)) {
        $this->incrementStateCounter('custom_module.daily_msg_skipped_no_custom');
        $this->skipAndReturn(
          "UID {$user->id()} | {$patient_email} | Day: {$program_day} | Clinic: {$clinic->label()} (ID: {$clinic->id()}) — view returned 0 results.",
          'custom',
          $counted
        );
        return;
      }

      foreach ($custom_daily_message as $value) {
        $message = $value->_entity->get("body")->value;
        $subject = $value->_entity->getTitle();

        $params = [
          'subject' => $subject,
          'message' => Markup::create($message),
        ];
        $language = \Drupal::languageManager()->getDefaultLanguage()->getId();

        try {
          $result = $this->mailManager->mail(
            'custom_module',
            'message_email',
            $patient_email,
            $language,
            $params,
            $clinic_email,
            TRUE
          );
        } catch (\Exception $e) {
          $this->logger->error("Exception sending custom daily email to {$patient_email}: {$e->getMessage()}");
          continue;
        }

        if (!empty($result['result'])) {
          $user->set('field_std_dm_ntfd_pd', $program_day);
          $email_sent = TRUE;
          $this->trackEmail('custom', 'sent', [
            'email'   => $patient_email,
            'subject' => $subject,
            'day'     => $program_day,
            'clinic'  => $clinic->label(),
          ]);
        } else {
          $this->trackEmail('custom', 'failed', [
            'email'   => $patient_email,
            'subject' => $subject,
            'day'     => $program_day,
            'clinic'  => $clinic->label(),
          ]);
        }
      }
    }

    // -------------------------------------------------------------------------
    // STANDARD DAILY MESSAGE
    // -------------------------------------------------------------------------
    else {
      if (!$user->get('field_program_day_term')->isEmpty()) {
        $program_day_term  = $user->get('field_program_day_term')->entity;
        $program_day_title = $program_day_term->label();
        $program_message   = $program_day_term->get('field_message_plain')->value ?? '';
        $subject           = "Program Day {$program_day_title}: A Message From Your Doctor";

        $params = [
          'subject' => $subject,
          'message' => $program_message,
        ];

        $language = \Drupal::languageManager()->getDefaultLanguage()->getId();

        try {
          $result = $this->mailManager->mail(
            'custom_module',
            'message_email',
            $patient_email,
            $language,
            $params,
            $clinic_email,
            TRUE
          );
        } catch (\Exception $e) {
          $this->logger->error("Exception sending standard daily email to {$patient_email}: {$e->getMessage()}");
          $this->skipAndReturn(null, null, $counted);
          return;
        }

        if (!empty($result['result'])) {
          // Save the numeric $program_day (NOT the term label) so the
          // "already notified" comparison always matches apples-to-apples.
          $user->set('field_std_dm_ntfd_pd', $program_day);
          $email_sent = TRUE;
          $this->trackEmail('standard', 'sent', [
            'email'   => $patient_email,
            'subject' => $subject,
            'day'     => $program_day,
            'clinic'  => $clinic->label(),
          ]);
        } else {
          $this->trackEmail('standard', 'failed', [
            'email'   => $patient_email,
            'subject' => $subject,
            'day'     => $program_day,
            'clinic'  => $clinic->label(),
          ]);
        }
      } else {
        $this->incrementStateCounter('custom_module.daily_msg_skipped_no_term');
        $this->skipAndReturn(
          "UID {$user->id()} | {$patient_email} | Day: {$program_day} | Clinic: {$clinic->label()} — no program day term found.",
          'standard',
          $counted
        );
        return;
      }
    }

    // Save user only if email was sent.
    if ($email_sent) {
      try {
        $user->changed->preserve = TRUE;
        $user->save();
        $this->incrementStateCounter('custom_module.daily_msg_users_sent');
      } catch (\Exception $e) {
        $this->logger->error("Failed to save user {$user->id()} after sending email: {$e->getMessage()}");
        $this->incrementStateCounter('custom_module.daily_msg_users_skipped');
      }
    } else {
      $this->incrementStateCounter('custom_module.daily_msg_users_skipped');
    }

    $this->incrementStateCounter('custom_module.daily_msg_users_processed');
    $this->maybeLogCompletion();
    $counted = TRUE;
  }

  /**
   * Executes the custom daily message view and returns results.
   *
   * KEY NOTES:
   * - $view->access() is NOT called. Cron runs as anonymous/system user so
   *   access() always returns FALSE, causing all custom message users to be
   *   silently skipped. The view's own config handles content-level access.
   * - $view->preExecute() IS called before execute(). This is required when
   *   running views programmatically — it applies contextual filters, cache
   *   contexts, and exposed filter defaults that execute() alone does not run.
   *   Skipping preExecute() was causing the view to return empty results even
   *   when matching content existed.
   *
   * @param string $program_day
   *   The current program day number (e.g. "34").
   * @param int|string $clinic_id
   *   The clinic entity ID.
   * @param int|string $user_id
   *   The user ID — used only for log context.
   *
   * @return array|null
   *   Array of view result rows (may be empty), or NULL if the view failed.
   */
  private function getCustomDailyMessages(string $program_day, $clinic_id, $user_id): ?array
  {
    // Views::getView() returns FALSE if the view machine name doesn't exist
    // or the view is disabled. Without this check, calling setDisplay() on
    // FALSE throws a fatal that gets swallowed by the outer try/catch.
    $view = Views::getView('entity_reference_custom_daily_message');

    if (!$view) {
      $this->logger->error(
        'Custom daily message view "entity_reference_custom_daily_message" not found or disabled. UID @uid | Day: @day | Clinic ID: @clinic',
        ['@uid' => $user_id, '@day' => $program_day, '@clinic' => $clinic_id]
      );
      return NULL;
    }

    try {
      $view->setDisplay('views_rules_1');
      $view->setArguments([$program_day, $clinic_id]);

      // Do NOT call preExecute() manually. execute() already calls it internally
      // via build(). Calling preExecute() first causes double-initialization
      // which resets arguments and returns 0 results even when content exists.
      // Confirmed: view preview 4/525 returns node 3099 but preExecute() broke it.
      $view->execute();

      $results = $view->result;

      // Do NOT log a per-user warning here when results are empty.
      // The caller collects the skip reason with full details (UID, email,
      // day, clinic) into custom_module.custom_skipped_reasons via
      // skipAndReturn(). All 274 reasons are flushed together inside the
      // single [CUSTOM DAILY MESSAGE] summary log at end of batch —
      // no individual warning rows flooding the log table.

      return $results;
    } catch (\Exception $e) {
      $this->logger->error(
        'Exception executing custom daily message view. UID @uid | Day: @day | Clinic ID: @clinic | Error: @message',
        ['@uid' => $user_id, '@day' => $program_day, '@clinic' => $clinic_id, '@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Tracks a sent or failed email under a specific category (custom/standard).
   *
   * @param string $category
   *   Either 'custom' or 'standard'.
   * @param string $status
   *   Either 'sent' or 'failed'.
   * @param array $details
   *   Keys: email, subject, day, clinic.
   */
  private function trackEmail(string $category, string $status, array $details): void
  {
    $key = "custom_module.{$category}_{$status}_emails";
    $state = \Drupal::state();
    $entries = $state->get($key, []);
    $entries[] = $details;
    $state->set($key, $entries);
  }

  /**
   * Increments a numeric state value used for tracking queue processing.
   *
   * @param string $key
   *   The state key to increment.
   */
  private function incrementStateCounter(string $key): void
  {
    $state = \Drupal::state();
    $count = $state->get($key, 0) + 1;
    $state->set($key, $count);
  }

  /**
   * Builds a compact single-line summary of email entries for a log message.
   *
   * @param array $entries
   *   Each entry has keys: email, subject, day, clinic.
   *
   * @return string
   */
  private function formatEmailEntries(array $entries): string
  {
    if (empty($entries)) {
      return 'none';
    }
    return implode(' | ', array_map(
      fn($e) => "{$e['email']} (Day: {$e['day']}, Clinic: {$e['clinic']}, Subject: {$e['subject']})",
      $entries
    ));
  }

  /**
   * Fires four log entries once all queued users have been processed.
   *
   *  1. [SUMMARY]               — overall totals, all counts add up.
   *  2. [CUSTOM DAILY MESSAGE]  — sent, failed, skipped details.
   *  3. [STANDARD DAILY MESSAGE]— sent, failed, skipped details.
   *  4. [GENERAL SKIPPED]       — pre-category skips (already notified,
   *                               missing fields, no clinic, invalid user).
   */
  private function maybeLogCompletion(): void
  {
    $state = \Drupal::state();
    $processed = $state->get('custom_module.daily_msg_users_processed', 0);
    $total     = $state->get('custom_module.daily_msg_total_users', 0);

    if ($processed >= $total && $total > 0) {
      $sent      = $state->get('custom_module.daily_msg_users_sent', 0);
      $skipped   = $state->get('custom_module.daily_msg_users_skipped', 0);
      $no_custom = $state->get('custom_module.daily_msg_skipped_no_custom', 0);
      $no_term   = $state->get('custom_module.daily_msg_skipped_no_term', 0);

      $custom_sent     = $state->get('custom_module.custom_sent_emails', []);
      $custom_failed   = $state->get('custom_module.custom_failed_emails', []);
      $custom_skipped  = $state->get('custom_module.custom_skipped_reasons', []);

      $standard_sent    = $state->get('custom_module.standard_sent_emails', []);
      $standard_failed  = $state->get('custom_module.standard_failed_emails', []);
      $standard_skipped = $state->get('custom_module.standard_skipped_reasons', []);

      $general_skipped = $state->get('custom_module.general_skipped_reasons', []);
      $general_count   = count($general_skipped);

      // LOG 1 — Overall summary. All counts add up:
      // Sent + no_custom + no_term + general_count = Total
      $this->logger->info(
        '[SUMMARY] Daily messages complete — Total: @total | Sent: @sent | Skipped: @skipped (no custom msg: @no_custom | no day term: @no_term | pre-category: @general)',
        [
          '@total'     => $total,
          '@sent'      => $sent,
          '@skipped'   => $skipped,
          '@no_custom' => $no_custom,
          '@no_term'   => $no_term,
          '@general'   => $general_count,
        ]
      );

      // LOG 2 — Custom Daily Message details.
      $this->logger->info(
        '[CUSTOM DAILY MESSAGE] Sent: @sent_count | Failed: @failed_count | Skipped (no custom message): @skip_count -- Sent to: @sent_list -- Failed: @failed_list -- Skipped: @skipped_list',
        [
          '@sent_count'   => count($custom_sent),
          '@failed_count' => count($custom_failed),
          '@skip_count'   => $no_custom,
          '@sent_list'    => $this->formatEmailEntries($custom_sent),
          '@failed_list'  => $this->formatEmailEntries($custom_failed),
          '@skipped_list' => !empty($custom_skipped) ? implode(' | ', $custom_skipped) : 'none',
        ]
      );

      // LOG 3 — Standard Daily Message details.
      $this->logger->info(
        '[STANDARD DAILY MESSAGE] Sent: @sent_count | Failed: @failed_count | Skipped (no program day term): @skip_count -- Sent to: @sent_list -- Failed: @failed_list -- Skipped: @skipped_list',
        [
          '@sent_count'   => count($standard_sent),
          '@failed_count' => count($standard_failed),
          '@skip_count'   => $no_term,
          '@sent_list'    => $this->formatEmailEntries($standard_sent),
          '@failed_list'  => $this->formatEmailEntries($standard_failed),
          '@skipped_list' => !empty($standard_skipped) ? implode(' | ', $standard_skipped) : 'none',
        ]
      );

      // LOG 4 — General (pre-category) skips.
      $this->logger->info(
        '[GENERAL SKIPPED] Count: @count -- Reasons: @reasons',
        [
          '@count'   => $general_count,
          '@reasons' => !empty($general_skipped) ? implode(' | ', $general_skipped) : 'none',
        ]
      );

      // Clear all transient state after logging.
      foreach ([
        'custom_module.custom_sent_emails',
        'custom_module.custom_failed_emails',
        'custom_module.custom_skipped_reasons',
        'custom_module.standard_sent_emails',
        'custom_module.standard_failed_emails',
        'custom_module.standard_skipped_reasons',
        'custom_module.general_skipped_reasons',
      ] as $key) {
        $state->delete($key);
      }
    }
  }

  /**
   * Collects a skip reason, increments counters, checks completion.
   *
   * @param string|null $reason
   *   Human-readable reason the user was skipped.
   * @param string|null $category
   *   'custom' or 'standard'. Null for pre-category skips.
   * @param bool $counted
   *   Passed by reference. Set TRUE to prevent double-counting in finally.
   */
  private function skipAndReturn(?string $reason, ?string $category, bool &$counted): void
  {
    if (!empty($reason)) {
      $state = \Drupal::state();
      $key = $category
        ? "custom_module.{$category}_skipped_reasons"
        : 'custom_module.general_skipped_reasons';
      $reasons = $state->get($key, []);
      $reasons[] = $reason;
      $state->set($key, $reasons);
    }

    $this->incrementStateCounter('custom_module.daily_msg_users_skipped');
    $this->incrementStateCounter('custom_module.daily_msg_users_processed');
    $this->maybeLogCompletion();
    $counted = TRUE;
  }

}