<?php

declare(strict_types=1);

namespace Drupal\headless_patients\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_intake\ClinicIntakeLinkService;
use Drupal\headless_intake\ClinicLinkAccessDeniedException;
use Drupal\headless_intake\ClinicLinkNotFoundException;
use Drupal\headless_patients\ClinicScope;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * JSON endpoints for a clinic's single permanent intake link.
 *
 * Three endpoints, matching the three states the UI has: read what is there,
 * create it if it is missing, or deliberately throw it away and make a new one.
 *
 * Lives in headless_patients rather than headless_intake even though it manages an
 * intake resource, because it resolves the clinic through ClinicScope, which belongs
 * to this module, and headless_patients already depends on headless_intake. Declaring
 * the reverse dependency too would make the two modules depend on each other, which
 * Drupal reports as a circular dependency. The token is still headless_intake's:
 * `headless_intake.clinic_link` owns storage and generation, and this controller
 * only decides who may ask about it.
 *
 * The clinic is never taken from the request. A chiropractor's clinic comes from
 * `ClinicScope`, which reads their own account, so there is no parameter a caller
 * can vary to reach another clinic. An administrator can act on any clinic, but
 * only by naming one, and only one that exists.
 *
 * Responses are deliberately thin: just the URL. The old UI wanted counts and a
 * table of every token ever issued; none of that exists in this model, and a
 * response that carries it would imply the backend still tracked it.
 */
final class ClinicIntakeLinkController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly ClinicIntakeLinkService $links,
    private readonly ClinicScope $clinicScope,
    // See the note on the same parameter in PatientsController: ControllerBase
    // already declares a non-readonly $currentUser, so a readonly property of
    // that name fatals at class load.
    private readonly AccountInterface $account,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('headless_intake.clinic_link'),
      $container->get('headless_patients.clinic_scope'),
      $container->get('current_user'),
      $container->get('logger.channel.headless_intake'),
    );
  }

  /**
   * GET /api/intake-link.
   *
   * Returns `{ "url": null }` for a clinic that has not generated one. That is
   * not an error: it is the state the "Generate my link" button is drawn for, so
   * the client needs to be able to tell "no link yet" from "something went
   * wrong" without parsing a message.
   */
  public function show(): JsonResponse {
    return $this->respond(function (): JsonResponse {
      $clinicId = $this->requireClinicId();

      return $this->json(['url' => $this->links->urlFor($clinicId)]);
    });
  }

  /**
   * POST /api/intake-link.
   *
   * Idempotent. Creates the token only when there is none; otherwise returns the
   * existing URL and writes nothing. That is what makes a double-click, a
   * retried fetch, or two tabs open at once harmless.
   */
  public function createLink(): JsonResponse {
    return $this->respond(function (): JsonResponse {
      $clinicId = $this->requireClinicId();
      $this->links->createIfMissing($clinicId);

      return $this->json(['url' => $this->links->urlFor($clinicId)]);
    });
  }

  /**
   * POST /api/intake-link/regenerate.
   *
   * Destroys the current token and issues a new one. Only valid when a link
   * already exists — regenerating something that was never created is a client
   * bug, and treating it as a create would let a mistimed double-click on the
   * wrong control destroy a working link.
   */
  public function regenerate(): JsonResponse {
    return $this->respond(function (): JsonResponse {
      $clinicId = $this->requireClinicId();

      try {
        $this->links->regenerate($clinicId);
      }
      catch (\Exception $e) {
        throw new ClinicLinkNotFoundException($e->getMessage());
      }

      // Logged separately from the service's create notice because this is the
      // one operation that invalidates a URL patients may already have printed,
      // and someone asking "who killed our link?" needs an answer.
      $this->logger->notice(
        'Regenerated the intake link for clinic @clinic by @user (@uid@). The previous URL stopped working immediately.',
        [
          '@clinic' => $clinicId,
          '@user' => $this->account->getAccountName(),
          '@uid' => $this->account->id(),
        ],
      );

      return $this->json(['url' => $this->links->urlFor($clinicId)]);
    });
  }

  /**
   * The clinic this request may act on.
   *
   * @throws \Drupal\headless_intake\ClinicLinkAccessDeniedException
   *   When the caller is anonymous, has no clinic, or named a clinic that is
   *   not theirs and is not an administrator.
   */
  private function requireClinicId(): int {
    $requested = $this->requestedClinicId();

    if ($requested !== NULL) {
      return $requested;
    }

    $own = $this->clinicScope->clinicId($this->account);
    if ($own === NULL) {
      throw new ClinicLinkAccessDeniedException(
        'Your account is not assigned to a clinic, so there is no intake link to manage.',
      );
    }

    return $own;
  }

  /**
   * The clinic an administrator explicitly asked for, if any.
   *
   * Only a request carrying the permission may name a clinic. For everyone else
   * the value is ignored entirely rather than rejected, so a chiropractor who
   * somehow has a stale frontend sending one is scoped to their own clinic
   * instead of being handed a 403 for a field they cannot influence anyway.
   *
   * @return int|null
   *   The requested clinic id, or NULL when none was validly requested.
   *
   * @throws \Drupal\headless_intake\ClinicLinkAccessDeniedException
   *   When a clinic was named that does not exist. Silently ignoring it would
   *   leave the administrator looking at a different clinic's link and believing
   *   it was the one they asked for.
   */
  private function requestedClinicId(): ?int {
    if ($this->account->isAnonymous()) {
      return NULL;
    }
    if (!$this->account->hasPermission('administer site configuration')) {
      return NULL;
    }

    $request = $this->requestStack()->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }

    $raw = $request->query->get('clinic');
    if ($raw === NULL || $raw === '' || !is_numeric($raw)) {
      return NULL;
    }

    $id = (int) $raw;
    if ($id <= 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage('clinic');
    if ($storage->load($id) === NULL) {
      throw new ClinicLinkAccessDeniedException('That clinic does not exist.');
    }

    return $id;
  }

  /**
   * Request stack, lazily.
   *
   * A private helper rather than a property, because the only caller wants the
   * current request and there is no reason to hold one across the request. It
   * cannot be a method on ControllerBase — that class has no requestStack() — so
   * this name is free; entityTypeManager() is not, and is used inherited.
   *
   * @return \Symfony\Component\HttpFoundation\RequestStack
   *   The request stack.
   */
  private function requestStack(): \Symfony\Component\HttpFoundation\RequestStack {
    return \Drupal::requestStack();
  }

  /**
   * Runs an action and reports expected failures as JSON.
   */
  private function respond(callable $action): JsonResponse {
    try {
      return $action();
    }
    // Both exceptions expose their own status rather than having it repeated at
    // the throw site and again here, which is how the two can drift apart.
    catch (ClinicLinkNotFoundException | ClinicLinkAccessDeniedException $e) {
      return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
    }
  }

  /**
   * A private, unshared JSON response.
   *
   * Uncacheable on purpose: the URL is one clinic's tenant secret and must not
   * be served to another session from a shared cache.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->setVary('Cookie', FALSE);
    return $response;
  }

}