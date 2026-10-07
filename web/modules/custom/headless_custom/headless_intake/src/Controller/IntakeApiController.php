<?php

declare(strict_types=1);

namespace Drupal\headless_intake\Controller;

use Drupal\contact\Entity\Message;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\headless_intake\ClinicIntakeLinkService;
use Drupal\headless_intake\IntakeInviteService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Anonymous JSON endpoints backing the headless patient intake.
 *
 * The clinic is resolved exclusively from the invite token; neither endpoint
 * accepts a clinic identifier from the request.
 *
 * Moved here from ctt_patient_intake, which now holds only the original
 * patient_intake email notification behaviour. The route *paths* are unchanged,
 * because the Next.js app calls them at fixed URLs.
 */
final class IntakeApiController implements ContainerInjectionInterface {

  /**
   * Fields the client may never set: both are server-owned.
   */
  private const SERVER_OWNED_FIELDS = [
    'field_clinic',
    'field_domain',
  ];

  /**
   * Agreement fields, whose stored text always comes from the server.
   */
  private const LEGAL_FIELDS = [
    'field_agreement',
    'field_program_agreement',
    'field_media_release_agreement',
  ];

  /**
   * Memoised allowlist of contact_message field names.
   *
   * @var array<string, bool>|null
   */
  private ?array $allowedFields = NULL;

  public function __construct(
    private readonly IntakeInviteService $invites,
    private readonly ClinicIntakeLinkService $clinicLinks,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('headless_intake.invite'),
      $container->get('headless_intake.clinic_link'),
      $container->get('config.factory'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('logger.channel.headless_intake'),
    );
  }

  /**
   * GET /api/intake/invite/{token}.
   *
   * @param string $token
   *   Invite token from the URL.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Token state, clinic brand and the resolved legal texts.
   */
  public function invite(string $token): JsonResponse {
    // Resolution is against the clinic's own permanent token. An unknown, empty
    // or malformed token is a 404 with a fixed body: the response must not
    // distinguish "no such clinic" from "no such token" from "that clinic has
    // not generated a link", because the only way to tell them apart is to
    // already know which clinics exist.
    $clinicId = $this->clinicLinks->clinicIdForToken($token);

    if ($clinicId === NULL) {
      return $this->notFound();
    }

    $body = [
      'token' => $token,
      'status' => 'active',
      'brand' => $this->invites->brandFor($clinicId),
      'legal' => [],
    ];

    foreach (self::LEGAL_FIELDS as $field) {
      $resolved = $this->invites->resolveLegalText($field, $clinicId);
      $body['legal'][$field] = $this->invites->applyTokens($resolved['text'], $clinicId);
    }

    return $this->json($body);
  }

  /**
   * The one 404 this controller returns.
   *
   * Centralised so every rejection on the public path is byte-identical. A
   * response that leaked which of the three "no" cases applied would turn the
   * intake endpoint into an oracle for guessing clinic tokens.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A 404 saying nothing about any clinic.
   */
  private function notFound(): JsonResponse {
    return $this->json(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
  }

  /**
   * A private, uncacheable JSON response.
   *
   * @param array<string, mixed> $data
   *   Body to send.
   * @param int $status
   *   HTTP status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

  /**
   * POST /api/intake/submit.
   *
   * Expected body: {"token": "...", "fields": {"field_first_name": "..."}}.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The JSON request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The stored message id, or an error payload.
   */
  public function submit(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (
      !is_array($payload)
      || !isset($payload['token'])
      || !is_string($payload['token'])
      || !isset($payload['fields'])
      || !is_array($payload['fields'])
    ) {
      return new JsonResponse(
        ['error' => 'bad_request'],
        JsonResponse::HTTP_BAD_REQUEST,
      );
    }

    $token = $payload['token'];
    $fields = $payload['fields'];
    unset($fields['field_clinic'], $fields['field_domain']);

    // Parity with the frontend TOKEN_SHAPE guard.
    if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token)) {
      return $this->notFound();
    }

