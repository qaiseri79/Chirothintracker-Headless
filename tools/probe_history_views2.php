<?php

/**
 * Probe: dump the raw config of the views that could be the patient's
 * log-history table. Reads the config arrays directly (no plugin
 * instantiation), so it cannot fail on a display that fails to build.
 *
 * Run: drush php:script /app/tools/probe_history_views2.php
 */

$candidates = [
  'page_patient_details_cs',
  'patient_download',
  'patient_download_weight_submissions_',
  'my_intake_form',
  'patient_home_buttons',
  'intake_forms_cs',
];

foreach ($candidates as $id) {
  $config = \Drupal::configFactory()->get('views.view.' . $id);
  if ($config->isNew()) {
    print "=== $id : NOT FOUND ===" . PHP_EOL . PHP_EOL;
    continue;
  }

  print '=== ' . $id . ' ===' . PHP_EOL;
  print '  label: ' . $config->get('label') . PHP_EOL;
  print '  base:  ' . $config->get('base.table') . PHP_EOL;
  print '  tag:   ' . $config->get('tag') . PHP_EOL;
  print PHP_EOL;

  foreach ((array) $config->get('display') as $did => $display) {
    if (!is_array($display)) {
      continue;
    }
    $opts = $display['display_options'] ?? [];
    $plugin = $display['display_plugin'] ?? '?';
    $title = $display['display_title'] ?: '-';

    print '  -- display "' . $did . '"  type=' . $plugin . '  title=' . $title . PHP_EOL;

    if (!empty($opts['path'])) {
      print '     path:     ' . $opts['path'] . PHP_EOL;
    }
    $access = $opts['access']['type'] ?? 'none';
    $access_detail = $opts['access'][$access] ?? [];
    unset($access_detail['id'], $access_detail['type'], $access_detail['label'], $access_detail['weight'], $access_detail['provider']);
    print '     access:   ' . $access . ' ' . json_encode($access_detail) . PHP_EOL;

    foreach ((array) ($opts['arguments'] ?? []) as $arg) {
      if (!empty($arg['id'])) {
        print '     arg:      ' . $arg['id']
          . ' default=' . json_encode($arg['default_argument'] ?? NULL) . PHP_EOL;
      }
    }

    $fields = $opts['fields'] ?? [];
    if ($fields) {
      print '     fields (' . count($fields) . '):' . PHP_EOL;
      foreach ($fields as $field => $cfg) {
        $label = $cfg['label'] ?? '';
        if (!is_string($label) || $label === '' || $label === 'Hide') {
          $label = $field;
        }
        if (($cfg['exclude'] ?? FALSE) && $field !== 'node_bulk_form') {
          $label .= '  (excluded)';
        }
        print '       - ' . str_pad($label, 30) . '  (' . $field . ')' . PHP_EOL;
      }
    }

    $filters = $opts['filters'] ?? [];
    if ($filters) {
      print '     filters:' . PHP_EOL;
      foreach ($filters as $field => $cfg) {
        $extra = [];
        foreach (['value', 'operator', 'condition_type', 'entity_type', 'argument'] as $k) {
          if (isset($cfg[$k]) && $cfg[$k] !== '') {
            $extra[] = $k . '=' . json_encode($cfg[$k]);
          }
        }
        print '       - ' . str_pad($field, 30) . '  ' . str_pad($cfg['plugin_id'] ?? '?', 18) . ' ' . implode(' ', $extra) . PHP_EOL;
      }
    }

    $relations = $opts['relationships'] ?? [];
    if ($relations) {
      print '     relationships:' . PHP_EOL;
      foreach ($relations as $field => $cfg) {
        print '       - ' . $field . '  ' . json_encode($cfg['argument'] ?? NULL) . PHP_EOL;
      }
    }

    $sorts = [];
    foreach ((array) ($opts['sorts'] ?? []) as $field => $cfg) {
      $sorts[] = $field . ' ' . strtoupper((string) ($cfg['order'] ?? 'asc'));
    }
    if ($sorts) {
      print '     sorts:    ' . implode(', ', $sorts) . PHP_EOL;
    }

    $pager = $opts['pager'] ?? [];
    if (!empty($pager['type'])) {
      print '     pager:    ' . $pager['type'] . ' quantity=' . json_encode($pager['quantity'] ?? NULL) . PHP_EOL;
    }
  }
  print PHP_EOL;
}
