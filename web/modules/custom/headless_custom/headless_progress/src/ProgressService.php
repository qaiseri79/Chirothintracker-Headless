<?php

namespace Drupal\headless_progress;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds the patient's progress snapshot for the headless API.
 */
class ProgressService {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   */
  public function __construct(
    protected EntityTypeManagerInterface $entity_type_manager,
    protected AccountProxyInterface $current_user,
  ) {}

  /**
   * Returns the progress snapshot for the current user.
   *
   * @return array
   *   Keys: summary (array), entries (array of arrays).
   */
  public function getProgress(): array {
    $uid = $this->current_user->id();
    if (!$uid) {
      return ['summary' => $this->emptySummary(), 'entries' => []];
    }

    // Load user with the summary fields.
    $account = $this->entity_type_manager->getStorage('user')->load($uid);
    if (!$account) {
      return ['summary' => $this->emptySummary(), 'entries' => []];
    }

    return $this->forAccount($account);
  }

  /**
   * Builds the summary object from user fields.
   */
  public function forAccount(\Drupal\user\UserInterface $account, int $limit = 0, int $offset = 0, bool $byLogDate = FALSE): array {
    return ['summary' => $this->summaryForAccount($account), 'entries' => $this->buildEntries((int) $account->id(), $limit, $offset, $byLogDate)];
  }

  public function summaryForAccount(\Drupal\user\UserInterface $account): array {
    return $this->buildSummary($account);
  }

  protected function buildSummary(\Drupal\user\UserInterface $account): array {
    $program_day = $this->getFieldValue($account, 'field_user_current_program_day_c');
    $goal_weight = $this->getFieldValue($account, 'field_goal_weight');
    $start_weight = $this->getFieldValue($account, 'field_program_start_weight');
    $net_weight_loss = $this->getFieldValue($account, 'field_net_weight_loss');
    $net_inches_lost = $this->getFieldValue($account, 'field_net_inches_lost');
    $overall_weight_loss = $this->getFieldValue($account, 'field_gross_weight_loss');
    $goal_achieved = $this->getFieldValue($account, 'field_goal_achieved');

    // goal_achieved is stored as a percentage (e.g., 97.50). Convert to fraction.
    $goal_progress = $goal_achieved !== null ? (float) $goal_achieved / 100.0 : 0.0;

    return [
      'programStartDate' => $this->getFieldValue($account, 'field_program_start_date'),
      'startWeight' => $start_weight !== NULL ? (float) $start_weight : NULL,
      'programDay' => $program_day !== null ? (int) $program_day : 0,
      'goalProgress' => $goal_progress,
      'goalWeight' => $goal_weight !== null ? (float) $goal_weight : null,
      'netWeightLoss' => $net_weight_loss !== null ? (float) $net_weight_loss : null,
      'netInchesLost' => $net_inches_lost !== null ? (float) $net_inches_lost : null,
      'overallWeightLoss' => $overall_weight_loss !== null ? (float) $overall_weight_loss : null,
    ];
  }

  /**
   * Builds the log entries from the user's tracking_weight submissions.
   */
  protected function buildEntries(int $uid, int $limit = 0, int $offset = 0, bool $byLogDate = FALSE): array {
    $query = $this->entity_type_manager->getStorage('contact_message')->getQuery()
      ->accessCheck(FALSE)
      ->condition('contact_form', 'tracking_weight')
      ->condition('uid', $uid);
    if ($byLogDate) { $query->sort('field_date', 'DESC'); }
    $query->sort('created', 'DESC')->sort('id', 'DESC');
    if ($limit > 0) { $query->range($offset, $limit); }
    $ids = $query->execute();

    if (!$ids) {
      return [];
    }

    $storage = $this->entity_type_manager->getStorage('contact_message');
    $messages = $storage->loadMultiple($ids);

    $entries = [];
    foreach ($messages as $msg) {
      $entry = $this->mapEntry($msg);
      if ($entry) {
        $entries[] = $entry;
      }
    }

    return $entries;
  }

