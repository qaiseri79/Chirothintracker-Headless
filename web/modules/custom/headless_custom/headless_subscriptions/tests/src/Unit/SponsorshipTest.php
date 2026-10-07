<?php
declare(strict_types=1);
namespace Drupal\Tests\headless_subscriptions\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Entity\{EntityTypeManagerInterface, EntityStorageInterface};
use Drupal\Core\Config\{ConfigFactoryInterface, ImmutableConfig};
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\user\UserInterface;
use Drupal\headless_access\{PortalAccessResolver, PortalRoles};
use Drupal\headless_subscriptions\{SubscriptionRepository, Sponsorship};

/** @group headless_subscriptions */
final class SponsorshipTest extends UnitTestCase {
  private function fixture(): array {
    $state = new \ArrayObject(['now'=>1000, 'until'=>2000, 'purchaseState'=>'active', 'cancel'=>FALSE,
      'parentActive'=>TRUE, 'memberState'=>'active', 'memberExists'=>TRUE, 'memberClinic'=>44,
      'childClinic'=>44, 'parentClinic'=>44, 'parentMember'=>FALSE, 'independentChild'=>FALSE,
      'secondaryReference'=>101, 'childActive'=>TRUE, 'nested'=>NULL]);
    $make = function (int $id, array $roles, array $refs, callable $active): UserInterface {
      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn((string) $id);
      $user->method('isAuthenticated')->willReturn(TRUE);
      $user->method('isActive')->willReturnCallback($active);
      $user->method('getRoles')->willReturn($roles);
      $user->method('hasField')->willReturnCallback(fn($name)=>array_key_exists($name, $refs));
      $fields = [];
      foreach ($refs as $name=>$value) {
        $field = $this->createMock(FieldItemListInterface::class);
        $field->method('__get')->willReturnCallback(fn($key)=>$key === 'target_id' ? (is_callable($value) ? $value() : $value) : NULL);
        $fields[$name] = $field;
      }
      $user->method('get')->willReturnCallback(fn($name)=>$fields[$name]);
      $user->expects($this->never())->method('save');
      $user->expects($this->never())->method('addRole');
      $user->expects($this->never())->method('removeRole');
      return $user;
    };
    $primary = $make(101, [PortalRoles::CHIROPRACTOR_ACTIVE],
      ['field_clinic'=>fn()=>$state['parentClinic'], 'field_chiropractor'=>101, 'field_chiropractor_subscribers'=>fn()=>$state['nested']], fn()=>$state['parentActive']);
    $secondary = $make(102, [PortalRoles::CHIROPRACTOR_INACTIVE],
      ['field_clinic'=>fn()=>$state['childClinic'], 'field_chiropractor_subscribers'=>101, 'field_chiropractor'=>fn()=>$state['secondaryReference']], fn()=>$state['childActive']);
    $patient = $make(201, [PortalRoles::PATIENT_ENROLLED], ['field_clinic'=>44, 'field_chiropractor'=>102], fn()=>TRUE);
    $archived = $make(202, [PortalRoles::PATIENT_ARCHIVED], ['field_clinic'=>44, 'field_chiropractor'=>102], fn()=>TRUE);
    $users = [101=>$primary,102=>$secondary,201=>$patient,202=>$archived];
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn($id)=>$users[$id] ?? NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user')->willReturn($storage);
    $repo = $this->createMock(SubscriptionRepository::class);
    $repo->method('account')->willReturnCallback(fn($id)=>$id === 101 || ($id === 102 && $state['independentChild']) ? ['uid'=>$id,'clinic_id'=>44,'ever_paid'=>1] : NULL);
    $repo->method('member')->willReturnCallback(fn($id)=>
      ($id === 102 && $state['memberExists']) || ($id === 101 && $state['parentMember'])
        ? ['uid'=>$id,'primary_uid'=>101,'clinic_id'=>$state['memberClinic'],'state'=>$state['memberState']] : NULL);
    $repo->method('current')->willReturnCallback(fn($id)=>['state'=>$state['purchaseState'],'paid_until'=>$state['until'],'cancel_requested'=>$state['cancel'],'plan'=>['patientLimit'=>4,'ecommerce'=>TRUE]]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn()=>$state['now']);
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->with('grace_days')->willReturn(0);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->with('headless_subscriptions.settings')->willReturn($settings);
    $queues = $this->createMock(QueueFactory::class);
    $sponsorship = new Sponsorship($repo,$entities,$time,$config,$queues);
    $resolver = new PortalAccessResolver($entities,$time,$config,$repo,$sponsorship);
    return compact('state','sponsorship','resolver','primary','secondary','patient','archived');
  }

