<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\headless_content\Exception\ContentException;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ContentFavorites — the `favorites` flag wrapper.
 *
 * The role list lives in ContentCapabilities as a frontend hint; this class is
 * authoritative and asks the flag. The tests below are the ones where those two
 * could disagree in a way that leaks or breaks a button: a caller with no
 * permission must not be able to write a flagging, a bundle the flag does not cover
 * must fail as "cannot be favourited" rather than as a 403, and a repeated toggle
 * must not invert the star.
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\ContentFavorites
 */
class ContentFavoritesTest extends TestCase {

  use ProphecyTrait;

  protected ObjectProphecy $flagService;

  protected ObjectProphecy $flag;

  protected ObjectProphecy $account;

  /**
   * Bundles the flag is configured on, per the prophecy.
   *
   * @var array<int, string>
   */
  protected array $flagBundles = ['recipe'];

  /**
   * Whether the flag entity resolves at all.
   */
  protected bool $flagExists = TRUE;

  /**
   * Whether `flag` / `unflag` action access is granted.
   */
  protected bool $actionAllowed = TRUE;

  /**
   * The mutable state the Prophecy callbacks above read.
   *
   * The public properties are mirrors of the three fixture properties above, kept in
   * step by {@see self::applyState()}, which setUp() calls and every test that changes
   * a fixture calls again. The indirection exists because a Prophecy callback cannot
   * read `$this` — see the note in setUp().
   *
   * @var object{bundles: array<int, string>, flagExists: bool, actionAllowed: bool, flag: ObjectProphecy|null}
   */
  protected object $state;

  protected ContentFavorites $favourites;

  protected function setUp(): void {
    parent::setUp();

    $this->flagService = $this->prophesize(FlagServiceInterface::class);
    $this->flag = $this->prophesize(FlagInterface::class);
    $this->account = $this->prophesize(AccountInterface::class);

    // Shared mutable state for the Prophecy callbacks, reached through `use`.
    //
    // Prophecy's CallbackPromise rebinds a closure to the prophecy object before
    // calling it, but only if the closure has a `$this` at all:
    //
    //   if ($callback instanceof Closure && (new ReflectionFunction($callback))
    //       ->getClosureThis() !== null) {
    //     $callback = Closure::bind($callback, $object);
    //   }
    //
    // A non-static closure declared inside a test method has a `$this`, so `$this`
    // inside `will(function () { return $this->flagBundles; })` is the
    // `Double\FlagServiceInterface\P3` double rather than the test. Reading a test
    // property from there fails with "Undefined property: Double\…::$flagExists",
    // which names the double and not the line at fault.
    //
    // A **static** closure has no `$this`, so nothing is rebound and `use`d variables
    // survive intact. The state is a plain object rather than test properties
    // precisely so a test can flip `$this->actionAllowed` and have the already-stubbed
    // callback observe it — the alternative is re-stubbing in every test.
    //
    // Same class of trap as README §6.1: the signature you stub is not necessarily
    // the signature that runs.
    $state = new class {
      /** @var array<int, string> */
      public array $bundles = ['recipe'];

      public bool $flagExists = TRUE;

      public bool $actionAllowed = TRUE;

      public ?ObjectProphecy $flag = NULL;
    };
    $state->flag = $this->flag;
    $this->state = $state;
    $this->applyState();

    $this->account->isAuthenticated()->willReturn(TRUE);

    $this->flag->getBundles()->will(static function (array $args) use ($state): array {
      return $state->bundles;
    });
    // Both actions are consulted, so a grant on either permits the write. The
    // trailing wildcard matters: `actionAccess($action, $account, $flaggable)` is
    // called with the node as the third argument, and a stub pinned to two arguments
    // does not match a three-argument call — it returns NULL, and NULL->isAllowed()
    // is a fatal error rather than a test failure, which is a miserable way to
    // discover it.
    $this->flag->actionAccess('flag', Argument::cetera())->will(static function (array $args) use ($state): AccessResultInterface {
      return self::accessResultFor($state->actionAllowed);
    });
    $this->flag->actionAccess('unflag', Argument::cetera())->will(static function (array $args) use ($state): AccessResultInterface {
      return self::accessResultFor($state->actionAllowed);
    });

    $this->flagService->getFlagById(ContentFavorites::FLAG_ID)->will(static function (array $args) use ($state): ?FlagInterface {
      return $state->flagExists ? $state->flag?->reveal() : NULL;
    });
    // Empty by default: no favourites unless a test stubs them.
    $this->flagService->getFlagUserFlaggings($this->flag->reveal(), $this->account->reveal())
      ->willReturn([]);

    $this->favourites = new ContentFavorites(
      $this->flagService->reveal(),
      $this->account->reveal(),
    );
  }

