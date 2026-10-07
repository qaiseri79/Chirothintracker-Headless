<?php
/**
 * Fix the custom_module.php syntax errors and comment out tracking_weight blocks.
 * Uses token_get_all to properly find block boundaries.
 */

$file = '/app/web/modules/custom/custom_module/custom_module.module';
$content = file_get_contents($file);

// First, fix the obvious syntax error: the double `}` around line 790-795
// Looking at the code, there are two `}` on consecutive lines where there should be one

$lines = explode("\n", $content);

// Remove the duplicate `}` around line 792 (0-indexed ~791)
if (isset($lines[790]) && trim($lines[790]) === '}' && isset($lines[791]) && trim($lines[791]) === '}') {
  // Check if line 789 is also a closing brace or if we have a pattern like:
  //   }
  // }
  // function next_function
  if (isset($lines[792]) && strpos($lines[792], 'function ') === 0) {
    // Remove one of the duplicate braces
    unset($lines[791]);
    $lines = array_values($lines);
    print "Fixed duplicate closing brace at line 792\n";
  }
}

// Also fix the assignment bug in the if conditions: $form_id = "..." should be ==
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
print "Fixed syntax errors.\n";

// Now verify
$php_check = "php -l $file";
exec($php_check, $output, $return_var);
if ($return_var === 0) {
  print "Syntax OK\n";
} else {
  print "Syntax errors remain:\n";
  print implode("\n", $output) . "\n";
}