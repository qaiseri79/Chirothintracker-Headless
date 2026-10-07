<?php
$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);

// Show lines around 802
for ($i = 790; $i < 820 && $i < count($lines); $i++) {
  print ($i+1) . ": " . $lines[$i] . PHP_EOL;
}