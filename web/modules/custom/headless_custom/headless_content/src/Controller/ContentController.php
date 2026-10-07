<?php

declare(strict_types=1);

namespace Drupal\headless_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_content\ContentScope;
use Drupal\headless_content\ContentService;
use Drupal\headless_content\Exception\ContentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * JSON endpoints for the content library.
 *
 * Only plumbing: resolve the caller's visibility, hand off to ContentService, and
 * turn a failure into a status code. Every rule about what is visible lives in
 * ContentScope and ContentService so it is testable without a request and there is
 * one answer per question.
 *
 * ## Read-only apart from the star and the chiropractor creates
 *
 * There is no update or delete route, and content can only *appear* here in two
 * ways: the favourite toggle (per-caller state, see below) and a chiropractor
 * adding a recipe, resource or training item of their own. Everything else is
 * authored in the Drupal admin UI, which is a different surface from this API.
 * See the routing file's header.
 *
 * The one write here is the
 *
 * @see \Drupal\headless_content\ContentService for the list and item shapes.
 */
class ContentController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly ContentService $content,
    private readonly ContentScope $scope,
    // Named $account, not $currentUser. ControllerBase declares a non-readonly
    // $currentUser, so promoting a readonly property under that name is a fatal
    // "Cannot redeclare ... as readonly" at class load, which surfaces as a white
    // screen on every route in the module rather than as a failure of the one
    // line that caused it.
    private readonly AccountInterface $account,
    // Injected, and not reached for through ControllerBase. That base class gets
    // `getLogger()` from LoggerChannelTrait, not `logger()`, so the catch block in
    // respond() was calling a method that does not exist — the error path was itself
    // the fatal, in production as much as in a test. Same reasoning as the comment on
    // headless_content.content in the services file: a static container call here is a
    // hidden dependency no unit test can substitute, and this is precisely the one
    // place a test most wants to reach.
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('headless_content.content'),
      $container->get('headless_content.scope'),
      $container->get('current_user'),
      $container->get('logger.channel.headless_content'),
    );
  }

  /**
   * The library for one bundle, one page of it.
   *
   * `$page` and `$page_size` are read from the query string here, and that is not
   * incidental — it is the only way they can arrive at all.
   *
   * They are declared as route *defaults* in the routing file, with `defaults` for the
   * values and `requirements: '\d+'`. Both are inert. Symfony resolves controller
   * arguments from request **attributes** via RequestAttributeValueResolver, and a
   * query string does not populate attributes: attributes come from the path and from
   * route defaults, and nothing else. A `requirements` entry constrains a path
   * placeholder, and these are not placeholders, so the regex is never evaluated
   * either.
   *
   * Measured against the live router before this was fixed:
   *
   *     GET /api/headless/content/recipe?page=3&page_size=200
   *     matched page      = '1'
   *     matched page_size = '50'
   *
   * So every request returned page one at the default size, a client paging through
   * the library received the same 50 rows N times, and nothing reported a fault: each
   * response was well-formed and its `pagination.pageCount` was truthful. It surfaced
   * only as duplicated rows in the frontend. Reading the query directly is the fix;
   * keeping the route defaults as well costs nothing and documents the values.
   *
   * A value that is present but not an integer is a 400
   * ({@see ContentException::invalidPaging()}), as is a readable value below 1
   * ({@see ContentException::pagingOutOfRange()}). An absent one is not an error, and
   * an oversized one is not either — {@see ContentService::normalisePaging()} owns the
   * second decision and this method defers to it. `page=0` is deliberately *not*
   * clamped here: answering a request that named no page with page one is what let a
   * 0-indexed client fetch page one twice.
   *
   * These are the only parameters a caller has. There is deliberately no clinic,
   * author or category parameter, because `field_published_to` is the tenant boundary
   * and a client-supplied clinic would replace it.
   */
  public function list(Request $request, string $bundle): JsonResponse {
    return $this->respond($request, 'list', fn (): JsonResponse => $this->json(
      $this->content->listBundle(
        $bundle,
        $this->scope->visibility($bundle, $this->account),
        $this->account->getRoles(),
        $this->pagingValue($request, 'page', 1),
        $this->pagingValue($request, 'page_size', ContentService::DEFAULT_PAGE_SIZE),
      )
    ));
  }

  /**
   * One paging parameter from the query string, or the default.
   *
   * @param int $default
   *   Used when the parameter is absent. Deliberately the same value the route
   *   declares, so the two cannot drift.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the parameter is present but not a plain integer.
   */
  private function pagingValue(Request $request, string $name, int $default): int {
    // Read from all() rather than get(). InputBag::get() throws
    // BadRequestException when a parameter is non-scalar — `?page[]=3` — which is a
    // 400 by nature, but it arrives as a Throwable from inside an action and would be
    // reported as a 500 by respond()'s catch-all, telling the caller its request was
    // the server's fault. all() hands back the raw value instead, so the same case is
    // judged by the same rule as `?page=abc` and gets the same 400.
    $given = $request->query->all()[$name] ?? NULL;

    // Absent is not an error: a caller who does not care about paging gets one page.
    if ($given === NULL || $given === '') {
      return $default;
    }

    // Not a scalar at all, so there is nothing to check digits on.
    if (!is_scalar($given)) {
      throw ContentException::invalidPaging($name, 'a non-scalar value');
    }

    // A query parameter is always a string, so this is a cast and a validation in one.
    // Rejecting rather than clamping is what distinguishes "you sent me nonsense" from
    // "you sent me a value I will adjust", which is the rule normalisePaging() then
    // applies. Kept as an explicit pattern so nothing is coerced that was not an integer
    // to begin with — a float, a leading +, or '1e3' are all refused rather than quietly
    // becoming something the caller did not ask for.
    if (!preg_match('/^\d+$/', (string) $given)) {
      throw ContentException::invalidPaging($name, (string) $given);
    }

    return (int) $given;
  }

  /**
   * One item.
   */
  public function get(Request $request, string $bundle, int $node): JsonResponse {
    return $this->respond($request, 'get', fn (): JsonResponse => $this->json(
      $this->content->get($bundle, $node, $this->scope->visibility($bundle, $this->account))
    ));
  }

  /**
   * Flags or unflags a node and returns the state afterwards.
   *
   * The body is optional. `{"favourite": true}` and `{"favourite": false}` are
   * idempotent — posting either twice is a no-op, so a client that retries after a
   * dropped response does not invert the star. An absent body flips whatever the
   * state is now, which is what a plain star button wants.
   *
   * A body is read as a single allowlisted key, not decoded and cast blindly:
   * `favourite` arrives as a bool from JSON and as a string from a form-encoded
   * post, and both have to mean the same thing. Anything else in the body is ignored
   * rather than rejected, so adding a field later does not break an older frontend.
   */
  public function favourite(Request $request, string $bundle, int $node): JsonResponse {
    return $this->respond($request, 'favourite', fn (): JsonResponse => $this->json(
      $this->content->setFavourite(
        $bundle,
        $node,
        $this->scope->visibility($bundle, $this->account),
        $this->wantedFavourite($request->getContent()),
      )
    ));
  }

  /**
   * The option lists a create form needs.
   *
   * Read-gated like every other read: the payload is taxonomy labels, the same
   * ones the library rows already carry, so there is nothing narrower about it.
   * The doctor might be the only caller who *asks*, but a patient asking is not a
   * leak.
   */
  public function options(Request $request, string $bundle): JsonResponse {
    return $this->respond($request, 'options', fn (): JsonResponse => $this->json(
      $this->content->options($bundle)
    ));
  }

  /**
   * Adds a recipe, resource or training item in the caller's own clinic.
   *
   * The route is gated by `WriteChiropractorAccess` (active chiropractors only),
   * not `PortalMemberAccess`: this is the one write that changes a node, so it
   * is the one route that must be narrower than the read library. The body keys
   * are bundle-specific (see ContentService::create()) — `title` always, then
   * e.g. `categories`/`types`/`ingredients`/`body` for a recipe, `body`/`video`
   * for training, `resourceTypes`/`description`/`resource` for a resource.
   * Anything else — in particular `field_published_to` and `field_my_clinic` —
   * is ignored in ContentService::create(), because the audience is derived,
   * never supplied.
   *
   * Named createContent rather than create: `ControllerBase::create()` is the
   * static container factory every Drupal controller inherits, and PHP treats a
   * redeclaration as fatal, not as an override.
   */
  public function createContent(Request $request, string $bundle): JsonResponse {
    return $this->respond($request, 'create', fn (): JsonResponse => $this->json(
      $this->content->create(
        $bundle,
        $this->account,
        $this->createPayload($request->getContent()),
        $this->scope->visibility($bundle, $this->account),
      ),
      201,
    ));
  }

  /**
   * Stores a chiropractor-uploaded content file and returns its file id.
   *
   * The write that precedes {@see self::createContent()} for a resource or
   * training item with a file: the upload route is gated by the same
   * `WriteChiropractorAccess` as the create route, and the returned `fid` is
   * what a subsequent create payload names as `resource` or `video`. The body is
   * the raw file bytes in a multipart `files[attachment]` part, the same
   * contract as the messages attachment upload. Which allowlist and size cap
   * apply is the bundle's — resources take the 20 MB document union, training
   * the MP4-only 100 MB rules of its `field_video`.
   */
  public function upload(Request $request, string $bundle): JsonResponse {
    return $this->respond($request, 'upload', function () use ($request, $bundle): JsonResponse {
      $file = $this->uploadedResourceFile($request);

      return $this->json(
        $this->content->storeUploadedFile(
          $file->getClientOriginalName(),
          file_get_contents($file->getPathname()) ?: '',
          $bundle,
        ),
        201,
      );
    });
  }

  /**
   * The uploaded file from a multipart `files[attachment]` part.
   *
   * Kept inside the respond() callback so an invalid upload is caught by the same
   * 400-handling as every other ContentException rather than escaping the action.
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the request has no usable file part.
   */
  private function uploadedResourceFile(Request $request): \Symfony\Component\HttpFoundation\File\UploadedFile {
    $files = $request->files->get('files', []);
    $file = $files['attachment'] ?? NULL;

    if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
      throw ContentException::invalidCreate('No valid file uploaded.');
    }

    return $file;
  }

  /**
   * The decoded request body, narrowed to what create can use.
   *
   * @param string $payload
   *   Raw request body.
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the body is not a JSON object at all.
   */
  private function createPayload(string $payload): array {
    $decoded = json_decode($payload, TRUE);
    if (!is_array($decoded)) {
      throw ContentException::invalidCreateBody();
    }

    return $decoded;
  }

  /**
   * The state the caller asked for, or NULL to flip.
   *
   * @param string $payload
   *   Raw request body. Empty for a body-less post, which is a flip.
   *
   * @return bool|null
   *   NULL means "flip". TRUE/FALSE means "make it so".
   *
   * @throws \Drupal\headless_content\Exception\ContentException
   *   400 when the body is not JSON at all, or carries `favourite` in a shape that
   *   is not recognisably a boolean.
   */
  private function wantedFavourite(string $payload): ?bool {
    if (trim($payload) === '') {
      return NULL;
    }

    $decoded = json_decode($payload, TRUE);
    if (!is_array($decoded)) {
      throw ContentException::invalidFavouriteBody();
    }

    if (!array_key_exists('favourite', $decoded)) {
      // A body that is valid JSON but says nothing about favourites. Same meaning
      // as no body: flip. Rejecting would make a client that always sends its whole
      // item object fail on a field it had not heard of.
      return NULL;
    }

    return match ($decoded['favourite']) {
      TRUE, 1, '1', 'true' => TRUE,
      FALSE, 0, '0', 'false' => FALSE,
      default => throw ContentException::invalidFavouriteBody(),
    };
  }

  /**
   * Runs an action and reports any failure as JSON.
   *
   * A ContentException becomes its status code. Anything else is a bug, and is
   * logged with the caller and the path before being reported as a bare 500: the
   * detail has to be in watchdog, because the JSON body deliberately carries none
   * and a 500 that renders as a generic error page is what made the progress
   * dashboard take a full debugging round to diagnose.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request, for the path in the log line. Passed in rather than read
   *   from `$this->request`, which nothing ever assigns: Symfony resolves the request
   *   as a controller *argument*, and Drupal's ControllerBase does not declare the
   *   property. Reading it was a warning on every unexpected error, and an unhandled
   *   one outside a request altogether.
   */
  private function respond(Request $request, string $action, callable $callback): JsonResponse {
    try {
      return $callback();
    }
    catch (ContentException $e) {
      return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
    }
    catch (Throwable $e) {
      $this->logger->error(
        'Content @action failed for uid @uid at @path: @message (@class: @line)',
        [
          '@action' => $action,
          '@uid' => (int) $this->account->id(),
          '@path' => $request->getPathInfo(),
          '@message' => $e->getMessage(),
          // README §3.2: class and line, because a bare message costs a round trip.
          '@class' => get_class($e),
          '@line' => $e->getLine(),
        ]
      );

      // "Could not be read" would be wrong on the favourite route, which writes.
      // Deliberately says nothing about why: the detail is in watchdog, and this
      // body reaches the frontend as something loggable rather than as a rendered
      // error page.
      return $this->json(['error' => 'The content library request could not be completed.'], 500);
    }
  }

  /**
   * A private, unshared JSON response.
   *
   * Explicitly uncacheable rather than CacheableJsonResponse, for the reason in
   * PatientsController::json(): the response is per-caller and must not be
   * possible to share, and making that a property of the code rather than of a
   * class name means a later refactor cannot introduce a shared cache entry by
   * swapping the class.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    // setVary($headers, FALSE) appends, and the replace flag matters: Drupal and
    // Symfony have already put values in Vary, and overwriting them would make
    // the response look more cacheable than it is rather than less.
    $response->setVary('Cookie', FALSE);
    return $response;
  }

}