  public function testCoveredDoctorAndPatientReceivePaidAccessWithoutBillingOwnership(): void {
    extract($this->fixture());
    $doctor = $resolver->resolve($secondary);
    $this->assertTrue($doctor['write']);
    $this->assertSame('sponsored',$doctor['funding']);
    $this->assertFalse($doctor['canManageBilling']);
    $this->assertFalse($doctor['canManageDoctors']);
    $this->assertTrue($resolver->resolve($patient)['write']);
    $this->assertSame('enrolled',$resolver->resolve($patient)['enrollmentStatus']);
  }

  public function testCancellationKeepsCoverageThroughTheVerifiedPaidPeriod(): void {
    extract($this->fixture());
    $state['purchaseState']='cancelled'; $state['cancel']=TRUE;
    $this->assertTrue($resolver->resolve($secondary)['write']);
    $this->assertFalse($resolver->resolve($secondary)['cancelAtPeriodEnd']);
    $this->assertTrue($resolver->resolve($patient)['write']);
    $state['now']=2000;
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $this->assertSame('sponsor_subscription_inactive',$resolver->resolve($secondary)['reason']);
    $this->assertFalse($resolver->resolve($patient)['write']);
    $this->assertTrue($resolver->resolve($patient)['read']);
    $this->assertSame('enrolled',$resolver->resolve($patient)['enrollmentStatus']);
  }

  public function testRenewalRestoresEnrolledOperationsAndPreservesManualArchives(): void {
    extract($this->fixture());
    $state['now']=2000;
    $this->assertFalse($resolver->resolve($patient)['write']);
    $state['until']=3000;
    $this->assertTrue($resolver->resolve($secondary)['write']);
    $this->assertTrue($resolver->resolve($patient)['write']);
    $this->assertFalse($resolver->resolve($archived)['write']);
    $this->assertSame('program_archived',$resolver->resolve($archived)['reason']);
  }

  public function testBlockedPrimarySuspendsItsDoctorAndPatients(): void {
    extract($this->fixture());
    $state['parentActive']=FALSE;
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $this->assertTrue($resolver->resolve($secondary)['read']);
    $this->assertSame('sponsor_unavailable',$resolver->resolve($secondary)['reason']);
    $this->assertFalse($resolver->resolve($patient)['write']);
  }

  public function testManualSecondaryBlockSurvivesRenewal(): void {
    extract($this->fixture());
    $state['memberState']='blocked'; $state['childActive']=FALSE; $state['until']=4000;
    $this->assertNull($resolver->resolve($secondary));
    $this->assertFalse($resolver->resolve($patient)['write']);
    $this->assertSame('enrolled',$resolver->resolve($patient)['enrollmentStatus']);
  }

  public function testConflictingDoctorReferencesCannotGrantCoverage(): void {
    extract($this->fixture());
    $state['secondaryReference']=999;
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $this->assertFalse($resolver->resolve($patient)['write']);
  }

  public function testCrossClinicRelationshipCannotGrantCoverage(): void {
    extract($this->fixture());
    $state['childClinic']=45;
    $this->assertFalse($resolver->resolve($secondary)['read']);
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $this->assertFalse($resolver->resolve($patient)['write']);
  }

  public function testMembershipClinicMustMatchPayerClinic(): void {
    extract($this->fixture());
    $state['memberClinic']=45;
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $this->assertFalse($resolver->resolve($patient)['write']);
  }

  public function testNestedSponsorshipIsRejected(): void {
    extract($this->fixture());
    $state['parentMember']=TRUE;
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $state['parentMember']=FALSE; $state['nested']=999;
    $this->assertFalse($resolver->resolve($secondary)['write']);
  }

  public function testIndependentSubscriptionCannotAlsoUseSponsorship(): void {
    extract($this->fixture());
    $state['independentChild']=TRUE;
    $this->assertFalse($resolver->resolve($secondary)['write']);
    $this->assertFalse($resolver->resolve($secondary)['canManageBilling']);
  }

  public function testUnmarkedLegacyReferencesAreNotAutomaticallyAdopted(): void {
    extract($this->fixture());
    $state['memberExists']=FALSE;
    $this->assertNull($sponsorship->funding(102));
    $this->assertFalse($resolver->resolve($secondary)['write']);
  }

  public function testPatientDoesNotReceivePayerCancellationOrManagementDetails(): void {
    extract($this->fixture());
    $state['cancel']=TRUE;
    $access=$resolver->resolve($patient);
    $this->assertFalse($access['canManageBilling']);
    $this->assertFalse($access['canManageDoctors']);
    $this->assertFalse($access['cancelAtPeriodEnd']);
    $this->assertNull($access['funding']);
    $this->assertArrayNotHasKey('ownerId',$access);
    $this->assertArrayNotHasKey('paymentMethod',$access);
  }
}