  /**
   * Maps a contact_message to a ProgressEntry.
   */
  protected function mapEntry(\Drupal\contact\MessageInterface $msg): ?array {
    $date = $this->getFieldValue($msg, 'field_date');
    // Format as mm/dd/yyyy for the frontend.
    $date_formatted = $this->formatDate($date);
    if ($date_formatted === '') {
      // Either no date was recorded, or it was recorded in a shape formatDate()
      // cannot read. Both fall back to the submission's created time, which is
      // always present on the entity.
      $date_formatted = date('m/d/Y', (int) $msg->get('created')->value);
    }

    $day = $this->getFieldValue($msg, 'field_program_day');
    // The computed day, kept separate from `field_program_day` because the chart's
    // axis label branches on it: 0 means the log predates the program start and
    // must read "Previous", anything above 0 reads "Day N". Collapsing the two
    // would lose that distinction, since `field_program_day` is clamped to 0 too.
    $program_day_computed = $this->getFieldValue($msg, 'field_program_day_computed');
    $adherence = $this->getFieldValue($msg, 'field_grade');
    $weight = $this->getFieldValue($msg, 'field_weight');
    $loss = $this->getFieldValue($msg, 'field_weight_loss_to_date');
    $water = $this->getFieldValue($msg, 'field_water_intake');
    $sleep = $this->getFieldValue($msg, 'field_sleep_hours');
    $note = $this->getFieldValue($msg, 'field_notes');
    $flags = $this->flagLabels($msg);

    // Compose lunch/dinner/other from structured fields.
    $lunch = $this->composeMeal($msg, 'lunch');
    $dinner = $this->composeMeal($msg, 'dinner');
    $other = $this->getFieldValue($msg, 'field_other_food');

    return [
      'id' => (int) $msg->id(),
      'date' => $date_formatted,
      'day' => $day !== null ? (int) $day : 0,
      'programDayComputed' => $program_day_computed !== null ? (int) $program_day_computed : 0,
      'flags' => $flags,
      'adherence' => $adherence !== null ? (int) $adherence : 0,
      'adherenceRecorded' => $adherence !== NULL && $adherence !== '',
      'authorId' => (int) $this->getFieldValue($msg, 'field_author'),
      'bloodSugar' => ($bloodSugar = $this->getFieldValue($msg, 'field_blood_sugar')) !== NULL ? (float) $bloodSugar : NULL,
      'bloodPressure' => (string) ($this->getFieldValue($msg, 'field_blood_pressure') ?? ''),
      'weight' => $weight !== null ? (float) $weight : 0,
      'loss' => $loss !== null ? (float) $loss : 0,
      'water' => $water !== null ? (float) $water : 0,
      'sleep' => $sleep !== null ? (float) $sleep : 0,
      // getFieldValue() returns NULL for an empty field, and `NULL !== ''` is
      // TRUE, so both of these used to pass NULL straight through for a day with
      // no note and no other-food text. `other` is declared `string` on the
      // frontend, so that was a null arriving where the chart expects text.
      'note' => ($note === null || $note === '') ? null : $note,
      'lunch' => $lunch,
      'dinner' => $dinner,
      'other' => ($other === null || $other === '') ? '' : $other,
      'measurements' => $this->measurements($msg),
    ];
  }

  /** Measurement names and labels shared by log details and the doctor chart. */
  public function measurementFields(array $definitions): array {
    $fields = [];
    foreach ($definitions as $name => $definition) {
      if (preg_match('/^field_(neck|shoulders|chest|abdomen|hips|buttocks|arm_(left|right)_bicep|thigh_(left|right)|calf_(left|right)|back_bra_line|above_umbilicus|below_umbilicus)$/', $name)) {
        $fields[$name] = (string) $definition->getLabel();
      }
    }
    return $fields;
  }

