<?php

declare(strict_types=1);

namespace Drupal\headless_patients;

use Drupal\headless_patients\Exception\PatientsException;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Resolves the clinic a request is allowed to see.
 *
 * This exists as its own class so there is exactly one place in this module that
 * decides which clinic a request belongs to. Every read and every write in
 * PatientsService and PatientIntakeService is scoped by the value this returns,
 * and nothing in this module ever reads a clinic from the request. That is the
 * whole tenant boundary: there is no query parameter, body field or route
 * default that can move it, so a caller cannot ask for another clinic's roster
 * by asking.
 *
 * The rule mirrors IntakeLinkRevokeForm::ownClinicId(), which is where the
 * admin side of the intake feature resolves the same thing.
 */
class ClinicScope {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The clinic the account belongs to, or NULL when it has none.
   *
   * Loaded from storage rather than trusting the runtime account object: the
   * account that arrives on a request is an anonymous or stale proxy in some
   * code paths, and the field is a reference that only the stored entity has.
   */
  public function clinicId(AccountInterface $account): ?int {
    if ($account->isAuthenticated() === FALSE) {
      return NULL;
    }

    // New subscription accounts have an authoritative one-to-one clinic binding.
    if (\Drupal::hasContainer() && \Drupal::hasService('headless_subscriptions.repository')) {
      $subscriptionAccount = \Drupal::service('headless_subscriptions.repository')->account((int) $account->id());
      if ($subscriptionAccount) return (int) $subscriptionAccount['clinic_id'];
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $user = $storage->load((int) $account->id());

    if (!$user instanceof FieldableEntityInterface || !$user->hasField('field_clinic')) {
      return NULL;
    }

    $target = $user->get('field_clinic')->target_id;
    if ($target === NULL || $target === '') {
      return NULL;
    }

    $id = (int) $target;

    return $id > 0 ? $id : NULL;
  }

  /**
   * The clinic the account belongs to, or a 403 failure.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the account has no clinic.
   */
  public function requireClinicId(AccountInterface $account): int {
    $id = $this->clinicId($account);
    if ($id === NULL) {
      throw PatientsException::noClinic();
    }
    return $id;
  }

  /**
   * Loads a user and proves it belongs to the clinic.
   *
   * @param int $clinicId
   *   Clinic the caller is scoped to.
   * @param int $userId
   *   Candidate user ID.
   * @param string $what
   *   Noun for the not-found message.
   *
   * @return \Drupal\user\UserInterface
   *   The loaded, in-scope user.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the user does not exist or is in another clinic.
   */
  public function requireUser(int $clinicId, int $userId, string $what = 'patient'): UserInterface {
    $user = $this->entityTypeManager->getStorage('user')->load($userId);

    if (!$user instanceof UserInterface) {
      throw PatientsException::notFound($what);
    }

    if (!$user->hasField('field_clinic')) {
      throw PatientsException::notFound($what);
    }

    if ((int) $user->get('field_clinic')->target_id !== $clinicId) {
      throw PatientsException::notFound($what);
    }

    return $user;
  }
}
