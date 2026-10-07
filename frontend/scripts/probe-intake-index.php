<?php

/**
 * Throwaway: prove the intake token column is empty and unique.
 *
 * Two claims, both of which have to hold on a database that already has real
 * clinics in it:
 *
 * - No clinic has a token. Every clinic starts with none, and nothing in the
 *   import or the update hook is allowed to invent one.
 * - The unique index is really there. It was added by hand in an update hook,
 *   because Drupal's field storage config cannot express one, so "the hook ran"
 *   and "the index exists" are different claims.
 */

$database = \Drupal::database();
$table = 'clinic__field_intake_token';
$column = 'field_intake_token_value';

$total = (int) $database->select($table, 't')->countQuery()->execute()->fetchField();
$withToken = (int) $database->select($table, 't')
  ->condition($column, NULL, 'IS NOT NULL')
  ->countQuery()
  ->execute()
  ->fetchField();

echo "clinics=$total with_token=$withToken", PHP_EOL;

$indexes = [];
foreach ($database->query("SHOW INDEX FROM $table") as $row) {
  $row = (object) $row;
  $indexes[$row->Key_name][(int) $row->Non_unique === 0 ? 'unique' : 'nonunique'][] = $row->Column_name;
}

foreach ($indexes as $name => $kinds) {
  foreach ($kinds as $kind => $columns) {
    echo "index $name ($kind): ", implode(', ', $columns), PHP_EOL;
  }
}

echo isset($indexes['intake_token_unique']) ? "PASS: intake_token_unique exists" : "FAIL: intake_token_unique missing";
echo PHP_EOL;

// The service resolves a token with an entity query on this field, so the column
// is worth an index even though the clinic-side reads are by entity id.
$where = (int) $database->select($table, 't')
  ->condition($column, str_repeat('a', 36))
  ->countQuery()
  ->execute()
  ->fetchField();
echo "lookup_for_a_36_char_token=$where (expected 0)", PHP_EOL;