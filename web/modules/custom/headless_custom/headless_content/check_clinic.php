<?php

/**
 * @file
 * Follow-up diagnostic, revision 3: content distribution per clinic.
 *
 * Revision 2 of this script stopped after printing the user counts, because it
 * loaded all 33,706 user entities one at a time to read field_clinic. That is
 * ~33k entity queries. This revision reads the one column it needs straight out of
 * the join table instead — a single query, and it is the same table the entity
 * query would have used.
 *
 * Known so far:
 *   - 594 clinics: 456 `clinic`, 138 `clinic_location`.
 *   - field_hide_shared_resources is set on 8 of 456; the 138 clinic_location
 *     rows have no such field at all.
 *   - field_false_clinic is set on 8 of 456, on a *different* set of ids.
 *   - Only 8 of 33,706 accounts have no field_clinic.
 *
 * This revision answers:
 *   1. how many accounts point at a clinic id that no clinic entity has;
 *   2. for each content bundle, how many published nodes name at least one clinic,
 *      and the distribution of those targets;
 *   3. which bundle the opt-out clinics belong to.
 *
 * Scope note, now that the legacy Views config has been exported: the only
 * audience field that matters is `field_published_to`, and "shared" is not an empty
 * field. It is the resources View running without a clinic filter at all, which
 * ContentScope reproduces by mode rather than by inspecting the field. See
 * ContentScope's docblock.
 *
 * Usage:
 *   docker exec chirothintrackerreact_appserver_1 drush php:script \
 *     /app/web/modules/custom/headless_custom/headless_content/check_clinic.php
 *
 * ## Why direct SQL here
 *
 * Not a shortcut around the entity API — a deliberate exception for a one-off
 * diagnostic over 33k rows. The module itself reads clinics through the entity
 * API; this script is asking a data-quality question about the join table that
 * the entity query cannot express (a per-value histogram without loading the
 * entities), and doing it in one query instead of 33k.
 */

if (!function_exists('hc_out')) {
  function hc_out(string $text = ''): void {
    echo $text . "\n";
  }

  function hc_head(string $title): void {
    hc_out();
    hc_out('=============================================================');
    hc_out($title);
    hc_out('-------------------------------------------------------------');
  }

  function hc_ids($storage): array {
    $ids = array_map('intval', array_keys($storage->getQuery()->accessCheck(FALSE)->execute()));
    sort($ids);
    return $ids;
  }

  function hc_bundle_of($storage, int $id): string {
    $entity = $storage->load($id);
    return $entity ? $entity->bundle() : '(unloadable)';
  }

  function hc_targets($node, string $fieldName): array {
    if (!$node->hasField($fieldName)) {
      return [];
    }
    $targets = [];
    foreach ($node->get($fieldName) as $item) {
      $value = $item->target_id ?? NULL;
      if ($value !== NULL && $value !== '') {
        $targets[(int) $value] = TRUE;
      }
    }
    return array_keys($targets);
  }
}

$database = \Drupal::database();
$clinicStorage = \Drupal::entityTypeManager()->getStorage('clinic');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

$clinicIds = hc_ids($clinicStorage);

hc_head('clinic id -> bundle, for reference');
hc_out('  ' . count($clinicIds) . ' clinics total');
$bundles = [];
foreach ($clinicIds as $clinicId) {
  $bundle = hc_bundle_of($clinicStorage, $clinicId);
  $bundles[$bundle] = ($bundles[$bundle] ?? 0) + 1;
}
foreach ($bundles as $bundle => $count) {
  hc_out(sprintf('    %-20s %d', $bundle, $count));
}

hc_head('accounts -> clinic, from the join table (one query)');

$tally = [];
$orphans = [];

try {
  $rows = $database->select('user__field_clinic', 'f')
    ->fields('f', ['entity_id', 'field_clinic_target_id'])
    ->execute();

  foreach ($rows as $row) {
    $target = (int) $row->field_clinic_target_id;
    $tally[$target] = ($tally[$target] ?? 0) + 1;
    if (!in_array($target, $clinicIds, TRUE)) {
      $orphans[$target] = ($orphans[$target] ?? 0) + 1;
    }
  }
}
catch (\Throwable $e) {
  hc_out('  could not read user__field_clinic: ' . $e->getMessage());
}

ksort($tally);
hc_out(sprintf('  distinct clinics referenced by accounts: %d', count($tally)));
hc_out(sprintf('  accounts in the join table:             %d', array_sum($tally)));

