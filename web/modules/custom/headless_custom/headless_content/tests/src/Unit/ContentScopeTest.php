<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_access\PortalRoles;
use Drupal\headless_content\Exception\ContentException;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ContentScope — the clinic tenant boundary and the shared toggle.
 *
 * The visibility rule is the tenant boundary for this module, so it is tested
 * without a request. The cases below are the ones that could leak content: an
 * account with no clinic, a clinic whose toggle decides whether the resource
 * library widens, a node belonging to another clinic, and a node with no clinic on
 * it at all.
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\ContentScope
 */
class ContentScopeTest extends TestCase {

  use ProphecyTrait;

  protected ContentScope $scope;

  protected ObjectProphecy $entityTypeManager;

  protected ObjectProphecy $userStorage;

  protected ObjectProphecy $clinicStorage;

  /**
   * The clinic the helper user belongs to, or NULL for no clinic field.
   */
  protected ?int $userClinicId = NULL;

  /**
   * The clinic toggle value, or NULL when the clinic is missing the field.
   */
  protected ?bool $clinicHidesShared = NULL;

  /**
   * Whether the clinic entity itself exists.
   */
  protected bool $clinicExists = TRUE;

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->userStorage = $this->prophesize(EntityStorageInterface::class);
    $this->clinicStorage = $this->prophesize(EntityStorageInterface::class);
    $this->entityTypeManager->getStorage('user')->willReturn($this->userStorage->reveal());
    $this->entityTypeManager->getStorage('clinic')->willReturn($this->clinicStorage->reveal());

