<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_access\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\headless_access\PortalAccessResolver;
use Drupal\headless_access\PortalRoles;
use Drupal\headless_access\Access\PortalMemberAccess;
use Drupal\headless_access\Access\WritePatientAccess;
use Drupal\headless_subscriptions\SubscriptionRepository;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/** @group headless_access */
final class PortalAccessResolverTest extends UnitTestCase {

  private function user(int $id, array $roles, array $references = [], bool $active = TRUE): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn((string) $id);
    $user->method('isAuthenticated')->willReturn(TRUE);
    $user->method('isActive')->willReturn($active);
    $user->method('getRoles')->willReturn($roles);
    $user->method('hasField')->willReturnCallback(fn($field) => array_key_exists($field, $references));
    $fields = [];
    foreach ($references as $name => $value) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('__get')->willReturnCallback(fn($key) => $key === 'target_id' ? $value : NULL);
      $fields[$name] = $field;
    }
    $user->method('get')->willReturnCallback(fn($name) => $fields[$name]);
    $user->expects($this->never())->method('save');
    $user->expects($this->never())->method('addRole');
    $user->expects($this->never())->method('removeRole');
    return $user;
  }

  private function fixture(array $patientRoles = [PortalRoles::PATIENT_ENROLLED], bool $providerActive = TRUE, int $patientClinic = 44, int $grace = 0): array {
    $patient = $this->user(201, $patientRoles, ['field_chiropractor' => 101, 'field_clinic' => $patientClinic]);
    $doctor = $this->user(101, [PortalRoles::CHIROPRACTOR_ACTIVE], [], $providerActive);
    $users = [101 => $doctor, 201 => $patient];
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn($id) => $users[$id] ?? NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user')->willReturn($storage);
    $state = new \ArrayObject(['now' => 1000, 'paidUntil' => 2000, 'status' => 'active', 'cancelled' => FALSE, 'managed' => TRUE]);
    $repo = $this->createMock(SubscriptionRepository::class);
    $repo->method('account')->willReturnCallback(fn($id) => $id === 101 && $state['managed'] ? ['uid' => 101, 'clinic_id' => 44, 'ever_paid' => 1] : NULL);
    $repo->method('current')->willReturnCallback(fn($id) => ['state' => $state['status'], 'paid_until' => $state['paidUntil'], 'cancel_requested' => $state['cancelled'], 'plan' => ['patientLimit' => 4]]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn() => $state['now']);
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->with('grace_days')->willReturn($grace);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->with('headless_subscriptions.settings')->willReturn($settings);
    $resolver = new PortalAccessResolver($entities, $time, $config, $repo);
    return compact('resolver', 'patient', 'doctor', 'state');
  }

  public function testExpirySuspendsOperationsWithoutChangingEnrollment(): void {
    extract($this->fixture());
    $this->assertTrue($resolver->resolve($patient)['write']);
    $state['now'] = 2000;
    $access = $resolver->resolve($patient);
    $this->assertFalse($access['write']);
    $this->assertTrue($access['read']);
    $this->assertSame('inactive', $access['mode']);
    $this->assertSame('enrolled', $access['enrollmentStatus']);
    $this->assertSame('provider_subscription_inactive', $access['reason']);
    $this->assertSame([PortalRoles::PATIENT_ENROLLED], $patient->getRoles());
  }

  public function testPaidCancellationKeepsOperationsUntilExpiry(): void {
    extract($this->fixture());
    $state['status'] = 'cancelled';
    $state['cancelled'] = TRUE;
    $this->assertTrue($resolver->resolve($patient)['write']);
    $this->assertTrue($resolver->resolve($doctor)['cancelAtPeriodEnd']);
    $state['now'] = 2000;
    $this->assertFalse($resolver->resolve($patient)['write']);
    $this->assertFalse($resolver->resolve($doctor)['write']);
  }

  public function testRenewalRestoresEnrolledPatientsWithoutReEnrollment(): void {
    extract($this->fixture());
    $state['now'] = 2100;
    $this->assertFalse($resolver->resolve($patient)['write']);
    $state['paidUntil'] = 3000;
    $this->assertTrue($resolver->resolve($patient)['write']);
    $this->assertSame('enrolled', $resolver->resolve($patient)['enrollmentStatus']);
  }

  public function testManualArchiveIsNeverUndoneByPayment(): void {
    extract($this->fixture([PortalRoles::PATIENT_ARCHIVED]));
    $state['paidUntil'] = 3000;
    $access = $resolver->resolve($patient);
    $this->assertFalse($access['write']);
    $this->assertSame('archived', $access['enrollmentStatus']);
    $this->assertSame('program_archived', $access['reason']);
  }

  public function testBlockedProviderCannotGrantOperations(): void {
    extract($this->fixture(providerActive: FALSE));
    $this->assertFalse($resolver->resolve($patient)['write']);
    $this->assertSame('provider_unavailable', $resolver->resolve($patient)['reason']);
  }

  public function testWrongClinicCannotUseAnotherDoctorFunding(): void {
    extract($this->fixture(patientClinic: 45));
    $this->assertFalse($resolver->resolve($patient)['write']);
  }

  public function testUnmanagedLegacyPatientKeepsExistingRolePolicy(): void {
    extract($this->fixture());
    $state['managed'] = FALSE;
    $state['now'] = 3000;
    $this->assertTrue($resolver->resolve($patient)['write']);
  }

  public function testGraceAppliesToOverdueRenewalButNotCancelledAccess(): void {
    extract($this->fixture(grace: 1));
    $state['now'] = 2100;
    $this->assertTrue($resolver->resolve($patient)['write']);
    $state['status'] = 'cancelled';
    $this->assertFalse($resolver->resolve($patient)['write']);
  }

  public function testPatientSessionDoesNotExposePrivateBilling(): void {
    extract($this->fixture());
    $access = $resolver->resolve($patient);
    $this->assertSame('patient', $access['audience']);
    $this->assertFalse($access['canManageBilling']);
    $this->assertFalse($access['cancelAtPeriodEnd']);
    foreach (['subscription', 'plan', 'paymentMethod', 'payments', 'providerId', 'transactionId'] as $key) {
      $this->assertArrayNotHasKey($key, $access);
    }
  }

  public function testBackendRouteRejectsInactiveWriteAndPermitsOwnHistory(): void {
    extract($this->fixture());
    $state['now'] = 2100;
    $container = new ContainerBuilder();
    $container->set('headless_access.portal_access', $resolver);
    $contexts = $this->createMock(\Drupal\Core\Cache\Context\CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $route = new Route('/api/headless/progress');
    $this->assertTrue(PortalMemberAccess::access($route, $patient, Request::create('/', 'GET'))->isAllowed());
    $this->assertFalse(PortalMemberAccess::access($route, $patient, Request::create('/', 'POST'))->isAllowed());
    $this->assertFalse(WritePatientAccess::access($route, $patient)->isAllowed());
    $state['paidUntil'] = 3000;
    $this->assertTrue(WritePatientAccess::access($route, $patient)->isAllowed());
  }

  public function testArchivedPatientCannotSubmitToBackend(): void {
    extract($this->fixture([PortalRoles::PATIENT_ARCHIVED]));
    $container = new ContainerBuilder();
    $container->set('headless_access.portal_access', $resolver);
    $contexts = $this->createMock(\Drupal\Core\Cache\Context\CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $this->assertFalse(PortalMemberAccess::access(new Route('/'), $patient, Request::create('/', 'POST'))->isAllowed());
    $this->assertTrue(PortalMemberAccess::access(new Route('/'), $patient, Request::create('/', 'GET'))->isAllowed());
  }

}
