<?php

/**
 * Throwaway: exercise the public intake controller against real storage.
 *
 * The controller is built through its own container factory, so the real services
 * and the real config are in play — entity storage, field storage, the configured
 * base URL, the legal text resolution. Only the HTTP kernel is bypassed: calling
 * `$kernel->handle()` repeatedly inside one drush process leaves the request
 * attributes half-populated, and the controller resolver then fails with "not
 * callable" for a route that demonstrably matches and demonstrably has a callable
 * on it. Route matching itself is covered separately by probe-routes.php.
 *
 * Submissions are deleted and the clinic's link restored afterwards.
 */

use Drupal\Core\Http\RequestStack;
use Drupal\headless_intake\ClinicIntakeLinkService;
use Drupal\headless_intake\Controller\IntakeApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$clinicId = 13;
$container = \Drupal::getContainer();
$controller = IntakeApiController::create($container);
$links = $container->get('headless_intake.clinic_link');

$messageStorage = $container->get('entity_type.manager')->getStorage('contact_message');
$clinicStorage = $container->get('entity_type.manager')->getStorage('clinic');
$field = ClinicIntakeLinkService::TOKEN_FIELD;

$original = $clinicStorage->load($clinicId)->get($field)->value;
$created = [];

$failures = [];
$report = static function (string $label, $expected, $actual) use (&$failures): void {
  $ok = $expected === $actual;
  if (!$ok) {
    $failures[] = $label;
  }
  printf(
    "%s %-52s expected=%s actual=%s%s",
    $ok ? 'PASS' : 'FAIL',
    $label,
    var_export($expected, TRUE),
    var_export($actual, TRUE),
    PHP_EOL,
  );
};

$invite = static fn (string $token): Response => $controller->invite($token);

$submit = static function (string $token, array $fields) use ($container, $controller, &$created): Response {
  $request = Request::create('/api/intake/submit', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
  ], json_encode(['token' => $token, 'fields' => $fields]));

  // A real request carries a session, and saving a contact message reaches for it
  // (session-based token/anonymous tracking). Without one, save() throws "Session
  // has not been set" and the submission never gets as far as the database, which
  // would make this probe fail for a reason that has nothing to do with the link.
  $request->setSession(new Session(new MockArraySessionStorage()));

  // The controller reads the client IP off the request stack, so the request has to
  // be pushed there or the field is set from NULL.
  $stack = $container->get('request_stack');
  $stack->push($request);
  try {
    return $controller->submit($request);
  }
  finally {
    $stack->pop();
  }
};

$body = static fn (Response $response) => json_decode((string) $response->getContent(), TRUE);

// Start from no link.
$clinicStorage->resetCache();
$clinicStorage->load($clinicId)->set($field, NULL)->save();

foreach ([
  'unknown 36-char token' => str_repeat('c', 36),
  'short token' => 'abc123',
  'long token' => str_repeat('c', 200),
  'url-safe but not hex' => str_repeat('Z', 36),
] as $label => $bad) {
  $response = $invite($bad);
  $report("invite $label -> 404", 404, $response->getStatusCode());
  $report("invite $label -> fixed body", ['error' => 'not_found'], $body($response));
}

// Generate the real link and use it.
$token = $links->createIfMissing($clinicId);

$response = $invite($token);
$report('invite with a live token -> 200', 200, $response->getStatusCode());
$report('invite reports the token', $token, $body($response)['token'] ?? NULL);
$report('invite reports active', 'active', $body($response)['status'] ?? NULL);
$report('invite carries brand', TRUE, array_key_exists('brand', $body($response)));
$report('invite carries legal text', TRUE, ($body($response)['legal'] ?? []) !== []);
$report('invite is not shared-cached', TRUE, str_contains((string) $response->headers->get('Cache-Control'), 'no-store'));

$fields = [
  'field_first_name' => 'Harness',
  'field_last_name' => 'Probe',
  'field_email_address' => 'harness.probe@example.com',
  'field_consent' => 1,
  'field_gender' => 'M',
  'field_mailing_address' => [
    'country_code' => 'US',
    'address_line1' => '123 Test St',
    'locality' => 'Testville',
    'administrative_area' => 'TX',
    'postal_code' => '12345',
  ],
  'field_phone_number' => '5551234567',
  'field_program_start_date' => date('Y-m-d'),
  'field_weight' => 180,
];

$response = $submit($token, $fields);
$report('submit with a live token -> 200', 200, $response->getStatusCode());
if ($response->getStatusCode() !== 200) {
  echo '  body: ', $response->getContent(), PHP_EOL;
}
$id = $body($response)['id'] ?? NULL;
$report('submit returns an id', TRUE, is_int($id));
if (is_int($id)) {
  $created[] = $id;
  $message = $messageStorage->load($id);
  $report('submission is attached to the link owner', $clinicId, (int) $message->get('field_clinic')->target_id);
  $report('legal text was stored server-side', TRUE, $message->get('field_agreement')->value !== '');
}

// The whole point of a permanent link: the second patient is not refused.
$response = $submit($token, $fields);
$report('a second submission on the same link -> 200', 200, $response->getStatusCode());
if (is_int($body($response)['id'] ?? NULL)) {
  $created[] = $body($response)['id'];
}

// Regenerating invalidates the old token immediately.
$links->regenerate($clinicId);
$response = $invite($token);
$report('old token after regenerate -> 404', 404, $response->getStatusCode());
$response = $submit($token, $fields);
$report('old token submit after regenerate -> 404', 404, $response->getStatusCode());
$response = $submit($token, $fields);
$report('old token submit body is the fixed one', ['error' => 'not_found'], $body($response));

$newToken = $links->tokenFor($clinicId);
$report('new token -> 200', 200, $invite($newToken)->getStatusCode());

// Field validation still applies on a live token.
$response = $submit($newToken, ['field_first_name' => 'x', 'field_not_a_real_field' => 'y']);
$report('unknown field -> 422', 422, $response->getStatusCode());
$report('unknown field names itself', 'field_not_a_real_field', $body($response)['issues'][0]['field'] ?? NULL);

// An unknown token carrying bad fields is a 404, not a 422: the token decides, not
// the payload.
$response = $submit(str_repeat('d', 36), ['field_not_a_real_field' => 'y']);
$report('unknown token with a bad field -> 404', 404, $response->getStatusCode());

// A bad token shape is a 404 too, even with a perfect payload.
$response = $submit('nope', $fields);
$report('malformed token -> 404', 404, $response->getStatusCode());

// Restore.
foreach ($created as $id) {
  $message = $messageStorage->load($id);
  if ($message !== NULL) {
    $message->delete();
  }
}
$clinicStorage->resetCache();
$clinicStorage->load($clinicId)->set($field, $original)->save();

echo $failures === [] ? 'ALL PASS' : 'FAILURES: ' . implode(' | ', $failures), PHP_EOL;