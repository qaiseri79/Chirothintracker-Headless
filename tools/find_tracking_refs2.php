<?php
$file = '/app/web/modules/custom/ctt_patient_intake/ctt_patient_intake.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);

foreach ($lines as $i => $line) {
  if (strpos($line, 'tracking_weight') !== false) {
    print "Line " . ($i+1) . ": " . trim($line) . PHP_EOL;
  }
}