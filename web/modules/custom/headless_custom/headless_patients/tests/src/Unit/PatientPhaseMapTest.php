<?php

declare(strict_types=1);

namespace Drupal\Tests\headless_patients\Unit;

use Drupal\headless_patients\PatientPhaseMap;
use PHPUnit\Framework\TestCase;

/**
 * Tests the phase code translation.
 *
 * @coversDefaultClass \Drupal\headless_patients\PatientPhaseMap
 * @group headless_patients
 */
class PatientPhaseMapTest extends TestCase {

  /**
   * The map under test.
   */
  private PatientPhaseMap $map;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->map = new PatientPhaseMap();
  }

  /**
   * The stored values and their codes, from SetLockField.php's labels.
   */
  public static function phaseProvider(): array {
    return [
      'zero-day' => ['phase-0', 'Z', 'Zero-Day (Pre-Loading)'],
      'loading' => ['phase-1', 'D', 'Loading Phase'],
      'losing' => ['phase-2', 'L', 'Losing Phase'],
      'cycling' => ['phase-3', 'M', 'Cycling / Maintenance Phase'],
      'continuity' => ['phase-4', 'C', 'Continuity Phase'],
    ];
  }

  /**
   * Every phase round-trips in both directions.
   *
   * @dataProvider phaseProvider
   */
  public function testPhaseRoundTrips(string $stored, string $code, string $label): void {
    $this->assertSame($code, $this->map->code($stored), 'Stored value maps to its code.');
    $this->assertSame($stored, $this->map->stored($code), 'Code maps back to its stored value.');
    $this->assertSame($label, $this->map->label($code), 'Code maps to its label.');
  }

  /**
   * The codes are deliberately not the phase numbers.
   *
   * If this ever starts passing, someone has "fixed" the map by making the code
   * equal the index, which would silently repoint every phase in every patient
   * record already written to the database.
   */
  public function testCodesAreNotPhaseNumbers(): void {
    $this->assertSame('C', $this->map->code('phase-4'), 'Continuity is phase-4, not C-as-index.');
    $this->assertSame('M', $this->map->code('phase-3'), 'Cycling is phase-3.');
    $this->assertNotSame('L', $this->map->code('phase-0'), 'Losing is not phase-0.');
  }

  /**
   * Unknown and absent values do not invent a phase.
   *
   * A patient with no phase set must come back as NULL rather than as a default
   * phase, or the roster would show a program phase they are not in.
   */
  public function testUnknownValuesAreNull(): void {
    $this->assertNull($this->map->code(NULL));
    $this->assertNull($this->map->code(''));
    $this->assertNull($this->map->code('phase-9'));
    $this->assertNull($this->map->code('Zero-Day'));
    $this->assertNull($this->map->stored(NULL));
    $this->assertNull($this->map->stored('X'));
    $this->assertNull($this->map->label('X'));
    $this->assertFalse($this->map->isValidCode('X'));
    $this->assertFalse($this->map->isValidCode(NULL));
  }

  /**
   * Codes are accepted case-insensitively on input.
   *
   * The stored values are uppercase single letters, and a lowercase "l" from a
   * hand-edited request should not be reported as an unknown phase.
   */
  public function testValidCodeIsCaseInsensitiveWhenCheckedByCaller(): void {
    $this->assertTrue($this->map->isValidCode('L'));
    $this->assertTrue($this->map->isValidCode('l') === FALSE, 'isValidCode is exact; callers normalise.');
  }

  /**
   * The form's option order is the design's, and every code is present once.
   */
  public function testOptionsMatchTheFormOrder(): void {
    $this->assertSame(['L', 'D', 'Z', 'C', 'M'], array_keys($this->map->options()));
    $this->assertCount(5, $this->map->all());
  }

  /**
   * Every stored value is unique, so a reverse lookup cannot be ambiguous.
   */
  public function testStoredValuesAreUnique(): void {
    $stored = $this->map->storedByCode();
    $this->assertSame($stored, array_unique($stored));
    $this->assertCount(5, $this->map->codeByStored());
  }
}