  /**
   * Copies the fixture properties onto the state object the callbacks read.
   *
   * Called once by setUp() and again by every test that changes a fixture, after the
   * change. The alternative — re-stubbing `getBundles()` per test — reads better but
   * re-registers a method prophecy that was already registered, and Prophecy keeps
   * both, so the *last* registered one wins unpredictably against the order tests run
   * in. Mutating one shared object has no such ambiguity.
   */
  protected function applyState(): void {
    $this->state->bundles = $this->flagBundles;
    $this->state->flagExists = $this->flagExists;
    $this->state->actionAllowed = $this->actionAllowed;
  }

  /**
   * An AccessResultInterface carrying the given verdict.
   *
   * Core's `AccessResult::allowed()` / `::forbidden()` rather than a hand-rolled
   * stand-in. It is a real implementation of the interface, so it satisfies
   * `orIf`/`andIf` and the rest without this test having to restate them — and
   * `isAllowed()` is the only method ContentFavorites actually calls, so nothing else
   * matters here.
   *
   * Static because the Prophecy callbacks above cannot reach `$this`. See the note in
   * setUp() and on self::$state.
   */
  protected static function accessResultFor(bool $allowed): AccessResultInterface {
    return $allowed ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * The AccessResult stand-in: only isAllowed() is called.
   */
  protected function accessResult(): AccessResultInterface {
    return self::accessResultFor($this->actionAllowed);
  }

  /**
   * A node of the given bundle and id.
   */
  protected function node(string $bundle, int $nid): NodeInterface {
    $node = $this->prophesize(NodeInterface::class);
    $node->bundle()->willReturn($bundle);
    $node->id()->willReturn((string) $nid);

    return $node->reveal();
  }

  /**
   * Flaggings for the given node ids, so favouriteIds() has something to intersect.
   *
   * @param array<int, int> $entityIds
   */
  protected function stubFlaggings(array $entityIds): void {
    $flaggings = [];
    foreach ($entityIds as $entityId) {
      $flagging = $this->prophesize(FlaggingInterface::class);
      $flagging->get('entity_id')->willReturn(new class ((string) $entityId) {

        public function __construct(public string $value) {}

      });
      $flaggings[] = $flagging->reveal();
    }

    $this->flagService->getFlagUserFlaggings($this->flag->reveal(), $this->account->reveal())
      ->willReturn($flaggings);
  }

  /**
   * The flag applies to recipes and to nothing else.
   *
   * Mirrors `flag.flag.favorites.yml` `bundles: [recipe]`. An empty list would mean
   * "all bundles" per FlagInterface, which is not the configured state.
   */
  public function testAppliesToRecipeOnly(): void {
    $this->assertTrue($this->favourites->appliesTo('recipe'));
    $this->assertFalse($this->favourites->appliesTo('training'));
    $this->assertFalse($this->favourites->appliesTo('chirothin_resource'));
  }

  /**
   * An empty bundle list means every bundle is applicable.
   *
   * FlagInterface::getBundles() documents an empty array as "all bundles are valid",
   * so this branch exists in ContentFavorites and is worth pinning: it is the
   * difference between "the flag was reconfigured site-wide" and "the flag is
   * missing".
   */
  public function testEmptyBundleListAppliesToEverything(): void {
    $this->flagBundles = [];
    $this->applyState();

    $this->assertTrue($this->favourites->appliesTo('recipe'));
    $this->assertTrue($this->favourites->appliesTo('training'));
    $this->assertTrue($this->favourites->appliesTo('chirothin_resource'));
  }

  /**
   * A missing flag entity refuses rather than fatally on a null.
   */
  public function testMissingFlagRefusesEverything(): void {
    $this->flagExists = FALSE;
    $this->applyState();

    $this->assertFalse($this->favourites->appliesTo('recipe'));
    $this->assertFalse($this->favourites->canFlag($this->node('recipe', 1)));
    $this->assertSame([], $this->favourites->favouriteIds([1, 2]));
  }

  /**
   * A caller with the flag permission may write.
   */
  public function testPermittedCallerCanFlag(): void {
    $this->assertTrue($this->favourites->canFlag($this->node('recipe', 1)));
  }

  /**
   * A caller without the flag permission is refused, and no flagging is written.
   *
   * The `flag()` call is asserted never to happen, not just that the return value is
   * false. A refusal that still writes is the failure that matters.
   */
  public function testUnpermittedCallerIsRefusedAndWritesNothing(): void {
    $this->actionAllowed = FALSE;
    $this->applyState();

    $this->assertFalse($this->favourites->canFlag($this->node('recipe', 1)));

    $this->flagService->flag(Argument::cetera())->shouldNotBeCalled();
    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();
  }

  /**
   * A role without the permission gets a 403, not a silent success.
   */
  public function testSetRefusesUnpermittedCallerWith403(): void {
    $this->actionAllowed = FALSE;
    $this->applyState();

    // Asserts on the status rather than through expectExceptionCode(): the code is a
    // second copy rather than the source, and getStatusCode() is what the controller
    // reads. `testExceptionCodeMatchesStatus` pins that the two agree — see README §4.13.
    try {
      $this->favourites->set($this->node('recipe', 1));
      $this->fail('Expected a ContentException for an account without flag favorites.');
    }
    catch (ContentException $e) {
      $this->assertSame(403, $e->getStatusCode());
    }
  }

  /**
   * The exception code agrees with the status.
   *
   * Pinned because it was 0 for a while and nothing in the controller noticed: the
   * controller reads getStatusCode(), so a test using expectExceptionCode() was the
   * only thing that would have caught it. RuntimeException's third argument is the
   * code, and the constructor passed only a message.
   */
  public function testExceptionCodeMatchesStatus(): void {
    try {
      $this->favourites->set($this->node('training', 1));
      $this->fail('Expected a ContentException for a bundle the flag does not cover.');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
      $this->assertSame(400, $e->getCode());
    }
  }

  /**
   * A bundle the flag does not cover is a 400, not a 403.
   *
   * The distinction is the point: the caller *could* favourite things, this thing
   * just cannot be favourited. A 403 here would tell an enrolled patient their
   * account is wrong when their account is fine.
   */
  public function testSetRefusesUnsupportedBundleWith400(): void {
    try {
      $this->favourites->set($this->node('training', 1));
      $this->fail('Expected a ContentException for a bundle the flag does not cover.');
    }
    catch (ContentException $e) {
      $this->assertSame(400, $e->getStatusCode());
    }

    $this->flagService->flag(\Prophecy\Argument::cetera())->shouldNotBeCalled();
  }

  /**
   * An anonymous caller cannot favourite.
   *
   * Unreachable through the route gate today, but the service holds `current_user`
   * directly and a future route could be token-addressed.
   */
  public function testAnonymousCannotFlag(): void {
    $this->account->isAuthenticated()->willReturn(FALSE);

    $this->assertFalse($this->favourites->canFlag($this->node('recipe', 1)));
  }

  /**
   * The caller's favourites come back as node id => TRUE.
   */
  public function testFavouriteIdsReadsFlaggings(): void {
    $this->stubFlaggings([7, 9]);

    $this->assertSame([7 => TRUE, 9 => TRUE], $this->favourites->favouriteIds([7, 8, 9]));
  }

  /**
   * Only the requested ids come back.
   *
   * A caller who has favourited something on another page must not have it appear in
   * this page's answer: the caller of favouriteIds() asked about specific rows.
   */
  public function testFavouriteIdsIntersectsTheRequest(): void {
    $this->stubFlaggings([7, 9, 400]);

    $this->assertSame([7 => TRUE], $this->favourites->favouriteIds([7]));
  }

  /**
   * Node ids arrive as strings from entity storage and come back as int keys.
   *
   * `$node->id()` is a string in Drupal, so a strict in_array() against int keys
   * would find nothing. This is the shape the serialiser depends on.
   */
  public function testFavouriteIdsCoercesStringIds(): void {
    $this->stubFlaggings([7]);

    $this->assertSame([7 => TRUE], $this->favourites->favouriteIds(['7']));
    $this->assertTrue($this->favourites->isFavourite($this->node('recipe', 7)));
    $this->assertFalse($this->favourites->isFavourite($this->node('recipe', 8)));
  }

  /**
   * Flipping an unfavourited node writes a flagging.
   */
  public function testToggleFromUnfavouritedFlags(): void {
    $node = $this->node('recipe', 5);

    $this->flagService->flag($this->flag->reveal(), $node, $this->account->reveal())
      ->shouldBeCalledOnce()
      ->willReturn(NULL);
    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();

    $this->assertTrue($this->favourites->set($node));
  }

  /**
   * Flipping a favourited node removes the flagging.
   */
  public function testToggleFromFavouritedUnflags(): void {
    $this->stubFlaggings([5]);
    $node = $this->node('recipe', 5);

    $this->flagService->unflag($this->flag->reveal(), $node, $this->account->reveal())
      ->shouldBeCalledOnce()
      ->willReturn(NULL);
    $this->flagService->flag(Argument::cetera())->shouldNotBeCalled();

    $this->assertFalse($this->favourites->set($node));
  }

  /**
   * An explicit state is idempotent, so a retried POST does not invert the star.
   *
   * This is why `set()` takes an optional target rather than only flipping: the flag
   * module throws a LogicException when asked to flag something already flagged, and
   * a client retrying after a dropped response is an ordinary thing to do.
   */
  public function testExplicitStateIsIdempotent(): void {
    $node = $this->node('recipe', 5);
    $this->flagService->flag(Argument::cetera())->shouldNotBeCalled();
    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();

    // Already flagged, so asking for TRUE is a no-op.
    $this->stubFlaggings([5]);
    $this->assertTrue($this->favourites->set($node, TRUE));

    // Not flagged, so asking for FALSE is a no-op. The stub has to be re-declared
    // rather than reused: `getFlagUserFlaggings()` keeps answering with what it was
    // told, so without this the node still reads as flagged and asking for FALSE is a
    // real unflag. That would make this test pass for the wrong reason on a second run
    // and fail for the right one — a test whose correctness depends on how many times
    // it has been called is not a test.
    $this->stubFlaggings([]);
    $this->assertFalse($this->favourites->set($node, FALSE));
  }

  /**
   * Repeating the same toggle intent does not invert the state.
   *
   * The bug this prevents: a client that POSTs with no body to mean "toggle", gets
   * a response it never sees, retries, and the star is now off.
   */
  public function testRepeatedExplicitToggleDoesNotInvert(): void {
    $this->stubFlaggings([5]);
    $node = $this->node('recipe', 5);

    // Both calls want it flagged. The first is a no-op because it already is; the
    // second must not unflag it.
    $this->assertTrue($this->favourites->set($node, TRUE));
    $this->assertTrue($this->favourites->set($node, TRUE));

    $this->flagService->unflag(Argument::cetera())->shouldNotBeCalled();
  }

}