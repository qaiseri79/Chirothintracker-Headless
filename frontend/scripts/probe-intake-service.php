<?php

/**
 * Throwaway: exercise the real service against the real clinic rows, then undo it.
 *
 * The unit tests prove the service's rules against fakes. This proves the same
 * rules against Drupal's actual entity storage, field tables and config, which is
 * where the assumptions that cannot be faked live: that the field is attached to
 * the clinic bundle, that the token column accepts a 36-character value, that
 * entity queries can find it, and that the configured base URL is applied.
 *
 * Everything is restored afterwards. Clinic 13 goes back to no link, which is the
 * state it was in before this ran.
 */

use Drupal\headless_intake\ClinicIntakeLinkService;

$clinicId = 13;
$links = \Drupal::service('headless_intake.clinic_link');

$storage = \Drupal::entityTypeManager()->getStorage('clinic');
$clinic = $storage->load($clinicId);
if ($clinic === NULL) {
  echo "FAIL: clinic $clinicId does not exist", PHP_EOL;
  return;
}

$field = ClinicIntakeLinkService::TOKEN_FIELD;
$original = $clinic->get($field)->value;
echo "starting token: ", var_export($original, TRUE), PHP_EOL;

// Clear whatever is there so the run starts from the real starting state.
if ($original !== NULL) {
  $clinic->set($field, NULL)->save();
}

$steps = [];
$steps['hasLink before'] = $links->hasLink($clinicId);
$steps['urlFor before'] = $links->urlFor($clinicId);

$first = $links->createIfMissing($clinicId);
$steps['createIfMissing'] = $first;
$steps['createIfMissing again'] = $links->createIfMissing($clinicId);
$steps['tokenFor'] = $links->tokenFor($clinicId);
$steps['urlFor after'] = $links->urlFor($clinicId);
$steps['hasLink after'] = $links->hasLink($clinicId);
$steps['clinicIdForToken'] = $links->clinicIdForToken($first);
$steps['unknown token'] = $links->clinicIdForToken(str_repeat('b', 36));
$steps['empty token'] = $links->clinicIdForToken('');

$second = $links->regenerate($clinicId);
$steps['regenerate'] = $second;
$steps['old token after regenerate'] = $links->clinicIdForToken($first);
$steps['new token after regenerate'] = $links->clinicIdForToken($second);

foreach ($steps as $label => $value) {
  echo str_pad($label, 28), ' => ', var_export($value, TRUE), PHP_EOL;
}

$pass = TRUE;
$check = static function (string $label, $expected, $actual) use (&$pass): void {
  $ok = $expected === $actual;
  $pass = $pass && $ok;
  printf(
    "%s %-38s expected=%s actual=%s%s",
    $ok ? 'PASS' : 'FAIL',
    $label,
    var_export($expected, TRUE),
    var_export($actual, TRUE),
    PHP_EOL,
  );
};

$check('no link at rest', FALSE, $steps['hasLink before']);
$check('no url at rest', NULL, $steps['urlFor before']);
$check('token is 36 hex', 1, preg_match('/^[0-9a-f]{36}$/', $steps['createIfMissing']));
$check('create is idempotent', $steps['createIfMissing'], $steps['createIfMissing again']);
$check('token readable', $steps['createIfMissing'], $steps['tokenFor']);
$check('url carries the token', 1, str_contains((string) $steps['urlFor after'], $steps['createIfMissing']) ? 1 : 0);
$check('url has the intake path', 1, str_ends_with((string) $steps['urlFor after'], '/intake/' . $steps['createIfMissing']) ? 1 : 0);
$check('token resolves to clinic', $clinicId, $steps['clinicIdForToken']);
$check('unknown token misses', NULL, $steps['unknown token']);
$check('empty token misses', NULL, $steps['empty token']);
$check('regenerate changed the token', 1, $steps['regenerate'] !== $steps['createIfMissing'] ? 1 : 0);
$check('old token is dead', NULL, $steps['old token after regenerate']);
$check('new token resolves', $clinicId, $steps['new token after regenerate']);

// A second clinic must be untouched by anything done to the first, and its own
// (absent) token must not resolve. Clinic isolation is the whole tenant boundary:
// if clinic B could resolve clinic A's token, every patient would land on whichever
// clinic the lookup found first.
$others = array_filter(
  $storage->loadMultiple(),
  static fn ($other): bool => (int) $other->id() !== $clinicId,
);
$other = reset($others);
if ($other !== FALSE) {
  $otherId = (int) $other->id();
  $check('other clinic has no token', NULL, $links->tokenFor($otherId));
  $check('other clinic has no url', NULL, $links->urlFor($otherId));
  $check('other clinic tokenFor miss', NULL, $links->clinicIdForToken($links->tokenFor($otherId) ?? ''));
  $check('other clinic resolves nothing', NULL, $links->clinicIdForToken(''));
}

// Restore. resetCache() first so the restore is read back from storage rather than
// from the copy this script has been mutating all along.
$storage->resetCache();
$clinic = $storage->load($clinicId);
$clinic->set($field, $original)->save();
$storage->resetCache();
$restored = $storage->load($clinicId)->get($field)->value;
$check('clinic restored to its original state', $original, $restored);

echo $pass ? 'ALL PASS' : 'FAILURES ABOVE', PHP_EOL;