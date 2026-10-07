<?php
/**
 * Fix syntax errors in custom_module.php, then comment out tracking_weight blocks.
 */

$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);
$lines = explode("\n", $content);

// Fix 1: Remove duplicate closing braces
for ($i = 0; $i < count($lines) - 1; $i++) {
  if (trim($lines[$i]) === "}" && trim($lines[$i+1]) === "}") {
    for ($j = $i + 2; $j < count($lines); $j++) {
      $next = trim($lines[$j]);
      if ($next === "") continue;
      if (strpos($next, "function ") === 0) {
        unset($lines[$i+1]);
        $lines = array_values($lines);
        echo "Fixed duplicate brace at line " . ($i+1) . "\n";
        break 2;
      }
      break;
    }
  }
}

// Fix 2: Fix assignment in if conditions
$content = implode("\n", $lines);
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

file_put_contents($file, $content);
echo "Fixed syntax errors.\n";