  public function measurements(\Drupal\contact\MessageInterface $message): array {
    $result = [];
    foreach ($this->measurementFields($message->getFieldDefinitions()) as $name => $label) {
      $value = $this->getFieldValue($message, $name);
      if ($value !== NULL && is_numeric($value)) { $result[] = ['area' => $label, 'value' => (float) $value]; }
    }
    return $result;
  }

  /** Composes a meal string from the structured fields. */
  protected function composeMeal(\Drupal\contact\MessageInterface $msg, string $prefix): string {
    $parts = [];

    // Protein.
    $protein = $this->termLabels($this->getFieldValue($msg, "field_{$prefix}_protein", TRUE));
    if ($protein) {
      $parts[] = 'Protein: ' . implode(', ', $protein);
    }

    // Vegetables.
    $veg = $this->termLabels($this->getFieldValue($msg, "field_{$prefix}_vegetables", TRUE));
    if ($veg) {
      $parts[] = 'Veg: ' . implode(', ', $veg);
    }

    // Free vegetables.
    $free_veg = $this->termLabels($this->getFieldValue($msg, "field_{$prefix}_vegetables_free", TRUE));
    if ($free_veg) {
      $parts[] = 'Free Veg: ' . implode(', ', $free_veg);
    }

    // Fruit.
    $fruit = $this->termLabels($this->getFieldValue($msg, "field_{$prefix}_fruit_sel", TRUE));
    if ($fruit) {
      $parts[] = 'Fruit: ' . implode(', ', $fruit);
    }

    // Bread.
    $bread = $this->termLabels($this->getFieldValue($msg, "field_{$prefix}_bread", TRUE));
    if ($bread) {
      $parts[] = 'Bread: ' . implode(', ', $bread);
    }

    // Other (free text).
    $other = $this->getFieldValue($msg, "field_{$prefix}_other");
    if ($other) {
      $parts[] = is_array($other) ? implode(', ', array_filter(array_map(static fn($item) => is_array($item) ? ($item['value'] ?? '') : (is_scalar($item) ? (string) $item : ''), $other))) : (string) $other;
    }

    return implode(' | ', $parts);
  }

  /**
   * Resolves taxonomy term IDs to labels, dropping IDs that no longer resolve.
   *
   * Every term field is read multi-value here, including the ones that in
   * practice hold a single selection. `field_{prefix}_protein` used to be the
   * one exception, read as a single value, and that broke the first time a
   * patient selected more than one protein: `getFieldValue()` falls through to
   * returning the raw value array whenever a field holds more than one item, so
   * `Term::load()` received a nested array and threw
   * `TypeError: Illegal offset type`. That escaped `getProgress()` and 500'd the
   * entire dashboard — one meal entry took out every other one — because
   * `ProgressController::current()` has no error handling. Nothing in storage
   * required the single-value read, so the asymmetry was the bug, not the data.
   *
   * Non-scalar members are skipped so this helper cannot reproduce that TypeError
   * for any caller, whatever shape a field turns out to hold. Terms are loaded in
   * one query rather than one each, which is why this replaced the per-ID
   * `Term::load()` calls it now serves.
   *
   * ## loadMultiple(), not load()
   *
   * The batch read is `loadMultiple($scalars)`. This was `load($scalars)` with
   * the same argument, which is a different call with the same syntax and a
   * different contract: `load()` expects one ID and returns one entity (or NULL),
   * so an array argument is used as an array *key* inside
   * `EntityStorageBase::load()` —
   *
   *     $entities = $this->loadMultiple([$id]);
   *     return $entities[$id] ?? NULL;    // Illegal offset type
   *
   * — and PHP fatals with `TypeError: Illegal offset type` because an array
   * cannot be a key. The `?? NULL` does not help; the illegal offset is raised
   * when evaluating the left operand, before the coalesce applies.
   *
   * It is worth being precise about why this survived the other fixes to this
   * method. The earlier TypeError had the identical message and came from the
   * identical line of core, so a reading of the log that stops at the message
   * says the multi-protein bug is still present. It is not: the caller-side
   * nesting that produced a nested array is fixed, and this is a second,
   * independent defect that only appears once the argument is a flat list of
   * scalars — which is what the fix for the first one started passing. The two
   * share a message and a line, and only the argument distinguishes them.
   *
   * @param array|null $term_ids
   *   Target IDs, or NULL when the field is absent or empty.
   *
   * @return string[]
   *   Labels in selection order; IDs whose term was deleted are omitted rather
   *   than emitted as bare IDs.
   */
  protected function termLabels(?array $term_ids): array {
    if (empty($term_ids)) {
      return [];
    }
    $scalars = [];
    foreach ($term_ids as $term_id) {
      if (is_scalar($term_id)) {
        $scalars[] = $term_id;
      }
    }
    if (empty($scalars)) {
      return [];
    }
    $terms = $this->entity_type_manager->getStorage('taxonomy_term')->loadMultiple($scalars);
    $labels = [];
    foreach ($scalars as $term_id) {
      if (isset($terms[$term_id]) && $terms[$term_id] instanceof TermInterface && $terms[$term_id]->label() !== NULL) {
        $labels[] = (string) $terms[$term_id]->label();
      }
    }
    return $labels;
  }