    // A permanent link has no use counter and no expiry, so there is nothing to
    // consume: resolving the token is the whole authorisation. The clinic that
    // owns the link is where the submission goes.
    //
    // This runs before field validation, not after it. The order is what makes
    // "an unknown token is a 404" true of the endpoint rather than true of the
    // happy path: validating first would answer 422 for an unknown token that
    // happened to carry a bad field, so the status would depend on the payload
    // rather than on the token.
    $clinicId = $this->clinicLinks->clinicIdForToken($token);
    if ($clinicId === NULL) {
      return $this->notFound();
    }

    $issues = $this->validateFields($fields);
    if (!empty($issues)) {
      return new JsonResponse(
        ['error' => 'validation_error', 'issues' => $issues],
        JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    try {
      $message = $this->buildMessage($clinicId, $fields);
      $violations = $message->validate();
      if ($violations->count() > 0) {
        // Nothing was reserved, so there is nothing to give back: the patient
        // can correct and resubmit on the same link as often as they like.
        return new JsonResponse(
          [
            'error' => 'validation_error',
            'issues' => $this->mapViolations($violations),
          ],
          JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
        );
      }
      $message->save();
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'Intake submission failed to save for token @token: @message',
        ['@token' => $token, '@message' => $e->getMessage()],
      );
      throw $e;
    }

    return new JsonResponse([
      'id' => (int) $message->id(),
      'received' => $this->dateFormatter->format(
        (int) $message->get('created')->value,
        'custom',
        'c',
      ),
    ], JsonResponse::HTTP_OK);
  }

  /**
   * Builds the contact message for a submission.
   *
   * @param int $clinicId
   *   Clinic resolved from the token.
   * @param array<string, mixed> $fields
   *   Already validated, already allowlisted client fields.
   *
   * @return \Drupal\contact\Entity\Message
   *   Unsaved message entity.
   */
  private function buildMessage(int $clinicId, array $fields): Message {
    /** @var \Drupal\contact\Entity\Message $message */
    $message = Message::create(['contact_form' => 'patient_intake']);
    $message->set('uid', 0);
    $message->set('ip_address', $this->requestStack->getCurrentRequest()?->getClientIp());
    $message->set('field_clinic', $clinicId);

    foreach ($fields as $name => $value) {
      if ($value === NULL || $value === '') {
        continue;
      }
      $message->set($name, $value);
    }

    $message->set('subject', 'New patient intake submission');
    $message->set('message', $this->summary($fields));
    $name = trim(($fields['field_first_name'] ?? '') . ' ' . ($fields['field_last_name'] ?? ''));
    if ($name !== '') {
      $message->set('name', $name);
    }
    if (($fields['field_email_address'] ?? '') !== '') {
      $message->set('mail', (string) $fields['field_email_address']);
    }

    // The stored legal text is always the server-side resolved text, so the
    // patient consents to exactly what is stored.
    foreach (self::LEGAL_FIELDS as $field) {
      $resolved = $this->invites->resolveLegalText($field, $clinicId);
      $message->set($field, $this->invites->applyTokens($resolved['text'], $clinicId));
    }

    return $message;
  }

  /**
   * Builds the human summary stored in the message body.
   *
   * @param array<string, mixed> $fields
   *   Submitted fields.
   *
   * @return string
   *   One-line summary naming the patient.
   */
  private function summary(array $fields): string {
    $name = trim(($fields['field_first_name'] ?? '') . ' ' . ($fields['field_last_name'] ?? ''));
    $email = ($fields['field_email_address'] ?? '') !== ''
      ? (string) $fields['field_email_address']
      : NULL;
    $parts = array_filter([$name !== '' ? $name : NULL, $email]);
    $summary = 'Headless patient intake submission';
    if (!empty($parts)) {
      $summary .= ' from ' . implode(' / ', $parts);
    }
    return $summary;
  }

