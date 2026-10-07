<?php

$names = ['field_agreement', 'field_program_agreement', 'field_media_release_agreement'];
$sources = [];
foreach ($names as $fname) {
  $conf = \Drupal::config("field.field.contact_message.patient_intake.$fname");
  $epp = $conf->get('third_party_settings.epp.value');
  $default = $conf->get('default_value');
  $text = is_string($epp) && $epp !== ''
    ? ['source' => 'epp', 'value' => $epp]
    : (is_array($default) && isset($default[0]['value']) && $default[0]['value'] !== ''
        ? ['source' => 'default_value', 'value' => $default[0]['value']]
        : ['source' => null, 'value' => '']);
  $sources[$fname] = $text;
  preg_match_all('/\[[^\]]+\]/', $text['value'], $m);
  $tokens = array_values(array_unique($m[0] ?? []));
  echo "$fname source={$text['source']} len=" . strlen($text['value']) . "\n";
  echo "  tokens: " . implode(' | ', $tokens) . "\n";
  echo "  head: " . substr(preg_replace('/\s+/', ' ', $text['value']), 0, 200) . "\n";
}

echo "\n== cluster around clinicbrand for context ==\n";
$ag = $sources['field_agreement'];
$pos = strpos($ag['value'], 'clinicbrand');
if ($pos !== false) {
  echo substr($ag['value'], max(0, $pos - 400), 900) . "\n";
}

echo "\n== system.site ==\n";
echo "  name: " . \Drupal::config('system.site')->get('name') . "\n";
echo "  url: " . \Drupal::config('system.site')->get('url') . "\n";

echo "\n== clinic 1 field_brand raw ==\n";
$clinic = \Drupal::entityTypeManager()->getStorage('clinic')->load(1);
echo "  field_brand: " . var_export($clinic->get('field_brand')->getValue(), TRUE) . "\n";