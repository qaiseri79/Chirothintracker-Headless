<?php
declare(strict_types=1);
namespace Drupal\headless_clinic;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\headless_access\PortalAccessResolver;
use Drupal\headless_access\PortalRoles;
use Drupal\headless_subscriptions\{SubscriptionRepository, Sponsorship, Registration};
use Drupal\headless_mail\Mailer;
use Drupal\user\UserInterface;

/** A primary owns the team; caller-supplied clinic, payer and roles are never accepted. */
final class ClinicService {
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly PortalAccessResolver $access,
    private readonly SubscriptionRepository $repository,
    private readonly Sponsorship $sponsorship,
    private readonly Registration $registration,
    private readonly Mailer $mailer,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {}

  private function scope(AccountInterface $actor, bool $write = FALSE): array {
    $user = $this->entities->getStorage('user')->load((int) $actor->id());
    $access = $this->access->resolve($actor);
    if (!$actor->isAuthenticated() || !$this->sponsorship->isDoctor($user) || !$user->isActive()
      || !$access || !$access[$write ? 'write' : 'read'] || $access['audience'] !== 'chiropractor') {
      throw new ClinicException('Active doctor access is required for this operation.', 403);
    }
    $member = $this->repository->member((int) $user->id());
    $primaryId = $member ? (int) $member['primary_uid'] : ($this->sponsorship->primaryId($user) ?? (int) $user->id());
    $primary = $this->entities->getStorage('user')->load($primaryId);
    if (!$this->sponsorship->isDoctor($primary) || $this->sponsorship->primaryId($primary) !== NULL) {
      throw new ClinicException('Your primary doctor relationship needs to be reviewed by the clinic.', 403);
    }
    $account = $this->repository->account($primaryId);
    $clinicId = $account ? (int) $account['clinic_id'] : $this->sponsorship->reference($primary, 'field_clinic');
    if (!$clinicId || $this->sponsorship->reference($user, 'field_clinic') !== $clinicId
      || $this->sponsorship->reference($primary, 'field_clinic') !== $clinicId) {
      throw new ClinicException('Your clinic relationship needs to be reviewed.', 403);
    }
    $clinic = $this->entities->getStorage('clinic')->load($clinicId);
    if (!$clinic || $clinic->bundle() !== 'clinic') throw new ClinicException('Clinic not found.', 404);
    $owner = (int) $user->id() === $primaryId;
    if ($write && !$owner) throw new ClinicException('Only the primary doctor can manage clinic doctors and locations.', 403);
    return compact('user', 'primary', 'primaryId', 'clinicId', 'clinic', 'owner', 'access');
  }

  public function snapshot(AccountInterface $actor): array {
    $scope = $this->scope($actor);
    $doctors = array_map(fn($user) => $this->doctorDto($user), $this->teamDoctors($scope['primaryId'], $scope['clinicId']));
    $locations = $this->locations($scope['clinicId'], (string) $scope['clinic']->label());
    return [
      'clinic' => ['id' => $scope['clinicId'], 'name' => (string) $scope['clinic']->label()],
      'primary' => ['id' => $scope['primaryId'], 'name' => $this->name($scope['primary'])],
      'canManage' => $scope['owner'] && $scope['access']['write'],
      'funding' => $scope['owner'] ? 'self' : 'sponsored',
      'chiropractors' => $doctors, 'locations' => $locations,
    ];
  }

  private function teamDoctors(int $primaryId, int $clinicId): array {
    $users = $this->entities->getStorage('user');
    $query = $users->getQuery()->accessCheck(FALSE)->condition('roles', PortalRoles::CHIROPRACTOR_READ, 'IN')
      ->condition('field_clinic', $clinicId)->condition('uid', $primaryId, '<>');
    $query->condition($query->orConditionGroup()->condition('field_chiropractor_subscribers', $primaryId)->condition('field_chiropractor', $primaryId));
    $ids = $query->sort('field_full_name')->sort('uid')->execute();
    $doctors = [];
    foreach ($users->loadMultiple($ids) as $user) {
      if ($this->sponsorship->primaryId($user) !== $primaryId) continue;
      $doctors[] = $user;
    }
    return $doctors;
  }

