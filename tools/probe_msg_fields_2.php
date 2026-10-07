<?php

$manager = \Drupal::service('entity_field.manager');
$field = $manager->getFieldDefinitions('contact_message', 'message')['field_attachments'];
$storage = $field->getFieldStorageDefinition();
$settings = $field->getSettings();

print "field_attachments field settings:\n";
print_r($settings);

print "\nstorage cardinality: " . $storage->getCardinality() . "\n";
print "storage settings:\n";
print_r($storage->getSettings());

print "\n\nbasic_html filter format exists: ";
$formats = \Drupal::entityTypeManager()->getStorage('filter_format')->loadMultiple();
$ids = array_keys($formats);
print in_array('basic_html', $ids, TRUE) ? 'YES' : 'NO';
print "\navailable formats: " . implode(', ', $ids) . "\n";

if (!empty($formats['basic_html'])) {
  print "\nbasic_html filters:\n";
  foreach ($formats['basic_html']->filters() as $name => $filter) {
    print "  - " . $filter->getPluginId() . " (status=" . ($filter->status ? 'on' : 'off') . ")\n";
  }

  print "\ncheck_markup test (script tag):\n";
  print check_markup('<p>Hello <b>world</b></p><script>alert(1)</script><a href="http://evil.com">link</a>\nsecond line', 'basic_html') . "\n";
}

print "\nfile scheme of existing attached files (need a message with a real attachment):\n";
$fids = \Drupal::entityQuery('contact_message')
  ->condition('contact_form', 'message')
  ->condition('field_attachments.target_id', 0, '<>')
  ->range(0, 5)
  ->execute();
print "messages with attachments: " . count($fids) . "\n";
foreach ($fids as $mid) {
  $msg = \Drupal::entityTypeManager()->getStorage('contact_message')->load($mid);
  $attach = $msg->get('field_attachments')->getValue();
  print "  msg " . $msg->id() . " field_attachments=" . json_encode($attach) . "\n";
  foreach ($attach as $item) {
    $fid = (int) ($item['target_id'] ?? 0);
    if ($fid) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      if ($file) {
        print "    fid " . $fid . " uri=" . $file->getFileUri() . " name=" . $file->getFilename()
          . " size=" . $file->getSize() . " mime=" . $file->getMimeType() . " status(permanent)=" . $file->isPermanent() . "\n";
      }
    }
  }
}