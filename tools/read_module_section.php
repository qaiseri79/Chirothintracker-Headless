<?php
$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);
for ($i = 600; $i < 650 && $i < count($lines); $i++) {
  print ($i+1) . ": " . $lines[$i] . PHP_EOL;
}