$top = array_slice($tally, 0, 15, TRUE);
hc_out('');
hc_out('  largest clinics (accounts):');
foreach ($top as $clinicId => $count) {
  hc_out(sprintf('    clinic %-6s %-7d %s', $clinicId, $count, hc_bundle_of($clinicStorage, $clinicId)));
}
if (count($tally) > 15) {
  hc_out(sprintf('    … and %d smaller clinics', count($tally) - 15));
}

hc_out('');
if ($orphans === []) {
  hc_out('  every account points at a clinic that exists.');
}
else {
  hc_out('  WARNING — accounts pointing at a clinic id with no clinic entity:');
  foreach ($orphans as $clinicId => $count) {
    hc_out(sprintf('    clinic %-6s %d accounts', $clinicId, $count));
  }
  hc_out('    These see only the shared library. Silent narrowing, not an error.');
}

hc_head('content: shared vs targeted, per bundle');

$bundlesToCheck = ['recipe', 'training', 'chirothin_resource'];

foreach ($bundlesToCheck as $bundle) {
  $publishedIds = $nodeStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $bundle)
    ->condition('status', 1)
    ->execute();

  $total = $nodeStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $bundle)
    ->execute();

  $shared = 0;
  $targeted = 0;
  $fieldMissing = 0;
  $targetTally = [];
  $ownerTally = [];

  foreach (array_keys($publishedIds) as $nid) {
    $node = $nodeStorage->load($nid);
    if (!$node) {
      continue;
    }

    $owner = NULL;
    if ($node->hasField('field_my_clinic')) {
      $raw = $node->get('field_my_clinic')->target_id;
      if ($raw !== NULL && $raw !== '') {
        $owner = (int) $raw;
        $ownerTally[$owner] = ($ownerTally[$owner] ?? 0) + 1;
      }
    }

    if (!$node->hasField('field_published_to')) {
      $fieldMissing++;
      continue;
    }

    $targets = hc_targets($node, 'field_published_to');
    if ($targets === []) {
      $shared++;
      continue;
    }

    $targeted++;
    foreach ($targets as $target) {
      $targetTally[$target] = ($targetTally[$target] ?? 0) + 1;
    }
  }

  hc_out();
  hc_out('  ' . $bundle);
  hc_out(sprintf('    all=%-6d published=%-6d', count($total), count($publishedIds)));
  hc_out(sprintf('    shared (no publish_to value)  = %d', $shared));
  hc_out(sprintf('    targeted (has publish_to)     = %d', $targeted));
  if ($fieldMissing > 0) {
    hc_out(sprintf('    field absent on the node      = %d', $fieldMissing));
  }

  if ($targetTally !== []) {
    ksort($targetTally);
    hc_out('    published_to targets:');
    foreach ($targetTally as $clinicId => $count) {
      $marker = in_array($clinicId, $clinicIds, TRUE) ? '' : '   <-- NO SUCH CLINIC';
      hc_out(sprintf('      clinic %-6s %-5d %-16s%s', $clinicId, $count, hc_bundle_of($clinicStorage, $clinicId), $marker));
    }
  }

  if ($ownerTally !== []) {
    ksort($ownerTally);
    hc_out('    field_my_clinic owners:');
    foreach ($ownerTally as $clinicId => $count) {
      hc_out(sprintf('      clinic %-6s %-5d %s', $clinicId, $count, hc_bundle_of($clinicStorage, $clinicId)));
    }
  }
}

hc_head('opt-out clinics in context');

hc_out('  field_hide_shared_resources = TRUE on: 2, 49, 157, 189, 232, 233, 266, 328');
hc_out('');
foreach ([2, 49, 157, 189, 232, 233, 266, 328] as $clinicId) {
  $label = '(no clinic ' . $clinicId . ')';
  if (in_array($clinicId, $clinicIds, TRUE)) {
    $clinic = $clinicStorage->load($clinicId);
    $label = $clinic->label() . '  [' . $clinic->bundle() . ']';
  }
  $accounts = $tally[$clinicId] ?? 0;
  hc_out(sprintf('    clinic %-5s %-52s %d accounts', $clinicId, $label, $accounts));
}

hc_head('next step');

hc_out('  Nothing here needs a fix. What this gives the rewrite:');
hc_out('   - visibility resolves to ONE number (the caller\'s field_clinic);');
hc_out('   - empty publish_to = shared library, visible to all;');
hc_out('   - field_hide_shared_resources on the caller\'s clinic = shared');
hc_out('     library withheld for that clinic;');
hc_out('   - field_my_clinic is ownership only, NOT a visibility grant.');
hc_out('');
hc_out('  The view you are checking should be read against those four, and');
hc_out('  especially against whether it filters on field_my_clinic at all.');