  /** Admin visibility is permission-gated and does not expose patient records. */
  public function adminTeam(AccountInterface $admin, int $primaryId): array {
    if (!$admin->hasPermission('administer portal subscriptions')) throw new ClinicException('Subscription administration permission is required.', 403);
    $account = $this->repository->account($primaryId);
    if (!$account) throw new ClinicException('Subscription account not found.', 404);
    $doctors = $this->teamDoctors($primaryId, (int) $account['clinic_id']);
    $counts = [];
    if ($doctors) {
      $ids = array_map(static fn($doctor) => (int) $doctor->id(), $doctors);
      $q = $this->database->select('user__field_chiropractor', 'd');
      $q->innerJoin('user__field_clinic', 'c', 'c.entity_id = d.entity_id AND c.deleted = 0');
      $q->innerJoin('user__roles', 'r', 'r.entity_id = d.entity_id AND r.deleted = 0');
      $q->addField('d', 'field_chiropractor_target_id', 'doctor');
      $q->addExpression("COUNT(DISTINCT CASE WHEN r.roles_target_id = 'enrolled_patient' THEN d.entity_id END)", 'enrolled');
      $q->addExpression("COUNT(DISTINCT CASE WHEN r.roles_target_id = 'archived_patient' THEN d.entity_id END)", 'archived');
      $q->condition('d.deleted', 0)->condition('d.field_chiropractor_target_id', $ids, 'IN')
        ->condition('c.field_clinic_target_id', (int) $account['clinic_id'])
        ->condition('r.roles_target_id', ['enrolled_patient','archived_patient'], 'IN')
        ->groupBy('d.field_chiropractor_target_id');
      foreach ($q->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) $counts[(int) $row['doctor']] = $row;
    }
    return array_map(function ($user) use ($counts): array {
      $row = $this->doctorDto($user);
      $row['enrolledPatients'] = (int) ($counts[$row['id']]['enrolled'] ?? 0);
      $row['archivedPatients'] = (int) ($counts[$row['id']]['archived'] ?? 0);
      $row['managedSponsorship'] = $this->repository->member($row['id']) !== NULL;
      return $row;
    }, $doctors);
  }

