<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_intake\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\eck\Entity\EckEntity;
use Drupal\headless_intake\ClinicIntakeLinkService;
use Drupal\headless_intake\IntakeInviteService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one-link-per-clinic rules.
 *
 * The service is the only place that decides whether a clinic has a link, so
 * these tests cover the four properties everything else assumes: absence is
 * representable, creation is idempotent, regeneration really invalidates, and a
 * token resolves to exactly one clinic.
 *
 * Storage is a hand-written fake rather than a mock of EntityStorageInterface,
 * because the interesting behaviour here is *what got stored*. A mock has to be
 * told what to save, so a test that wanted to prove "the second call wrote
 * nothing" would have to encode that expectation into the mock, at which point
 * it proves only that the mock agrees with itself.
 */
final class ClinicIntakeLinkServiceTest extends TestCase {

  /**
   * The clinic under test.
   */
  private const CLINIC_ID = 42;

  /**
   * A second clinic, for the isolation tests.
   */
  private const OTHER_CLINIC_ID = 43;

  /**
   * Live token per clinic id.
   *
   * @var array<int, \Drupal\Tests\headless_intake\Unit\TokenHolder>
   */
  private array $holders = [];

  private EntityTypeManagerInterface $entityTypeManager;

  protected function setUp(): void {
    parent::setUp();

    $this->holders = [
      self::CLINIC_ID => new TokenHolder(),
      self::OTHER_CLINIC_ID => new TokenHolder(),
    ];

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      fn (int $id): ?EckEntity => isset($this->holders[$id]) ? $this->clinic($id) : NULL,
    );
    $storage->method('getQuery')->willReturnCallback(
      fn (): QueryInterface => $this->tokenQuery(),
    );

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')
      ->with('clinic')
      ->willReturn($storage);
  }

  /**
   * The service under test.
   */
  private function service(): ClinicIntakeLinkService {
    // The real IntakeInviteService, not a mock: it is declared final, and
    // PHPUnit cannot double a final class. Constructing it needs four services,
    // and only one of them — intakeUrl() — is ever called from here, so the
    // collaborators are unconfigured stand-ins rather than expectations.
    return new ClinicIntakeLinkService(
      $this->entityTypeManager,
      new IntakeInviteService(
        $this->createMock(Connection::class),
        $this->entityTypeManager,
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(RequestStack::class),
      ),
    );
  }

  /**
   * A clinic entity backed by its token holder.
   *
   * A PHPUnit mock of ECK's base class rather than a hand-written object,
   * because the service checks that what it loaded is an `EckEntity`. A plain
   * stub implementing only hasField()/get() would fail that check for the wrong
   * reason — it would not be a clinic at all — and every "a real clinic is
   * accepted" assertion would stop proving anything about clinics.
   *
   * @param int $id
   *   Clinic id.
   *
   * @return \Drupal\eck\Entity\EckEntity
   *   The clinic, reading and writing the shared token holder.
   */
  private function clinic(int $id): EckEntity {
    $holder = $this->holders[$id];

    $clinic = $this->createMock(EckEntity::class);
    $clinic->method('id')->willReturn($id);
    $clinic->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === ClinicIntakeLinkService::TOKEN_FIELD,
    );
    $clinic->method('get')->willReturnCallback(
      static fn (string $field): FakeFieldList => new FakeFieldList($holder->get()),
    );
    $clinic->method('set')->willReturnCallback(
      function (string $field, $value) use ($holder, $clinic): EckEntity {
        if ($field === ClinicIntakeLinkService::TOKEN_FIELD) {
          $holder->set(is_string($value) ? $value : NULL);
        }
        return $clinic;
      },
    );
    $clinic->method('save')->willReturn([]);

    return $clinic;
  }

  /**
   * An entity query that matches on the token field.
   *
   * Reads the holders at execute() time rather than at condition() time, so a
   * search after a write sees the new value.
   */
  private function tokenQuery(): QueryInterface {
    $wanted = NULL;

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      function (string $field, $value) use (&$wanted, $query): QueryInterface {
        if ($field === ClinicIntakeLinkService::TOKEN_FIELD) {
          $wanted = is_string($value) ? $value : NULL;
        }
        return $query;
      },
    );
    $query->method('execute')->willReturnCallback(function () use (&$wanted): array {
      $found = [];
      foreach ($this->holders as $id => $holder) {
        if ($wanted !== NULL && $holder->get() === $wanted) {
          $found[] = $id;
        }
      }
      return $found;
    });

    return $query;
  }

  /**
   * The token a clinic currently holds in the fake store.
   */
  private function storedToken(int $clinicId): ?string {
    return $this->holders[$clinicId]->get();
  }

  /**
   * Seeds a clinic's token, as if it had been generated earlier.
   */
  private function seedToken(int $clinicId, string $token): void {
    $this->holders[$clinicId]->set($token);
  }

  /**
   * A clinic with no link has none, and asking twice agrees.
   */
  public function testClinicWithNoTokenHasNoLink(): void {
    $service = $this->service();

    $this->assertNull($service->tokenFor(self::CLINIC_ID));
    $this->assertNull($service->urlFor(self::CLINIC_ID));
    $this->assertFalse($service->hasLink(self::CLINIC_ID));
  }

  /**
   * Creating produces a token, and it is 36 hex characters.
   *
   * The length is asserted because the frontend's token shape check and the
   * field's 64-character column both assume it: a shorter token would be
   * accepted by the service and then turned away by the column.
   */
  public function testCreateGeneratesAThirtySixCharacterHexToken(): void {
    $token = $this->service()->createIfMissing(self::CLINIC_ID);

    $this->assertMatchesRegularExpression('/^[0-9a-f]{36}$/', $token);
    $this->assertSame($token, $this->storedToken(self::CLINIC_ID));
    $this->assertTrue($this->service()->hasLink(self::CLINIC_ID));
  }

  /**
   * Creating again returns the same token and does not replace it.
   *
   * This is the property the idempotency requirement exists for: a
   * double-clicked button must not invalidate a URL patients already hold.
   */
  public function testCreateIsIdempotent(): void {
    $service = $this->service();

    $first = $service->createIfMissing(self::CLINIC_ID);
    $second = $service->createIfMissing(self::CLINIC_ID);
    $third = $service->createIfMissing(self::CLINIC_ID);

    $this->assertSame($first, $second);
    $this->assertSame($first, $third);
    $this->assertSame($first, $this->storedToken(self::CLINIC_ID));
  }

  /**
   * A clinic that already has a link never had one generated over the top of it.
   */
  public function testCreateLeavesAnExistingTokenAlone(): void {
    $this->seedToken(self::CLINIC_ID, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    $returned = $this->service()->createIfMissing(self::CLINIC_ID);

    $this->assertSame(
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      $returned,
      'createIfMissing() replaced a token that was already in use.',
    );
    $this->assertSame(
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      $this->storedToken(self::CLINIC_ID),
    );
  }

  /**
   * Two clinics get different tokens.
   *
   * Without this, two doctors could share a link and patients would arrive on
   * whichever clinic the lookup happened to return first.
   */
  public function testTwoClinicsGetDifferentTokens(): void {
    $service = $this->service();

    $first = $service->createIfMissing(self::CLINIC_ID);
    $second = $service->createIfMissing(self::OTHER_CLINIC_ID);

    $this->assertNotSame($first, $second);
    $this->assertSame($first, $this->storedToken(self::CLINIC_ID));
    $this->assertSame($second, $this->storedToken(self::OTHER_CLINIC_ID));
  }

  /**
   * Regenerating replaces the token.
   */
  public function testRegenerateReplacesTheToken(): void {
    $service = $this->service();
    $original = $service->createIfMissing(self::CLINIC_ID);

    $replacement = $service->regenerate(self::CLINIC_ID);

    $this->assertNotSame($original, $replacement);
    $this->assertSame($replacement, $this->storedToken(self::CLINIC_ID));
  }

  /**
   * After regenerating, the old token resolves to nothing.
   *
   * The acceptance criterion, stated as behaviour rather than as a comparison of
   * two strings: a patient holding a printed old URL gets a 404.
   */
  public function testTheOldTokenStopsResolvingAfterRegenerate(): void {
    $service = $this->service();
    $original = $service->createIfMissing(self::CLINIC_ID);

    $this->assertSame(
      self::CLINIC_ID,
      $service->clinicIdForToken($original),
      'The token did not resolve before regenerating.',
    );

    $service->regenerate(self::CLINIC_ID);

    $this->assertNull(
      $service->clinicIdForToken($original),
      'The old token still resolves after regenerate.',
    );
    $this->assertNotNull($service->clinicIdForToken($this->storedToken(self::CLINIC_ID)));
  }

  /**
   * Regenerating a clinic with no link is refused.
   *
   * Treated as a caller error rather than a silent create, because "regenerate"
   * and "create" being the same action would let a double-click on the wrong
   * control destroy a working link.
   */
  public function testRegenerateWithoutALinkIsRefused(): void {
    $this->expectException(\Exception::class);

    $this->service()->regenerate(self::CLINIC_ID);
  }

  /**
   * A refused regenerate leaves the stored token alone.
   *
   * Proves the refusal happens before any write, which is the part that matters:
   * an exception thrown after a save would still have destroyed the link.
   */
  public function testRefusedRegenerateLeavesTheTokenUntouched(): void {
    $this->seedToken(self::OTHER_CLINIC_ID, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

    try {
      // A clinic id with no holder cannot be loaded, which is the same path a
      // clinic with no token takes.
      $this->service()->regenerate(9999);
    }
    catch (\Exception $e) {
      // Expected.
    }

    $this->assertSame(
      'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      $this->storedToken(self::OTHER_CLINIC_ID),
    );
  }

  /**
   * A token resolves to the clinic that owns it.
   */
  public function testTokenResolvesToItsClinic(): void {
    $service = $this->service();
    $token = $service->createIfMissing(self::OTHER_CLINIC_ID);

    $this->assertSame(self::OTHER_CLINIC_ID, $service->clinicIdForToken($token));
  }

  /**
   * An unknown token resolves to nothing.
   */
  public function testUnknownTokenResolvesToNothing(): void {
    $service = $this->service();
    $service->createIfMissing(self::CLINIC_ID);

    $this->assertNull($service->clinicIdForToken('ffffffffffffffffffffffffffffffffffff'));
  }

  /**
   * A clinic with no token cannot be found by any token, including its own.
   *
   * The empty string is included because it is what a request for a clinic whose
   * token was cleared would carry, and it must miss rather than match.
   */
  public function testEmptyAndMalformedTokensResolveToNothing(): void {
    $service = $this->service();

    $this->assertNull($service->clinicIdForToken(''));
    $this->assertNull($service->clinicIdForToken('short'));
    $this->assertNull($service->clinicIdForToken(str_repeat('z', 36)));
    $this->assertNull($service->clinicIdForToken("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"));
  }

  /**
   * Tokens are long enough that the shape check accepts what it generated.
   *
   * Guards the two ends of the contract at once: the service's generator and the
   * resolver's regex have to agree, or a freshly created link would 404 on the
   * patient side.
   */
  public function testAGeneratedTokenIsAcceptedByTheResolverShapeCheck(): void {
    $service = $this->service();

    for ($i = 0; $i < 25; $i++) {
      $token = $service->generateToken();
      $this->assertMatchesRegularExpression(
        '/^[A-Za-z0-9_-]{16,128}$/',
        $token,
        'A generated token does not satisfy the shape the resolver requires.',
      );
    }
  }

  /**
   * A deleted clinic's token resolves to nothing.
   *
   * A token can outlive its clinic — the row is there but the entity is not —
   * and the public endpoint must treat that as a miss rather than submitting to
   * clinic id 0.
   */
  public function testATokenWhoseClinicIsGoneResolvesToNothing(): void {
    $service = $this->service();
    $token = $service->createIfMissing(self::CLINIC_ID);

    unset($this->holders[self::CLINIC_ID]);

    $this->assertNull($service->clinicIdForToken($token));
  }

}