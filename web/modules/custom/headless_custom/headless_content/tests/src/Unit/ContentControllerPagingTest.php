<?php

declare(strict_types=1);

namespace Drupal\headless_content;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_content\Controller\ContentController;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that the controller reads paging from the query string.
 *
 * These exist for a fault that was live in the endpoint and invisible from every other
 * test in the module. `list()` took `int $page` and `int $page_size` as arguments, and
 * Symfony resolves those from request *attributes* — which a query string does not
 * populate. `page` and `page_size` were declared as route defaults, and a `defaults`
 * value is an attribute, so the caller's numbers were discarded on the way in and the
 * controller received `page=1, page_size=50` whatever was asked for.
 *
 * Verified against the live router before the fix:
 *
 *     GET /api/headless/content/recipe?page=3&page_size=200
 *     matched page      = '1'
 *     matched page_size = '50'
 *
 * Every request returned page one. A client paging through the library got the same 50
 * rows N times. Nothing reported a fault at any layer: every response was individually
 * well-formed, `pagination.pageCount` was truthful, and the status was 200 throughout.
 * It surfaced as duplicate React keys in the recipes page, which is a symptom a long way
 * from its cause.
 *
 * {@see \Drupal\headless_content\ContentPaginationTest} could not catch it. It tests
 * `normalisePaging()`, which decides what a paging value *means*, and never checks that
 * the value arrives. Those are separate obligations and the second was untested.
 *
 * No catch-all `listBundle()` prophecy is registered in setUp(), and none of these
 * tests registers more than one. Both are deliberate: Prophecy keys its method
 * prophecies by method *name* only — `ObjectProphecy::getMethodProphecy()` returns the
 * first `MethodProphecy` for a name regardless of arguments — so a second registration
 * for `listBundle()` returns the first object and its `willReturn()` overwrites the
 * previous promise rather than adding a case. A test that wants to observe the page it
 * was asked for registers one callback which echoes that page back, instead of
 * registering one expectation per page.
 *
 * Two further Prophecy details this file works around, both of which fail confusingly:
 *
 * - `Argument::cetera()` is only valid as the *last* token. Placed earlier it scores the
 *   whole match 0, so nothing matches, the double returns NULL, and the controller
 *   fatals inside `json(null)` — surfacing as `Call to undefined method
 *   ContentController::logger()`, because `respond()` catches the TypeError and tries to
 *   log it, which needs a container this unit test does not have. Positional
 *   `Argument::any()` is used throughout instead.
 * - An unmatched call on a revealed double returns NULL rather than raising, so a typo in
 *   a matcher reads as a 500 in the controller under test instead of a test failure.
 * - A `will()` callback is rebound to the doubled object, so `$this` inside it is the
 *   double. Test state has to be captured with `use (&$local)`.
 *
 * All three were found the hard way, and all three surface as a 500 from
 * `ContentController::respond()` rather than as a test failure — its catch-all swallows
 * the cause and returns a bare error body. Reading the logged line
 * (`@message`/`@class`/`@line`) is the only way through, which is the second reason the
 * controller now takes an injected logger.
 *
 *
 * @group headless_content
 * @coversDefaultClass \Drupal\headless_content\Controller\ContentController
 */
class ContentControllerPagingTest extends TestCase {

  use ProphecyTrait;

  protected ObjectProphecy $content;

  protected ObjectProphecy $scope;

  protected ObjectProphecy $account;

  /**
   * The page and page size the service was actually called with, in order.
   *
   * @var array<int, array{0: int, 1: int}>
   */
  protected array $askedFor = [];

  protected function setUp(): void {
    parent::setUp();

    $this->content = $this->prophesize(ContentService::class);
    $this->scope = $this->prophesize(ContentScope::class);
    $this->account = $this->prophesize(AccountInterface::class);

    $this->scope->visibility(Argument::any(), Argument::any())->willReturn(
      ContentVisibility::wholeLibrary('recipe', 31)
    );
    $this->account->getRoles()->willReturn(['enrolled_patient']);
    // Only read by respond()'s catch-all. Stubbed so that a test which unexpectedly
    // reaches it reports the real cause instead of an UnexpectedCallException on id(),
    // which is the second error standing between a failure and its explanation.
    $this->account->id()->willReturn(963);
  }

