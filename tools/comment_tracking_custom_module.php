<?php
/**
 * Comment out tracking_weight related code blocks in custom_module.module
 * by line number ranges. This is safer than regex.
 *
 * Run: drush php:script /app/tools/comment_tracking_custom_module.php
 */

$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);

// Blocks to comment out: [start_line, end_line] (1-indexed, inclusive)
// These are the tracking_weight blocks found in the earlier probe
$blocks = [
  // form_alter: two blocks around lines 618-638
  [618, 638],

  // webform_submission_presave: lines ~882-922
  [882, 922],

  // entity_presave: lines ~927-960 (and duplicate around 950-960)
  [927, 960],

  // entity_insert: lines ~1802-1840
  [1802, 1840],

  // entity_update: lines ~1681-1692
  [1681, 1692],
];

// Sort by start line descending so line numbers don't shift during modification
usort($blocks, fn($a, $b) => $b[0] - $a[0]);

foreach ($blocks as [$start, $end]) {
  // Convert to 0-indexed
  $start_idx = $start - 1;
  $end_idx = $end - 1;

  if ($start_idx < 0 || $end_idx >= count($lines)) {
    print "Block $start-$end out of range (file has " . count($lines) . " lines)\n";
    continue;
  }

  // Check if already commented
  $already_commented = true;
  for ($i = $start_idx; $i <= $end_idx; $i++) {
    if (!preg_match('/^\s*\/\//', $lines[$i])) {
      $already_commented = false;
      break;
    }
  }

  if ($already_commented) {
    print "Block $start-$end already commented\n";
    continue;
  }

  // Comment out each line in the block
  for ($i = $start_idx; $i <= $end_idx; $i++) {
    $lines[$i] = '// [headless_progress] ' . $lines[$i];
  }

  print "Commented block $start-$end\n";
}

file_put_contents($file, implode("\n", $lines));
print "Done.\n";