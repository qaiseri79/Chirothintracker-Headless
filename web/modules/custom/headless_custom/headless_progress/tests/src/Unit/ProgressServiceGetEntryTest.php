<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\contact\Entity\Message;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\headless_progress\ProgressService;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ProgressService::getEntry(), the payload the edit form is seeded from.
 *
 * The contract this pins down is deliberately narrow, because every item here
 * corresponds to a way the edit form was wrong before:
 *
 * - only allowlisted, patient-editable fields come back, so a save cannot
 *   write a base field or a server-computed one back onto the message;
 * - a multi-value entity reference is always a list, including when exactly
 *   one option is selected, or every checkbox renders unchecked;
 * - a boolean is a real boolean, not 0/1;
 * - a number stays a decimal string, since the form state is a string;
 * - a date is `Y-m-d`, which is what `<input type="date">` needs;
 * - an entry owned by somebody else is not readable at all.
 *
 * @group headless_progress
 */
class ProgressServiceGetEntryTest extends TestCase {

  use ProphecyTrait;

  /**
   * The service under test.
   *
   * @var \Drupal\headless_progress\ProgressService
   */
  protected $service;

  /**
   * The entity type manager double.
   *
   * @var \ObjectProphecy|\Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The contact_message storage double.
   *
   * @var \ObjectProphecy|\Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The message double.
   *
   * @var \ObjectProphecy|\Drupal\contact\Entity\Message
   */
  protected $message;

  protected function setUp(): void {
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->storage = $this->prophesize(EntityStorageInterface::class);
    $this->entityTypeManager->getStorage('contact_message')->willReturn($this->storage->reveal());

    $this->service = new ProgressService(
      $this->entityTypeManager->reveal(),
      $this->prophesize(AccountProxyInterface::class)->reveal()
    );

    $this->message = $this->prophesize(Message::class);
    $this->message->bundle()->willReturn('tracking_weight');

    // getEntry() probes `hasField()` for every field in its allowlist, and a
    // prophecy double throws on a call it has no promise for. So default every
    // lookup to "no such field"; the exact-argument stubs registered by
    // withValue()/withTargets() outrank this wildcard and return TRUE.
    $this->message->hasField(Argument::any())->willReturn(FALSE);
  }

  /**
   * Builds a field item list double for a non-reference field.
   *
   * The concrete `FieldItemList` is doubled rather than the interface, because
   * the interface does not extend `\IteratorAggregate` and so does not declare
   * `getIterator()`, which prophecy refuses to stub on an interface.
   *
   * @param array $value
   *   The raw field value, e.g. `[['value' => '388.40']]`. An empty array
   *   makes the field report itself empty.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The revealed double.
   */
  protected function field(array $value) {
    $list = $this->prophesize(FieldItemList::class);
    $list->isEmpty()->willReturn($value === []);
    $list->getValue()->willReturn($value);
    return $list->reveal();
  }

  /**
   * Builds a field item list double for an entity reference field.
   *
   * The service reads reference fields by iterating the list and touching
   * `$item->target_id` as a real property (`isset()` on it), so the items are
   * plain objects with a public property rather than prophecy doubles, which
   * would not satisfy that check.
   *
   * @param array $target_ids
   *   The selected target ids.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The revealed double.
   */
  protected function referenceField(array $target_ids) {
    $items = [];
    $raw = [];
    foreach ($target_ids as $target_id) {
      $item = new \stdClass();
      $item->target_id = (string) $target_id;
      $items[] = $item;
      $raw[] = ['target_id' => (string) $target_id];
    }

    $list = $this->prophesize(FieldItemList::class);
    $list->isEmpty()->willReturn($target_ids === []);
    $list->getValue()->willReturn($raw);
    $list->getIterator()->willReturn(new \ArrayIterator($items));
    return $list->reveal();
  }

  /**
   * Registers a raw-value field on the message double.
   *
   * @param string $name
   *   The field machine name.
   * @param mixed $value
   *   The raw value array, e.g. `[['value' => '388.40']]`.
   */
  protected function withValue(string $name, array $value): void {
    $this->message->hasField($name)->willReturn(TRUE);
    $this->message->get($name)->willReturn($this->field($value));
  }

  /**
   * Registers an entity reference field on the message double.
   *
   * @param string $name
   *   The field machine name.
   * @param array $target_ids
   *   The selected target ids.
   */
  protected function withTargets(string $name, array $target_ids): void {
    $this->message->hasField($name)->willReturn(TRUE);
    $this->message->get($name)->willReturn($this->referenceField($target_ids));
  }

  /**
   * Makes the message unreadable by the given user.
   *
   * @param int $owner
   *   The uid that owns the message.
   */
  protected function ownedBy(int $owner): void {
    $this->withValue('uid', [['target_id' => (string) $owner]]);
    $this->storage->load(100)->willReturn($this->message->reveal());
  }

