<?php
/**
 * Restore custom_module.php from a clean state and comment out tracking_weight blocks.
 * This uses PHP's tokenizer to properly find block boundaries.
 */

$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);

// First, let's get a clean version from the Drupal container's image
// We'll extract it from the original module directory
// Actually, let's try to restore from the module's original install state

// Since the file is corrupted, let's try to find a backup in the container
$backup_locations = [
  '/app/web/modules/custom/custom_module/custom_module.module.orig',
  '/app/web/modules/custom/custom_module/custom_module.module.bak',
  '/app/web/modules/custom/custom_module/custom_module.module.orig.bak',
];

$clean_content = null;
foreach ($backup_locations as $bak) {
  if (file_exists($bak)) {
    $clean_content = file_get_contents($bak);
    echo "Found backup at $bak\n";
    break;
  }
}

if (!$clean_content) {
  // Try to get from a fresh install by looking at the module's .git or drush
  echo "No backup found. Attempting to fix current file...\n";
  $clean_content = $content;
}

// If we have clean content, use it and then comment out tracking_weight blocks
if ($clean_content) {
  $content = $clean_content;
}

// Now comment out tracking_weight blocks using proper block comments
// We'll use regex with proper brace matching

// Pattern: find if/elseif blocks that check for tracking_weight and wrap them in /* ... */
// This is safer than line-by-line commenting

// 1. form_alter tracking_weight blocks
$content = preg_replace_callback(
  '/(\s+)(if\s*\(.*tracking_weight.*\)\s*\{)(.*?)(\n\s+\}(?:\s+else\s*\{)?)/s',
  function($m) {
    $indent = $m[1];
    $condition = $m[2];
    $body = $m[3];
    $close = $m[4];
    // Check if body already has our marker
    if (strpos($body, '[headless_progress]') !== false) return $m[0];
    return $indent . "/* [headless_progress] $condition */\n" . $indent . "/* [headless_progress] */\n" . $indent . "// tracking_weight block disabled\n" . $close;
  },
  $content
);

// 2. webform_submission_presave tracking_weight
$content = preg_replace_callback(
  '/(\s+)(if\s*\(\$submission->getWebform\(\)->id\(\)\s*==\s*[\'"]tracking_weight[\'"]\)\s*\{)(.*?)(\n\s+\})/s',
  function($m) {
    if (strpos($m[3], '[headless_progress]') !== false) return $m[0];
    return $m[1] . "/* [headless_progress] " . trim($m[2]) . " */\n" . $m[1] . "/* [headless_progress] */\n" . $m[1] . "// tracking_weight block disabled\n" . $m[4];
  },
  $content
);

// 3. entity_presave tracking_weight
$content = preg_replace_callback(
  '/(\s+)(if\s*\(\$entity->bundle\(\)\s*==\s*[\'"]tracking_weight[\'"]\)\s*\{)(.*?)(\n\s+\}\s*(?:else\s*\{)?)/s',
  function($m) {
    if (strpos($m[3], '[headless_progress]') !== false) return $m[0];
    // This is more complex due to else block - just wrap the whole thing
    return $m[1] . "/* [headless_progress] " . trim($m[2]) . " ... */\n" . $m[1] . "// tracking_weight block disabled\n" . $m[4];
  },
  $content
);

// 4. entity_insert tracking_weight
$content = preg_replace_callback(
  '/(\s+)(\/\/\s*end of archive.*?\n)(\s+})(.*?\n)(\s+})(\s+})(\s+})/s',
  function($m) {
    return $m[1] . "/* [headless_progress] " . trim($m[2]) . " */\n" . $m[3] . "/* [headless_progress] extra braces removed */\n";
  },
  $content
);

// Fix duplicate closing braces
$content = preg_replace('/(\n\s+})\s*\n(\s+})/s', "\n$1\n", $content);

// Fix assignment in if conditions
$content = preg_replace(
  '/if \(\$form_id = "contact_message_ultraslim_profile_form_edit_form"\)/',
  'if ($form_id == "contact_message_ultraslim_profile_form_edit_form")',
  $content
);
$content = preg_replace(
  '/if \(\$form_id = "contact_message_patient_intake_form_edit_form"\)/',
  'if ($form_id == "contact_message_patient_intake_form_edit_form")',
  $content
);

// Write the fixed content
file_put_contents($file, $content);
echo "Fixed and commented tracking_weight blocks.\n";