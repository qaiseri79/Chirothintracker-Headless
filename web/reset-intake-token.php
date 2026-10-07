<?php

/**
 * Throwaway: leave clinic 13 with no intake link.
 *
 * An earlier run of probe-intake-service.php died before its restore step, so
 * clinic 13 was left holding a token the probe invented. This puts it back to the
 * state it was in before any of this work: no link.
 */

use Drupal\headless_intake\ClinicIntakeLinkService;

$clinicId = 13;
$storage = \Drupal::entityTypeManager()->getStorage('clinic');
$clinic = $storage->load($clinicId);
$field = ClinicIntakeLinkService::TOKEN_FIELD;

echo "before: ", var_export($clinic->get($field)->value, TRUE), PHP_EOL;

$clinic->set($field, NULL)->save();
$storage->resetCache();
echo "after: ", var_export($storage->load($clinicId)->get($field)->value, TRUE), PHP_EOL;