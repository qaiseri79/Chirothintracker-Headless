<?php

use Drupal\Core\Entity\Query\QueryInterface;

$storage = \Drupal::entityTypeManager()->getStorage('contact_message');

$fids_with_attachment = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('contact_form', 'message')
  ->condition('field_attachments.target_id', 0, '>')
  ->range(0, 8)
  ->sort('created', 'DESC')
  ->execute();

print "messages with real attachments: " . count($fids_with_attachment) . "\n\n";
foreach ($fids_with_attachment as $mid) {
  $msg = $storage->load($mid);
  $attach = $msg->get('field_attachments')->getValue();
  printf(
    "-- msg %d from=%d to=%d\n",
    $msg->id(),
    (int) ($msg->get('field_from')->target_id ?? 0),
    (int) ($msg->get('field_to')->target_id ?? 0)
  );
  foreach ($attach as $item) {
    $fid = (int) ($item['target_id'] ?? 0);
    if (!$fid) {
      continue;
    }
    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    if ($file) {
      printf(
        "     fid=%d uri=%s name=%s size=%d mime=%s\n",
        $fid,
        $file->getFileUri(),
        $file->getFilename(),
        (int) $file->getSize(),
        $file->getMimeType()
      );
    }
  }
}

print "\n=== recent messages: format + raw value peek ===";
$recent = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('contact_form', 'message')
  ->range(0, 8)
  ->sort('created', 'DESC')
  ->execute();
foreach ($recent as $mid) {
  $msg = $storage->load($mid);
  $val = $msg->get('field_message')->getValue();
  $v = $val[0]['value'] ?? '';
  $fmt = $val[0]['format'] ?? '?';
  $preview = preg_replace('/\s+/', ' ', trim($v));
  $preview = mb_substr($preview, 0, 90);
  printf("  msg %d fmt=%-12s html=%s  %s\n", $mid, $fmt, (strpos($v, '<') !== FALSE) ? 'YES' : 'no', $preview);
}