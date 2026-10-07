<?php

namespace Drupal\custom_module\Plugin\QueueWorker;

use Drupal\user\UserInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\custom_module\Service\CMServices;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * QueueWorker to update program day fields for enrolled patients.
 *
 * SEQUENCING:
 * This queue MUST fully complete before send_standard_daily_messages runs.
 * The gate (ready_to_send_daily_messages) is set to TRUE once the processed
 * counter reaches total_users. The counter is incremented in a finally block
 * so it always fires even if an exception is thrown.
 *
 * STATE KEYS (isolated — never shared with send_standard_daily_messages):
 *   custom_module.program_day_total_users
 *   custom_module.program_day_users_processed
 *
 * @QueueWorker(
 *   id = "set_user_current_program_day",
 *   title = @Translation("Set User Current Program Day"),
 *   cron = {"time" = 60}
 * )
 */
class SetUserCurrentProgramDay extends QueueWorkerBase implements ContainerFactoryPluginInterface
{

  // Phase constants
  private const PHASE_0 = 'phase-0';
  private const PHASE_1 = 'phase-1';
  private const PHASE_2 = 'phase-2';
  private const PHASE_3 = 'phase-3';
  private const PHASE_4 = 'phase-4';

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The logger channel for this module.
   */
  protected $logger;

  /**
   * CMServices Object.
   */
  protected $cMServices;

  /**
   * Constructs a new SetUserCurrentProgramDay object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    CMServices $cMServices
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('custom_module');
    $this->cMServices = $cMServices;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('custom_module.services')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data)
  {
    // The finally block guarantees markProcessed() always fires — even if
    // doProcessItem() throws or returns early — so the counter reliably
    // reaches total_users and the daily messages gate always opens.
    try {
      $this->doProcessItem($data);
    } catch (\Exception $e) {
      $this->logger->error(
        'Unhandled exception in set_user_current_program_day for UID @uid: @message',
        [
          '@uid'     => $data['uid'] ?? 'unknown',
          '@message' => $e->getMessage(),
        ]
      );
    } finally {
      $this->markProcessed();
    }
  }

  /**
   * Core processing logic — separated for clean exception handling.
   */
  private function doProcessItem($data): void
  {
    if (empty($data['uid'])) {
      $this->logger->warning('set_user_current_program_day: queue item missing UID, skipping.');
      return;
    }

    $uid = $data['uid'];

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($uid);

    if (!$user instanceof UserInterface) {
      $this->logger->warning('set_user_current_program_day: UID @uid is not a valid user.', ['@uid' => $uid]);
      return;
    }

    if ($user->get('field_program_start_date')->isEmpty()) {
      $this->logger->warning('set_user_current_program_day: UID @uid missing field_program_start_date.', ['@uid' => $uid]);
      return;
    }

    // Compute program day from start date.
    $start_date = $user->get('field_program_start_date')->value;
    $day = $this->cMServices->computeProgramStartDate($start_date);

    // Update all program day fields.
    $this->updateProgramDayFields($user, $day);

    // Update weight loss phase.
    $this->updateWeightLossPhase($user, $day);

    // Update taxonomy term for program day.
    if ($day > 0) {
      $this->setProgramDayTerm($user, $day);
    } else {
      $user->set('field_program_day_term', []);
    }

    $user->changed->preserve = TRUE;
    $user->save();
  }

  /**
   * Increments the processed counter and opens the daily messages gate
   * once all queued users have been handled.
   *
   * Called in a finally block so it always fires — success, skip, or exception.
   */
  private function markProcessed(): void
  {
    $state = \Drupal::state();
    $processed = $state->get('custom_module.program_day_users_processed', 0) + 1;
    $total     = $state->get('custom_module.program_day_total_users', 0);

    $state->set('custom_module.program_day_users_processed', $processed);

    if ($processed >= $total && $total > 0) {
      $this->logger->notice(
        'set_user_current_program_day complete: @processed / @total users processed. Daily messages gate is now OPEN.',
        ['@processed' => $processed, '@total' => $total]
      );
      $state->set('custom_module.ready_to_send_daily_messages', TRUE);
    }
  }

  /**
   * Updates user's day-related fields.
   */
  private function updateProgramDayFields(UserInterface $user, int $day): void
  {
    $user->set('field_user_current_program_day_c', $day);
    $user->set('field_current_program_day', $day);
    $user->set('field_program_day', $day);
  }

  /**
   * Updates the user's weight loss phase based on day.
   */
  private function updateWeightLossPhase(UserInterface $user, int $day): void
  {
    $current_phase = $user->get('field_weight_loss_phase')->value ?? '';

    if ($day === 0) {
      $user->set('field_weight_loss_phase', self::PHASE_0);
    } elseif (in_array($day, [1, 2], true)) {
      $user->set('field_weight_loss_phase', self::PHASE_1);
    } elseif ($day > 2 && !in_array($current_phase, [self::PHASE_3, self::PHASE_4], true)) {
      $user->set('field_weight_loss_phase', self::PHASE_2);
    }
  }

  /**
   * Sets the taxonomy term reference for the given day.
   */
  private function setProgramDayTerm(UserInterface $user, int $day): void
  {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $term_storage->loadByProperties([
      'name' => (string) $day,
      'vid'  => 'program_days',
    ]);

    $term = reset($terms);
    if ($term) {
      $user->set('field_program_day_term', ['target_id' => $term->id()]);
    } else {
      $user->set('field_program_day_term', []);
    }
  }

}