<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\headless_access\PortalRoles;

final class Registration {
  private bool $registering = FALSE;
  public function __construct(private readonly EntityTypeManagerInterface $entities, private readonly SubscriptionRepository $repository, private readonly Connection $database, private readonly LockBackendInterface $lock, private readonly TimeInterface $time, private readonly ConfigFactoryInterface $config) {}
  public function isRegistering(): bool { return $this->registering; }
  /** Reuse the clinic-creation boundary for sponsored doctors in an existing clinic. */
  public function withoutLegacyClinicSetup(callable $operation): mixed {
    $previous = $this->registering;
    $this->registering = TRUE;
    try { return $operation(); } finally { $this->registering = $previous; }
  }
  public function register(array $body): array {
    if (!$this->config->get('headless_subscriptions.settings')->get('registration_enabled')) throw new SubscriptionException('registration_disabled', 'Registration is currently unavailable.', 503);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = $body['password'] ?? '';
    $name = trim((string) ($body['fullName'] ?? ''));
    $clinicName = trim((string) ($body['clinicName'] ?? ''));
    $errors = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) $errors['email'] = 'Enter a valid email address.';
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 128) $errors['password'] = 'Use a password between 12 and 128 characters.';
    if ($name === '' || mb_strlen($name) > 128) $errors['fullName'] = 'Enter your full name (up to 128 characters).';
    if ($clinicName === '' || mb_strlen($clinicName) > 128) $errors['clinicName'] = 'Enter your clinic name (up to 128 characters).';
    if (($body['acceptTerms'] ?? NULL) !== TRUE) $errors['acceptTerms'] = 'Accept the subscription terms to continue.';
    if ($errors) throw new SubscriptionException('validation_failed', 'Check the registration fields.', 422, $errors);
    $key = 'headless_subscription.registration.' . hash('sha256', $email);
    if (!$this->lock->acquire($key, 30)) throw new SubscriptionException('busy', 'Registration is already in progress.', 409);
    $transaction = $this->database->startTransaction();
    try {
      $users = $this->entities->getStorage('user');
      $query = $users->getQuery()->accessCheck(FALSE);
      $group = $query->orConditionGroup()->condition('mail', $email)->condition('name', $email);
      if ($query->condition($group)->range(0, 1)->execute()) throw new SubscriptionException('account_exists', 'An account with this email address already exists. Please use a different email address, or log in.', 409, ['email' => 'An account with this email address already exists. Use a different email address, or log in.']);
      // These are NEW records. No existing doctor or clinic is adopted here.
      $clinic = $this->entities->getStorage('clinic')->create(['type' => 'clinic', 'title' => $clinicName, 'uid' => 1, 'field_clinic_mail' => $email, 'field_ecommerce_enabled' => FALSE]);
      $clinic->save();
      $user = $users->create(['name' => $email, 'mail' => $email, 'pass' => $password, 'status' => 1, 'roles' => [PortalRoles::CHIROPRACTOR_INACTIVE], 'field_full_name' => $name, 'field_clinic' => $clinic->id()]);
      $this->registering = TRUE;
      try { $user->save(); } finally { $this->registering = FALSE; }
      $clinic->setOwnerId((int) $user->id()); $clinic->save();
      $location = $this->entities->getStorage('clinic')->create(['type' => 'clinic_location', 'title' => $clinicName . ' (Location)', 'uid' => $user->id(), 'field_clinic' => $clinic->id()]);
      $location->save();

      // Generate a commerce store for this new clinic owner.
      $store = $this->entities->getStorage('commerce_store')->create([
        'type' => 'online',
        'name' => $clinicName . ' Store',
        'uid' => $user->id(),
        'default_currency' => 'USD',
        'mail' => $email,
        'address' => [
          'country_code' => 'US',
        ],
      ]);
      $store->save();

      $this->repository->addAccount((int) $user->id(), (int) $clinic->id(), $this->time->getCurrentTime());
      return ['id' => (int) $user->id(), 'clinicId' => (int) $clinic->id(), 'requiresLogin' => TRUE, 'subscriptionStatus' => 'none'];
    }
    catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
    finally { $this->lock->release($key); }
  }
}