  /**
   * The payload excludes base fields and server-computed fields.
   */
  public function testExcludesBaseAndComputedFields(): void {
    $this->ownedBy(42);
    $this->withValue('field_weight', [['value' => '388.40']]);
    $this->withValue('field_date', [['value' => '2026-09-28 00:00:00']]);
    // These exist on the entity but are not patient-editable.
    $this->withValue('field_program_day', [['value' => '14']]);
    $this->withValue('field_total_measurements', [['value' => '270.50']]);
    $this->withValue('field_weight_loss_to_date', [['value' => '12.00']]);
    $this->withTargets('field_author', ['42']);

    $entry = $this->service->getEntry(100, 42);

    $this->assertIsArray($entry);
    $this->assertArrayHasKey('field_weight', $entry);
    $this->assertArrayHasKey('field_date', $entry);

    foreach (['uid', 'id', 'uuid', 'langcode', 'created', 'ip_address', 'subject', 'message', 'contact_form'] as $base) {
      $this->assertArrayNotHasKey($base, $entry, "Base field $base must not be returned.");
    }
    foreach (['field_program_day', 'field_total_measurements', 'field_weight_loss_to_date', 'field_author'] as $computed) {
      $this->assertArrayNotHasKey($computed, $entry, "Computed field $computed must not be returned.");
    }
  }

  /**
   * A single selected option still comes back as a list.
   *
   * This is the case that silently broke the form: a bare scalar left every
   * box in a checkbox-grid unchecked, so saving the form cleared the answer.
   */
  public function testSingleSelectionIsAList(): void {
    $this->ownedBy(42);
    $this->withTargets('field_lunch_fruit_sel', ['21']);

    $entry = $this->service->getEntry(100, 42);

    $this->assertArrayHasKey('field_lunch_fruit_sel', $entry);
    $this->assertSame(['21'], $entry['field_lunch_fruit_sel']);
  }

  /**
   * Several selected options come back as a list of strings.
   */
  public function testMultipleSelectionsAreAList(): void {
    $this->ownedBy(42);
    $this->withTargets('field_lunch_fruit_sel', ['21', '22', '23']);

    $entry = $this->service->getEntry(100, 42);

    $this->assertSame(['21', '22', '23'], $entry['field_lunch_fruit_sel']);
  }

  /**
   * A boolean field comes back as a real boolean, matching `value === true`.
   */
  public function testBooleanIsABoolean(): void {
    $this->ownedBy(42);
    $this->withValue('field_mind_set_work', [['value' => '1']]);
    $this->withValue('field_chiroburst_bend', [['value' => '0']]);

    $entry = $this->service->getEntry(100, 42);

    $this->assertTrue($entry['field_mind_set_work']);
    $this->assertFalse($entry['field_chiroburst_bend']);
  }

  /**
   * Decimal fields come back as strings, exactly as the patient typed them.
   *
   * The frontend's `FieldValue` is `string | string[] | boolean | undefined`
   * — there is no `number` member — and a number input's state is
   * `event.target.value`, so a float would both break the declared type and
   * reformat `"15.50"` into `15.5` under the patient.
   */
  public function testNumbersStayStrings(): void {
    $this->ownedBy(42);
    $this->withValue('field_weight', [['value' => '388.40']]);
    $this->withValue('field_neck', [['value' => '15.50']]);

    $entry = $this->service->getEntry(100, 42);

    $this->assertSame('388.40', $entry['field_weight']);
    $this->assertSame('15.50', $entry['field_neck']);
  }

  /**
   * A non-numeric value in a number field is dropped rather than seeded.
   */
  public function testNonNumericNumberFieldIsOmitted(): void {
    $this->ownedBy(42);
    $this->withValue('field_weight', [['value' => 'not a number']]);

    $entry = $this->service->getEntry(100, 42);

    $this->assertArrayNotHasKey('field_weight', $entry);
  }

  /**
   * A date comes back as Y-m-d, which is what `<input type="date">` needs.
   */
  public function testDateIsIsoWithoutTime(): void {
    $this->ownedBy(42);
    $this->withValue('field_date', [['value' => '2026-09-28 00:00:00']]);

    $entry = $this->service->getEntry(100, 42);

    $this->assertSame('2026-09-28', $entry['field_date']);
  }

  /**
   * A single-value entity reference and a list integer stay strings.
   */
  public function testScalarReferencesStayStrings(): void {
    $this->ownedBy(42);
    $this->withTargets('field_flags', ['3']);
    $this->withTargets('field_breakfast_protein', ['12']);
    $this->withValue('field_grade', [['value' => '7']]);

    $entry = $this->service->getEntry(100, 42);

    $this->assertSame('3', $entry['field_flags']);
    $this->assertSame('12', $entry['field_breakfast_protein']);
    $this->assertSame('7', $entry['field_grade']);
  }

  /**
   * Empty fields are omitted so the form falls back to its own defaults.
   */
  public function testEmptyFieldsAreOmitted(): void {
    $this->ownedBy(42);
    $this->withValue('field_weight', [['value' => '388.40']]);
    $this->message->hasField('field_notes')->willReturn(TRUE);
    $this->message->get('field_notes')->willReturn($this->field([]));

    $entry = $this->service->getEntry(100, 42);

    $this->assertArrayNotHasKey('field_notes', $entry);
  }

  /**
   * Somebody else's log is not readable.
   */
  public function testEntryOwnedByAnotherUserIsNotReturned(): void {
    $this->ownedBy(42);
    $this->withValue('field_weight', [['value' => '388.40']]);

    $this->assertNull($this->service->getEntry(100, 7));
  }

  /**
   * A message that does not exist is not returned.
   */
  public function testMissingMessageIsNotReturned(): void {
    $this->storage->load(100)->willReturn(NULL);

    $this->assertNull($this->service->getEntry(100, 42));
  }

  /**
   * A message from another contact form is not returned.
   */
  public function testOtherBundleIsNotReturned(): void {
    $this->message->bundle()->willReturn('patient_intake');
    $this->storage->load(100)->willReturn($this->message->reveal());

    $this->assertNull($this->service->getEntry(100, 42));
  }

}