  /**
   * Resolves field_flags to a list of human-readable labels.
   *
   * `field_flags` is a multi-value entity reference to taxonomy terms, so the raw
   * value is a list of term IDs. Labels are resolved here rather than at write
   * time so a renamed term shows correctly on already-saved weigh-ins.
   *
   * @return string[]
   *   The flag labels, in the order the patient selected them.
   */
  protected function flagLabels(\Drupal\contact\MessageInterface $msg): array {
    return $this->termLabels($this->getFieldValue($msg, 'field_flags', TRUE));
  }

  /**
   * Formats a date as mm/dd/yyyy.
   *
   * Takes the raw value {@see getFieldValue()} returns rather than a `string`,
   * because that helper hands back whatever the field item list held whenever a
   * single-value read did not match its two known shapes. A date field that
   * carries a second column — `['value' => '2025-01-14', 'timezone' => 'UTC']`
   * is the ordinary case, not an exotic one — falls through both `count() === 1`
   * checks in that helper and arrives here as an array. Under a `string` type
   * hint that is a TypeError, and TypeErrors are not catchable per-field: one
   * malformed date on one weigh-in takes down every entry on the dashboard.
   *
   * Nothing here guesses a date out of an array. A shape this helper does not
   * recognise yields an empty string, `mapEntry()` falls back to the submission's
   * created time, and one unreadable date costs one label rather than the page.
   *
   * @param mixed $date
   *   A scalar date string, an array shaped like a field item list, or NULL.
   *
   * @return string
   *   `mm/dd/yyyy`, the unparseable input unchanged when it was a usable
   *   string, or '' when there was nothing date-shaped to read.
   */
  protected function formatDate($date): string {
    if (is_array($date)) {
      // Field item list, possibly with more than the two keys getFieldValue()
      // knows about.
      $date = $date['value'] ?? $date[0]['value'] ?? NULL;
    }
    if (!is_string($date) || $date === '') {
      return '';
    }

    // Input might be Y-m-d, Y-m-d H:i:s or an ISO 8601 string.
    $ts = strtotime($date);
    if ($ts === false) {
      return $date;
    }
    return date('m/d/Y', $ts);
  }

