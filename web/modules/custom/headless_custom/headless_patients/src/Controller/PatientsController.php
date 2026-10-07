<?php

declare(strict_types=1);

namespace Drupal\headless_patients\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\headless_patients\ClinicScope;
use Drupal\headless_patients\Exception\PatientsException;
use Drupal\headless_patients\PatientIntakeService;
use Drupal\headless_patients\PatientPhaseMap;
use Drupal\headless_patients\PatientsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoints for the chiropractor Patients roster.
 *
 * This class is only plumbing: it resolves the clinic, hands off to a service,
 * and turns the one expected-failure type into a status code. The rules about
 * what is a valid clinic, a valid patient and a valid payload all live in the
 * services, so they are testable without a request and there is one answer per
 * question rather than one per endpoint.
 *
 * The response from {@see self::snapshot()} is exactly the PatientsSnapshot type
 * the frontend declares: active, archived, intake, intakeLink, enrolledCount and
 * intakeNewCount. The two counts come from the server because deriving them in
 * the client meant counting a filtered list, which quietly disagrees with the
 * total once a filter is applied. The separate list routes exist so one tab can
 * refresh without refetching the other three, not because the page needs four
 * round trips.
 */
class PatientsController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly PatientsService $patients,
    private readonly PatientIntakeService $intake,
    private readonly ClinicScope $clinicScope,
    private readonly PatientPhaseMap $phaseMap,
    // Named $account, not $currentUser. ControllerBase declares a non-readonly
    // $currentUser, so promoting a readonly property under that name is a fatal
    // "Cannot redeclare ... as readonly" at class load, which surfaces as a
    // white screen on every route in the module rather than as a failure of the
    // one line that caused it.
    private readonly AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('headless_patients.patients'),
      $container->get('headless_patients.intake'),
      $container->get('headless_patients.clinic_scope'),
      $container->get('headless_patients.phase_map'),
      $container->get('current_user'),
    );
  }

  /**
   * The whole roster in one response, for the page's first render.
   */
  public function snapshot(?Request $request = NULL): JsonResponse {
    return $this->respond(function () use ($request): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      // Sidebar badges use the same review-queue count without loading the roster.
      if ($request?->query->getBoolean('intake_count')) {
        return $this->json(['intakeNewCount' => $this->intake->countNew($clinicId)]);
      }
      // One partition() call, not active() and archived() separately: both wrap
      // it, and the page renders both tabs at once.
      $roster = $this->patients->partition($clinicId);
      $enrolled = $this->patients->enrolledCount($clinicId);

      return $this->json([
        'active' => $roster['active'],
        'archived' => $roster['archived'],
        'intake' => $this->intake->list($clinicId),
        'intakeLink' => $this->intake->intakeLink($clinicId),
        // The header pill and the intake tab's "new" badge both need a count,
        // and the frontend derives them from the arrays it already has. Sending
        // them costs two cheap queries and keeps the two sides from disagreeing
        // about a count the client computed differently.
        'enrolledCount' => $enrolled,
        'enrollmentAllowance' => $this->patients->enrollmentAllowance($clinicId, $enrolled),
        'intakeNewCount' => $this->intake->countNew($clinicId),
        // The clinic's own locations, for the Add Patient form and the archived
        // re-enrollment step. Server-side and clinic-scoped, so the dropdown offers
        // exactly the ids the backend will accept rather than a hardcoded list that
        // has to be kept in step with it.
        'clinicLocations' => $this->patients->clinicLocations($clinicId),
      ]);
    });
  }

  /**
   * Archived patients only.
   */
  public function archived(): JsonResponse {
    return $this->respond(function (): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      return $this->json(['archived' => $this->patients->archived($clinicId)]);
    });
  }

  /**
   * Creates an enrolled patient in the caller's clinic.
   *
   * Named createPatient rather than create because ContainerInjectionInterface
   * already claims create() for the service factory. A method here cannot be
   * both, and the factory is not optional: it is how the five services below get
   * in.
   */
  public function createPatient(Request $request): JsonResponse {
    return $this->respond(function () use ($request): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      $result = $this->patients->create(
        $clinicId,
        $this->payload($request),
        (int) $this->account->id(),
      );

      // 201: a new patient exists, and the location is the roster it was added to.
      //
      // `warning` is a soft-quota notice rather than an error, so it rides along
      // on the success response instead of becoming a second request. The keys
      // are spread in rather than nested so `patient` stays exactly where an
      // existing client looks for it.
      return $this->json([
        'patient' => $result['patient'],
        'warning' => $result['warning'],
      ], 201);
    });
  }

  /**
   * Archives a patient: the same role swap the Drupal form performs.
   */
  public function archive(int $user): JsonResponse {
    return $this->respond(function () use ($user): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      return $this->json(['patient' => $this->patients->archive($clinicId, $user)]);
    });
  }

  /**
   * Re-enrols an archived patient.
   *
   * Deliberately not the create endpoint: re-enrolling changes two roles on an
   * account that already exists and must already exist, so it takes a user id and
   * has no form behind it. It shares create's enrollment-limit check, because it
   * consumes a slot from the same cap.
   *
   * Takes a request body for one optional field, `clinicLocation`. Re-enrolling
   * does not otherwise need any input — the account already has a name, an email
   * and a program start — but the location is the one thing a patient coming back
   * may need to change, and only a chiropractor should decide which branch they
   * return to. An absent or empty body keeps the existing location.
   */
  public function enroll(int $user, Request $request): JsonResponse {
    return $this->respond(function () use ($user, $request): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      $result = $this->patients->enroll($clinicId, $user, $this->optionalPayload($request));

      return $this->json([
        'patient' => $result['patient'],
        'warning' => $result['warning'],
      ]);
    });
  }

  /**
   * The clinic's intake submissions.
   */
  public function intakeList(): JsonResponse {
    return $this->respond(function (): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      return $this->json(['intake' => $this->intake->list($clinicId)]);
    });
  }

  /**
   * One intake submission in full.
   */
  public function intakeGet(int $message): JsonResponse {
    return $this->respond(function () use ($message): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      return $this->json(['intake' => $this->intake->get($clinicId, $message)]);
    });
  }

  /**
   * Deletes one intake submission.
   *
   * Same path as {@see self::intakeGet()} with a different method, because a
   * submission is one resource: the proxy mirrors the Drupal route rather than
   * inventing a separate delete URL that could fall out of step with it.
   *
   * Write-gated rather than read-gated even though it takes no body. Destroying a
   * submission is the same class of act as creating a patient, so it belongs with
   * `WriteChiropractorAccess` and not with the reads above.
   */
  public function intakeDelete(int $message): JsonResponse {
    return $this->respond(function () use ($message): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      $this->intake->delete($clinicId, $message);
      return $this->json(['id' => $message, 'deleted' => TRUE]);
    });
  }

  /**
   * Sets the review state of one or more intake submissions.
   *
   * Accepts `status` and `ids`, so the roster's single-row toggle and its bulk
   * "mark checked" button are the same request with a different array length.
   */
  public function intakeReview(Request $request): JsonResponse {
    return $this->respond(function () use ($request): JsonResponse {
      $clinicId = $this->clinicScope->requireClinicId($this->account);
      $payload = $this->payload($request);

      $ids = $payload['ids'] ?? [];
      if (!is_array($ids)) {
        throw PatientsException::invalid(['ids' => 'Send a list of submission ids.']);
      }

      $status = $payload['status'] ?? NULL;
      if (!is_string($status)) {
        throw PatientsException::invalid(['status' => 'Send a review state.']);
      }

      // The actor is the authenticated chiropractor, never anything in the body:
      // a flagging has to belong to a user, so it is both the audit trail and the
      // thing that would throw if it were anonymous.
      return $this->json($this->intake->setReview(
        $clinicId,
        $ids,
        $status,
        (int) $this->account->id()
      ));
    });
  }

  /**
   * The phase options, for the Add-patient form's select.
   *
   * Served from the endpoint rather than hard-coded in the frontend so the codes
   * in the form cannot drift away from the ones {@see PatientPhaseMap} writes.
   */
  public function phases(): JsonResponse {
    return $this->respond(fn (): JsonResponse => $this->json(['phases' => $this->phaseMap->all()]));
  }

  /**
   * Decodes a JSON body, refusing anything that is not a JSON object.
   *
   * @return array<string, mixed>
   *   The decoded body.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the body is absent, malformed, or a JSON array or scalar.
   */
  private function payload(Request $request): array {
    $content = trim($request->getContent());
    if ($content === '') {
      throw PatientsException::invalid(['body' => 'Send a JSON body.']);
    }

    return $this->decodePayload($content);
  }

  /**
   * A JSON object body that is allowed to be absent.
   *
   * For endpoints that take no meaningful input — enrolling an archived patient is a
   * role swap, and the body only carries the optional clinic location. Such an
   * endpoint used to ignore the body entirely, so rejecting an empty one would be a
   * regression for any client that never sent one. A body that *is* present is still
   * decoded and shape-checked, because a malformed one is a client bug worth
   * reporting rather than silently treating as "no location".
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array<string, mixed>
   *   The decoded body, or an empty array when there is none.
   */
  private function optionalPayload(Request $request): array {
    $content = trim($request->getContent());
    if ($content === '') {
      return [];
    }

    return $this->decodePayload($content);
  }

  /**
   * Decodes a request body that is known to be present.
   *
   * @param string $content
   *   The raw body.
   *
   * @return array<string, mixed>
   *   The decoded object.
   *
   * @throws \Drupal\headless_patients\Exception\PatientsException
   *   When the body is not valid JSON or is not a JSON object.
   */
  private function decodePayload(string $content): array {
    $decoded = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw PatientsException::invalid(['body' => 'The body is not valid JSON.']);
    }
    if (!is_array($decoded) || (($decoded !== []) && array_is_list($decoded))) {
      throw PatientsException::invalid(['body' => 'Send a JSON object.']);
    }

    return $decoded;
  }

  /**
   * Runs an action and reports any expected failure as JSON.
   *
   * One place decides how a PatientsException becomes a response, so every
   * endpoint reports the same shape: `error` for the message, and `errors` keyed
   * by field when the caller can fix individual inputs.
   */
  private function respond(callable $action): JsonResponse {
    try {
      return $action();
    }
    catch (PatientsException $e) {
      $body = ['error' => $e->getMessage()];
      if ($e->getErrors() !== []) {
        $body['errors'] = $e->getErrors();
      }
      return $this->json($body, $e->getStatusCode());
    }
  }

  /**
   * A private, unshared JSON response.
   *
   * CacheableJsonResponse with a zero TTL is not used on purpose: a roster is
   * per-user and no two chiropractors should ever be served the same object.
   * Making it explicitly uncacheable also means a later refactor cannot
   * accidentally introduce a shared cache entry by dropping a class name.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    // setVary($headers, FALSE) appends. add() was the wrong method entirely — it
    // takes a single array of headers, not a key/value/replace triple — and the
    // replace flag matters: Drupal and Symfony can already have put values in
    // Vary (Accept-Encoding, User-Agent), and overwriting them would make the
    // response look more cacheable than it is rather than less.
    $response->setVary('Cookie', FALSE);
    return $response;
  }
}
