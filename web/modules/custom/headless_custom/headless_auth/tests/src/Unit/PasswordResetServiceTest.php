<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_auth\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\headless_auth\PasswordResetService;
use Drupal\headless_mail\Mailer;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\ConstraintViolationList;

/** @coversDefaultClass \Drupal\headless_auth\PasswordResetService
 * @group headless_auth
 */
final class PasswordResetServiceTest extends UnitTestCase {
  private $storage;
  private $flood;
  private $lock;
  private $mailer;
  private PasswordResetService $service;

  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 7) . '/core/modules/user/user.module';
    new Settings(['hash_salt' => 'unit-test-only-salt']);
    $this->storage = $this->createMock(UserStorageInterface::class);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user')->willReturn($this->storage);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->mailer = $this->createMock(Mailer::class);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(2000);
    $this->service = new PasswordResetService($entities, $this->getConfigFactoryStub([
      'user.settings' => ['password_reset_timeout' => 1000],
      'headless_auth.settings' => ['frontend_base_url' => 'https://portal.example.com'],
    ]), $this->flood, $time, $this->lock, $this->mailer);
  }

  private function account(bool $active = TRUE, int $login = 100): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(9);
    $user->method('isActive')->willReturn($active);
    $user->method('getLastLoginTime')->willReturn($login);
    $user->method('getEmail')->willReturn('reset@example.com');
    $user->method('getPassword')->willReturn('stored-password-hash');
    $user->method('getPreferredLangcode')->willReturn('en');
    return $user;
  }

  public function testTokenRequiresActiveAccountValidSignatureAndExpiryEvenForFirstLogin(): void {
    foreach ([100, 0] as $login) {
      $user = $this->account(TRUE, $login);
      $this->assertTrue($this->service->validToken($user, 1500, user_pass_rehash($user, 1500)));
      $this->assertFalse($this->service->validToken($user, 1000, user_pass_rehash($user, 1000)));
      $this->assertFalse($this->service->validToken($user, 2001, user_pass_rehash($user, 2001)));
      $this->assertFalse($this->service->validToken($user, 1500, str_repeat('a', 43)));
    }
    $blocked = $this->account(FALSE);
    $this->assertFalse($this->service->validToken($blocked, 1500, user_pass_rehash($blocked, 1500)));
    $recentLogin = $this->account(TRUE, 1600);
    $this->assertFalse($this->service->validToken($recentLogin, 1500, user_pass_rehash($recentLogin, 1500)));
  }

  public function testResetMailUsesConfiguredPortalAndCoreToken(): void {
    $user = $this->account();
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->storage->expects($this->once())->method('loadByProperties')->with(['mail' => 'reset@example.com', 'status' => 1])->willReturn([$user]);
    $url = 'https://portal.example.com/reset-password/9/2000/' . user_pass_rehash($user, 2000);
    $this->mailer->expects($this->once())->method('send')->with('headless_auth', 'password_reset', 'reset@example.com', ['url' => $url, 'hours' => 1], 'en')->willReturn(TRUE);
    $this->service->request('reset@example.com', 'proxy-ip');
  }

  public function testUnknownAccountDoesNotSendMail(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->storage->method('loadByProperties')->willReturn([]);
    $this->mailer->expects($this->never())->method('send');
    $this->service->request('unknown@example.com', 'proxy-ip');
  }

  public function testAddressThrottleDoesNotLookUpOrSendMail(): void {
    $this->flood->method('isAllowed')->willReturnCallback(fn ($event) => $event !== 'headless_auth.request_email');
    $this->storage->expects($this->never())->method('loadByProperties');
    $this->mailer->expects($this->never())->method('send');
    $this->service->request('reset@example.com', 'proxy-ip');
  }

  public function testInvalidTokenCannotChangePasswordAndReleasesLock(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->lock->expects($this->once())->method('release')->with('headless_auth.reset.9');
    $user = $this->account();
    $user->expects($this->never())->method('setPassword');
    $user->expects($this->never())->method('save');
    $this->storage->method('load')->with(9)->willReturn($user);
    $this->expectException(HttpException::class);
    $this->service->reset(9, 1500, str_repeat('a', 43), 'new-password-123', 'proxy-ip');
  }

  public function testValidTokenSavesThroughUserEntityAndReleasesLock(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->lock->expects($this->once())->method('release');
    $user = $this->account();
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('validate')->willReturn(new ConstraintViolationList());
    $user->method('get')->with('pass')->willReturn($field);
    $user->expects($this->once())->method('setPassword')->with('new-password-123')->willReturnSelf();
    $user->expects($this->once())->method('save');
    $this->storage->method('load')->with(9)->willReturn($user);
    $this->service->reset(9, 1500, user_pass_rehash($user, 1500), 'new-password-123', 'proxy-ip');
  }

  public function testIpFloodBlocksBeforeLookup(): void {
    $this->flood->method('isAllowed')->willReturn(FALSE);
    $this->storage->expects($this->never())->method('loadByProperties');
    $this->expectException(HttpException::class);
    $this->service->request('reset@example.com', 'proxy-ip');
  }

  public function testLockConflictDoesNotLoadOrSaveAccount(): void {
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->lock->method('acquire')->willReturn(FALSE);
    $this->storage->expects($this->never())->method('load');
    $this->expectException(HttpException::class);
    $this->service->reset(9, 1500, str_repeat('a', 43), 'new-password-123', 'proxy-ip');
  }
}