  /**
   * Gets a field value, handling single-value and multi-value fields.
   *
   * @param bool $multi
   *   If true, returns array of target_ids for entity reference fields.
   */
  protected function getFieldValue($entity, string $field_name, bool $multi = false) {
    if (!$entity->hasField($field_name)) {
      return null;
    }
    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return null;
    }
    if ($multi) {
      $values = [];
      foreach ($field as $item) {
        if (isset($item->target_id)) {
          $values[] = $item->target_id;
        }
      }
      return $values ?: null;
    }
    $value = $field->getValue();
    if (is_array($value) && count($value) === 1 && isset($value[0]['value'])) {
      return $value[0]['value'];
    }
    if (is_array($value) && count($value) === 1 && isset($value[0]['target_id'])) {
      return $value[0]['target_id'];
    }
    return $value;
  }

  /**
   * Returns an empty summary structure.
   */
  protected function emptySummary(): array {
    return [
      'programDay' => 0,
      'goalProgress' => 0.0,
      'goalWeight' => null,
      'netWeightLoss' => null,
      'netInchesLost' => null,
      'overallWeightLoss' => null,
    ];
  }

  /**
   * The fields the edit form owns, with the shape each one must be returned in.
   *
   * This is a hand-maintained mirror of `frontend/src/lib/tracking/
   * tracking-blueprint.json`, which is generated by
   * `tools/export_intake_blueprint.php`. The two must be kept in step: a field
   * added to the blueprint needs an entry here, or it will not be seeded into
   * the edit form.
   *
   * The shape is dictated by the widget in `components/intake/step-field.tsx`:
   *
   * - `checkbox-grid` reads `Array.isArray(value)` and compares against
   *   `option.value` as a string, so it needs a list even for a single
   *   selection. A bare scalar would silently render every box unchecked.
   * - `checkbox` compares `value === true`, so it needs a real boolean rather
   *   than `0`/`1`.
   * - `radio` and `select` compare with `String(value ?? "")`, so a string is
   *   correct for those, including a single-valued entity reference.
   * - `date` is an `<input type="date">`, whose value is `Y-m-d`. Drupal stores
   *   `Y-m-d 00:00:00`, so the time part is cut.
   * - `number` is an `<input type="number">` seeded with `String(value ?? "")`
   *   and written back with `event.target.value`, so its state is a string.
   *   The frontend's `FieldValue` is `string | string[] | boolean | undefined` —
   *   no `number` — and `coerceScalar()` converts with `Number()` at submit
   *   time. Returning a string also keeps the value the patient actually typed
   *   (`"15.50"`) instead of a float that reformats to `15.5`.
   *
   * Base fields (`id`, `uuid`, `uid`, `created`, `ip_address`, `langcode`,
   * `subject`, `message`, `contact_form`) are deliberately absent: they are not
   * form inputs, and the submit path writes every key it is handed straight
   * back onto the entity via `$message->set()`, so returning them would let a
   * client rewrite its own `uid` or `ip_address`.
   *
   * Server-computed fields (`field_program_day`,
   * `field_program_day_computed`, `field_total_measurements`,
   * `field_weight_loss_computed`, `field_weight_loss_to_date`,
   * `field_current_phase`, `field_archive`, `field_author`) are absent for a
   * different reason: the calculator owns them. Echoing them back would let a
   * stale client value be written over a fresh computation.
   *
   * @return array<string, string>
   *   Field name => value, ready to seed `FormState`. Only non-empty fields.
   */
  protected function getEditableFieldShapes(): array {
    return [
      // date: Y-m-d.
      'field_date' => 'date',
      // Numbers stay strings: the frontend's `FieldValue` has no `number`
      // member, and a number input's state is whatever the user typed.
      'field_weight' => 'number',
      'field_water_intake' => 'number',
      'field_neck' => 'number',
      'field_chest' => 'number',
      'field_shoulders' => 'number',
      'field_arm_left_bicep' => 'number',
      'field_arm_right_bicep' => 'number',
      'field_abdomen' => 'number',
      'field_hips' => 'number',
      'field_thigh_left' => 'number',
      'field_thigh_right' => 'number',
      'field_calf_left' => 'number',
      'field_calf_right' => 'number',
      'field_blood_sugar' => 'number',
      'field_sleep_hours' => 'number',
      // booleans.
      'field_mind_set_work' => 'boolean',
      'field_chiroburst_bend' => 'boolean',
      // single-value entity references and list integers: plain strings.
      'field_grade' => 'string',
      'field_breakfast_protein' => 'string',
      'field_lunch_protein' => 'string',
      'field_lunch_bread' => 'string',
      'field_dinner_protein' => 'string',
      'field_dinner_bread' => 'string',
      'field_flags' => 'string',
      'field_chiropractor_flags' => 'string',
      // multi-value entity references: always a list, never a bare scalar.
      'field_breakfast_fruit_sel' => 'list',
      'field_lunch_fruit_sel' => 'list',
      'field_lunch_vegetables' => 'list',
      'field_lunch_vegetables_free' => 'list',
      'field_dinner_fruit_sel' => 'list',
      'field_dinner_vegetables' => 'list',
      'field_dinner_vegetables_free' => 'list',
      // text.
      'field_breakfast_other' => 'string',
      'field_lunch_other' => 'string',
      'field_dinner_other' => 'string',
      'field_blood_pressure' => 'string',
      'field_notes' => 'string',
      'field_question' => 'string',
      'field_other_food' => 'string',
    ];
  }

  /**
   * Coerces one field's raw value to the shape the form widget expects.
   *
   * @param mixed $value
   *   The value as read off the field item list.
   * @param string $shape
   *   One of the shape names in {@see getEditableFieldShapes()}.
   *
   * @return string|bool|string[]|null
   *   NULL when the value cannot be represented, so the caller omits the key
   *   and the form falls back to the blueprint default.
   */
  protected function coerceForForm($value, string $shape) {
    switch ($shape) {
      case 'list':
        $ids = [];
        foreach ((array) $value as $item) {
          if ($item === NULL || $item === '') {
            continue;
          }
          $ids[] = (string) $item;
        }
        return $ids;

      case 'boolean':
        return (bool) $value;

      case 'number':
        // Not cast to float: see the note in getEditableFieldShapes().
        return is_numeric($value) ? (string) $value : NULL;

      case 'date':
        if (!is_string($value) || $value === '') {
          return NULL;
        }
        $timestamp = strtotime($value);
        return $timestamp === FALSE ? NULL : date('Y-m-d', $timestamp);

      case 'string':
      default:
        if (is_array($value) || is_object($value)) {
          return NULL;
        }
        return $value === NULL ? NULL : (string) $value;
    }
  }

  /**
   * Returns the field values of a single tracking entry, shaped for the form.
   *
   * The caller is a server component, so this payload is spread straight into
   * `FormState` and posted back on save. That makes the allowlist below a
   * security boundary as much as a shape contract: only fields the patient is
   * allowed to edit are returned, so a save cannot write a field the read
   * never offered.
   *
   * @param int $message_id
   *   The contact_message entity ID.
   * @param int $uid
   *   The current user's UID, for ownership verification.
   *
   * @return array<string, string|bool|string[]>|null
   *   Field name => value, or NULL if the entry does not exist, is not a
   *   tracking log, or is not owned by this user.
   */
  public function getEntry(int $message_id, int $uid): ?array {
    $message = $this->entity_type_manager->getStorage('contact_message')->load($message_id);
    if (!$message || $message->bundle() !== 'tracking_weight') {
      return NULL;
    }
    if ((int) $this->getFieldValue($message, 'uid') !== $uid) {
      return NULL;
    }

    $values = [];
    foreach ($this->getEditableFieldShapes() as $field_name => $shape) {
      if (!$message->hasField($field_name)) {
        continue;
      }
      $field = $message->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }
      $raw = $shape === 'list' ? $this->getFieldValue($message, $field_name, TRUE) : $this->getFieldValue($message, $field_name);
      $coerced = $this->coerceForForm($raw, $shape);
      if ($coerced === NULL || $coerced === [] || $coerced === '') {
        continue;
      }
      $values[$field_name] = $coerced;
    }

    return $values;
  }

}