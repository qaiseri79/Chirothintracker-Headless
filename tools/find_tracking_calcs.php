<?php
$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);

// Find tracking_weight related calculation code
$found = false;
foreach ($lines as $i => $line) {
  if (strpos($line, 'tracking_weight') !== false) {
    $context_start = max(0, $i - 5);
    $context_end = min(count($lines) - 1, $i + 30);
    for ($j = $context_start; $j <= $context_end; $j++) {
      print ($j+1) . ": " . $lines[$j] . PHP_EOL;
    }
    print str_repeat("-", 80) . PHP_EOL;
    $found = true;
  }
}

if (!$found) {
  print "No tracking_weight references found.\n";
}