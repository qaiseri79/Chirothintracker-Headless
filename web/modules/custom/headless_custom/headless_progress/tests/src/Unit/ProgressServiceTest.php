<?php

namespace Drupal\Tests\headless_progress\Unit;

use Drupal\headless_progress\ProgressService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Tests for ProgressService's date and free-text handling.
 *
 * @group headless_progress
 */
class ProgressServiceTest extends TestCase {

  use ProphecyTrait;

  protected ProgressService $service;

  protected ObjectProphecy $entityTypeManager;

  protected ObjectProphecy $currentUser;

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->currentUser = $this->prophesize(AccountProxyInterface::class);
    $this->currentUser->id()->willReturn(7);

    $this->service = new ProgressService(
      $this->entityTypeManager->reveal(),
      $this->currentUser->reveal()
    );
  }

  /**
   * Calls the protected formatDate() through reflection.
   *
   * formatDate() is deliberately not widened to public: it is an internal detail
   * of how one entry is shaped, and the only caller is mapEntry(). Testing it
   * directly is honest about that, whereas driving it through getProgress()
   * would mean stubbing a static entityQuery() call to reach one line.
   */
  protected function formatDate($value): string {
    $method = new \ReflectionMethod(ProgressService::class, 'formatDate');
    $method->setAccessible(TRUE);
    return $method->invoke($this->service, $value);
  }

  /**
   * A plain stored date string is formatted for display.
   */
  public function testPlainDateStringIsFormatted(): void {
    $this->assertSame('01/14/2025', $this->formatDate('2025-01-14'));
  }

  /**
   * A datetime string keeps working.
   */
  public function testDateTimeStringIsFormatted(): void {
    $this->assertSame('01/14/2025', $this->formatDate('2025-01-14 00:00:00'));
  }

  /**
   * The value getFieldValue() returns for a single-value date field.
   */
  public function testFieldItemListShapeIsUnwrapped(): void {
    $this->assertSame('01/14/2025', $this->formatDate(['value' => '2025-01-14']));
  }

  /**
   * A date field that also carries a timezone.
   *
   * This is the shape that could not be passed to the old `string` parameter at
   * all: getFieldValue() returns the whole item list whenever its two known
   * single-value shapes do not match, and a two-key array matches neither.
   */
  public function testDateFieldWithTimezoneIsUnwrapped(): void {
    $this->assertSame(
      '01/14/2025',
      $this->formatDate(['value' => '2025-01-14', 'timezone' => 'UTC'])
    );
  }

  /**
   * A nested list is handled too, rather than assumed away.
   */
  public function testNestedListShapeIsUnwrapped(): void {
    $this->assertSame('01/14/2025', $this->formatDate([['value' => '2025-01-14']]));
  }

  /**
   * Nothing date-shaped yields an empty string, never an exception.
   *
   * mapEntry() treats '' as "no date recorded" and falls back to the submission's
   * created time.
   */
  public function testUnusableValueYieldsEmptyString(): void {
    $this->assertSame('', $this->formatDate(NULL));
    $this->assertSame('', $this->formatDate(''));
    $this->assertSame('', $this->formatDate([]));
    $this->assertSame('', $this->formatDate(['nonsense' => 'value']));
    $this->assertSame('', $this->formatDate(12345));
  }

  /**
   * An unparseable string is passed through rather than discarded, so a
   * malformed stored value is visible on screen instead of silently hidden.
   */
  public function testUnparseableStringIsPreserved(): void {
    $this->assertSame('not a date', $this->formatDate('not a date'));
  }

}