  protected function controller(): ContentController {
    return new ContentController(
      $this->content->reveal(),
      $this->scope->reveal(),
      $this->account->reveal(),
      $this->prophesize(LoggerChannelInterface::class)->reveal(),
    );
  }

  /**
   * A request built the way a real one is, so the query bag is a real InputBag.
   */
  protected function request(string $query = ''): Request {
    return Request::create('/api/headless/content/recipe' . $query, 'GET');
  }

  /**
   * Answer any `listBundle()` call with a page that echoes the page it was asked for.
   *
   * One registration, and the body is derived from the real arguments, so a controller
   * that ignored the query string would visibly answer page one every time. That is the
   * original fault reproduced as a test: the failure mode is *not* an error or a crash
   * but a well-formed page-one response, so the only way to see it is to compare what
   * was asked against what came back.
   */
  protected function echoPageBack(): void {
    // Bound by reference, not via $this. Prophecy rebinds a will() callback to the
    // doubled object, so inside the closure $this is the ContentService double, not
    // this test. Reaching for $this->askedFor from there is an undefined property on a
    // double, which the controller reports as a 500 — a genuinely baffling way to learn
    // about the rebinding.
    $askedFor = &$this->askedFor;

    $this->content
      ->listBundle(Argument::any(), Argument::any(), Argument::any(), Argument::type('int'), Argument::type('int'))
      ->will(function (array $args) use (&$askedFor): array {
        $page = (int) $args[3];
        $pageSize = (int) $args[4];

        $askedFor[] = ['page' => $page, 'pageSize' => $pageSize];

        return [
          'bundle' => 'recipe',
          'label' => 'Recipes',
          'visibility' => 'shared',
          'items' => [],
          'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'pageCount' => max(1, (int) ceil(634 / max(1, $pageSize))),
            'totalItems' => 634,
          ],
          'capabilities' => [],
        ];
      });
  }

  /**
   * The decoded response body.
   */
  protected function bodyOf(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * The caller's page number and page size reach the service.
   *
   * The regression test. Before the fix the service was asked for 1 and 50 whatever the
   * query said.
   */
  public function testQueryStringPageAndSizeReachTheService(): void {
    $this->echoPageBack();

    $this->controller()->list($this->request('?page=3&page_size=200'), 'recipe');

    $this->assertSame([['page' => 3, 'pageSize' => 200]], $this->askedFor);
  }

  /**
   * ...and the response is built from that page, not from a default.
   *
   * Separate from the argument assertion because "the service was called correctly" and
   * "the caller can see which page it got" are different promises, and the second is what
   * a client concatenating responses relies on.
   */
  public function testResponseReportsTheRequestedPage(): void {
    $this->echoPageBack();

    $response = $this->controller()->list($this->request('?page=3&page_size=200'), 'recipe');
    $body = $this->bodyOf($response);

    $this->assertSame(3, $body['pagination']['page']);
    $this->assertSame(200, $body['pagination']['pageSize']);
    $this->assertSame(4, $body['pagination']['pageCount']);
    $this->assertSame(634, $body['pagination']['totalItems']);
  }

  /**
   * The parameter is `page_size`, and the name is load-bearing.
   *
   * `?pageSize=200` is not an alias for `?page_size=200`. It arrives, it is not a
   * recognised name, and it is ignored — which is a different answer from a 400 and a
   * worse one, because the caller gets a short list rather than an error. Asserted so
   * nobody "fixes" a client by swapping the name in the other direction.
   */
  public function testPageSizeIsTheRecognisedName(): void {
    $this->echoPageBack();

    $this->controller()->list($this->request('?pageSize=200'), 'recipe');

    $this->assertSame(
      [['page' => 1, 'pageSize' => ContentService::DEFAULT_PAGE_SIZE]],
      $this->askedFor,
      'pageSize is not an alias; the default page size must be used instead.',
    );
  }

  /**
   * No paging parameters at all means one default page.
   *
   * A caller who does not care about paging must not have to send anything.
   */
  public function testAbsentPagingUsesTheDefaults(): void {
    $this->echoPageBack();

    $this->controller()->list($this->request(), 'recipe');

    $this->assertSame([['page' => 1, 'pageSize' => ContentService::DEFAULT_PAGE_SIZE]], $this->askedFor);
  }

  /**
   * An empty value is absent, not nonsense.
   *
   * `?page=` is what a form serialisation of an empty number input sends, and a client
   * building a URL by concatenation produces it by accident.
   */
  public function testEmptyPagingValueUsesTheDefaults(): void {
    $this->echoPageBack();

    $this->controller()->list($this->request('?page=&page_size='), 'recipe');

    $this->assertSame([['page' => 1, 'pageSize' => ContentService::DEFAULT_PAGE_SIZE]], $this->askedFor);
  }

  /**
   * A value that is present and unreadable is a 400, and the service is never reached.
   *
   * Distinct from a clampable value on purpose. `page=0` is a caller asking for
   * something the service knows how to adjust; `page=abc` is a caller and a server
   * disagreeing about the protocol, and answering it with page one would look like
   * success.
   */
  public function testUnreadablePagingValueIsRejected(): void {
    $this->content->listBundle(Argument::cetera())->shouldNotBeCalled();

    $response = $this->controller()->list($this->request('?page=abc'), 'recipe');

    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('page', $this->bodyOf($response)['error']);
  }

  /**
   * The rejection covers the shapes that a loose cast would silently accept.
   *
   * Each of these becomes a number under `(int)` or `intval()` and quietly asks for a
   * different page than the caller named. `1e3` is the sharpest of them: it is not a
   * whole number in the textual sense, and PHP's numeric-string cast accepts it.
   *
   * @param string $query
   *   The query string to send.
   *
   * @dataProvider unreadablePagingValues
   */
  public function testNoUnreadableShapeIsSilentlyCoerced(string $query): void {
    $this->content->listBundle(Argument::cetera())->shouldNotBeCalled();

    $response = $this->controller()->list($this->request($query), 'recipe');

    $this->assertSame(
      400,
      $response->getStatusCode(),
      "Expected $query to be rejected rather than coerced.",
    );
  }

  /**
   * @return array<string, array{string}>
   */
  public static function unreadablePagingValues(): array {
    return [
      'letters' => ['?page=abc'],
      'float' => ['?page=1.5'],
      'exponent' => ['?page=1e3'],
      'negative' => ['?page=-1'],
      'leading plus' => ['?page=%2B3'],
      'hex' => ['?page=0x10'],
      'whitespace' => ['?page=%203'],
      'trailing text' => ['?page=3abc'],
      'underscored size' => ['?page_size=1_0'],
      'page size letters' => ['?page_size=lots'],
    ];
  }

  /**
   * An oversized value reaches the service raw; the service decides.
   *
   * `page_size=99999` is legal to send and wrong to honour, so it is clamped by
   * normalisePaging() rather than refused by the controller. The controller's job is
   * to read the number; deciding what an out-of-range one means belongs to the
   * service, so that there is one answer per question. Asserting the raw 99999
   * reaches the service is what keeps the two responsibilities apart.
   *
   * `page=0` used to ride along in this assertion as a clampable value. It is not
   * clampable and this test no longer claims it is — see
   * {@see self::testControllerPassesZeroThroughForTheServiceToRefuse()}.
   */
  public function testOversizedValuesArePassedThroughNotRejected(): void {
    $this->echoPageBack();

    $response = $this->controller()->list($this->request('?page=2&page_size=99999'), 'recipe');

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([['page' => 2, 'pageSize' => 99999]], $this->askedFor);
  }

  /**
   * `page=0` is read successfully and handed to the service unaltered.
   *
   * The controller is not where out-of-range becomes an error, and this pins that
   * split. `0` is a readable whole number, so {@see ContentController::pagingValue()}
   * has nothing to object to; normalisePaging() then refuses it with a 400, because
   * pages are 1-based and the old clamp to page one is what let a 0-indexed client
   * fetch page one twice.
   *
   * The value reaching the service raw is the assertion. Whether it is a 400 is
   * ContentPaginationTest's to decide.
   */
  public function testControllerPassesZeroThroughForTheServiceToRefuse(): void {
    $this->echoPageBack();

    $response = $this->controller()->list($this->request('?page=0'), 'recipe');

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([['page' => 0, 'pageSize' => 50]], $this->askedFor);
  }

  /**
   * Four distinct pages, and each one says which page it is.
   *
   * The end-to-end statement of the bug. With 634 recipes and a 200-row page a client
   * walks four requests; before the fix all four returned page one, so a client that
   * concatenated them got 200 rows of the wrong recipes and four copies of the same
   * React keys.
   *
   * Asserted on the `page` echoed back in each response rather than only on the call
   * arguments, because the echoed page is what a client can actually see.
   */
  public function testFourRequestsReturnFourDistinctPages(): void {
    $this->echoPageBack();

    $pageSize = ContentService::MAX_PAGE_SIZE;
    $pageCount = (int) ceil(634 / $pageSize);
    $this->assertSame(4, $pageCount);

    $reported = [];
    for ($page = 1; $page <= $pageCount; $page++) {
      $response = $this->controller()->list(
        $this->request("?page=$page&page_size=$pageSize"),
        'recipe'
      );
      $reported[] = $this->bodyOf($response)['pagination']['page'];
    }

    $this->assertSame([1, 2, 3, 4], $reported, 'Each request must return a different page, not four copies of the first.');
    $this->assertSame(
      [
        ['page' => 1, 'pageSize' => 200],
        ['page' => 2, 'pageSize' => 200],
        ['page' => 3, 'pageSize' => 200],
        ['page' => 4, 'pageSize' => 200],
      ],
      $this->askedFor,
    );
  }

  /**
   * A non-scalar paging value is a 400 too, not a 500.
   *
   * `?page[]=3` is the case that made `InputBag::get()` unusable here: it throws
   * `BadRequestException`, which arrives inside an action and is caught by respond()'s
   * catch-all, so the caller was told 500 — its request was reported as the server's
   * fault. Reading `all()` and judging the raw value puts it under the same rule as
   * `?page=abc`. Asserted because the fix is a one-line change to a different InputBag
   * method, which is easy to revert without noticing.
   */
  public function testArrayShapedPagingValueIsRejected(): void {
    $this->content->listBundle(Argument::any())->shouldNotBeCalled();

    $response = $this->controller()->list($this->request('?page[]=3'), 'recipe');

    $this->assertSame(400, $response->getStatusCode());
    $this->assertStringContainsString('page', $this->bodyOf($response)['error']);
  }

  /**
   * The default page size is still reachable without asking for it.
   *
   * The routing file documents `page_size: '50'` and pagingValue() mirrors it. If one
   * moves and the other does not, they disagree silently, so the pair is asserted from
   * the controller side as well.
   */
  public function testDefaultPageSizeMatchesTheDocumentedRouteDefault(): void {
    $this->assertSame(50, ContentService::DEFAULT_PAGE_SIZE);
    $this->assertGreaterThan(0, ContentService::MAX_PAGE_SIZE);
  }

  /**
   * The bundle allowlist is not enforced here, and should not start being.
   *
   * `bundle` is a genuine path placeholder, so the routing file's
   * `bundle: 'recipe|training|chirothin_resource'` requirement *does* constrain it — the
   * router 404s an unknown bundle before a controller is ever resolved. That is the whole
   * enforcement and it is the right layer: the same mechanism that turned out to be
   * inert for the non-placeholder `page` works here, precisely because it is in the path.
   *
   * Asserted so that a future redundant check inside `list()` is a visible change to a
   * test rather than a silent one. If such a check is ever wanted — a programmatic
   * caller, a sub-request that bypasses routing — it should be added deliberately, with
   * its own test, rather than appearing as a side effect of editing the paging code.
   */
  public function testBundleIsNotRevalidatedInTheController(): void {
    $this->echoPageBack();

    $this->controller()->list($this->request('?page=1'), 'not_a_bundle');

    $this->assertCount(1, $this->askedFor, 'The controller must not add a bundle check of its own.');
  }

}
