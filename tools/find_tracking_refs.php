<?php
/**
 * Find all tracking_weight references in custom_module.
 */
$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);

foreach ($lines as $i => $line) {
  if (strpos($line, 'tracking_weight') !== false) {
    print "Line " . ($i+1) . ": " . trim($line) . PHP_EOL;
  }
}