  /**
   * Server-side hardening, in addition to the frontend allowlist.
   *
   * Rejects unknown fields, unknown option values and over-long values.
   * Handles scalar values, multi-value arrays (checkbox grids, repeatable
   * fields) and nested address arrays.
   *
   * @param array<string, mixed> $fields
   *   Submitted fields.
   *
   * @return array<int, array{field: string, message: string}>
   *   Problems found, empty when the payload is acceptable.
   */
  private function validateFields(array $fields): array {
    $allowed = $this->allowedFieldNames();
    $issues = [];

    foreach ($fields as $name => $value) {
      if (!isset($allowed[$name])) {
        $issues[] = [
          'field' => $name,
          'message' => 'Field is not part of the intake form.',
        ];
        continue;
      }
      if ($value === NULL || $value === '') {
        continue;
      }

      $storage = $this->configFactory->get("field.storage.contact_message.$name");
      $type = (string) $storage->get('type');
      $max = $storage->get('settings.max_length');
      $max = is_int($max) && $max > 0 ? $max : NULL;
      $values = is_array($value) ? array_values($value) : [$value];
      $isEnum = $type === 'list_string' || $type === 'list_integer';
      $allowedOptions = $isEnum
        ? $this->allowedOptions($storage->get('settings.allowed_values'))
        : NULL;

      foreach ($values as $scalar) {
        if (!is_scalar($scalar) && $scalar !== NULL) {
          $issues[] = [
            'field' => $name,
            'message' => 'Field value must be scalar.',
          ];
          continue;
        }
        if ($scalar === NULL || $scalar === '') {
          continue;
        }
        $text = (string) $scalar;
        if ($isEnum && $allowedOptions !== NULL && !in_array($text, $allowedOptions, TRUE)) {
          $issues[] = [
            'field' => $name,
            'message' => 'Value is not an allowed option for this field.',
          ];
        }
        if ($max !== NULL && strlen($text) > $max) {
          $issues[] = [
            'field' => $name,
            'message' => "Value exceeds the maximum length of $max characters.",
          ];
        }
      }
    }

    return $issues;
  }

  /**
   * Normalises an allowed_values list into plain strings.
   *
   * @param mixed $raw
   *   Raw allowed_values from field storage.
   *
   * @return list<string>
   *   Allowed option values.
   */
  private function allowedOptions(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }
    return array_map(
      static fn (mixed $row): string => (string) (is_array($row) ? ($row['value'] ?? '') : $row),
      $raw,
    );
  }

  /**
   * Field names a client is allowed to submit.
   *
   * @return array<string, bool>
   *   Allowlist keyed by field name.
   */
  private function allowedFieldNames(): array {
    if ($this->allowedFields !== NULL) {
      return $this->allowedFields;
    }
    $definitions = $this->entityFieldManager
      ->getFieldDefinitions('contact_message', 'patient_intake');
    $names = [];
    foreach (array_keys($definitions) as $name) {
      if (!str_starts_with($name, 'field_')) {
        continue;
      }
      if (in_array($name, self::SERVER_OWNED_FIELDS, TRUE)) {
        continue;
      }
      $names[$name] = TRUE;
    }
    $this->allowedFields = $names;
    return $names;
  }

  /**
   * Converts entity validation violations into API issue payloads.
   *
   * @param \Drupal\Core\Entity\EntityConstraintViolationListInterface $violations
   *   Violations from the message entity.
   *
   * @return array<int, array{field: string, message: string}>
   *   Issues keyed for the client.
   */
  private function mapViolations(iterable $violations): array {
    $issues = [];
    foreach ($violations as $violation) {
      $issues[] = [
        'field' => trim((string) $violation->getPropertyPath(), '.'),
        'message' => $violation->getMessage(),
      ];
    }
    return $issues;
  }

}
