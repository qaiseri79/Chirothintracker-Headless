<?php

namespace Drupal\custom_module\Service;

use Drupal;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\user\Entity\User;

/**
 * Service that handles User Account data.
 */
class CMServices
{

  /**
   * The entity type manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Session\AccountProxy definition.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Database Connection Service.
   *
   * @var Drupal\Core\Database\Connection
   */
  protected $dbConn;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxy $current_user,
    Connection $db_connection,
    MessengerInterface $messenger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->dbConn = $db_connection;
    $this->messenger = $messenger;
  }

  /**
   * Calculates the current program day based on the program's start date.
   *
   * This function compares the given program start date with a reference date
   * (defaults to today) to determine which day of the program it currently is.
   *
   * Logic:
   * - Returns 0 if the program has not started yet (start date is in the future).
   * - Returns 1 if the program starts today.
   * - Returns the number of days since the program started, starting from 1.
   *
   * @param string $start_date
   *   The program's start date (e.g., '2025-09-15').
   * @param string|null $date
   *   Optional reference date to compare against (e.g., '2025-09-19').
   *   Defaults to today if not provided.
   *
   * @return int
   *   The current program day:
   *     - 0: if program hasn't started yet,
   *     - 1: if program starts today,
   *     - N: if program is in progress (Day 2, Day 3, etc.).
   */
  public function computeProgramStartDate($start_date, $date = null)
  {
    // Ensure the start date is valid
    if (empty($start_date)) {
      throw new \InvalidArgumentException('Start date cannot be null or empty.');
    }

    // Create date objects directly (no strtotime needed)
    $field_program_start_date = new DrupalDateTime($start_date);
    $field_date = !empty($date) ? new DrupalDateTime($date) : new DrupalDateTime('today');

    // Compare dates
    $diff = $field_date->diff($field_program_start_date);
    $program_day = $diff->days + 1;

    // If same date → return 1
    if ($field_program_start_date->format('Y-m-d') === $field_date->format('Y-m-d')) {
      return 1;
    }

    // If start date is in the future → return 0
    if ($diff->invert === 0) {
      return 0;
    }

    // Otherwise → return program day
    return $program_day;
  }

  /**
   * Updates the clinic entity based on the products purchased in a completed order.
   *
   * This method checks if an order is completed and belongs to store ID 1(Subscription Store (Default)).
   * If the customer is linked to a clinic via the `field_clinic` reference field,
   * it updates that clinic's fields (`field_enrollment_package` and `field_laser_clinic`)
   * based on the purchased product.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The completed commerce order entity.
   */
  public function updateEnrollment(OrderInterface $order)
  {
    // Only process completed orders from store ID 1.
    if ($order->getState()->getId() !== 'completed' || $order->getStoreId() != 1) {
      return;
    }

    // Get the user who placed the order.
    $user = $order->getCustomer();

    // Ensure the user is associated with a clinic via field_clinic.
    if ($user->get('field_clinic')->isEmpty()) {
      return;
    }

    // Load the clinic entity using the referenced clinic ID.
    $clinic_id = $user->get('field_clinic')->target_id;
    $clinic = $this->entityTypeManager->getStorage('clinic')->load($clinic_id);

    if (!$clinic) {
      return;
    }

    // Loop through order items to determine which product was purchased.
    foreach ($order->getItems() as $item) {
      $purchased_entity = $item->getPurchasedEntity();

      // Ensure the purchased entity is a product variation.
      if (!$purchased_entity instanceof ProductVariation) {
        continue;
      }

      // Get the product ID from the variation.
      $product_id = $purchased_entity->getProduct()->id();

      // Map product IDs to enrollment and laser clinic values.
      $map = [
        1 => [3, 0], // Product ID 1 → enrollment package 3, not a laser clinic
        2 => [4, 0], // Product ID 2 → enrollment package 4, not a laser clinic
        3 => [1, 0], // Product ID 3 → enrollment package 1, not a laser clinic
      ];

      // Default: enrollment package 1, laser clinic true.
      [$enrollment, $laser] = $map[$product_id] ?? [1, 1];

      // Update clinic fields based on product.
      $clinic->set('field_enrollment_package', $enrollment);
      $clinic->set('field_laser_clinic', $laser);

      // Save the clinic entity.
      $clinic->save();

      // Exit after first valid product match.
      break;
    }
  }

  /**
   * Gets the subscriber user entity from a chiropractor user ID.
   *
   * @param int $chiropractor_id
   *   The user ID of the chiropractor.
   *
   * @return \Drupal\user\Entity\User|null
   *   The subscriber user entity, or NULL if chiropractor not found.
   */
  public function getSubscriberByChiropractor(int $chiropractor_id): ?User
  {

    $user_storage = $this->entityTypeManager->getStorage('user');
    $chiropractor = $user_storage->load($chiropractor_id);

    if (!$chiropractor) {
      return NULL;
    }

    // Case 1: Has field_chiropractor_subscribers populated
    // → load the referenced subscriber.
    if (
      $chiropractor->hasField('field_chiropractor_subscribers') &&
      !$chiropractor->get('field_chiropractor_subscribers')->isEmpty()
    ) {
      $subscriber_id = $chiropractor->get('field_chiropractor_subscribers')->target_id;
      return $user_storage->load($subscriber_id) ?: NULL;
    }

    // Case 2: field_chiropractor_subscribers is empty or missing
    // → chiropractor IS the subscriber.
    return $chiropractor;
  }

  /**
   * Gets the subscriber user entity from a patient user ID.
   *
   * Looks up the patient's assigned chiropractor via the
   * `field_chiropractor` reference field, then resolves that
   * chiropractor's subscriber.
   *
   * @param int $patient_id
   *   The user ID of the patient.
   *
   * @return \Drupal\user\Entity\User|null
   *   The subscriber user entity, or NULL if the patient, their
   *   chiropractor, or the subscriber cannot be found.
   */
  public function getSubscriberByPatient(int $patient_id): ?User
  {
    $user_storage = $this->entityTypeManager->getStorage('user');
    $patient = $user_storage->load($patient_id);

    if (
      !$patient ||
      !$patient->hasField('field_chiropractor') ||
      $patient->get('field_chiropractor')->isEmpty()
    ) {
      return NULL;
    }

    $chiropractor_id = $patient->get('field_chiropractor')->target_id;

    return $this->getSubscriberByChiropractor($chiropractor_id);
  }
}