    $this->scope = new ContentScope($this->entityTypeManager->reveal());
  }

  /**
   * An account carrying the given roles.
   */
  protected function account(int $uid, array $roles): AccountInterface {
    $account = $this->prophesize(AccountInterface::class);
    $account->id()->willReturn((string) $uid);
    $account->isAuthenticated()->willReturn($uid > 0);
    $account->getRoles()->willReturn($roles);

    return $account->reveal();
  }

  /**
   * The stored user entity for uid 77, per the $userClinicId fixture property.
   */
  protected function stubUser(int $uid): void {
    $user = $this->prophesize(FieldableEntityInterface::class);
    if ($this->userClinicId === NULL) {
      $user->hasField('field_clinic')->willReturn(FALSE);
    }
    else {
      $user->hasField('field_clinic')->willReturn(TRUE);
      // A plain object, not a prophecy: clinicId() only reads `->target_id` off the
      // field, and a prophecy cannot carry a public property its interface does not
      // declare.
      $user->get('field_clinic')->willReturn(new class ((string) $this->userClinicId) {
        public function __construct(public string $target_id) {}
      });
    }

    $this->userStorage->load($uid)->willReturn($user->reveal());
  }

  /**
   * The clinic entity, per the $clinicHidesShared / $clinicExists properties.
   */
  protected function stubClinic(int $clinicId): void {
    if (!$this->clinicExists) {
      $this->clinicStorage->load($clinicId)->willReturn(NULL);

      return;
    }

    $clinic = $this->prophesize(FieldableEntityInterface::class);
    if ($this->clinicHidesShared === NULL) {
      $clinic->hasField('field_hide_shared_resources')->willReturn(FALSE);
    }
    else {
      $clinic->hasField('field_hide_shared_resources')->willReturn(TRUE);
      $clinic->get('field_hide_shared_resources')->willReturn(new class ($this->clinicHidesShared ? '1' : '0') {
        public function __construct(public string $value) {}

        public function first(): ?object {
          return $this;
        }
      });
    }

    $this->clinicStorage->load($clinicId)->willReturn($clinic->reveal());
  }

  /**
   * A node whose field_published_to names the given clinic ids.
   */
  protected function node(?array $clinicIds): NodeInterface {
    $node = $this->prophesize(NodeInterface::class);
    $node->hasField('field_published_to')->willReturn($clinicIds !== NULL);

    if ($clinicIds === NULL) {
      return $node->reveal();
    }

    $items = [];
    foreach ($clinicIds as $clinicId) {
      $item = new \stdClass();
      $item->target_id = (string) $clinicId;
      $items[] = $item;
    }

    // A plain iterable, not a prophecy of FieldItemListInterface.
    //
    // `FieldItemListInterface extends ListInterface`, and ListInterface declares
    // `getIterator()` only by inheriting it from `\Traversable`. Prophecy cannot
    // double a method an interface does not declare itself — it reflects over the
    // declaring class, and `\Traversable::getIterator()` has no implementation to
    // copy — so `$field->getIterator()` raises
    // `MethodNotFoundException: Method 'Double\FieldItemListInterface\P9::getIterator()'
    // is not defined`.
    //
    // Both call sites only do `foreach ($node->get(...))` and read `->target_id` off
    // each item, so an ArrayIterator satisfies the whole contract. The same reason the
    // clinic/user field fixtures use anonymous classes rather than prophecies.
    $node->get('field_published_to')->willReturn(new \ArrayIterator($items));

    return $node->reveal();
  }

  /**
   * Asserts the call is refused with a 403 ContentException.
   *
   * A try/catch rather than expectExceptionCode(403), because ContentException carries
   * the HTTP status in a property and getCode() is only a mirror of it. Asserting the
   * code was asserting the mirror; the controller reads getStatusCode(), so that is
   * what gets asserted here. README §4.13.
   *
   * @param callable $call
   *   The call expected to be refused.
   */
  protected function assertRefused(callable $call): void {
    try {
      $call();
      $this->fail('Expected a ContentException: an account with no clinic was admitted.');
    }
    catch (ContentException $e) {
      $this->assertSame(403, $e->getStatusCode());
    }
  }

  /**
   * An anonymous caller has no clinic and no visibility decision.
   */
  public function testAnonymousIsRefused(): void {
    $this->assertRefused(
      fn () => $this->scope->visibility('recipe', $this->account(0, [PortalRoles::PATIENT_ENROLLED]))
    );
  }

  /**
   * A signed-in account with no clinic is refused.
   *
   * The route gate already admits only portal roles, so this is the service
   * refusing to guess a scope for the 8 accounts site-wide with an empty
   * field_clinic. Falling back to "no scope means see everything" would hand the
   * whole resource library to exactly the accounts whose clinic link is broken.
   */
  public function testAccountWithoutClinicIsRefused(): void {
    $this->userClinicId = NULL;
    $this->stubUser(77);

    $this->assertRefused(
      fn () => $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ENROLLED]))
    );
  }

  /**
   * A signed-in account whose user entity is missing is refused, not defaulted.
   */
  public function testMissingUserEntityIsRefused(): void {
    $this->userStorage->load(77)->willReturn(NULL);

    $this->assertRefused(
      fn () => $this->scope->visibility('recipe', $this->account(77, [PortalRoles::PATIENT_ENROLLED]))
    );
  }

  /**
   * Recipes are clinic scoped whatever the clinic's toggle says.
   *
   * Both legacy recipes blocks filter on field_published_to containing the viewer's
   * clinic; the toggle only chooses which of the two blocks renders. Reproducing
   * the toggle as a row filter here would invent a difference the site does not
   * have.
   */
  public function testRecipesAreClinicScopedEvenWhenSharedAreVisible(): void {
    $this->userClinicId = 5;
    $this->stubUser(77);
    $this->clinicHidesShared = FALSE;
    $this->stubClinic(5);

    $visibility = $this->scope->visibility('recipe', $this->account(77, [PortalRoles::PATIENT_ENROLLED]));

    $this->assertTrue($visibility->clinicOnly);
    $this->assertSame(5, $visibility->clinicId);
    $this->assertSame('clinic', $visibility->mode());
  }

  /**
   * Training is clinic scoped with the toggle on as well.
   */
  public function testTrainingIsClinicScopedWhenSharedAreHidden(): void {
    $this->userClinicId = 5;
    $this->stubUser(77);
    $this->clinicHidesShared = TRUE;
    $this->stubClinic(5);

    $this->assertTrue($this->scope->visibility('training', $this->account(77, [PortalRoles::CHIROPRACTOR_ACTIVE]))->clinicOnly);
  }

  /**
   * Resources widen to the whole library when the clinic has not hidden shared.
   *
   * The resources View's embed_2 display is handed an empty argument, which Views
   * treats as no argument at all, so it carries no clinic filter at all.
   */
  public function testResourcesWidenWhenSharedAreVisible(): void {
    $this->userClinicId = 5;
    $this->stubUser(77);
    $this->clinicHidesShared = FALSE;
    $this->stubClinic(5);

    $visibility = $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ENROLLED]));

    $this->assertFalse($visibility->clinicOnly);
    $this->assertSame('shared', $visibility->mode());
    // The clinic is still carried: it is the reason the mode was chosen, and the
    // response echoes it back to the frontend.
    $this->assertSame(5, $visibility->clinicId);
  }

  /**
   * Resources narrow to the clinic once the toggle is on.
   *
   * 8 of 456 clinics have it set, so this is the rare path — but it is the whole
   * reason the toggle exists.
   */
  public function testResourcesAreClinicScopedWhenSharedAreHidden(): void {
    $this->userClinicId = 2;
    $this->stubUser(77);
    $this->clinicHidesShared = TRUE;
    $this->stubClinic(2);

    $visibility = $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ENROLLED]));

    $this->assertTrue($visibility->clinicOnly);
    $this->assertSame(2, $visibility->clinicId);
  }

  /**
   * A clinic entity that has never had the toggle behaves like the field default.
   *
   * The field instance defaults to 0, and 448 of 456 clinics have no value, so this
   * is the common case rather than an edge one.
   */
  public function testMissingToggleFieldReadsAsSharedVisible(): void {
    $this->userClinicId = 49;
    $this->stubUser(77);
    $this->clinicHidesShared = NULL;
    $this->stubClinic(49);

    $this->assertFalse($this->scope->hidesSharedResources(49));
    $this->assertFalse(
      $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ENROLLED]))->clinicOnly
    );
  }

  /**
   * A clinic id that no longer exists fails open, as the field default implies.
   *
   * 16 accounts site-wide point at a clinic that is gone. The legacy code would
   * fatal on `$clinic->get()` here; this keeps those accounts on the shared rows
   * rather than turning a stale reference into an error page.
   */
  public function testMissingClinicEntityFailsOpen(): void {
    $this->userClinicId = 4;
    $this->stubUser(77);
    $this->clinicExists = FALSE;
    $this->stubClinic(4);

    $this->assertFalse($this->scope->hidesSharedResources(4));
    $this->assertFalse(
      $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ENROLLED]))->clinicOnly
    );
  }

  /**
   * A node published to the caller's clinic is visible.
   */
  public function testNodePublishedToOwnClinicIsVisible(): void {
    $visibility = ContentVisibility::clinicScoped('recipe', 5);

    $this->assertTrue($this->scope->canSee($this->node([5]), $visibility));
    // Order and extra references do not matter; it is a membership test.
    $this->assertTrue($this->scope->canSee($this->node([49, 5, 2]), $visibility));
  }

  /**
   * A node published only to another clinic is hidden.
   *
   * The case that matters most: two clinics, one of which has a recipe the other
   * must never see. Published_to is a clinic reference, so this is the only thing
   * standing between them.
   */
  public function testNodePublishedToAnotherClinicIsHidden(): void {
    $visibility = ContentVisibility::clinicScoped('recipe', 5);

    $this->assertFalse($this->scope->canSee($this->node([49]), $visibility));
  }

  /**
   * A node with no clinic on it is invisible in clinic-scoped mode.
   *
   * Both legacy displays required the relationship, so a node without one matched
   * neither. Reading it as shared would publish it to every clinic.
   */
  public function testNodeWithoutAudienceIsHiddenWhenClinicScoped(): void {
    $visibility = ContentVisibility::clinicScoped('recipe', 5);

    $this->assertFalse($this->scope->canSee($this->node([]), $visibility));
    $this->assertFalse($this->scope->canSee($this->node(NULL), $visibility));
  }

  /**
   * In shared mode the audience is not consulted at all.
   *
   * Every published row matches, including one aimed at another clinic and one with
   * no audience — that is what the resources View's embed_2 does.
   */
  public function testSharedModeSeesEveryAudience(): void {
    $visibility = ContentVisibility::wholeLibrary('chirothin_resource', 5);

    $this->assertTrue($this->scope->canSee($this->node([49]), $visibility));
    $this->assertTrue($this->scope->canSee($this->node([]), $visibility));
    $this->assertTrue($this->scope->canSee($this->node(NULL), $visibility));
  }

  /**
   * The audience ids are read in stored order, deduplicated.
   */
  public function testPublishedClinicIdsAreDeduplicated(): void {
    $this->assertSame([5, 49, 2], $this->scope->publishedClinicIds($this->node([5, 49, 5, 2])));
    $this->assertSame([], $this->scope->publishedClinicIds($this->node([])));
    $this->assertSame([], $this->scope->publishedClinicIds($this->node(NULL)));
  }

  /**
   * An archived patient reads the library exactly as an enrolled one does.
   *
   * Archival is a role, not a permission on this module: both roles resolve to the
   * same clinic and the same rows. The legacy Views did restrict these displays by
   * role, which is a deliberate difference — see the README.
   */
  public function testArchivedPatientReadsLikeEnrolled(): void {
    $this->userClinicId = 5;
    $this->stubUser(77);
    $this->clinicHidesShared = FALSE;
    $this->stubClinic(5);

    $archived = $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ARCHIVED]));
    $enrolled = $this->scope->visibility('chirothin_resource', $this->account(77, [PortalRoles::PATIENT_ENROLLED]));

    $this->assertSame($enrolled->mode(), $archived->mode());
    $this->assertSame($enrolled->clinicId, $archived->clinicId);
  }

}