  private function locations(int $clinicId, string $clinicName): array {
    $storage = $this->entities->getStorage('clinic');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'clinic_location')
      ->condition('field_clinic', $clinicId)->sort('title')->sort('id')->execute();
    return array_map(static fn($location) => [
      'id' => (int) $location->id(), 'title' => (string) $location->label(), 'clinic' => $clinicName,
      'authoredOn' => gmdate('m/d/Y', (int) $location->get('created')->value),
    ], array_values($storage->loadMultiple($ids)));
  }

  private function name(UserInterface $user): string {
    return $user->hasField('field_full_name') && !$user->get('field_full_name')->isEmpty()
      ? (string) $user->get('field_full_name')->value : (string) $user->getAccountName();
  }

  private function doctorDto(UserInterface $user): array {
    $access = $this->access->resolve($user);
    return [
      'id' => (int) $user->id(), 'name' => $this->name($user), 'username' => (string) $user->getAccountName(),
      'email' => (string) $user->getEmail(), 'locationId' => $this->sponsorship->reference($user, 'field_clinic_location'),
      'blocked' => !$user->isActive(), 'active' => (bool) ($access['write'] ?? FALSE),
      'accessReason' => !$user->isActive() ? 'doctor_blocked' : ($access['reason'] ?? NULL),
    ];
  }

  private function doctor(array $scope, int $id): UserInterface {
    $user = $this->entities->getStorage('user')->load($id);
    if ($id === $scope['primaryId'] || !$this->sponsorship->isDoctor($user)
      || $this->sponsorship->reference($user, 'field_clinic') !== $scope['clinicId']
      || $this->sponsorship->primaryId($user) !== $scope['primaryId'] || $this->repository->account($id)) {
      throw new ClinicException('Chiropractor not found.', 404);
    }
    $member = $this->repository->member($id);
    if ($member && ((int) $member['primary_uid'] !== $scope['primaryId'] || (int) $member['clinic_id'] !== $scope['clinicId'])) {
      throw new ClinicException('Chiropractor not found.', 404);
    }
    return $user;
  }

  private function payload(array $body, array $keys): void {
    if (array_diff(array_keys($body), $keys)) throw new ClinicException('The request contains unsupported fields.', 400);
  }

  private function values(array $body, int $clinicId): array {
    $this->payload($body, ['name', 'email', 'locationId']);
    $name = is_string($body['name'] ?? NULL) ? trim($body['name']) : '';
    $email = is_string($body['email'] ?? NULL) ? strtolower(trim($body['email'])) : '';
    $location = $body['locationId'] ?? NULL;
    $errors = [];
    if ($name === '' || mb_strlen($name) > 128) $errors['name'] = 'Enter a full name of up to 128 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) $errors['email'] = 'Enter a valid email address.';
    if (!is_int($location) || $location < 1) $errors['locationId'] = 'Choose a clinic location.';
    if (!$errors) {
      $entity = $this->entities->getStorage('clinic')->load($location);
      if (!$entity || $entity->bundle() !== 'clinic_location' || (int) $entity->get('field_clinic')->target_id !== $clinicId) {
        $errors['locationId'] = 'Choose a location belonging to your clinic.';
      }
    }
    if ($errors) throw new ClinicException('Check the highlighted fields.', 422, $errors);
    return compact('name', 'email', 'location');
  }

  private function uniqueEmail(string $email, ?int $except = NULL): void {
    $query = $this->entities->getStorage('user')->getQuery()->accessCheck(FALSE);
    $query->condition($query->orConditionGroup()->condition('mail', $email)->condition('name', $email));
    if ($except) $query->condition('uid', $except, '<>');
    if ($query->range(0, 1)->execute()) throw new ClinicException('An account with this email address already exists. Please use a different email address.', 409, ['email' => 'An account with this email address already exists.']);
  }

  private function locked(AccountInterface $actor, string $email, callable $operation): mixed {
    $scope = $this->scope($actor, TRUE);
    $keys = ['headless_subscription.account.' . $scope['primaryId'], 'headless_subscription.registration.' . hash('sha256', $email)];
    $held = [];
    try {
      foreach ($keys as $key) {
        if (!$this->lock->acquire($key, 30)) throw new ClinicException('Another clinic change is in progress. Please try again.', 409);
        $held[] = $key;
      }
      $scope = $this->scope($actor, TRUE);
      $transaction = $this->database->startTransaction();
      try { $result = $operation($scope); }
      catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
      // Drupal commits when the transaction object is released. Keep the locks
      // until that commit completes so another request sees the saved state.
      unset($transaction);
      return $result;
    } finally { foreach (array_reverse($held) as $key) $this->lock->release($key); }
  }

  public function createDoctor(AccountInterface $actor, array $body): array {
    $scope = $this->scope($actor, TRUE);
    $values = $this->values($body, $scope['clinicId']);
    $id = $this->locked($actor, $values['email'], function ($scope) use ($values): int {
      $this->uniqueEmail($values['email']);
      $user = $this->entities->getStorage('user')->create([
        'name' => $values['email'], 'mail' => $values['email'], 'pass' => bin2hex(random_bytes(24)),
        'status' => 1, 'roles' => [PortalRoles::CHIROPRACTOR_INACTIVE], 'field_full_name' => $values['name'],
        'field_clinic' => $scope['clinicId'], 'field_clinic_location' => $values['location'],
        'field_chiropractor_subscribers' => $scope['primaryId'], 'field_chiropractor' => $scope['primaryId'],
      ]);
      $this->registration->withoutLegacyClinicSetup(fn() => $user->save());
      if ($this->repository->account($scope['primaryId'])) {
        $this->repository->addMember((int) $user->id(), $scope['primaryId'], $scope['clinicId'], $this->time->getCurrentTime());
        $this->sponsorship->syncRole((int) $user->id());
      } else {
        $user->removeRole(PortalRoles::CHIROPRACTOR_INACTIVE);
        $user->addRole(PortalRoles::CHIROPRACTOR_ACTIVE);
        $user->save();
      }
      return (int) $user->id();
    });
    $user = $this->entities->getStorage('user')->load($id);
    $sent = $this->mailer->sendAccountCreated($user);
    $result = $this->snapshot($actor);
    $result['createdId'] = $id;
    $result['invitationSent'] = $sent;
    if (!$sent) $result['notice'] = 'The account was created, but the invitation could not be sent. They can use Forgot password to set up their login.';
    return $result;
  }

  public function updateDoctor(AccountInterface $actor, int $id, array $body): array {
    $scope = $this->scope($actor, TRUE);
    $this->doctor($scope, $id);
    $values = $this->values($body, $scope['clinicId']);
    $this->locked($actor, $values['email'], function ($scope) use ($id, $values): void {
      $user = $this->doctor($scope, $id);
      $this->uniqueEmail($values['email'], $id);
      if (strtolower($user->getAccountName()) === strtolower($user->getEmail())) $user->setUsername($values['email']);
      $user->setEmail($values['email']);
      $user->set('field_full_name', $values['name']);
      $user->set('field_clinic_location', $values['location']);
      $user->save();
    });
    return $this->snapshot($actor);
  }

  public function setDoctorAccess(AccountInterface $actor, int $id, array $body): array {
    $this->payload($body, ['blocked']);
    if (!is_bool($body['blocked'] ?? NULL)) throw new ClinicException('Specify whether the chiropractor is blocked.', 422, ['blocked' => 'Use true or false.']);
    $scope = $this->scope($actor, TRUE);
    $target = $this->doctor($scope, $id);
    $this->locked($actor, strtolower($target->getEmail()), function ($scope) use ($id, $body): void {
      $user = $this->doctor($scope, $id);
      $body['blocked'] ? $user->block() : $user->activate();
      $user->save();
      if ($this->repository->member($id)) {
        $this->repository->setMemberState($id, $scope['primaryId'], $body['blocked'] ? 'blocked' : 'active', $this->time->getCurrentTime());
        $this->sponsorship->syncRole($id);
      }
    });
    return $this->snapshot($actor);
  }

  public function saveLocation(AccountInterface $actor, ?int $id, array $body): array {
    $this->payload($body, ['title']);
    $title = is_string($body['title'] ?? NULL) ? trim($body['title']) : '';
    if ($title === '' || mb_strlen($title) > 128) throw new ClinicException('Enter a location title of up to 128 characters.', 422, ['title' => 'Enter a valid title.']);
    $this->locked($actor, 'location', function ($scope) use ($id, $title): void {
      $storage = $this->entities->getStorage('clinic');
      $location = $id ? $storage->load($id) : $storage->create(['type' => 'clinic_location', 'uid' => $scope['primaryId'], 'field_clinic' => $scope['clinicId']]);
      if (!$location || $location->bundle() !== 'clinic_location' || (int) $location->get('field_clinic')->target_id !== $scope['clinicId']) throw new ClinicException('Clinic location not found.', 404);
      $location->set('title', $title);
      $location->save();
    });
    return $this->snapshot($actor);
  }
}
